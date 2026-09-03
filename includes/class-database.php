<?php
class Hermes_Bridge_Database {

    public static function init() {
        // Nothing needed here for now
    }

    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Sync State Table - tracks last sync for each source
        $sql_sync_state = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hermes_sync_state (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(50) NOT NULL,
            last_sync_at datetime DEFAULT '0000-00-00 00:00:00',
            last_id bigint(20) DEFAULT 0,
            checksum varchar(64) DEFAULT '',
            meta longtext DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source (source)
        ) $charset_collate;";

        // Events Table - APPEND-ONLY for unique events
        $sql_events = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hermes_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            source varchar(50) NOT NULL,
            source_id varchar(100) NOT NULL,
            payload longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            consumed tinyint(1) DEFAULT 0,
            consumed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY source_lookup (source, source_id, event_type),
            KEY consumed (consumed, created_at)
        ) $charset_collate;";

        // Snapshots Table - OVERWRITE for changing metrics
        $sql_snapshots = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hermes_snapshots (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            metric_key varchar(100) NOT NULL,
            metric_value longtext NOT NULL,
            metric_type varchar(20) DEFAULT 'gauge',
            delta longtext DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY metric_key (metric_key)
        ) $charset_collate;";

        // Actions Queue Table
        $sql_actions = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hermes_actions_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_type varchar(50) NOT NULL,
            target_system varchar(50) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) DEFAULT 'pending',
            result longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status, created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sync_state);
        dbDelta($sql_events);
        dbDelta($sql_snapshots);
        dbDelta($sql_actions);

        // Insert initial sync states
        $sources = array('edd_orders', 'edd_customers', 'erp_contacts', 'erp_deals', 
                         'erp_tasks', 'pm_projects', 'pm_tasks', 'pm_milestones', 'wp_stats');
        foreach ($sources as $source) {
            $wpdb->replace($wpdb->prefix . 'hermes_sync_state', array(
                'source' => $source,
                'last_sync_at' => current_time('mysql'),
                'last_id' => 0,
                'checksum' => ''
            ), array('%s', '%s', '%d', '%s'));
        }
    }

    public static function get_sync_state($source) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hermes_sync_state WHERE source = %s",
            $source
        ), ARRAY_A);
    }

    public static function update_sync_state($source, $data) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'hermes_sync_state', $data, 
                      array('source' => $source), null, array('%s'));
    }

    public static function insert_event($event_type, $source, $source_id, $payload) {
        global $wpdb;

        // Deduplication: skip if same source + source_id + event_type exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hermes_events 
             WHERE source = %s AND source_id = %s AND event_type = %s LIMIT 1",
            $source, $source_id, $event_type
        ));

        if ($exists) return false;

        $wpdb->insert($wpdb->prefix . 'hermes_events', array(
            'event_type' => $event_type,
            'source' => $source,
            'source_id' => $source_id,
            'payload' => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
            'consumed' => 0
        ));

        return $wpdb->insert_id;
    }

    public static function get_unconsumed_events($limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hermes_events 
             WHERE consumed = 0 ORDER BY created_at ASC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public static function mark_events_consumed($ids) {
        global $wpdb;
        if (empty($ids)) return;
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}hermes_events SET consumed = 1, consumed_at = %s WHERE id IN ($placeholders)",
            array_merge(array(current_time('mysql')), $ids)
        ));
    }

    public static function update_snapshot($metric_key, $metric_value, $metric_type = 'gauge') {
        global $wpdb;

        $old = $wpdb->get_row($wpdb->prepare(
            "SELECT metric_value FROM {$wpdb->prefix}hermes_snapshots WHERE metric_key = %s",
            $metric_key
        ), ARRAY_A);

        $delta = null;
        if ($old) {
            $old_val = json_decode($old['metric_value'], true);
            $new_val = is_array($metric_value) ? $metric_value : json_decode($metric_value, true);
            if (is_numeric($old_val) && is_numeric($new_val)) {
                $delta = $new_val - $old_val;
            }
        }

        $wpdb->replace($wpdb->prefix . 'hermes_snapshots', array(
            'metric_key' => $metric_key,
            'metric_value' => is_string($metric_value) ? $metric_value : wp_json_encode($metric_value),
            'metric_type' => $metric_type,
            'delta' => $delta !== null ? wp_json_encode($delta) : null,
            'updated_at' => current_time('mysql')
        ));
    }

    public static function get_snapshots() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hermes_snapshots", ARRAY_A);
    }

    public static function queue_action($action_type, $target_system, $payload) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'hermes_actions_queue', array(
            'action_type' => $action_type,
            'target_system' => $target_system,
            'payload' => wp_json_encode($payload),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ));
        return $wpdb->insert_id;
    }

    public static function get_pending_actions($limit = 10) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hermes_actions_queue 
             WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public static function update_action_status($id, $status, $result = null) {
        global $wpdb;
        $data = array('status' => $status, 'processed_at' => current_time('mysql'));
        if ($result !== null) $data['result'] = wp_json_encode($result);
        $wpdb->update($wpdb->prefix . 'hermes_actions_queue', $data, array('id' => $id));
    }
}
