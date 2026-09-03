<?php
/**
 * Agent DB — tables for chat (with sessions), memory, reports, and learning log.
 * Key change v2.1: no more "approval queue" — proposals go to PM/ERP directly.
 * Instead we have: agent_log (learning), sessions (chat sessions).
 */
class Hermes_Bridge_Agent_DB {

    public static function init() {}

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p  = $wpdb->prefix;
        $cc = $wpdb->get_charset_collate();

        $sessions = "CREATE TABLE IF NOT EXISTS {$p}hermes_sessions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL DEFAULT 'New Chat',
            archived tinyint(1) NOT NULL DEFAULT 0,
            pinned tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY archived (archived, pinned)
        ) $cc;";

        $chat = "CREATE TABLE IF NOT EXISTS {$p}hermes_chat (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) NOT NULL DEFAULT 0,
            user_id bigint(20) NOT NULL DEFAULT 0,
            role varchar(20) NOT NULL,
            content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session (session_id),
            KEY role (role)
        ) $cc;";

        $memory = "CREATE TABLE IF NOT EXISTS {$p}hermes_memory (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            memory_key varchar(150) DEFAULT '',
            memory_value longtext NOT NULL,
            kind varchar(30) DEFAULT 'fact',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY kind (kind)
        ) $cc;";

        $reports = "CREATE TABLE IF NOT EXISTS {$p}hermes_reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            depth tinyint(1) NOT NULL DEFAULT 2,
            model varchar(150) DEFAULT '',
            summary text,
            report_json longtext,
            feedback longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY depth (depth)
        ) $cc;";

        $log = "CREATE TABLE IF NOT EXISTS {$p}hermes_agent_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_name varchar(60) DEFAULT 'general',
            proposal_title varchar(255) DEFAULT '',
            decision varchar(30) DEFAULT 'proposed',
            report_id bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY group_name (group_name),
            KEY decision (decision)
        ) $cc;";

        dbDelta( $sessions );
        dbDelta( $chat );
        dbDelta( $memory );
        dbDelta( $reports );
        dbDelta( $log );

        // Seed default memory
        self::add_memory( 'agent_name', 'Hermes Business Agent', 'fact' );
        self::add_memory( 'brand', 'Dynamix Systems — engineering design, mechatronics, UAV/RC, 3D printed parts', 'fact' );
    }

    // ========== CHAT SESSIONS ==========
    public static function create_session( $name = 'New Chat' ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hermes_sessions', array(
            'name' => sanitize_text_field( $name ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ) );
        return $wpdb->insert_id;
    }

    public static function get_sessions() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, archived, pinned, created_at, updated_at,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}hermes_chat WHERE session_id = s.id) as msg_count
             FROM {$wpdb->prefix}hermes_sessions s
             ORDER BY pinned DESC, updated_at DESC LIMIT 100",
            ARRAY_A
        );
    }

    public static function update_session( $id, $data ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'hermes_sessions', $data, array( 'id' => intval( $id ) ) );
    }

    public static function delete_session( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'hermes_chat', array( 'session_id' => intval( $id ) ) );
        $wpdb->delete( $p . 'hermes_sessions', array( 'id' => intval( $id ) ) );
    }

    // ========== CHAT MESSAGES ==========
    public static function insert_chat( $session_id, $role, $content ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hermes_chat', array(
            'session_id' => intval( $session_id ),
            'user_id'    => get_current_user_id(),
            'role'       => in_array( $role, array( 'user', 'assistant' ), true ) ? $role : 'user',
            'content'    => $content,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s' ) );
        // Touch session updated_at
        $wpdb->update( $wpdb->prefix . 'hermes_sessions',
            array( 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => intval( $session_id ) ) );
        return $wpdb->insert_id;
    }

    public static function get_chat( $session_id, $limit = 200 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT role, content, created_at FROM {$wpdb->prefix}hermes_chat
             WHERE session_id = %d ORDER BY id ASC LIMIT %d",
            $session_id, $limit
        ), ARRAY_A );
    }

    public static function clear_chat( $session_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'hermes_chat', array( 'session_id' => intval( $session_id ) ) );
    }

    // ========== MEMORY ==========
    public static function add_memory( $key, $value, $kind = 'fact' ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}hermes_memory WHERE memory_key = %s AND kind = %s LIMIT 1",
            $key, $kind
        ) );
        if ( $exists ) {
            $wpdb->update( $p . 'hermes_memory',
                array( 'memory_value' => $value, 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $exists ) );
            return $exists;
        }
        $wpdb->insert( $p . 'hermes_memory', array(
            'memory_key'   => $key,
            'memory_value' => $value,
            'kind'         => $kind,
            'created_at'   => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ) );
        return $wpdb->insert_id;
    }

    public static function get_memories( $kinds = array() ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( empty( $kinds ) ) {
            return $wpdb->get_results( "SELECT * FROM {$p}hermes_memory ORDER BY updated_at DESC LIMIT 200", ARRAY_A );
        }
        $in = array_map( 'esc_sql', $kinds );
        return $wpdb->get_results(
            "SELECT * FROM {$p}hermes_memory WHERE kind IN ('" . implode( "','", $in ) . "') ORDER BY updated_at DESC LIMIT 200",
            ARRAY_A
        );
    }

    public static function delete_memory( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'hermes_memory', array( 'id' => intval( $id ) ) );
    }

    // ========== REPORTS ==========
    public static function insert_report( $depth, $model, $summary, $report_json ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hermes_reports', array(
            'depth'       => intval( $depth ),
            'model'       => $model,
            'summary'     => $summary,
            'report_json' => $report_json,
            'created_at'  => current_time( 'mysql' ),
        ) );
        return $wpdb->insert_id;
    }

    public static function get_reports( $limit = 30 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hermes_reports ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    public static function add_report_feedback( $report_id, $feedback ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'hermes_reports',
            array( 'feedback' => $feedback ),
            array( 'id' => intval( $report_id ) ) );
    }

    // ========== LEARNING LOG ==========
    public static function log_proposal( $group, $title, $decision, $report_id = 0 ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hermes_agent_log', array(
            'group_name'      => sanitize_text_field( $group ),
            'proposal_title'  => sanitize_text_field( $title ),
            'decision'        => in_array( $decision, array( 'proposed', 'approved', 'rejected', 'edited', 'auto_executed', 'failed' ), true ) ? $decision : 'proposed',
            'report_id'       => intval( $report_id ),
            'created_at'      => current_time( 'mysql' ),
        ) );
        return $wpdb->insert_id;
    }

    public static function log_decision( $group, $decision, $title = '' ) {
        return self::log_proposal( $group, $title, $decision, 0 );
    }

    public static function get_learning_log( $group = '', $limit = 100 ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $group ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}hermes_agent_log WHERE group_name = %s ORDER BY id DESC LIMIT %d",
                $group, $limit
            ), ARRAY_A );
        }
        return $wpdb->get_results( "SELECT * FROM {$p}hermes_agent_log ORDER BY id DESC LIMIT $limit", ARRAY_A );
    }
}