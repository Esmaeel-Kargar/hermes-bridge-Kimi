<?php
/**
 * Agent cron — scheduled analysis + feedback reading.
 */
class Hermes_Bridge_Agent_Cron {

    const HOOK = 'hermes_bridge_agent_cron';
    const FEEDBACK_HOOK = 'hermes_bridge_feedback_cron';

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
        add_action( self::HOOK, array( 'Hermes_Bridge_Agent', 'run_scheduled' ) );
        add_action( self::FEEDBACK_HOOK, array( 'Hermes_Bridge_Agent', 'learn_from_feedback' ) );
    }

    public static function schedules( $schedules ) {
        $schedules['hermes_15min'] = array( 'interval' => 900,  'display' => __( 'Every 15 Minutes (Agent)', 'hermes-bridge' ) );
        $schedules['hermes_30min'] = array( 'interval' => 1800, 'display' => __( 'Every 30 Minutes (Agent)', 'hermes-bridge' ) );
        return $schedules;
    }

    public static function schedule() {
        $s = Hermes_Bridge_Agent::settings();
        $interval = $s['schedule'];
        self::unschedule();
        if ( 'none' !== $interval ) {
            wp_schedule_event( time() + 60, $interval, self::HOOK );
        }
        // Feedback cron: daily at 2am
        if ( ! wp_next_scheduled( self::FEEDBACK_HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 2am' ), 'daily', self::FEEDBACK_HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }
}