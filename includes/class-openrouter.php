<?php
/**
 * OpenRouter API client — chat completions with retry/backoff,
 * plus live model catalog (typeahead-friendly).
 * Key is NOT stored here — read from Agent::get_openrouter_key().
 */
class Hermes_Bridge_OpenRouter {

    const BASE = 'https://openrouter.ai/api/v1';

    public static function init() {}

    /**
     * Live model list from OpenRouter, cached 24h.
     * Returns array of ['id','name','context','pricing'].
     */
    public static function get_models() {
        $cached = get_transient( 'hermes_or_models' );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }
        $key = Hermes_Bridge_Agent::get_openrouter_key();
        if ( ! $key ) {
            return array();
        }
        $resp = wp_remote_get( self::BASE . '/models', array(
            'timeout' => 30,
            'headers' => array( 'Authorization' => 'Bearer ' . $key ),
        ) );
        if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
            return array();
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        $models = array();
        if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
            foreach ( $body['data'] as $m ) {
                $models[] = array(
                    'id'      => isset( $m['id'] ) ? $m['id'] : '',
                    'name'    => isset( $m['name'] ) ? $m['name'] : '',
                    'context' => isset( $m['context_length'] ) ? intval( $m['context_length'] ) : 0,
                    'pricing' => isset( $m['pricing'] ) ? $m['pricing'] : array(),
                );
            }
        }
        set_transient( 'hermes_or_models', $models, DAY_IN_SECONDS );
        return $models;
    }

    /**
     * Chat completion. Retries up to 3 times with backoff on 429/5xx.
     * Key is obtained from Agent::get_openrouter_key() each call.
     */
    public static function chat( $messages, $model, $temperature = 0.6, $max_tokens = 2048 ) {
        $key = Hermes_Bridge_Agent::get_openrouter_key();
        if ( ! $key ) {
            return array( 'success' => false, 'content' => '', 'error' => 'OpenRouter key not configured (set in WordPress AI → Connectors or HERMES_OPENROUTER_KEY constant)', 'model' => $model );
        }
        if ( ! $model ) {
            return array( 'success' => false, 'content' => '', 'error' => 'No model selected', 'model' => '' );
        }

        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => floatval( $temperature ),
            'max_tokens'  => intval( $max_tokens ),
        );

        $attempts = 3;
        for ( $i = 0; $i < $attempts; $i++ ) {
            $resp = wp_remote_post( self::BASE . '/chat/completions', array(
                'timeout' => 120,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                    'X-Title'       => 'Dynamix Hermes Agent',
                ),
                'body' => wp_json_encode( $body ),
            ) );

            if ( is_wp_error( $resp ) ) {
                if ( $i < $attempts - 1 ) { sleep( 2 + $i * 2 ); continue; }
                return array( 'success' => false, 'content' => '', 'error' => $resp->get_error_message(), 'model' => $model );
            }

            $code = wp_remote_retrieve_response_code( $resp );
            $raw  = wp_remote_retrieve_body( $resp );

            if ( 200 === $code ) {
                $json = json_decode( $raw, true );
                $content = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
                return array( 'success' => true, 'content' => $content, 'error' => null, 'model' => $model );
            }

            if ( 429 === $code || $code >= 500 ) {
                if ( $i < $attempts - 1 ) { sleep( 3 + $i * 3 ); continue; }
            }

            return array( 'success' => false, 'content' => '', 'error' => 'HTTP ' . $code . ': ' . substr( $raw, 0, 300 ), 'model' => $model );
        }

        return array( 'success' => false, 'content' => '', 'error' => 'retries exhausted', 'model' => $model );
    }
}