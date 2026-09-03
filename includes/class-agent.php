<?php
/**
 * Agent brain — v2.1 simplified.
 * 1. Collects data from site (snapshots + events + memory)
 * 2. Asks OpenRouter to analyze and produce structured proposals
 * 3. Writes proposals to PM/ERP via Integrator (as tasks in "🤖 Agent Proposals")
 * 4. E.K reviews in PM/ERP (approve/edit/reject)
 * 5. Agent reads back decisions, learns, promotes groups to auto
 */
class Hermes_Bridge_Agent {

    public static function init() {
        add_action( 'hermes_bridge_agent_cron', array( __CLASS__, 'run_scheduled' ) );
    }

    public static function settings() {
        $defaults = array(
            'chat_model'     => '',
            'analysis_model' => '',
            'schedule'       => 'none',
            'analysis_depth' => 2,      // 1=quick, 2=standard, 3=deep
            'auto_groups'    => array(),// group_name => true
            'approval_threshold' => 90, // % approval to auto-promote a group
        );
        $s = get_option( 'hermes_agent_settings', array() );
        return wp_parse_args( is_array( $s ) ? $s : array(), $defaults );
    }

    public static function save_settings( $data ) {
        $s = self::settings();
        if ( isset( $data['chat_model'] ) )     $s['chat_model'] = sanitize_text_field( $data['chat_model'] );
        if ( isset( $data['analysis_model'] ) )  $s['analysis_model'] = sanitize_text_field( $data['analysis_model'] );
        if ( isset( $data['schedule'] ) && in_array( $data['schedule'], array( 'none', 'hermes_15min', 'hermes_30min', 'hourly', 'daily' ), true ) )
            $s['schedule'] = $data['schedule'];
        if ( isset( $data['analysis_depth'] ) ) {
            $d = intval( $data['analysis_depth'] );
            $s['analysis_depth'] = in_array( $d, array(1,2,3), true ) ? $d : 2;
        }
        if ( isset( $data['approval_threshold'] ) ) {
            $t = intval( $data['approval_threshold'] );
            $s['approval_threshold'] = min( 100, max( 50, $t ) );
        }
        if ( isset( $data['auto_groups'] ) && is_array( $data['auto_groups'] ) ) {
            $s['auto_groups'] = array();
            foreach ( $data['auto_groups'] as $g ) {
                $g = sanitize_text_field( $g );
                if ( $g ) $s['auto_groups'][ $g ] = true;
            }
        }
        update_option( 'hermes_agent_settings', $s );
        return $s;
    }

    public static function goals() {
        return get_option( 'hermes_agent_goals', '' );
    }

    // ---------- Context ----------
    private static function build_context( $depth ) {
        $parts = array();
        global $wpdb;
        $p = $wpdb->prefix;

        // Snapshots (v1 tables)
        $snaps = $wpdb->get_results( "SELECT metric_key, metric_value, metric_type, delta, updated_at FROM {$p}hermes_snapshots", ARRAY_A );
        if ( $snaps ) {
            $lines = array();
            foreach ( $snaps as $sn ) {
                $v = json_decode( $sn['metric_value'], true );
                if ( null === $v ) $v = $sn['metric_value'];
                if ( is_array( $v ) ) $v = wp_json_encode( $v );
                $lines[] = $sn['metric_key'] . ' = ' . $v . ' (updated ' . $sn['updated_at'] . ')';
            }
            $parts[] = "CURRENT SITE SNAPSHOTS:\n" . implode( "\n", $lines );
        }

        // Recent events
        $events = $wpdb->get_results( "SELECT event_type, source, source_id, payload, created_at FROM {$p}hermes_events WHERE consumed = 0 ORDER BY id ASC LIMIT 30", ARRAY_A );
        if ( $events ) {
            $lines = array();
            foreach ( $events as $e ) {
                $lines[] = $e['event_type'] . '|' . $e['source'] . '|id=' . $e['source_id'] . '|' . $e['created_at'] . '|' . $e['payload'];
            }
            $parts[] = "RECENT EVENTS:\n" . implode( "\n", $lines );
        }

        // Memory facts
        $memories = Hermes_Bridge_Agent_DB::get_memories( array( 'fact', 'decision', 'feedback', 'goal' ) );
        if ( $memories ) {
            $lines = array();
            foreach ( $memories as $m ) {
                $lines[] = '[' . $m['kind'] . '] ' . $m['memory_key'] . ': ' . $m['memory_value'];
            }
            $parts[] = "LONG-TERM MEMORY:\n" . implode( "\n", $lines );
        }

        // Goals
        $goals = self::goals();
        if ( $goals ) $parts[] = "OPERATOR GOALS (highest priority):\n" . $goals;

        // Learning stats
        $stats = self::get_learning_stats();
        if ( $stats ) {
            $lines = array();
            foreach ( $stats as $g => $s ) {
                $auto = $s['auto'] ? ' [AUTO]' : '';
                $lines[] = "  {$g}: {$s['approved']}/{$s['total']} approved ({$s['rate']}%){$auto}";
            }
            $parts[] = "LEARNING STATS (per group):\n" . implode( "\n", $lines );
        }

        return implode( "\n\n==========\n\n", $parts );
    }

