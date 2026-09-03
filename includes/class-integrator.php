<?php
/**
 * Integrator — Agent PROPOSAL layer.
 * Creates proposals directly into WP Project Manager (as tasks in "🤖 Agent Proposals" project)
 * and WP ERP (as contacts/deals with "agent_proposed" stage).
 * Also reads back decisions from these tools to feed the learning loop.
 * All i18n strings use __() for WordPress translation.
 */
class Hermes_Bridge_Integrator {

    const PROPOSAL_PROJECT_TITLE = '🤖 Agent Proposals';

    public static function init() {}

    /**
     * Get or create the dedicated PM project for agent proposals.
     */
    private static function ensure_proposal_project() {
        global $wpdb;
        $p = $wpdb->prefix;
        $title = self::PROPOSAL_PROJECT_TITLE;

        // Check if project exists
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}pm_projects WHERE title = %s LIMIT 1",
            $title
        ) );
        if ( $existing ) {
            return intval( $existing );
        }
        // Create via internal REST (PM v2 API)
        $admin = self::ensure_admin_user();
        $request = new WP_REST_Request( 'POST', '/pm/v2/projects' );
        $request->set_param( 'title', $title );
        $request->set_param( 'description', __( 'Tasks proposed by the Hermes Business Agent. Review, approve, edit, or reject each item.', 'hermes-bridge' ) );
        $response = rest_do_request( $request );
        if ( $response->is_error() ) {
            // Fallback: direct insert
            $wpdb->insert( $p . 'pm_projects', array(
                'title' => $title,
                'status' => '0',
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ) );
            return intval( $wpdb->insert_id );
        }
        $data = $response->get_data();
        if ( isset( $data['data']['id'] ) ) return intval( $data['data']['id'] );
        if ( isset( $data['id'] ) ) return intval( $data['id'] );
        return 0;
    }

    /**
     * Find an admin user for internal REST calls.
     */
    private static function ensure_admin_user() {
        static $admin_id = null;
        if ( null !== $admin_id ) return $admin_id;
        $admin_id = 0;
        $admins = get_users( array( 'role' => 'Administrator', 'number' => 1, 'fields' => 'ID' ) );
        if ( ! empty( $admins ) ) {
            $admin_id = intval( $admins[0] );
            wp_set_current_user( $admin_id );
        }
        return $admin_id;
    }

    /**
     * CREATE a proposal from the agent.
     * $proposal = array(
     *   'agent_group' => string,    // group name for learning (e.g. 'follow_up', 'seo_keyword')
     *   'title'       => string,
     *   'description' => string,    // full analysis/reasoning
     *   'target'      => 'pm'|'erp_crm',
     *   'payload'     => array,     // action-specific data
     * )
     * Returns array('success'=>bool, 'message'=>string, 'id'=>int, 'pm_task_id'=>int|null, 'erp_contact_id'=>int|null)
     */
    public static function create_proposal( $proposal ) {
        $group   = isset( $proposal['agent_group'] ) ? sanitize_text_field( $proposal['agent_group'] ) : 'general';
        $title   = isset( $proposal['title'] ) ? sanitize_text_field( $proposal['title'] ) : '';
        $desc    = isset( $proposal['description'] ) ? sanitize_textarea_field( $proposal['description'] ) : '';
        $target  = isset( $proposal['target'] ) ? $proposal['target'] : 'pm';
        $payload = isset( $proposal['payload'] ) ? $proposal['payload'] : array();

        if ( ! $title ) {
            return array( 'success' => false, 'message' => __( 'Title required', 'hermes-bridge' ), 'id' => 0, 'pm_task_id' => null, 'erp_contact_id' => null );
        }

        $result = array( 'success' => true, 'message' => '', 'id' => 0, 'pm_task_id' => null, 'erp_contact_id' => null );

        // === PM Proposal (always — as the review/approval dashboard) ===
        $project_id = self::ensure_proposal_project();
        if ( $project_id ) {
            self::ensure_admin_user();
            $req = new WP_REST_Request( 'POST', '/pm/v2/projects/' . $project_id . '/tasks' );
            $req->set_param( 'title', $title );
            $req->set_param( 'description', $desc . "\n\n" . __( 'Group:', 'hermes-bridge' ) . ' ' . $group . "\n" . __( 'Target system:', 'hermes-bridge' ) . ' ' . $target . "\n" . __( 'Status: pending_review', 'hermes-bridge' ) );
            $resp = rest_do_request( $req );
            if ( ! $resp->is_error() ) {
                $data = $resp->get_data();
                $pm_id = isset( $data['data']['id'] ) ? intval( $data['data']['id'] ) : ( isset( $data['id'] ) ? intval( $data['id'] ) : 0 );
                $result['pm_task_id'] = $pm_id;
                $result['id'] = $pm_id;
            }
        }

        // === ERP Proposal (if target is erp_crm) ===
        if ( 'erp_crm' === $target && function_exists( 'erp_insert_people' ) ) {
            $contact_data = array(
                'first_name' => isset( $payload['first_name'] ) ? sanitize_text_field( $payload['first_name'] ) : substr( $title, 0, 50 ),
                'last_name'  => isset( $payload['last_name'] ) ? sanitize_text_field( $payload['last_name'] ) : '',
                'email'      => isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '',
                'phone'      => isset( $payload['phone'] ) ? sanitize_text_field( $payload['phone'] ) : '',
                'type'       => 'contact',
                'life_stage' => 'agent_proposed',
                'notes'      => $desc,
            );
            $contact_id = erp_insert_people( $contact_data );
            if ( ! is_wp_error( $contact_id ) && intval( $contact_id ) > 0 ) {
                $result['erp_contact_id'] = intval( $contact_id );
                if ( ! $result['id'] ) $result['id'] = intval( $contact_id );
            }
        }

        $result['message'] = $result['id'] ? __( 'Proposal created', 'hermes-bridge' ) : __( 'Proposal creation failed', 'hermes-bridge' );
        $result['success'] = (bool) $result['id'];
        return $result;
    }

    // ================= READ FEEDBACK =================

    /**
     * Read feedback from PM: check tasks in the Proposal project that have been
     * completed (approved), trashed (rejected), or edited.
     * Returns array of ['group_name', 'decision' => 'approved'|'rejected'|'edited', 'task_id', 'title']
     */
    public static function read_pm_feedback() {
        global $wpdb;
        $p = $wpdb->prefix;
        $project_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}pm_projects WHERE title = %s LIMIT 1",
            self::PROPOSAL_PROJECT_TITLE
        ) );
        if ( ! $project_id ) return array();

        $feedback = array();

        // Get tasks that have been completed (status=1) → approved
        if ( self::table_exists( $p . 'pm_tasks' ) ) {
            $cols = self::table_columns( $p . 'pm_tasks' );
            $date_col = in_array( 'updated_at', $cols, true ) ? 'updated_at' : 'updated_at';

            $approved = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, title, description, {$date_col} AS updated_at
                 FROM {$p}pm_tasks
                 WHERE project_id = %d AND status = '1'
                   AND updated_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
                 ORDER BY updated_at DESC LIMIT 50",
                $project_id
            ), ARRAY_A );

            foreach ( $approved as $t ) {
                $group = self::extract_group( $t['description'] );
                $feedback[] = array(
                    'group_name' => $group,
                    'decision'   => 'approved',
                    'task_id'    => intval( $t['id'] ),
                    'title'      => $t['title'],
                );
            }

            // Check trashed/deleted tasks → rejected
            // (PM doesn't have a native "trash" for tasks; we check for deletion by absence)
            // We'll track via our own log instead.
        }

        return $feedback;
    }

    /**
     * Read feedback from ERP: contacts with life_stage "agent_proposed" that
     * have been changed to another stage (approved) or trashed (rejected).
     */
    public static function read_erp_feedback() {
        global $wpdb;
        $p = $wpdb->prefix;
        $feedback = array();

        if ( ! self::table_exists( $p . 'erp_peoples' ) ) return $feedback;

        $cols = self::table_columns( $p . 'erp_peoples' );
        $has_life_stage = in_array( 'life_stage', $cols, true );
        $has_created = in_array( 'created', $cols, true ) ? 'created' : ( in_array( 'created_at', $cols, true ) ? 'created_at' : 'created' );

        if ( $has_life_stage ) {
            $changed = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, first_name, last_name, life_stage, {$has_created} AS created_at
                 FROM {$p}erp_peoples
                 WHERE life_stage = %s AND {$has_created} > DATE_SUB(NOW(), INTERVAL 2 DAY)
                 ORDER BY {$has_created} DESC LIMIT 50",
                'agent_proposed'
            ), ARRAY_A );
            // These are contacts that were proposed but are still agent_proposed
            // We need to check the OPPOSITE: contacts that used to be agent_proposed but now have a different stage
            // For that we need a history log. Let's track in our own table.
        }

        return $feedback;
    }

    /**
     * Extract group name from a PM task description.
     */
    private static function extract_group( $description ) {
        if ( preg_match( '/Group:\s*(\S+)/i', $description, $m ) ) {
            return sanitize_text_field( $m[1] );
        }
        return 'general';
    }

    // ================= HELPERS =================

    private static function table_exists( $table ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private static function table_columns( $table ) {
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        return $cols ? $cols : array();
    }
}