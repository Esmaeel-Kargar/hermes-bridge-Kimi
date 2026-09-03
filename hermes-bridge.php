<?php
/**
 * Plugin Name: Hermes Bridge
 * Description: Smart data bridge between EDD, WP ERP, WP Project Manager, WP Statistics and Hermes AI Agent — with private AI Agent (OpenRouter), memory, and graduated automation.
 * Version: 2.1.0
 * Author: Dynamix Systems
 * Text Domain: hermes-bridge
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('HERMES_BRIDGE_VERSION', '2.0.0');
define('HERMES_BRIDGE_DIR', plugin_dir_path(__FILE__));
define('HERMES_BRIDGE_URL', plugin_dir_url(__FILE__));

// ---- v1 core (sync engine, REST, cron, admin dashboard) ----
require_once HERMES_BRIDGE_DIR . 'includes/class-database.php';
require_once HERMES_BRIDGE_DIR . 'includes/class-sync-engine.php';
require_once HERMES_BRIDGE_DIR . 'includes/class-rest-api.php';
require_once HERMES_BRIDGE_DIR . 'includes/class-cron-handler.php';
require_once HERMES_BRIDGE_DIR . 'includes/class-admin.php';

// ---- v2 agent stack (new) ----
require_once HERMES_BRIDGE_DIR . 'includes/class-agent-db.php';        // tables: chat, memory, reports, agent_actions
require_once HERMES_BRIDGE_DIR . 'includes/class-openrouter.php';      // OpenRouter API client + model catalog
require_once HERMES_BRIDGE_DIR . 'includes/class-integrator.php';      // safe adapter: WP ERP + WP Project Manager official APIs
require_once HERMES_BRIDGE_DIR . 'includes/class-agent.php';           // agent brain: prompt, memory, JSON actions, graduated tiers
require_once HERMES_BRIDGE_DIR . 'includes/class-agent-chat.php';      // private admin chat (server-side history, no client roles)
require_once HERMES_BRIDGE_DIR . 'includes/class-agent-cron.php';      // scheduled analysis levels
require_once HERMES_BRIDGE_DIR . 'includes/class-agent-ui.php';        // admin UI: settings, model picker+search, chat, approval queue

class Hermes_Bridge {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init() {
        // v1
        Hermes_Bridge_Database::init();
        Hermes_Bridge_Sync_Engine::init();
        Hermes_Bridge_REST_API::init();
        Hermes_Bridge_Cron_Handler::init();
        Hermes_Bridge_Admin::init();

        // v2 agent
        Hermes_Bridge_Agent_DB::init();
        Hermes_Bridge_OpenRouter::init();
        Hermes_Bridge_Integrator::init();
        Hermes_Bridge_Agent::init();
        Hermes_Bridge_Agent_Chat::init();
        Hermes_Bridge_Agent_Cron::init();
        Hermes_Bridge_Agent_UI::init();
    }

    public function activate() {
        Hermes_Bridge_Database::install();
        Hermes_Bridge_Agent_DB::install();
        Hermes_Bridge_Cron_Handler::schedule();
        flush_rewrite_rules();
    }

    public function deactivate() {
        Hermes_Bridge_Cron_Handler::unschedule();
        Hermes_Bridge_Agent_Cron::unschedule();
        flush_rewrite_rules();
    }
}

Hermes_Bridge::get_instance();