    private static function system_prompt( $depth ) {
        $depth_guide = '';
        if ( 1 === $depth ) $depth_guide = 'Quick status overview. Only obvious immediate actions.';
        elseif ( 3 === $depth ) $depth_guide = 'Deep strategic analysis with trends, insights, and detailed recommendations.';
        else $depth_guide = 'Standard analysis with actionable follow-ups and lead suggestions.';

        return implode( "\n", array(
            'You are the Hermes Business Agent for Dynamix Systems (engineering design, UAV/RC, 3D printing, CAD/STL).',
            'You analyze site data and propose concrete actions. Reply in Persian/English mix as appropriate.',
            $depth_guide,
            'Output ONLY valid JSON with this structure:',
            '{"summary":"...","insights":["..."],"proposals":[{"group_name":"follow_up","title":"...","description":"...","target":"pm|erp_crm","payload":{}}]}',
            'group_name categories: follow_up, seo_keyword, lead_generation, content_plan, customer_reengagement, product_optimization, general.',
            'target "pm": for general tasks, follow-ups, content plans. target "erp_crm": for new leads, contacts, deals.',
            'Every proposal payload must have the fields needed: pm tasks need title+description; erp_crm needs first_name+email+phone.',
            'If data is empty or stale, say so in summary and set proposals to [].',
            'Never invent data not present. Be honest about what you know and don\'t know.',
        ) );
    }

    // ---------- Run ----------
    public static function run_analysis( $depth = null ) {
        $key = self::get_openrouter_key();
        if ( ! $key ) {
            return array( 'success' => false, 'error' => __( 'OpenRouter key not found. Configure in WordPress AI → Connectors settings.', 'hermes-bridge' ) );
        }
        $s = self::settings();
        if ( null === $depth ) $depth = intval( $s['analysis_depth'] );
        $model = $s['analysis_model'];
        if ( ! $model ) return array( 'success' => false, 'error' => __( 'Analysis model not selected', 'hermes-bridge' ) );

        // Check auto-promoted groups (read feedback from PM/ERP first)
        self::learn_from_feedback();

        $system = self::system_prompt( $depth );
        $context = self::build_context( $depth );
        $user_msg = "Analysis depth: $depth\n\n$context";

        $res = Hermes_Bridge_OpenRouter::chat( array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user',   'content' => $user_msg ),
        ), $model, 0.4, 3000 );

        if ( ! $res['success'] ) {
            return array( 'success' => false, 'error' => $res['error'] );
        }

        $parsed = self::parse_json( $res['content'] );
        if ( null === $parsed ) {
            return array( 'success' => false, 'error' => __( 'Model output was not valid JSON', 'hermes-bridge' ), 'raw' => substr( $res['content'], 0, 500 ) );
        }

