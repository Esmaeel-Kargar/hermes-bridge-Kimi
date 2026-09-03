<?php
class Hermes_Bridge_Sync_Engine {

    public static function init() {
        add_action('hermes_bridge_sync', array(__CLASS__, 'run_full_sync'));
    }

    /**
     * Check if a table exists (guards against optional/missing ERP/PM/stats tables).
     */
    private static function table_exists( $table ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return ! empty( $found );
    }

    /**
     * Get list of column names for a table (to adapt queries to real schema).
     */
    private static function table_columns( $table ) {
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        return $cols ? $cols : array();
    }

    public static function run_full_sync() {
        $start_time = microtime(true);
        $stats = array(
            'edd_orders' => 0,
            'edd_customers' => 0,
            'erp_contacts' => 0,
            'erp_deals' => 0,
            'erp_tasks' => 0,
            'pm_projects' => 0,
            'pm_tasks' => 0,
            'pm_milestones' => 0,
            'wp_stats' => 0,
            'snapshots' => 0
        );

        try {
            $stats['edd_orders'] = self::sync_edd_orders();
            $stats['edd_customers'] = self::sync_edd_customers();
            $stats['erp_contacts'] = self::sync_erp_contacts();
            $stats['erp_deals'] = self::sync_erp_deals();
            $stats['erp_tasks'] = self::sync_erp_tasks();
            $stats['pm_projects'] = self::sync_pm_projects();
            $stats['pm_tasks'] = self::sync_pm_tasks();
            $stats['pm_milestones'] = self::sync_pm_milestones();
            $stats['wp_stats'] = self::sync_wp_statistics();
            $stats['snapshots'] = self::update_all_snapshots();

        } catch (Exception $e) {
            error_log('Hermes Bridge Sync Error: ' . $e->getMessage());
        }

        $duration = round(microtime(true) - $start_time, 3);
        update_option('hermes_bridge_last_sync', array(
            'time' => current_time('mysql'),
            'duration' => $duration,
            'stats' => $stats
        ));

        return $stats;
    }

    // ========== EDD SYNC ==========

    private static function sync_edd_orders() {
        if (!function_exists('edd_get_orders')) return 0;

        global $wpdb;
        $state = Hermes_Bridge_Database::get_sync_state('edd_orders');
        $last_id = $state ? intval($state['last_id']) : 0;

        $orders = edd_get_orders(array(
            'number' => 50,
            'orderby' => 'id',
            'order' => 'ASC',
            'id__gt' => $last_id
        ));

        $count = 0;
        foreach ($orders as $order) {
            $payload = array(
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'total' => $order->total,
                'status' => $order->status,
                'date' => $order->date_created,
                'items' => array()
            );

            foreach ($order->get_items() as $item) {
                $payload['items'][] = array(
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'amount' => $item->amount
                );
            }

            if (Hermes_Bridge_Database::insert_event('new_order', 'edd', $order->id, $payload)) {
                $count++;
                $last_id = max($last_id, $order->id);
            }
        }

        Hermes_Bridge_Database::update_sync_state('edd_orders', array(
            'last_sync_at' => current_time('mysql'),
            'last_id' => $last_id
        ));

        return $count;
    }

    private static function sync_edd_customers() {
        if (!function_exists('edd_get_customers')) return 0;

        $state = Hermes_Bridge_Database::get_sync_state('edd_customers');
        $last_id = $state ? intval($state['last_id']) : 0;

        $customers = edd_get_customers(array(
            'number' => 50,
            'orderby' => 'id',
            'order' => 'ASC',
            'id__gt' => $last_id
        ));

        $count = 0;
        foreach ($customers as $customer) {
            $payload = array(
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'purchase_count' => $customer->purchase_count,
                'purchase_value' => $customer->purchase_value,
                'date_created' => $customer->date_created
            );

            if (Hermes_Bridge_Database::insert_event('new_customer', 'edd', $customer->id, $payload)) {
                $count++;
                $last_id = max($last_id, $customer->id);
            }
        }

        Hermes_Bridge_Database::update_sync_state('edd_customers', array(
            'last_sync_at' => current_time('mysql'),
            'last_id' => $last_id
        ));

        return $count;
    }

    // ========== WP ERP SYNC ==========

