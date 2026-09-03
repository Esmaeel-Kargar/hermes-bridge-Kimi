<?php
class Hermes_Bridge_REST_API {

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        $namespace = 'hermes-bridge/v1';

        // GET /sync - Get unconsumed events + latest snapshots
        register_rest_route($namespace, '/sync', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_sync_data'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
            'args' => array(
                'since' => array(
                    'type' => 'string',
                    'default' => '1970-01-01 00:00:00',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'limit' => array(
                    'type' => 'integer',
                    'default' => 50,
                    'sanitize_callback' => 'absint'
                ),
                'mark_consumed' => array(
                    'type' => 'boolean',
                    'default' => true
                )
            )
        ));

        // POST /action - Queue an action from Hermes
        register_rest_route($namespace, '/action', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'queue_action'),
            'permission_callback' => array(__CLASS__, 'check_auth')
        ));

        // GET /summary - Quick summary for Hermes
        register_rest_route($namespace, '/summary', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_summary'),
            'permission_callback' => array(__CLASS__, 'check_auth')
        ));

        // POST /sync/trigger - Manual sync trigger
        register_rest_route($namespace, '/sync/trigger', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'trigger_sync'),
            'permission_callback' => array(__CLASS__, 'check_auth')
        ));
    }

    public static function check_auth($request) {
        // Check Application Password or custom API key
        $api_key = get_option('hermes_bridge_api_key');
        $provided_key = $request->get_header('X-Hermes-Key');

        if ($api_key && $provided_key === $api_key) {
            return true;
        }

        // Fallback to WordPress authentication
        return current_user_can('manage_options');
    }

    public static function get_sync_data($request) {
        global $wpdb;
        $since = $request->get_param('since');
        $limit = $request->get_param('limit');
        $mark_consumed = $request->get_param('mark_consumed');

        // Get unconsumed events
        $events = Hermes_Bridge_Database::get_unconsumed_events($limit);

        // Get all snapshots
        $snapshots_raw = Hermes_Bridge_Database::get_snapshots();
        $snapshots = array();
        foreach ($snapshots_raw as $snap) {
            $snapshots[$snap['metric_key']] = array(
                'value' => json_decode($snap['metric_value'], true),
                'type' => $snap['metric_type'],
                'delta' => $snap['delta'] ? json_decode($snap['delta'], true) : null,
                'updated_at' => $snap['updated_at']
            );
        }

        // Get last sync info
        $last_sync = get_option('hermes_bridge_last_sync');

        // Build summary text for Hermes
        $summary_parts = array();

        $revenue = isset($snapshots['edd_revenue_today']) ? $snapshots['edd_revenue_today']['value'] : 0;
        $orders = isset($snapshots['edd_orders_today']) ? $snapshots['edd_orders_today']['value'] : 0;
        $summary_parts[] = sprintf('درآمد امروز: %.2f USDT | سفارشات: %d', $revenue, $orders);

        $open_tasks = isset($snapshots['pm_open_tasks']) ? $snapshots['pm_open_tasks']['value'] : 0;
        $overdue = isset($snapshots['erp_overdue_tasks']) ? $snapshots['erp_overdue_tasks']['value'] : 0;
        $summary_parts[] = sprintf('تسک‌های باز: %d | overdue: %d', $open_tasks, $overdue);

        $visitors = isset($snapshots['stats_visitors_today']) ? $snapshots['stats_visitors_today']['value'] : 0;
        $summary_parts[] = sprintf('ویزیتور امروز: %d', $visitors);

        if (!empty($events)) {
            $event_counts = array();
            foreach ($events as $event) {
                $type = $event['event_type'];
                if (!isset($event_counts[$type])) $event_counts[$type] = 0;
                $event_counts[$type]++;
            }
            $event_summary = array();
            foreach ($event_counts as $type => $count) {
                $event_summary[] = $type . ': ' . $count;
            }
            $summary_parts[] = 'رویدادهای جدید: ' . implode(', ', $event_summary);
        }

        $response = array(
            'events' => $events,
            'snapshots' => $snapshots,
            'summary' => implode(' | ', $summary_parts),
            'last_sync' => $last_sync,
            'server_time' => current_time('mysql')
        );

        // Mark events as consumed
        if ($mark_consumed && !empty($events)) {
            $ids = wp_list_pluck($events, 'id');
            Hermes_Bridge_Database::mark_events_consumed($ids);
        }

        return rest_ensure_response($response);
    }

    public static function queue_action($request) {
        $body = $request->get_json_params();

        if (empty($body['type']) || empty($body['target_system'])) {
            return new WP_Error('missing_params', 'action_type and target_system required', array('status' => 400));
        }

        $action_id = Hermes_Bridge_Database::queue_action(
            sanitize_text_field($body['type']),
            sanitize_text_field($body['target_system']),
            isset($body['payload']) ? $body['payload'] : array()
        );

        // Try to process immediately
        $result = self::process_action($action_id);

        return rest_ensure_response(array(
            'success' => true,
            'action_id' => $action_id,
            'processed' => $result['success'],
            'message' => $result['message']
        ));
    }

    public static function get_summary($request) {
        global $wpdb;

        $snapshots = Hermes_Bridge_Database::get_snapshots();
        $snapshot_map = array();
        foreach ($snapshots as $snap) {
            $snapshot_map[$snap['metric_key']] = json_decode($snap['metric_value'], true);
        }

        $unconsumed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hermes_events WHERE consumed = 0");
        $pending_actions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hermes_actions_queue WHERE status = 'pending'");
        $last_sync = get_option('hermes_bridge_last_sync');

        return rest_ensure_response(array(
            'today_revenue' => isset($snapshot_map['edd_revenue_today']) ? $snapshot_map['edd_revenue_today'] : 0,
            'today_orders' => isset($snapshot_map['edd_orders_today']) ? $snapshot_map['edd_orders_today'] : 0,
            'total_customers' => isset($snapshot_map['edd_total_customers']) ? $snapshot_map['edd_total_customers'] : 0,
            'open_tasks' => isset($snapshot_map['pm_open_tasks']) ? $snapshot_map['pm_open_tasks'] : 0,
            'completed_tasks' => isset($snapshot_map['pm_completed_tasks']) ? $snapshot_map['pm_completed_tasks'] : 0,
            'overdue_tasks' => isset($snapshot_map['erp_overdue_tasks']) ? $snapshot_map['erp_overdue_tasks'] : 0,
            'total_contacts' => isset($snapshot_map['erp_total_contacts']) ? $snapshot_map['erp_total_contacts'] : 0,
            'visitors_today' => isset($snapshot_map['stats_visitors_today']) ? $snapshot_map['stats_visitors_today'] : 0,
            'pageviews_today' => isset($snapshot_map['stats_pageviews_today']) ? $snapshot_map['stats_pageviews_today'] : 0,
            'unconsumed_events' => intval($unconsumed_count),
            'pending_actions' => intval($pending_actions),
            'last_sync' => $last_sync
        ));
    }

    public static function trigger_sync($request) {
        $stats = Hermes_Bridge_Sync_Engine::run_full_sync();
        return rest_ensure_response(array(
            'success' => true,
            'stats' => $stats,
            'time' => current_time('mysql')
        ));
    }

    private static function process_action($action_id) {
        global $wpdb;
        $action = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hermes_actions_queue WHERE id = %d",
            $action_id
        ), ARRAY_A);

        if (!$action) return array('success' => false, 'message' => 'Action not found');

        $payload = json_decode($action['payload'], true);
        $type = $action['action_type'];
        $system = $action['target_system'];

        try {
            switch ($system) {
                case 'erp_crm':
                    $result = self::process_erp_action($type, $payload);
                    break;
                case 'pm':
                    $result = self::process_pm_action($type, $payload);
                    break;
                case 'edd':
                    $result = self::process_edd_action($type, $payload);
                    break;
                default:
                    $result = array('success' => false, 'message' => 'Unknown target system');
            }
        } catch (Exception $e) {
            $result = array('success' => false, 'message' => $e->getMessage());
        }

        Hermes_Bridge_Database::update_action_status(
            $action_id, 
            $result['success'] ? 'done' : 'failed',
            $result
        );

        return $result;
    }

    private static function process_erp_action($type, $payload) {
        global $wpdb;

        switch ($type) {
            case 'create_task':
                if (!isset($payload['title'])) {
                    return array('success' => false, 'message' => 'Title required');
                }
                $wpdb->insert($wpdb->prefix . 'erp_crm_tasks', array(
                    'title' => sanitize_text_field($payload['title']),
                    'contact_id' => isset($payload['contact_id']) ? intval($payload['contact_id']) : 0,
                    'due_date' => isset($payload['due_date']) ? sanitize_text_field($payload['due_date']) : null,
                    'status' => 'pending',
                    'created_at' => current_time('mysql')
                ));
                return array('success' => true, 'message' => 'Task created', 'id' => $wpdb->insert_id);

            case 'create_note':
                if (!isset($payload['contact_id']) || !isset($payload['content'])) {
                    return array('success' => false, 'message' => 'contact_id and content required');
                }
                $wpdb->insert($wpdb->prefix . 'erp_crm_notes', array(
                    'object_id' => intval($payload['contact_id']),
                    'object_type' => 'contact',
                    'content' => sanitize_textarea_field($payload['content']),
                    'created_at' => current_time('mysql')
                ));
                return array('success' => true, 'message' => 'Note created', 'id' => $wpdb->insert_id);

            case 'update_deal_stage':
                if (!isset($payload['deal_id']) || !isset($payload['stage'])) {
                    return array('success' => false, 'message' => 'deal_id and stage required');
                }
                $wpdb->update($wpdb->prefix . 'erp_crm_deals', 
                    array('stage' => sanitize_text_field($payload['stage'])),
                    array('id' => intval($payload['deal_id']))
                );
                return array('success' => true, 'message' => 'Deal stage updated');

            default:
                return array('success' => false, 'message' => 'Unknown action type for ERP');
        }
    }

    private static function process_pm_action($type, $payload) {
        global $wpdb;

        switch ($type) {
            case 'create_task':
                if (!isset($payload['title']) || !isset($payload['project_id'])) {
                    return array('success' => false, 'message' => 'title and project_id required');
                }
                $wpdb->insert($wpdb->prefix . 'pm_tasks', array(
                    'title' => sanitize_text_field($payload['title']),
                    'project_id' => intval($payload['project_id']),
                    'status' => '0',
                    'created_at' => current_time('mysql')
                ));
                return array('success' => true, 'message' => 'PM Task created', 'id' => $wpdb->insert_id);

            case 'create_milestone':
                if (!isset($payload['title']) || !isset($payload['project_id'])) {
                    return array('success' => false, 'message' => 'title and project_id required');
                }
                $wpdb->insert($wpdb->prefix . 'pm_milestones', array(
                    'title' => sanitize_text_field($payload['title']),
                    'project_id' => intval($payload['project_id']),
                    'created_at' => current_time('mysql')
                ));
                return array('success' => true, 'message' => 'Milestone created', 'id' => $wpdb->insert_id);

            default:
                return array('success' => false, 'message' => 'Unknown action type for PM');
        }
    }

    private static function process_edd_action($type, $payload) {
        switch ($type) {
            case 'create_discount':
                if (!function_exists('edd_store_discount')) {
                    return array('success' => false, 'message' => 'EDD not active');
                }
                if (!isset($payload['code']) || !isset($payload['amount'])) {
                    return array('success' => false, 'message' => 'code and amount required');
                }
                $discount_id = edd_store_discount(array(
                    'name' => sanitize_text_field($payload['code']),
                    'code' => sanitize_text_field($payload['code']),
                    'type' => isset($payload['type']) ? sanitize_text_field($payload['type']) : 'percent',
                    'amount' => floatval($payload['amount']),
                    'status' => 'active'
                ));
                return array('success' => true, 'message' => 'Discount created', 'id' => $discount_id);

            default:
                return array('success' => false, 'message' => 'Unknown action type for EDD');
        }
    }
}
