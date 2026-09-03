<?php
class Hermes_Bridge_Cron_Handler {

    const CRON_HOOK = 'hermes_bridge_sync';
    const CRON_INTERVAL = 'hourly';

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'add_custom_intervals'));
        add_action(self::CRON_HOOK, array('Hermes_Bridge_Sync_Engine', 'run_full_sync'));
    }

    public static function add_custom_intervals($schedules) {
        $schedules['hermes_30min'] = array(
            'interval' => 1800,
            'display' => __('Every 30 Minutes', 'hermes-bridge')
        );
        $schedules['hermes_15min'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'hermes-bridge')
        );
        return $schedules;
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function reschedule($interval = 'hourly') {
        self::unschedule();
        wp_schedule_event(time(), $interval, self::CRON_HOOK);
    }
}