    private static function sync_erp_contacts() {
        if (!class_exists('WeDevs\ERP\CRM\Contact')) return 0;

        global $wpdb;
        $table = $wpdb->prefix . 'erp_peoples';
        if ( ! self::table_exists( $table ) ) return 0;
        $cols = self::table_columns( $table );

        // Real schema: `created` (not `created_at`), no `type` column on this install.
        $date_col = in_array( 'created_at', $cols, true ) ? 'created_at' : 'created';
        $has_type = in_array( 'type', $cols, true );

        $state = Hermes_Bridge_Database::get_sync_state('erp_contacts');
        $last_time = $state ? $state['last_sync_at'] : '0000-00-00 00:00:00';

        $type_filter = $has_type ? " AND type = 'contact'" : '';
        $contacts = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, email, life_stage, {$date_col} AS created_at
             FROM {$table}
             WHERE {$date_col} > %s{$type_filter}
             ORDER BY {$date_col} ASC LIMIT 50",
            $last_time
        ));

        $count = 0;
        $max_time = $last_time;
        foreach ($contacts as $contact) {
            $payload = array(
                'contact_id' => $contact->id,
                'name' => trim($contact->first_name . ' ' . $contact->last_name),
                'email' => $contact->email,
                'life_stage' => $contact->life_stage,
                'created_at' => $contact->created_at
            );

            if (Hermes_Bridge_Database::insert_event('new_contact', 'erp_crm', $contact->id, $payload)) {
                $count++;
                if ($contact->created_at > $max_time) $max_time = $contact->created_at;
            }
        }

        Hermes_Bridge_Database::update_sync_state('erp_contacts', array(
            'last_sync_at' => $max_time
        ));

        return $count;
    }

    private static function sync_erp_deals() {
        if (!function_exists('erp_crm_get_deals')) return 0;

        global $wpdb;
        $table = $wpdb->prefix . 'erp_crm_deals';
        if ( ! self::table_exists( $table ) ) return 0;
        $cols = self::table_columns( $table );
        $date_col = in_array( 'created_at', $cols, true ) ? 'created_at' : 'created';

        $state = Hermes_Bridge_Database::get_sync_state('erp_deals');
        $last_time = $state ? $state['last_sync_at'] : '0000-00-00 00:00:00';

        $deals = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, value, stage, status, contact_id, {$date_col} AS created_at
             FROM {$table}
             WHERE {$date_col} > %s
             ORDER BY {$date_col} ASC LIMIT 50",
            $last_time
        ));

        $count = 0;
        $max_time = $last_time;
        foreach ($deals as $deal) {
            $payload = array(
                'deal_id' => $deal->id,
                'title' => $deal->title,
                'value' => $deal->value,
                'stage' => $deal->stage,
                'status' => $deal->status,
                'contact_id' => $deal->contact_id,
                'created_at' => $deal->created_at
            );

            if (Hermes_Bridge_Database::insert_event('new_deal', 'erp_crm', $deal->id, $payload)) {
                $count++;
                if ($deal->created_at > $max_time) $max_time = $deal->created_at;
            }
        }

        Hermes_Bridge_Database::update_sync_state('erp_deals', array(
            'last_sync_at' => $max_time
        ));

        return $count;
    }

    private static function sync_erp_tasks() {
        global $wpdb;
        $table = $wpdb->prefix . 'erp_crm_tasks';
        if ( ! self::table_exists( $table ) ) return 0;
        $cols = self::table_columns( $table );
        $date_col = in_array( 'created_at', $cols, true ) ? 'created_at' : 'created';

        $state = Hermes_Bridge_Database::get_sync_state('erp_tasks');
        $last_time = $state ? $state['last_sync_at'] : '0000-00-00 00:00:00';

        // WP ERP Pro Task table
        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, status, contact_id, due_date, {$date_col} AS created_at
             FROM {$table}
             WHERE {$date_col} > %s
             ORDER BY {$date_col} ASC LIMIT 50",
            $last_time
        ));

        $count = 0;
        $max_time = $last_time;
        foreach ($tasks as $task) {
            $payload = array(
                'task_id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'contact_id' => $task->contact_id,
                'due_date' => $task->due_date,
                'created_at' => $task->created_at
            );

            if (Hermes_Bridge_Database::insert_event('new_task', 'erp_crm', $task->id, $payload)) {
                $count++;
                if ($task->created_at > $max_time) $max_time = $task->created_at;
            }
        }

        Hermes_Bridge_Database::update_sync_state('erp_tasks', array(
            'last_sync_at' => $max_time
        ));

        return $count;
    }

    // ========== WP PROJECT MANAGER SYNC ==========

    private static function sync_pm_projects() {
        global $wpdb;
        $table = $wpdb->prefix . 'pm_projects';
        if ( ! self::table_exists( $table ) ) return 0;
        $cols = self::table_columns( $table );
        $date_col = in_array( 'created_at', $cols, true ) ? 'created_at' : 'created';

        $state = Hermes_Bridge_Database::get_sync_state('pm_projects');
        $last_time = $state ? $state['last_sync_at'] : '0000-00-00 00:00:00';

        $projects = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, status, {$date_col} AS created_at
             FROM {$table}
             WHERE {$date_col} > %s
             ORDER BY {$date_col} ASC LIMIT 50",
            $last_time
        ));

        $count = 0;
        $max_time = $last_time;
        foreach ($projects as $project) {
            $payload = array(
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status,
                'created_at' => $project->created_at
            );

            if (Hermes_Bridge_Database::insert_event('new_project', 'pm', $project->id, $payload)) {
                $count++;
                if ($project->created_at > $max_time) $max_time = $project->created_at;
            }
        }

        Hermes_Bridge_Database::update_sync_state('pm_projects', array(
            'last_sync_at' => $max_time
        ));

        return $count;
    }

    private static function sync_pm_tasks() {
        global $wpdb;
        $table = $wpdb->prefix . 'pm_tasks';
        if ( ! self::table_exists( $table ) ) return 0;
        $cols = self::table_columns( $table );
        $date_col = in_array( 'created_at', $cols, true ) ? 'created_at' : 'created';

        $state = Hermes_Bridge_Database::get_sync_state('pm_tasks');
        $last_time = $state ? $state['last_sync_at'] : '0000-00-00 00:00:00';

        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, status, project_id, completed_at, {$date_col} AS created_at
             FROM {$table}
             WHERE {$date_col} > %s
             ORDER BY {$date_col} ASC LIMIT 50",
            $last_time
        ));

        $count = 0;
        $max_time = $last_time;
        foreach ($tasks as $task) {
            $payload = array(
                'task_id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'project_id' => $task->project_id,
                'completed_at' => $task->completed_at,
                'created_at' => $task->created_at
            );

            if (Hermes_Bridge_Database::insert_event('new_pm_task', 'pm', $task->id, $payload)) {
                $count++;
                if ($task->created_at > $max_time) $max_time = $task->created_at;
            }
        }

        Hermes_Bridge_Database::update_sync_state('pm_tasks', array(
            'last_sync_at' => $max_time
        ));

        return $count;
    }

    private static function sync_pm_milestones() {
        global $wpdb;
        $table = $wpdb->prefix . 'pm_milestones';
        if ( ! self::table_exists( $table ) ) return 0;
        $cols = self::table_columns( $table );
        $date_col = in_array( 'created_at', $cols, true ) ? 'created_at' : 'created';

        $state = Hermes_Bridge_Database::get_sync_state('pm_milestones');
        $last_time = $state ? $state['last_sync_at'] : '0000-00-00 00:00:00';

        $milestones = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, project_id, achieve_date, {$date_col} AS created_at
             FROM {$table}
             WHERE {$date_col} > %s
             ORDER BY {$date_col} ASC LIMIT 50",
            $last_time
        ));

        $count = 0;
        $max_time = $last_time;
        foreach ($milestones as $milestone) {
            $payload = array(
                'milestone_id' => $milestone->id,
                'title' => $milestone->title,
                'project_id' => $milestone->project_id,
                'achieve_date' => $milestone->achieve_date,
                'created_at' => $milestone->created_at
            );

            if (Hermes_Bridge_Database::insert_event('new_milestone', 'pm', $milestone->id, $payload)) {
                $count++;
                if ($milestone->created_at > $max_time) $max_time = $milestone->created_at;
            }
        }

        Hermes_Bridge_Database::update_sync_state('pm_milestones', array(
            'last_sync_at' => $max_time
        ));

        return $count;
    }

    // ========== WP STATISTICS SYNC ==========

    private static function sync_wp_statistics() {
        if (!class_exists('WP_Statistics')) return 0;

        global $wpdb;
        $state = Hermes_Bridge_Database::get_sync_state('wp_stats');

        // WP Statistics stores data in its own tables
        $vis_table = $wpdb->prefix . 'statistics_visitor';
        $page_table = $wpdb->prefix . 'statistics_pages';
        if ( ! self::table_exists( $vis_table ) || ! self::table_exists( $page_table ) ) return 0;

        // Real schema: statistics_pages uses `count` (not `hits`), and has no `title`.
        $page_cols = self::table_columns( $page_table );
        $hits_col = in_array( 'hits', $page_cols, true ) ? 'hits' : 'count';
        $has_title = in_array( 'title', $page_cols, true );

        $today = date('Y-m-d');
        $visitors_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ip) FROM {$vis_table} WHERE last_counter = %s",
            $today
        ));

        $pageviews_today = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM({$hits_col}) FROM {$page_table} WHERE date = %s",
            $today
        ));

        $title_sel = $has_title ? 'title, ' : '';
        $top_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT uri, {$title_sel} SUM({$hits_col}) as total_hits
             FROM {$page_table}
             WHERE date = %s
             GROUP BY uri ORDER BY total_hits DESC LIMIT 10",
            $today
        ), ARRAY_A);

        $referrers = $wpdb->get_results($wpdb->prepare(
            "SELECT referred, COUNT(*) as count 
             FROM {$wpdb->prefix}statistics_visitor 
             WHERE last_counter = %s AND referred != ''
             GROUP BY referred ORDER BY count DESC LIMIT 10",
            $today
        ), ARRAY_A);

        // Store as snapshot (overwrite)
        Hermes_Bridge_Database::update_snapshot('stats_visitors_today', $visitors_today ?: 0, 'counter');
        Hermes_Bridge_Database::update_snapshot('stats_pageviews_today', $pageviews_today ?: 0, 'counter');
        Hermes_Bridge_Database::update_snapshot('stats_top_pages', $top_pages, 'gauge');
        Hermes_Bridge_Database::update_snapshot('stats_referrers', $referrers, 'gauge');

        Hermes_Bridge_Database::update_sync_state('wp_stats', array(
            'last_sync_at' => current_time('mysql')
        ));

        return 4; // number of snapshots updated
    }

    // ========== SNAPSHOTS ==========

    private static function update_all_snapshots() {
        $count = 0;

        // EDD Snapshots
        if (function_exists('edd_get_orders')) {
            $today_start = date('Y-m-d 00:00:00');
            $today_end = date('Y-m-d 23:59:59');

            global $wpdb;
            $revenue_today = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total) FROM {$wpdb->prefix}edd_orders 
                 WHERE date_created >= %s AND date_created <= %s AND status = 'complete'",
                $today_start, $today_end
            ));

            $orders_today = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders 
                 WHERE date_created >= %s AND date_created <= %s AND status = 'complete'",
                $today_start, $today_end
            ));

            $total_customers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}edd_customers");

            Hermes_Bridge_Database::update_snapshot('edd_revenue_today', floatval($revenue_today) ?: 0, 'counter');
            Hermes_Bridge_Database::update_snapshot('edd_orders_today', intval($orders_today) ?: 0, 'counter');
            Hermes_Bridge_Database::update_snapshot('edd_total_customers', intval($total_customers) ?: 0, 'gauge');
            $count += 3;
        }

        // ERP Snapshots
        global $wpdb;
        $people_table = $wpdb->prefix . 'erp_peoples';
        $deals_table  = $wpdb->prefix . 'erp_crm_deals';
        $tasks_table  = $wpdb->prefix . 'erp_crm_tasks';

        $total_contacts = 0;
        if ( self::table_exists( $people_table ) ) {
            $people_cols = self::table_columns( $people_table );
            $type_where = in_array( 'type', $people_cols, true ) ? " WHERE type = 'contact'" : '';
            $total_contacts = $wpdb->get_var( "SELECT COUNT(*) FROM {$people_table}{$type_where}" );
        }

        $deals_by_stage = array();
        if ( self::table_exists( $deals_table ) ) {
            $deals_by_stage = $wpdb->get_results(
                "SELECT stage, COUNT(*) as count, SUM(value) as total_value
                 FROM {$deals_table} GROUP BY stage",
                ARRAY_A
            );
        }

        $overdue_tasks = 0;
        if ( self::table_exists( $tasks_table ) ) {
            $overdue_tasks = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tasks_table}
                 WHERE status != 'completed' AND due_date < %s",
                current_time('mysql')
            ) );
        }

        Hermes_Bridge_Database::update_snapshot('erp_total_contacts', intval($total_contacts) ?: 0, 'gauge');
        Hermes_Bridge_Database::update_snapshot('erp_deals_by_stage', $deals_by_stage ?: array(), 'gauge');
        Hermes_Bridge_Database::update_snapshot('erp_overdue_tasks', intval($overdue_tasks) ?: 0, 'gauge');
        $count += 3;

        // PM Snapshots
        $pm_tasks_tbl    = $wpdb->prefix . 'pm_tasks';
        $pm_projects_tbl = $wpdb->prefix . 'pm_projects';

        $open_tasks = 0;
        $completed_tasks = 0;
        if ( self::table_exists( $pm_tasks_tbl ) ) {
            $open_tasks = $wpdb->get_var("SELECT COUNT(*) FROM {$pm_tasks_tbl} WHERE status != '1'");
            $completed_tasks = $wpdb->get_var("SELECT COUNT(*) FROM {$pm_tasks_tbl} WHERE status = '1'");
        }
        $total_projects = 0;
        if ( self::table_exists( $pm_projects_tbl ) ) {
            $total_projects = $wpdb->get_var("SELECT COUNT(*) FROM {$pm_projects_tbl}");
        }

        Hermes_Bridge_Database::update_snapshot('pm_open_tasks', intval($open_tasks) ?: 0, 'gauge');
        Hermes_Bridge_Database::update_snapshot('pm_completed_tasks', intval($completed_tasks) ?: 0, 'counter');
        Hermes_Bridge_Database::update_snapshot('pm_total_projects', intval($total_projects) ?: 0, 'gauge');
        $count += 3;

        return $count;
    }
}