        $summary = isset( $parsed['summary'] ) ? sanitize_text_field( $parsed['summary'] ) : '';
        $report_id = Hermes_Bridge_Agent_DB::insert_report( $depth, $model, $summary, wp_json_encode( $parsed, JSON_UNESCAPED_UNICODE ) );

        $proposals = isset( $parsed['proposals'] ) && is_array( $parsed['proposals'] ) ? $parsed['proposals'] : array();
        $results = array( 'created' => 0, 'auto_executed' => 0, 'skipped' => 0, 'failed' => 0 );

        foreach ( $proposals as $prop ) {
            $group = isset( $prop['group_name'] ) ? sanitize_text_field( $prop['group_name'] ) : 'general';
            $title = isset( $prop['title'] ) ? sanitize_text_field( $prop['title'] ) : '';
            if ( ! $title ) { $results['skipped']++; continue; }

            // Check if this group is auto-promoted
            if ( self::is_group_auto( $group ) ) {
                // Auto-execute directly
                $exec_result = self::execute_proposal( $prop );
                if ( $exec_result['success'] ) $results['auto_executed']++;
                else $results['failed']++;
            } else {
                // Create proposal in PM for review
                $prop['description'] = isset( $prop['description'] ) ? $prop['description'] : '';
                $prop['description'] .= "\n\n---\n" . __( 'Report ID:', 'hermes-bridge' ) . ' ' . $report_id;
                $r = Hermes_Bridge_Integrator::create_proposal( $prop );
                Hermes_Bridge_Agent_DB::log_proposal( $group, $prop['title'], $r['success'] ? 'proposed' : 'failed', $report_id );
                if ( $r['success'] ) $results['created']++;
                else $results['failed']++;
            }
        }

