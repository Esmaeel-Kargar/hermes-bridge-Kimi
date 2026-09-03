<?php
/**
 * Private admin chat — sessions, file/image uploads, RTL/LTR.
 * Security: history rebuilt SERVER-SIDE from DB. Client only sends plain text.
 * manage_options or X-Hermes-Key required.
 */
class Hermes_Bridge_Agent_Chat {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        $ns = 'hermes-bridge/v1';
        // Chat
        register_rest_route( $ns, '/chat', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'chat' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
            'args' => array(
                'message'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
                'session_id' => array( 'type' => 'integer', 'default' => 0 ),
            ),
        ) );
        // History
        register_rest_route( $ns, '/chat/history', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'history' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
            'args'     => array( 'session_id' => array( 'type' => 'integer', 'default' => 0 ) ),
        ) );
        // Sessions CRUD
        register_rest_route( $ns, '/chat/sessions', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'list_sessions' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
        register_rest_route( $ns, '/chat/sessions', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'create_session' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
            'args'     => array( 'name' => array( 'type' => 'string', 'default' => 'New Chat' ) ),
        ) );
        register_rest_route( $ns, '/chat/sessions/(?P<id>\d+)', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'update_session' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
        register_rest_route( $ns, '/chat/sessions/(?P<id>\d+)', array(
            'methods'  => 'DELETE',
            'callback' => array( __CLASS__, 'delete_session' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
        // Upload
        register_rest_route( $ns, '/chat/upload', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'upload' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
    }

    public static function check_auth( $request ) {
        $key = get_option( 'hermes_bridge_api_key' );
        $provided = $request->get_header( 'X-Hermes-Key' );
        if ( $key && $provided && hash_equals( $key, $provided ) ) return true;
        return current_user_can( 'manage_options' );
    }

    // ========== Sessions ==========
    public static function list_sessions() {
        return rest_ensure_response( array( 'sessions' => Hermes_Bridge_Agent_DB::get_sessions() ) );
    }

    public static function create_session( $request ) {
        $name = sanitize_text_field( $request->get_param( 'name' ) ?: 'New Chat' );
        $id = Hermes_Bridge_Agent_DB::create_session( $name );
        return rest_ensure_response( array( 'session_id' => $id, 'name' => $name ) );
    }

    public static function update_session( $request ) {
        $id = intval( $request->get_param( 'id' ) );
        $data = array();
        if ( $request->has_param( 'name' ) )     $data['name'] = sanitize_text_field( $request->get_param( 'name' ) );
        if ( $request->has_param( 'archived' ) )  $data['archived'] = $request->get_param( 'archived' ) ? 1 : 0;
        if ( $request->has_param( 'pinned' ) )    $data['pinned'] = $request->get_param( 'pinned' ) ? 1 : 0;
        if ( ! empty( $data ) ) {
            $data['updated_at'] = current_time( 'mysql' );
            Hermes_Bridge_Agent_DB::update_session( $id, $data );
        }
        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function delete_session( $request ) {
        $id = intval( $request->get_param( 'id' ) );
        Hermes_Bridge_Agent_DB::delete_session( $id );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ========== Chat ==========
    public static function history( $request ) {
        $session_id = intval( $request->get_param( 'session_id' ) );
        if ( ! $session_id ) {
            // Return session list instead
            return self::list_sessions();
        }
        $rows = Hermes_Bridge_Agent_DB::get_chat( $session_id, 200 );
        $sanitized = array();
        foreach ( $rows as $r ) {
            $sanitized[] = array(
                'role'    => $r['role'],
                'content' => $r['content'],
                'time'    => $r['created_at'],
            );
        }
        return rest_ensure_response( array( 'history' => $sanitized, 'session_id' => $session_id ) );
    }

    public static function chat( $request ) {
        if ( ! Hermes_Bridge_Agent::get_openrouter_key() ) {
            return new WP_Error( 'no_key', __( 'OpenRouter key not configured', 'hermes-bridge' ), array( 'status' => 400 ) );
        }
        $settings = Hermes_Bridge_Agent::settings();
        $model = $settings['chat_model'];
        if ( ! $model ) {
            return new WP_Error( 'no_model', __( 'No chat model selected', 'hermes-bridge' ), array( 'status' => 400 ) );
        }

        $session_id = intval( $request->get_param( 'session_id' ) );
        if ( ! $session_id ) {
            $session_id = Hermes_Bridge_Agent_DB::create_session();
        }

        $user_msg = sanitize_textarea_field( $request->get_param( 'message' ) );
        if ( ! $user_msg ) {
            return new WP_Error( 'empty', __( 'Message is empty', 'hermes-bridge' ), array( 'status' => 400 ) );
        }

        // Check for file attachment
        $file_url = '';
        if ( $request->has_param( 'file_url' ) ) {
            $file_url = esc_url_raw( $request->get_param( 'file_url' ) );
        }
        if ( $file_url ) {
            $user_msg .= "\n\n[Attachment: $file_url]";
        }

        // Rebuild conversation from this session (server-side)
        $db_rows = Hermes_Bridge_Agent_DB::get_chat( $session_id, 50 );
        $messages = array();
        foreach ( $db_rows as $r ) {
            $messages[] = array( 'role' => $r['role'], 'content' => $r['content'] );
        }

        // Context + system prompt
        $context = Hermes_Bridge_Agent::build_context( 2 );
        $system = "You are Hermes — the private business agent for Dynamix Systems. "
                . "You answer only the operator (E.K) inside the WP admin dashboard. "
                . "You help plan and manage. You can propose actions, but never execute anything in chat.\n\n"
                . "Current data/memory:\n" . $context;

        $messages = array_merge(
            array( array( 'role' => 'system', 'content' => $system ) ),
            $messages,
            array( array( 'role' => 'user', 'content' => $user_msg ) )
        );

        $res = Hermes_Bridge_OpenRouter::chat( $messages, $model, 0.7, 1500 );

        if ( ! $res['success'] ) {
            return new WP_Error( 'ai_error', $res['error'], array( 'status' => 502 ) );
        }

        Hermes_Bridge_Agent_DB::insert_chat( $session_id, 'user', $user_msg );
        Hermes_Bridge_Agent_DB::insert_chat( $session_id, 'assistant', $res['content'] );

        return rest_ensure_response( array(
            'reply'      => $res['content'],
            'model'      => $model,
            'session_id' => $session_id,
        ) );
    }

    // ========== Upload ==========
    public static function upload( $request ) {
        $files = $request->get_file_params();
        if ( empty( $files ) || ! isset( $files['file'] ) ) {
            return new WP_Error( 'no_file', __( 'No file uploaded', 'hermes-bridge' ), array( 'status' => 400 ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'upload_error', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }
        $url = wp_get_attachment_url( $attachment_id );
        $type = get_post_mime_type( $attachment_id );
        $name = basename( $url );
        return rest_ensure_response( array(
            'success' => true,
            'url'     => $url,
            'name'    => $name,
            'type'    => $type,
            'id'      => $attachment_id,
        ) );
    }
}