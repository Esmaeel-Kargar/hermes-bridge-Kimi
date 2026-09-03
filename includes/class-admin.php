<?php
class Hermes_Bridge_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_actions'));
    }

    public static function add_menu() {
        add_menu_page(
            __('Hermes Bridge', 'hermes-bridge'),
            'Hermes Bridge',
            'manage_options',
            'hermes-bridge',
            array(__CLASS__, 'render_dashboard'),
            'dashicons-rest-api',
            30
        );
    }

    public static function handle_actions() {
        if (!isset($_POST['hermes_bridge_action']) || !check_admin_referer('hermes_bridge_nonce')) {
            return;
        }

        $action = sanitize_text_field($_POST['hermes_bridge_action']);

        if ($action === 'generate_api_key') {
            $api_key = wp_generate_password(32, false);
            update_option('hermes_bridge_api_key', $api_key);
            wp_redirect(admin_url('admin.php?page=hermes-bridge&generated=1'));
            exit;
        }

        if ($action === 'trigger_sync') {
            Hermes_Bridge_Sync_Engine::run_full_sync();
            wp_redirect(admin_url('admin.php?page=hermes-bridge&synced=1'));
            exit;
        }

        if ($action === 'change_interval' && isset($_POST['interval'])) {
            $interval = sanitize_text_field($_POST['interval']);
            Hermes_Bridge_Cron_Handler::reschedule($interval);
            wp_redirect(admin_url('admin.php?page=hermes-bridge&interval=1'));
            exit;
        }
    }

    public static function render_dashboard() {
        global $wpdb;

        $api_key = get_option('hermes_bridge_api_key');
        $last_sync = get_option('hermes_bridge_last_sync');

        $event_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hermes_events");
        $unconsumed = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hermes_events WHERE consumed = 0");
        $snapshot_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hermes_snapshots");
        $pending_actions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hermes_actions_queue WHERE status = 'pending'");

        $next_cron = wp_next_scheduled('hermes_bridge_sync');
        ?>
        <div class="wrap">
            <h1>🏛️ Hermes Bridge</h1>

            <?php if (isset($_GET['generated'])): ?>
                <div class="notice notice-success"><p>API Key generated successfully!</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['synced'])): ?>
                <div class="notice notice-success"><p>Sync completed!</p></div>
            <?php endif; ?>

            <div class="hermes-dashboard" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">

                <!-- API Key Card -->
                <div class="card" style="padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
                    <h2>🔑 API Authentication</h2>
                    <?php if ($api_key): ?>
                        <p><code style="background: #f0f0f1; padding: 10px; display: block; word-break: break-all;"><?php echo esc_html($api_key); ?></code></p>
                        <p class="description">Send this in header: <code>X-Hermes-Key: <?php echo esc_html($api_key); ?></code></p>
                    <?php else: ?>
                        <p>No API key generated yet.</p>
                    <?php endif; ?>
                    <form method="post">
                        <?php wp_nonce_field('hermes_bridge_nonce'); ?>
                        <input type="hidden" name="hermes_bridge_action" value="generate_api_key">
                        <button type="submit" class="button button-primary">
                            <?php echo $api_key ? 'Regenerate API Key' : 'Generate API Key'; ?>
                        </button>
                    </form>
                </div>

                <!-- Sync Status Card -->
                <div class="card" style="padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
                    <h2>🔄 Sync Status</h2>
                    <p><strong>Last Sync:</strong> <?php echo $last_sync ? esc_html($last_sync['time']) : 'Never'; ?></p>
                    <p><strong>Duration:</strong> <?php echo $last_sync ? esc_html($last_sync['duration']) . 's' : 'N/A'; ?></p>
                    <p><strong>Next Cron:</strong> <?php echo $next_cron ? esc_html(date('Y-m-d H:i:s', $next_cron)) : 'Not scheduled'; ?></p>

                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('hermes_bridge_nonce'); ?>
                        <input type="hidden" name="hermes_bridge_action" value="trigger_sync">
                        <button type="submit" class="button button-secondary">Run Sync Now</button>
                    </form>

                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('hermes_bridge_nonce'); ?>
                        <input type="hidden" name="hermes_bridge_action" value="change_interval">
                        <select name="interval">
                            <option value="hermes_15min">Every 15 Minutes</option>
                            <option value="hermes_30min">Every 30 Minutes</option>
                            <option value="hourly" selected>Every Hour</option>
                            <option value="twicedaily">Twice Daily</option>
                            <option value="daily">Daily</option>
                        </select>
                        <button type="submit" class="button">Update Interval</button>
                    </form>
                </div>

                <!-- Data Stats Card -->
                <div class="card" style="padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
                    <h2>📊 Data Stats</h2>
                    <ul>
                        <li>Total Events: <strong><?php echo number_format($event_count); ?></strong></li>
                        <li>Unconsumed Events: <strong style="color: #d63638;"><?php echo number_format($unconsumed); ?></strong></li>
                        <li>Snapshots: <strong><?php echo number_format($snapshot_count); ?></strong></li>
                        <li>Pending Actions: <strong><?php echo number_format($pending_actions); ?></strong></li>
                    </ul>
                </div>

                <!-- REST API Endpoints Card -->
                <div class="card" style="padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
                    <h2>🌐 REST API Endpoints</h2>
                    <table class="widefat" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Endpoint</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>GET</code></td>
                                <td><code>/wp-json/hermes-bridge/v1/sync</code></td>
                                <td>Get events + snapshots</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/wp-json/hermes-bridge/v1/action</code></td>
                                <td>Queue action from Hermes</td>
                            </tr>
                            <tr>
                                <td><code>GET</code></td>
                                <td><code>/wp-json/hermes-bridge/v1/summary</code></td>
                                <td>Quick summary</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/wp-json/hermes-bridge/v1/sync/trigger</code></td>
                                <td>Manual sync trigger</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- Recent Events -->
            <div class="card" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
                <h2>📋 Recent Events (Last 20)</h2>
                <?php
                $recent = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hermes_events ORDER BY created_at DESC LIMIT 20", ARRAY_A);
                if ($recent): ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Source</th>
                            <th>Source ID</th>
                            <th>Created</th>
                            <th>Consumed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $event): ?>
                        <tr>
                            <td><?php echo $event['id']; ?></td>
                            <td><code><?php echo esc_html($event['event_type']); ?></code></td>
                            <td><?php echo esc_html($event['source']); ?></td>
                            <td><?php echo esc_html($event['source_id']); ?></td>
                            <td><?php echo esc_html($event['created_at']); ?></td>
                            <td><?php echo $event['consumed'] ? '✅' : '⏳'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p>No events recorded yet. Run sync to populate.</p>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }
}
