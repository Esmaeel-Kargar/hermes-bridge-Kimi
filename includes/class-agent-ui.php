<?php
/**
 * Agent UI — admin dashboard: Chat (sessions, RTL/LTR, file upload), Settings
 * (typeahead model picker, no key field), Learning stats, Memory.
 * No approval queue — proposals go to PM/ERP directly.
 */
class Hermes_Bridge_Agent_UI {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_menu', array( __CLASS__, 'erp_requests_menu' ) );
        add_action( 'admin_post_hb_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_hb_feedback', array( __CLASS__, 'feedback' ) );
        add_action( 'admin_post_hb_promote', array( __CLASS__, 'promote_group' ) );
        add_action( 'admin_post_hb_delete_memory', array( __CLASS__, 'delete_memory' ) );
        add_action( 'admin_post_hb_run_now', array( __CLASS__, 'run_now' ) );
        add_action( 'admin_post_hb_demote', array( __CLASS__, 'demote_group' ) );
        add_action( 'admin_post_hb_approve_request', array( __CLASS__, 'approve_request' ) );
        add_action( 'admin_post_hb_reject_request', array( __CLASS__, 'reject_request' ) );
    }

    public static function menu() {
        add_submenu_page(
            'hermes-bridge',
            __( 'Hermes Agent', 'hermes-bridge' ),
            'Agent',
            'manage_options',
            'hermes-bridge-agent',
            array( __CLASS__, 'render' )
        );
    }

    /**
     * ✅ Add "Requests" tab to WP ERP CRM menu.
     * Shows agent proposals that need approve/reject/feedback.
     * All decisions logged to memory for learning.
     */
    public static function erp_requests_menu() {
        // Only add if ERP CRM is active (menu exists)
        global $admin_page_hooks;
        if ( ! isset( $admin_page_hooks['erp-crm'] ) && ! menu_page_url( 'erp-crm', false ) ) {
            return;
        }
        add_submenu_page(
            'erp-crm',
            __( 'Agent Requests', 'hermes-bridge' ),
            __( 'Requests', 'hermes-bridge' ),
            'manage_options',
            'erp-crm-requests',
            array( __CLASS__, 'render_erp_requests' )
        );
    }

    public static function render_erp_requests() {
        $msg = sanitize_key( $_GET['msg'] ?? '' );
        $msg_map = array(
            'approved' => '✅ ' . __( 'Request approved and executed', 'hermes-bridge' ),
            'rejected' => '⛔ ' . __( 'Request rejected', 'hermes-bridge' ),
        );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Agent Requests', 'hermes-bridge' ); ?></h1>
            <p class="description"><?php _e( 'Proposals from the Hermes Business Agent. Approve, reject, or edit with feedback. Your decisions train the agent.', 'hermes-bridge' ); ?></p>
            <?php if ( isset( $msg_map[ $msg ] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg_map[ $msg ] ); ?></p></div><?php endif; ?>

            <?php
            // Read proposals from PM "🤖 Agent Proposals" project
            global $wpdb;
            $p = $wpdb->prefix;
            $project_title = '🤖 Agent Proposals';

            $project_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}pm_projects WHERE title = %s LIMIT 1",
                $project_title
            ) );

            if ( ! $project_id ) : ?>
                <p><?php _e( 'No proposals yet. Run an analysis first.', 'hermes-bridge' ); ?></p>
            <?php else :
                $tasks = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, title, description, created_at
                     FROM {$p}pm_tasks
                     WHERE project_id = %d AND status = '0'
                     ORDER BY created_at DESC
                     LIMIT 50",
                    $project_id
                ), ARRAY_A );

                if ( empty( $tasks ) ) : ?>
                    <p><?php _e( 'No pending proposals. All tasks have been processed.', 'hermes-bridge' ); ?></p>
                <?php else :
                    foreach ( $tasks as $task ) :
                        // Extract group from description
                        $group = 'general';
                        if ( preg_match( '/Group:\s*(\S+)/i', $task['description'], $m ) ) {
                            $group = sanitize_text_field( $m[1] );
                        }
                        ?>
                        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin:12px 0">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                                <div>
                                    <strong style="font-size:15px"><?php echo esc_html( $task['title'] ); ?></strong>
                                    <span class="description"> | <?php echo esc_html( $group ); ?> | <?php echo esc_html( $task['created_at'] ); ?></span>
                                </div>
                                <span class="description" style="font-size:11px;background:#f0f0f1;padding:2px 8px;border-radius:4px"><?php _e( 'pending_review', 'hermes-bridge' ); ?></span>
                            </div>
                            <div style="background:#f6f7f7;padding:10px;border-radius:4px;margin:8px 0;white-space:pre-wrap;font-size:13px"><?php echo esc_html( $task['description'] ); ?></div>

                            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
                                <!-- Approve -->
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="hb_approve_request">
                                    <input type="hidden" name="task_id" value="<?php echo esc_attr( $task['id'] ); ?>">
                                    <input type="hidden" name="group_name" value="<?php echo esc_attr( $group ); ?>">
                                    <input type="hidden" name="proposal_title" value="<?php echo esc_attr( $task['title'] ); ?>">
                                    <?php wp_nonce_field( 'hb_nonce', '_hb_nonce' ); ?>
                                    <button class="button button-primary">✅ <?php _e( 'Approve & Execute', 'hermes-bridge' ); ?></button>
                                </form>

                                <!-- Reject with feedback -->
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:6px;align-items:center">
                                    <input type="hidden" name="action" value="hb_reject_request">
                                    <input type="hidden" name="task_id" value="<?php echo esc_attr( $task['id'] ); ?>">
                                    <input type="hidden" name="group_name" value="<?php echo esc_attr( $group ); ?>">
                                    <input type="hidden" name="proposal_title" value="<?php echo esc_attr( $task['title'] ); ?>">
                                    <?php wp_nonce_field( 'hb_nonce', '_hb_nonce' ); ?>
                                    <textarea name="feedback" rows="1" style="min-width:240px;min-height:32px" placeholder="<?php esc_attr_e( 'Why rejected / conditions for approval / instructions...', 'hermes-bridge' ); ?>"></textarea>
                                    <button class="button">⛔ <?php _e( 'Reject with Feedback', 'hermes-bridge' ); ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach;
                endif;
            endif; ?>
        </div>
        <?php
    }

    public static function approve_request() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) )
            wp_die( 'Forbidden' );

        $task_id = intval( $_POST['task_id'] ?? 0 );
        $group = sanitize_text_field( $_POST['group_name'] ?? 'general' );
        $title = sanitize_text_field( $_POST['proposal_title'] ?? '' );

        if ( $task_id ) {
            // Mark task as completed in PM (status=1 = completed/approved)
            global $wpdb;
            $wpdb->update( $wpdb->prefix . 'pm_tasks',
                array( 'status' => '1', 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $task_id )
            );
            // Log the decision
            Hermes_Bridge_Agent_DB::log_decision( $group, 'approved', $title );
            // Add memory fact
            Hermes_Bridge_Agent_DB::add_memory( 'decision_' . $task_id,
                'Approved: ' . $title . ' (group: ' . $group . ')', 'decision' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=erp-crm-requests&msg=approved' ) );
        exit;
    }

    public static function reject_request() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) )
            wp_die( 'Forbidden' );

        $task_id = intval( $_POST['task_id'] ?? 0 );
        $group = sanitize_text_field( $_POST['group_name'] ?? 'general' );
        $title = sanitize_text_field( $_POST['proposal_title'] ?? '' );
        $feedback = sanitize_textarea_field( $_POST['feedback'] ?? '' );

        if ( $task_id ) {
            // Delete the task (rejected proposals are removed)
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'pm_tasks', array( 'id' => $task_id ) );
            // Log the decision
            Hermes_Bridge_Agent_DB::log_decision( $group, 'rejected', $title );
            // Store feedback as memory lesson
            if ( $feedback ) {
                Hermes_Bridge_Agent_DB::add_memory( 'lesson_' . $task_id,
                    'Rejected: ' . $title . ' — ' . $feedback, 'feedback' );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=erp-crm-requests&msg=rejected' ) );
        exit;
    }

    private static function redirect( $msg = 'ok' ) {
        wp_safe_redirect( admin_url( 'admin.php?page=hermes-bridge-agent&msg=' . $msg ) );
        exit;
    }

    private static function nonce() {
        wp_nonce_field( 'hb_nonce', '_hb_nonce' );
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) )
            wp_die( 'Forbidden' );
        Hermes_Bridge_Agent::save_settings( $_POST );
        if ( isset( $_POST['goals'] ) ) update_option( 'hermes_agent_goals', sanitize_textarea_field( $_POST['goals'] ) );
        delete_transient( 'hermes_or_models' );
        Hermes_Bridge_Agent_Cron::schedule();
        self::redirect( 'saved' );
    }

    public static function feedback() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) ) wp_die( 'Forbidden' );
        $rid = intval( $_POST['report_id'] ?? 0 );
        $text = sanitize_textarea_field( $_POST['feedback'] ?? '' );
        if ( $rid && $text ) Hermes_Bridge_Agent::save_feedback( $rid, $text );
        self::redirect( 'feedback' );
    }

    public static function promote_group() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) ) wp_die( 'Forbidden' );
        $group = sanitize_text_field( $_POST['group_name'] ?? '' );
        if ( $group ) {
            $s = Hermes_Bridge_Agent::settings();
            $s['auto_groups'][ $group ] = true;
            update_option( 'hermes_agent_settings', $s );
        }
        self::redirect( 'promoted' );
    }

    public static function demote_group() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) ) wp_die( 'Forbidden' );
        $group = sanitize_text_field( $_POST['group_name'] ?? '' );
        if ( $group ) {
            $s = Hermes_Bridge_Agent::settings();
            unset( $s['auto_groups'][ $group ] );
            update_option( 'hermes_agent_settings', $s );
        }
        self::redirect( 'demoted' );
    }

    public static function delete_memory() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) ) wp_die( 'Forbidden' );
        $id = intval( $_POST['memory_id'] ?? 0 );
        if ( $id ) Hermes_Bridge_Agent_DB::delete_memory( $id );
        self::redirect( 'mem_deleted' );
    }

    public static function run_now() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_hb_nonce'] ) || ! wp_verify_nonce( $_POST['_hb_nonce'], 'hb_nonce' ) ) wp_die( 'Forbidden' );
        $depth = intval( $_POST['depth'] ?? 2 );
        $result = Hermes_Bridge_Agent::run_analysis( $depth );
        if ( ! $result['success'] ) set_transient( 'hb_last_run_error', $result['error'] ?? 'unknown', 300 );
        self::redirect( $result['success'] ? 'ran' : 'ran_error' );
    }

    public static function render() {
        $settings = Hermes_Bridge_Agent::settings();
        $models   = Hermes_Bridge_OpenRouter::get_models();
        $goals    = Hermes_Bridge_Agent::goals();
        $reports  = Hermes_Bridge_Agent_DB::get_reports( 30 );
        $memories = Hermes_Bridge_Agent_DB::get_memories();
        $stats    = Hermes_Bridge_Agent::get_learning_stats();
        $error    = get_transient( 'hb_last_run_error' );
        $msg      = sanitize_key( $_GET['msg'] ?? '' );
        $rest_nonce = wp_create_nonce( 'wp_rest' );

        $msg_map = array(
            'saved'     => '✅ ' . __( 'Settings saved', 'hermes-bridge' ),
            'feedback'  => '✅ ' . __( 'Feedback saved to memory', 'hermes-bridge' ),
            'promoted'  => '✅ ' . __( 'Group promoted to auto', 'hermes-bridge' ),
            'demoted'   => '⏸ ' . __( 'Group demoted back to proposal', 'hermes-bridge' ),
            'mem_deleted' => '🗑 ' . __( 'Memory deleted', 'hermes-bridge' ),
            'ran'       => '✅ ' . __( 'Analysis complete — proposals in PM "🤖 Agent Proposals"', 'hermes-bridge' ),
            'ran_error' => '❌ ' . __( 'Analysis failed', 'hermes-bridge' ),
        );

        ?>
        <div class="wrap">
            <h1><?php _e( 'Hermes Agent', 'hermes-bridge' ); ?></h1>
            <?php if ( isset( $msg_map[ $msg ] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg_map[ $msg ] ); ?></p></div><?php endif; ?>
            <?php if ( $error ) : ?><div class="notice notice-error"><p>❌ <?php echo esc_html( $error ); ?></p><?php delete_transient( 'hb_last_run_error' ); ?></div><?php endif; ?>
            <?php if ( ! Hermes_Bridge_Agent::get_openrouter_key() ) : ?><div class="notice notice-warning"><p><?php _e( '🔑 OpenRouter key not found. Set it in WordPress AI → Connectors settings, or define <code>HERMES_OPENROUTER_KEY</code> in wp-config.', 'hermes-bridge' ); ?></p></div><?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a class="nav-tab hb-tab nav-tab-active" data-tab="chat" href="#">💬 <?php _e( 'Chat', 'hermes-bridge' ); ?></a>
                <a class="nav-tab hb-tab" data-tab="settings" href="#">⚙️ <?php _e( 'Settings', 'hermes-bridge' ); ?></a>
                <a class="nav-tab hb-tab" data-tab="learning" href="#">📊 <?php _e( 'Learning', 'hermes-bridge' ); ?></a>
                <a class="nav-tab hb-tab" data-tab="reports" href="#">📋 <?php _e( 'Reports', 'hermes-bridge' ); ?></a>
                <a class="nav-tab hb-tab" data-tab="memory" href="#">🧠 <?php _e( 'Memory', 'hermes-bridge' ); ?></a>
            </nav>

            <!-- ============ CHAT ============ -->
            <div class="hb-section" id="hb-chat">
                <div style="display:flex;gap:12px;min-height:500px">
                    <!-- Sessions sidebar -->
                    <div style="width:220px;flex-shrink:0;background:#f6f7f7;border-radius:6px;padding:8px;overflow-y:auto" id="hb-sessions">
                        <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                            <strong><?php _e( 'Sessions', 'hermes-bridge' ); ?></strong>
                            <button class="button button-small" id="hb-new-session">+</button>
                        </div>
                        <div id="hb-session-list"></div>
                    </div>
                    <!-- Chat area -->
                    <div style="flex:1;display:flex;flex-direction:column">
                        <!-- Header: model + RTL/LTR -->
                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
                            <select id="hb-chat-model" style="flex:1;min-width:140px" class="hb-model-select">
                                <option value=""><?php _e( 'Select model...', 'hermes-bridge' ); ?></option>
                                <?php foreach ( $models as $m ) : ?>
                                <option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $settings['chat_model'], $m['id'] ); ?>><?php echo esc_html( $m['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="button button-small" id="hb-rtl-input" title="RTL/LTR input">⇄</button>
                            <button class="button button-small" id="hb-rtl-msg" title="RTL/LTR messages">↔</button>
                            <span class="description" id="hb-session-name"><?php _e( 'Current Chat', 'hermes-bridge' ); ?></span>
                        </div>
                        <!-- Messages -->
                        <div style="flex:1;background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;overflow-y:auto;min-height:320px" id="hb-chat-messages"></div>
                        <!-- Input -->
                        <div style="display:flex;gap:6px;margin-top:8px;align-items:flex-end">
                            <textarea id="hb-chat-input" style="flex:1;min-height:50px" placeholder="<?php esc_attr_e( 'Type a message...', 'hermes-bridge' ); ?>"></textarea>
                            <div style="display:flex;flex-direction:column;gap:4px">
                                <button class="button" id="hb-upload-btn" title="<?php esc_attr_e( 'Upload file/image', 'hermes-bridge' ); ?>">📎</button>
                                <input type="file" id="hb-file-input" style="display:none" accept="image/*,.pdf,.doc,.docx,.txt,.csv">
                                <button class="button button-primary" id="hb-send-btn"><?php _e( 'Send', 'hermes-bridge' ); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                (function(){
                    var sid = 0, sname = '<?php _e( 'New Chat', 'hermes-bridge' ); ?>';
                    var msgEl = document.getElementById('hb-chat-messages');
                    var inp = document.getElementById('hb-chat-input');
                    var sendBtn = document.getElementById('hb-send-btn');
                    var sessionList = document.getElementById('hb-session-list');
                    var modelSel = document.getElementById('hb-chat-model');
                    var nonce = <?php echo wp_json_encode( $rest_nonce ); ?>;
                    var msgDir = 'ltr', inpDir = 'ltr';

                    function api(path, opts, cb) {
                        fetch('/wp-json/hermes-bridge/v1' + path, { headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, ...opts })
                        .then(r => r.json()).then(d => cb(d)).catch(e => { addMsg('assistant', 'Error: ' + e); });
                    }

                    function addMsg(role, text) {
                        var d = document.createElement('div');
                        d.style.cssText = 'padding:8px;margin:6px 0;border-radius:8px;white-space:pre-wrap;max-width:85%;word-wrap:break-word;'
                            + (role === 'user' ? 'background:#e8f0fe;align-self:flex-end;margin-left:auto' : 'background:#f1f1f1');
                        d.dir = msgDir;
                        d.textContent = (role === 'user' ? '🧑 ' : '🤖 ') + text;
                        msgEl.appendChild(d);
                        msgEl.scrollTop = msgEl.scrollHeight;
                    }

                    function loadSessions() {
                        api('/chat/sessions', { method: 'GET' }, function(d) {
                            var s = d.sessions || [];
                            sessionList.innerHTML = '';
                            s.forEach(function(ses) {
                                var div = document.createElement('div');
                                div.style.cssText = 'padding:6px 8px;margin:2px 0;border-radius:4px;cursor:pointer;font-size:13px;display:flex;justify-content:space-between'
                                    + (ses.id === sid ? ';background:#c7daf0' : '');
                                div.innerHTML = '<span>' + (ses.pinned ? '📌 ' : '') + escH(ses.name) + '</span>'
                                    + '<span style="font-size:11px;color:#888">' + (ses.msg_count||0) + '</span>';
                                div.onclick = function(){ switchSession(ses.id, ses.name); };
                                sessionList.appendChild(div);
                            });
                        });
                    }

                    function switchSession(id, name) {
                        sid = id; sname = name;
                        document.getElementById('hb-session-name').textContent = '📌 ' + name;
                        msgEl.innerHTML = '';
                        api('/chat/history?session_id=' + id, { method: 'GET' }, function(d) {
                            (d.history || []).forEach(function(m) { addMsg(m.role, m.content); });
                            if (!d.history || !d.history.length) msgEl.innerHTML = '<p class="description"><?php _e( 'Start a conversation...', 'hermes-bridge' ); ?></p>';
                        });
                        loadSessions();
                    }

                    function escH(s) { return s.replace(/[&<>]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; }); }

                    sendBtn.addEventListener('click', function(){
                        var msg = inp.value.trim();
                        if (!msg) return;
                        inp.value = '';
                        addMsg('user', msg);
                        msgEl.innerHTML = msgEl.innerHTML + '<p class="description"><?php _e( 'Thinking...', 'hermes-bridge' ); ?></p>';
                        fetch('/wp-json/hermes-bridge/v1/chat', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                            body: JSON.stringify({ message: msg, session_id: sid })
                        })
                        .then(r => r.json()).then(function(d) {
                            // Remove thinking indicator
                            msgEl.removeChild(msgEl.lastChild);
                            if (d.reply) { addMsg('assistant', d.reply); sid = d.session_id || sid; loadSessions(); }
                            else { addMsg('assistant', 'Error: ' + (d.message || 'unknown')); }
                        })
                        .catch(function(e){ msgEl.removeChild(msgEl.lastChild); addMsg('assistant', 'Error: ' + e); });
                    });

                    document.getElementById('hb-new-session').addEventListener('click', function(){
                        api('/chat/sessions', { method: 'POST', body: JSON.stringify({name: '<?php _e( 'New Chat', 'hermes-bridge' ); ?>'}) }, function(d){
                            switchSession(d.session_id, d.name);
                        });
                    });

                    // RTL/LTR toggle
                    document.getElementById('hb-rtl-input').addEventListener('click', function(){
                        inpDir = inpDir === 'ltr' ? 'rtl' : 'ltr'; inp.dir = inpDir;
                    });
                    document.getElementById('hb-rtl-msg').addEventListener('click', function(){
                        msgDir = msgDir === 'ltr' ? 'rtl' : 'ltr';
                        msgEl.querySelectorAll('div').forEach(function(d){ d.dir = msgDir; });
                    });

                    // File upload
                    document.getElementById('hb-upload-btn').addEventListener('click', function(){ document.getElementById('hb-file-input').click(); });
                    document.getElementById('hb-file-input').addEventListener('change', function(){
                        var fd = new FormData(); fd.append('file', this.files[0]);
                        fetch('/wp-json/hermes-bridge/v1/chat/upload', {
                            method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd
                        })
                        .then(r => r.json()).then(function(d){
                            if (d.url) inp.value += ' [' + (d.name) + '](' + d.url + ') ';
                        });
                    });

                    // Model typeahead: filter dropdown as user types
                    modelSel.addEventListener('input', function() {
                        var q = this.value.toLowerCase();
                        Array.prototype.forEach.call(this.options, function(o) {
                            o.style.display = (!o.value || o.text.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
                        });
                    });

                    // Init
                    <?php if ( $sessions = Hermes_Bridge_Agent_DB::get_sessions() ) :
                        $latest = $sessions[0]; ?>
                        switchSession(<?php echo intval( $latest['id'] ); ?>, <?php echo wp_json_encode( $latest['name'] ); ?>);
                    <?php else : ?>
                        msgEl.innerHTML = '<p class="description"><?php _e( 'Start a new conversation, or create a session.', 'hermes-bridge' ); ?></p>';
                    <?php endif; ?>
                })();
                </script>
            </div>

            <!-- ============ SETTINGS ============ -->
            <div class="hb-section" id="hb-settings" style="display:none">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="hb_save_settings"><?php self::nonce(); ?>
                    <table class="form-table">
                        <tr><th><?php _e( 'Chat Model', 'hermes-bridge' ); ?></th>
                            <td><?php echo self::model_picker( 'chat_model', $settings['chat_model'], $models ); ?>
                                <p class="description"><?php _e( 'Model for real-time chat.', 'hermes-bridge' ); ?></p></td></tr>
                        <tr><th><?php _e( 'Analysis Model', 'hermes-bridge' ); ?></th>
                            <td><?php echo self::model_picker( 'analysis_model', $settings['analysis_model'], $models ); ?>
                                <p class="description"><?php _e( 'Model for scheduled analysis and proposals.', 'hermes-bridge' ); ?></p></td></tr>
                        <tr><th><?php _e( 'Schedule', 'hermes-bridge' ); ?></th>
                            <td><select name="schedule">
                                <?php foreach ( array( 'none' => __( 'Disabled', 'hermes-bridge' ), 'hermes_15min' => __( 'Every 15 min', 'hermes-bridge' ), 'hermes_30min' => __( 'Every 30 min', 'hermes-bridge' ), 'hourly' => __( 'Hourly', 'hermes-bridge' ), 'daily' => __( 'Daily', 'hermes-bridge' ) ) as $k => $v ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $settings['schedule'], $k ); ?>><?php echo esc_html( $v ); ?></option>
                                <?php endforeach; ?></select></td></tr>
                        <tr><th><?php _e( 'Analysis Depth', 'hermes-bridge' ); ?></th>
                            <td><select name="analysis_depth">
                                <option value="1" <?php selected( $settings['analysis_depth'], 1 ); ?>><?php _e( 'Level 1 — Quick (status + obvious actions)', 'hermes-bridge' ); ?></option>
                                <option value="2" <?php selected( $settings['analysis_depth'], 2 ); ?>><?php _e( 'Level 2 — Standard (follow-ups + leads)', 'hermes-bridge' ); ?></option>
                                <option value="3" <?php selected( $settings['analysis_depth'], 3 ); ?>><?php _e( 'Level 3 — Deep (strategic + trends)', 'hermes-bridge' ); ?></option>
                            </select></td></tr>
                        <tr><th><?php _e( 'Auto-approval Threshold', 'hermes-bridge' ); ?></th>
                            <td><input type="number" name="approval_threshold" value="<?php echo esc_attr( $settings['approval_threshold'] ?? 90 ); ?>" min="50" max="100" style="width:80px"> %</td></tr>
                        <tr><th><?php _e( 'Goals & Strategy', 'hermes-bridge' ); ?></th>
                            <td><textarea name="goals" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'Main goals, strategy, focus areas...', 'hermes-bridge' ); ?>"><?php echo esc_textarea( $goals ); ?></textarea></td></tr>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'hermes-bridge' ) ); ?>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="hb_run_now"><input type="hidden" name="depth" value="2"><?php self::nonce(); ?>
                    <button class="button button-secondary">▶️ <?php _e( 'Run Analysis Now (Level 2)', 'hermes-bridge' ); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="hb_run_now"><input type="hidden" name="depth" value="3"><?php self::nonce(); ?>
                    <button class="button button-secondary">▶️ <?php _e( 'Deep Analysis (Level 3)', 'hermes-bridge' ); ?></button>
                </form>
            </div>

            <!-- ============ LEARNING ============ -->
            <div class="hb-section" id="hb-learning" style="display:none">
                <p><?php _e( 'Groups with high approval rates are automatically promoted. Once promoted, the agent executes those actions directly without asking.', 'hermes-bridge' ); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php _e( 'Group', 'hermes-bridge' ); ?></th><th><?php _e( 'Total', 'hermes-bridge' ); ?></th><th><?php _e( 'Approved', 'hermes-bridge' ); ?></th><th><?php _e( 'Rejected', 'hermes-bridge' ); ?></th><th><?php _e( 'Rate', 'hermes-bridge' ); ?></th><th><?php _e( 'Status', 'hermes-bridge' ); ?></th><th><?php _e( 'Action', 'hermes-bridge' ); ?></th></tr></thead>
                    <tbody>
                        <?php if ( empty( $stats ) ) : ?><tr><td colspan="7"><?php _e( 'No data yet. Run an analysis to start learning.', 'hermes-bridge' ); ?></td></tr><?php endif; ?>
                        <?php foreach ( $stats as $group => $st ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $group ); ?></strong></td>
                            <td><?php echo intval( $st['total'] ); ?></td>
                            <td style="color:green"><?php echo intval( $st['approved'] ); ?></td>
                            <td style="color:#c00"><?php echo intval( $st['rejected'] ); ?></td>
                            <td><strong><?php echo intval( $st['rate'] ); ?>%</strong></td>
                            <td><?php echo $st['auto'] ? '<span style="color:green">🤖 ' . __( 'Auto', 'hermes-bridge' ) . '</span>' : '<span style="color:#888">📋 ' . __( 'Proposal', 'hermes-bridge' ) . '</span>'; ?></td>
                            <td>
                                <?php if ( $st['auto'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <input type="hidden" name="action" value="hb_demote"><input type="hidden" name="group_name" value="<?php echo esc_attr( $group ); ?>"><?php self::nonce(); ?>
                                        <button class="button button-small">⏸ <?php _e( 'Demote', 'hermes-bridge' ); ?></button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <input type="hidden" name="action" value="hb_promote"><input type="hidden" name="group_name" value="<?php echo esc_attr( $group ); ?>"><?php self::nonce(); ?>
                                        <button class="button button-small" <?php echo $st['total'] < 5 ? 'disabled' : ''; ?>><?php echo $st['total'] < 5 ? '⏳ 5+' : '🚀 ' . __( 'Promote', 'hermes-bridge' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ============ REPORTS ============ -->
            <div class="hb-section" id="hb-reports" style="display:none">
                <?php foreach ( $reports as $r ) : $json = json_decode( $r['report_json'], true ); ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;margin:10px 0">
                    <strong>#<?php echo intval( $r['id'] ); ?></strong>
                    <span class="description">| <?php _e( 'Depth', 'hermes-bridge' ); ?> <?php echo intval( $r['depth'] ); ?> | <?php echo esc_html( $r['model'] ); ?> | <?php echo esc_html( $r['created_at'] ); ?></span>
                    <p><?php echo esc_html( $r['summary'] ); ?></p>
                    <?php if ( $r['feedback'] ) : ?><p><strong><?php _e( 'Feedback:', 'hermes-bridge' ); ?></strong> <?php echo esc_html( $r['feedback'] ); ?></p><?php endif; ?>
                    <details><summary><?php _e( 'View full JSON', 'hermes-bridge' ); ?></summary>
                        <pre style="background:#f6f7f7;padding:8px;overflow:auto;max-height:180px"><?php echo esc_html( json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                    </details>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
                        <input type="hidden" name="action" value="hb_feedback"><input type="hidden" name="report_id" value="<?php echo esc_attr( $r['id'] ); ?>"><?php self::nonce(); ?>
                        <textarea name="feedback" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Feedback on this analysis...', 'hermes-bridge' ); ?>"></textarea><br>
                        <button class="button">💾 <?php _e( 'Save feedback', 'hermes-bridge' ); ?></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ============ MEMORY ============ -->
            <div class="hb-section" id="hb-memory" style="display:none">
                <div style="margin-bottom:12px">
                    <input type="text" id="hb-memory-search" placeholder="<?php esc_attr_e( 'Search memory...', 'hermes-bridge' ); ?>" style="width:300px">
                    <select id="hb-memory-filter">
                        <option value=""><?php _e( 'All kinds', 'hermes-bridge' ); ?></option>
                        <option value="fact"><?php _e( 'Fact', 'hermes-bridge' ); ?></option>
                        <option value="decision"><?php _e( 'Decision', 'hermes-bridge' ); ?></option>
                        <option value="feedback"><?php _e( 'Feedback', 'hermes-bridge' ); ?></option>
                        <option value="goal"><?php _e( 'Goal', 'hermes-bridge' ); ?></option>
                    </select>
                </div>
                <table class="widefat striped">
                    <thead><tr><th><?php _e( 'Key', 'hermes-bridge' ); ?></th><th><?php _e( 'Value', 'hermes-bridge' ); ?></th><th><?php _e( 'Kind', 'hermes-bridge' ); ?></th><th><?php _e( 'Updated', 'hermes-bridge' ); ?></th><th></th></tr></thead>
                    <tbody id="hb-memory-tbody">
                        <?php foreach ( $memories as $m ) : ?>
                        <tr data-kind="<?php echo esc_attr( $m['kind'] ); ?>">
                            <td><strong><?php echo esc_html( $m['memory_key'] ); ?></strong></td>
                            <td><?php echo esc_html( mb_substr( $m['memory_value'], 0, 120 ) ); ?></td>
                            <td><span class="description"><?php echo esc_html( $m['kind'] ); ?></span></td>
                            <td><?php echo esc_html( $m['updated_at'] ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="hb_delete_memory"><input type="hidden" name="memory_id" value="<?php echo esc_attr( $m['id'] ); ?>"><?php self::nonce(); ?>
                                    <button class="button-link">🗑</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <script>
                (function(){
                    document.getElementById('hb-memory-search')?.addEventListener('input', function(){ filterMem(); });
                    document.getElementById('hb-memory-filter')?.addEventListener('change', function(){ filterMem(); });
                    function filterMem(){
                        var q = (document.getElementById('hb-memory-search').value || '').toLowerCase();
                        var k = document.getElementById('hb-memory-filter').value;
                        document.querySelectorAll('#hb-memory-tbody tr').forEach(function(r){
                            var show = (!k || r.getAttribute('data-kind') === k) && (r.textContent.toLowerCase().indexOf(q) !== -1);
                            r.style.display = show ? '' : 'none';
                        });
                    }
                })();
                </script>
            </div>

            <script>
            (function(){
                var tabs = document.querySelectorAll('.hb-tab');
                tabs.forEach(function(t){
                    t.addEventListener('click', function(e){
                        e.preventDefault();
                        tabs.forEach(function(x){ x.classList.remove('nav-tab-active'); });
                        t.classList.add('nav-tab-active');
                        document.querySelectorAll('.hb-section').forEach(function(s){ s.style.display = 'none'; });
                        var sec = document.getElementById('hb-' + t.getAttribute('data-tab'));
                        if (sec) sec.style.display = '';
                    });
                });
                // Typeahead for model selects: filter on input
                document.querySelectorAll('.hb-model-select').forEach(function(sel) {
                    sel.addEventListener('input', function() {
                        var q = this.value.toLowerCase();
                        Array.prototype.forEach.call(this.options, function(o) {
                            o.style.display = (!o.value || o.text.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
                        });
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Model picker: dropdown that filters as you type (typeahead).
     * Like Hermes' own model selector.
     */
    private static function model_picker( $field, $current, $models ) {
        ob_start(); ?>
        <select name="<?php echo esc_attr( $field ); ?>" class="hb-model-select" style="width:100%;max-width:520px">
            <option value="">— <?php _e( 'Type to search models...', 'hermes-bridge' ); ?> —</option>
            <?php foreach ( $models as $m ) : ?>
                <option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $current, $m['id'] ); ?>><?php echo esc_html( $m['name'] . ' (' . $m['id'] . ')' ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php _e( 'Start typing to filter models. Clear the field to see all.', 'hermes-bridge' ); ?></p>
        <?php return ob_get_clean();
    }
}