        return array( 'success' => true, 'report_id' => $report_id, 'summary' => $summary, 'results' => $results, 'model' => $model );
    }

    /**
     * Execute a proposal immediately (for auto-promoted groups).
     */
    private static function execute_proposal( $prop ) {
        $target = isset( $prop['target'] ) ? $prop['target'] : 'pm';
        $payload = isset( $prop['payload'] ) ? $prop['payload'] : array();
        $title = isset( $prop['title'] ) ? $prop['title'] : '';

        if ( 'pm' === $target ) {
            // Create task directly
            return Hermes_Bridge_Integrator::pm_create_task( 0, $title, $payload );
        }
        if ( 'erp_crm' === $target && function_exists( 'erp_insert_people' ) ) {
            $contact = array(
                'first_name' => isset( $payload['first_name'] ) ? $payload['first_name'] : $title,
                'last_name'  => isset( $payload['last_name'] ) ? $payload['last_name'] : '',
                'email'      => isset( $payload['email'] ) ? $payload['email'] : '',
                'phone'      => isset( $payload['phone'] ) ? $payload['phone'] : '',
                'type'       => 'contact',
                'life_stage' => 'lead',
            );
            $id = erp_insert_people( $contact );
            if ( is_wp_error( $id ) ) return array( 'success' => false, 'message' => $id->get_error_message(), 'id' => 0 );
            return array( 'success' => true, 'message' => 'Auto lead created', 'id' => intval( $id ) );
        }
        return array( 'success' => false, 'message' => 'Unknown target', 'id' => 0 );
    }

    // ---------- Learning ----------
    public static function learn_from_feedback() {
        $pm_feedback = Hermes_Bridge_Integrator::read_pm_feedback();
        foreach ( $pm_feedback as $fb ) {
            Hermes_Bridge_Agent_DB::log_decision( $fb['group_name'], $fb['decision'], $fb['title'] );
        }
        // Recalculate auto-promotions
        self::recalc_auto_groups();
        return count( $pm_feedback );
    }

    public static function recalc_auto_groups() {
        $stats = self::get_learning_stats();
        $s = self::settings();
        $threshold = intval( $s['approval_threshold'] );
        $changed = false;
        foreach ( $stats as $group => $st ) {
            if ( $st['total'] >= 5 && $st['rate'] >= $threshold && empty( $s['auto_groups'][ $group ] ) ) {
                $s['auto_groups'][ $group ] = true;
                $changed = true;
            }
            // If rate drops below threshold - 20%, demote
            if ( $st['total'] >= 5 && $st['rate'] < ( $threshold - 20 ) && ! empty( $s['auto_groups'][ $group ] ) ) {
                unset( $s['auto_groups'][ $group ] );
                $changed = true;
            }
        }
        if ( $changed ) update_option( 'hermes_agent_settings', $s );
    }

    public static function get_learning_stats() {
        global $wpdb;
        $p = $wpdb->prefix;
        $stats = array();

        $results = $wpdb->get_results(
            "SELECT group_name, 
                    COUNT(*) as total,
                    SUM(CASE WHEN decision = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN decision = 'edited' THEN 1 ELSE 0 END) as edited
             FROM {$p}hermes_agent_log
             WHERE decision IN ('approved','rejected','edited')
             GROUP BY group_name
             ORDER BY total DESC",
            ARRAY_A
        );

        $auto = self::settings()['auto_groups'];
        foreach ( $results as $r ) {
            $total = intval( $r['total'] );
            $approved = intval( $r['approved'] );
            $rate = $total > 0 ? round( $approved / $total * 100 ) : 0;
            $stats[ $r['group_name'] ] = array(
                'total'    => $total,
                'approved' => $approved,
                'rejected' => intval( $r['rejected'] ),
                'edited'   => intval( $r['edited'] ),
                'rate'     => $rate,
                'auto'     => ! empty( $auto[ $r['group_name'] ] ),
            );
        }
        return $stats;
    }

    public static function is_group_auto( $group ) {
        if ( ! $group ) return false;
        $s = self::settings();
        return ! empty( $s['auto_groups'][ $group ] );
    }

    public static function run_scheduled() {
        $s = self::settings();
        if ( 'none' === $s['schedule'] ) return;
        self::run_analysis( intval( $s['analysis_depth'] ) );
    }

    // ---------- Feedback ----------
    public static function save_feedback( $report_id, $feedback_text ) {
        if ( ! $feedback_text ) return false;
        Hermes_Bridge_Agent_DB::add_report_feedback( $report_id, $feedback_text );
        Hermes_Bridge_Agent_DB::add_memory( 'lesson_' . time(), $feedback_text, 'feedback' );
        return true;
    }

    // ---------- Helpers ----------
    private static function parse_json( $content ) {
        $content = trim( $content );
        if ( 0 === strpos( $content, '```' ) ) {
            $content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
            $content = preg_replace( '/```\s*$/', '', $content );
        }
        $data = json_decode( $content, true );
        if ( null === $data && preg_match( '/\{.*\}/s', $content, $m ) ) {
            $data = json_decode( $m[0], true );
        }
        return is_array( $data ) ? $data : null;
    }

    /**
     * Read OpenRouter key from WordPress AI plugin's credential system.
     * Checks: wpai_openrouter_api_key (WP AI Connectors), then HERMES_OPENROUTER_KEY constant.
     */
    public static function get_openrouter_key() {
        if ( defined( 'HERMES_OPENROUTER_KEY' ) && HERMES_OPENROUTER_KEY ) {
            return HERMES_OPENROUTER_KEY;
        }
        // WordPress AI plugin stores connector keys as wpai_{provider}_api_key
        $ai_key = get_option( 'wpai_openrouter_api_key', '' );
        if ( $ai_key ) return $ai_key;
        // Also try the connectors bulk option
        $connectors = get_option( 'wpai_connectors', array() );
        if ( is_array( $connectors ) && isset( $connectors['openrouter']['api_key'] ) ) {
            return $connectors['openrouter']['api_key'];
        }
        // Legacy fallback
        return get_option( 'hermes_bridge_openrouter_key', '' );
    }
}