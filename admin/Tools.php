<?php
namespace STBP\admin;

use STBP\includes\Logger;
use STBC\core\Detector;

defined('ABSPATH') || exit;

class Tools {
    public function init() {
        add_action('wp_ajax_stbp_install_logs', [$this, 'install_logs']);
        add_action('wp_ajax_stbp_purge_backups',  [$this, 'purge_backups']);
        add_action('wp_ajax_stbp_purge_logs',     [$this, 'purge_logs']);
        add_action('admin_post_stbp_export_logs', [$this, 'export_logs']);
        add_action('wp_ajax_stbp_scan_vc',        [$this, 'scan_vc_batch']);
        add_action('admin_post_stbp_dry_run_csv', [$this, 'export_dry_run_csv']);
        add_action('admin_post_stbp_export_converted', [$this, 'export_converted_csv']);
    }

    public function install_logs() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce','stbp_convert_nonce_field', false)) {
            wp_die(esc_html__('Invalid nonce', 'shortcode-to-blocks-pro'));
        }
        if (! current_user_can(Settings::tools_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }
        if (! class_exists('\STBP\includes\Logger')) {
            require_once STBP_PATH . 'includes/Logger.php';
        }
        \STBP\includes\Logger::maybe_install();
        wp_safe_redirect( admin_url('admin.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-logs&logs=installed') );
        exit;
    }


    public static function render_tools_page() {
        $opts = Settings::get();
        $ttl  = (int) ($opts['backup_ttl_days'] ?? 30);

        // Backups
        $purge_backups_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_purge_backups'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        $purge_backups_all_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_purge_backups&all=1'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        
        // Logs
        $purge_logs_14_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_purge_logs&days=14'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        $purge_logs_30_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_purge_logs&days=30'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        $purge_logs_all_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_purge_logs&all=1'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        $export_logs_url = wp_nonce_url(
            admin_url('admin-post.php?action=stbp_export_logs'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        $install_logs_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_install_logs'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );

        // Detection (JSON mode keeps us on page)
        $scan_vc_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=stbp_scan_vc&json=1'),
            'stbp_convert_nonce','stbp_convert_nonce_field'
        );
        ?>
        <?php \STBC\admin\Admin::render_tabs( (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-tools' ); ?>
        <div class="wrap">
        <!-- match dashboard/convert helpers + add column gaps -->
        <style>
            .stbp-grid{display:grid;grid-template-columns:1fr;gap:16px}
            @media (min-width:1100px){.stbp-grid{grid-template-columns:2fr 1fr}}
            .stbp-col-left,.stbp-col-right{display:flex;flex-direction:column;gap:16px}
            .stbp-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px}
            .stbp-muted{color:#646970}
            .stbp-row{border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:8px 0;background:#fff}
            .stbp-hd{display:flex;justify-content:space-between;align-items:center;gap:8px}
            .stbp-bar{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin-top:6px}
            .stbp-bar>span{display:block;height:100%;background:#007cba;width:0%}
            .stbp-badges{display:flex;gap:6px;flex-wrap:wrap}
            .stbp-badge{background:#f6f7f7;border:1px solid #dcdcde;border-radius:999px;padding:2px 8px;font-size:12px}
            .stbp-actions{display:flex;gap:8px;flex-wrap:wrap}
            .stbp-small{font-size:12px}
            .stbp-help{cursor:help;border-bottom:1px dotted #50575e}
            .stbp-list{max-height:380px;overflow:auto;margin-top:8px}
            .stbp-sticky{position:sticky;top:32px;z-index:10}
        </style>

        <h1><?php esc_html_e('Tools', 'shortcode-to-blocks-pro'); ?></h1>

        <?php
        // admin notices for this page (with nonce verification)
        $nonce_action = 'stbp_convert_nonce';
        $nonce_value  = isset($_GET['stbp_convert_nonce_field']) ? sanitize_text_field(wp_unslash($_GET['stbp_convert_nonce_field'])) : '';
        $nonce_valid  = ! empty($nonce_value) && wp_verify_nonce($nonce_value, $nonce_action);

        if (isset($_GET['purged_backups']) && $nonce_valid) {
            $n   = (int) $_GET['purged_backups'];
            $all = !empty($_GET['all']);
            $msg = $all
                ? __('All backups were purged.', 'shortcode-to-blocks-pro')
                : sprintf(
                    /* translators: %d: number of backups purged */
                    _n('%d backup was purged.', '%d backups were purged.', $n, 'shortcode-to-blocks-pro'),
                    $n
                );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        if (isset($_GET['scan']) && $_GET['scan'] === 'done' && $nonce_valid) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Detection scan complete.', 'shortcode-to-blocks-pro') . '</p></div>';
        }
        ?>

        <div class="stbp-grid">
            <!-- LEFT COLUMN: detection + logs -->
            <div class="stbp-col-left">
            <div class="stbp-card">
                <h2 class="title" style="margin-top:0;"><?php esc_html_e('Detection', 'shortcode-to-blocks-pro'); ?></h2>
                <p><?php esc_html_e('Scan selected post types to detect WPBakery content. Results feed the Dashboard and batch conversion tools.', 'shortcode-to-blocks-pro'); ?></p>
                <p class="stbp-actions">
                <button id="stbp-scan-btn" class="button button-primary"
                        data-start-url="<?php echo esc_url($scan_vc_url); ?>">
                    <?php esc_html_e('Run detection scan (batch)', 'shortcode-to-blocks-pro'); ?>
                </button>
                </p>
                <div id="stbp-scan-status" style="max-width:520px; display:none;">
                <div class="stbp-bar"><span id="stbp-scan-bar"></span></div>
                <p id="stbp-scan-text" class="stbp-small" style="margin:6px 0 0;"></p>
                </div>
            </div>

            <div class="stbp-card">
                <h2 class="title" style="margin-top:0;"><?php esc_html_e('Logs', 'shortcode-to-blocks-pro'); ?></h2>
                <p><?php esc_html_e('Manage converter logs: export for auditing, purge old entries, or clear all.', 'shortcode-to-blocks-pro'); ?></p>
                <p class="stbp-actions">
                <a class="button button-primary" href="<?php echo esc_url($export_logs_url); ?>">
                    <?php esc_html_e('Download logs CSV', 'shortcode-to-blocks-pro'); ?>
                </a>
                <?php if ( current_user_can( Settings::tools_capability() ) ) : ?>
                <a class="button" href="<?php echo esc_url($purge_logs_14_url); ?>"
                    onclick="return confirm('<?php echo esc_js(__('Permanently delete logs older than 14 days?', 'shortcode-to-blocks-pro')); ?>');">
                    <?php esc_html_e('Purge logs older than 14 days', 'shortcode-to-blocks-pro'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($purge_logs_30_url); ?>"
                    onclick="return confirm('<?php echo esc_js(__('Permanently delete logs older than 30 days?', 'shortcode-to-blocks-pro')); ?>');">
                    <?php esc_html_e('Purge logs older than 30 days', 'shortcode-to-blocks-pro'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($purge_logs_all_url); ?>"
                    onclick="return confirm('<?php echo esc_js(__('This will permanently delete ALL logs. Continue?', 'shortcode-to-blocks-pro')); ?>');">
                    <?php esc_html_e('Purge all logs', 'shortcode-to-blocks-pro'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($install_logs_url); ?>">
                    <?php esc_html_e('Install/repair logs table', 'shortcode-to-blocks-pro'); ?>
                </a>
                <?php endif; ?>
                </p>
            </div>
            </div>
            <?php if ( current_user_can( Settings::tools_capability() ) ) : ?>
            <!-- RIGHT COLUMN: backups -->
            <div class="stbp-col-right">
                <div class="stbp-card">
                    <h2 class="title" style="margin-top:0;"><?php esc_html_e('Backups', 'shortcode-to-blocks-pro'); ?></h2>
                    <p>
                    <?php
                        if ($ttl > 0) {
                            printf(
                                /* translators: %d: number of days */
                                esc_html__('“Purge old backups” deletes backups older than %d days. Nothing is deleted automatically unless you enable the 1-year auto-purge in Settings.', 'shortcode-to-blocks-pro'),
                                esc_html($ttl)
                            );
                        } else {
                            echo esc_html__('Backups are kept until you purge them. “Purge old backups” is disabled when the threshold is 0. You can still use “Purge all backups”.', 'shortcode-to-blocks-pro');
                        }
                    ?>
                    </p>
                    <p class="stbp-actions">
                    <a class="button button-secondary" href="<?php echo esc_url($purge_backups_url); ?>"
                        onclick="return confirm('<?php echo esc_js(__('Permanently delete old backups?', 'shortcode-to-blocks-pro')); ?>');">
                        <?php esc_html_e('Purge old backups', 'shortcode-to-blocks-pro'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url($purge_backups_all_url); ?>"
                        onclick="return confirm('<?php echo esc_js(__('This will permanently delete ALL backups. Continue?', 'shortcode-to-blocks-pro')); ?>');">
                        <?php esc_html_e('Purge all backups', 'shortcode-to-blocks-pro'); ?>
                    </a>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        </div>

        <script>
        (function(){
        const btn   = document.getElementById('stbp-scan-btn');
        if (!btn) return;
        const wrap  = document.getElementById('stbp-scan-status');
        const bar   = document.getElementById('stbp-scan-bar');
        const label = document.getElementById('stbp-scan-text');

        async function tick(url) {
            const res = await fetch(url, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('Network error');
            const data = await res.json();
            if (!data || !data.success) throw new Error('Bad response');

            const d = data.data || {};
            const pct = Math.max(0, Math.min(100, parseInt(d.percent || 0, 10)));
            bar.style.width = pct + '%';
            label.textContent = (d.label || '');

            if (d.done) {
            const next = new URL(window.location.href);
            next.searchParams.set('scan', 'done');
            window.location.assign(next.toString());
            } else if (d.next) {
            setTimeout(() => tick(d.next), 200);
            }
        }

        btn.addEventListener('click', function(ev){
            ev.preventDefault();
            wrap.style.display = 'block';
            bar.style.width = '0%';
            label.textContent = '<?php echo esc_js(__('Starting scan…', 'shortcode-to-blocks-pro')); ?>';
            tick(this.getAttribute('data-start-url'));
        });
        })();
        </script>
        <?php
    }

    public function purge_backups() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce','stbp_convert_nonce_field', false)) {
            wp_die(esc_html__('Invalid nonce', 'shortcode-to-blocks-pro'));
        }
        if (! current_user_can(Settings::tools_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }

        $purged    = 0;
        $all       = !empty($_GET['all']);
        $ttl_days  = (int) (Settings::get()['backup_ttl_days'] ?? 30);
        $cut       = time() - $ttl_days * DAY_IN_SECONDS;

        $q = new \WP_Query([
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional purge query against plugin-managed backup meta.
            'meta_key'       => '_stbp_original_content',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        foreach ($q->posts as $pid) {
            $ts = (int) get_post_meta($pid, '_stbp_original_content_ts', true);
            if ($all || ($ts && $ts < $cut)) {
                delete_post_meta($pid, '_stbp_original_content');
                delete_post_meta($pid, '_stbp_original_content_ts');
                $purged++;
            }
        }

        Logger::log('purge', 'success', "Purged backups: {$purged}");
        delete_transient('stbp_dash_counts');

        // back to Tools with a friendly notice (no more logs=installed confusion)
        $url = add_query_arg([
            'page'           => (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-tools',
            'purged_backups' => $purged,
            'all'            => $all ? 1 : 0,
        ], admin_url('admin.php'));

        wp_safe_redirect( $url );
        exit;
    }

    public function purge_logs() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce','stbp_convert_nonce_field', false)) {
            wp_die(esc_html__('Invalid nonce', 'shortcode-to-blocks-pro'));
        }
        if (! current_user_can(Settings::tools_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }
        $all  = ! empty($_GET['all']);
        $days = isset($_GET['days']) ? max(0, (int) $_GET['days']) : 14;
        if ($all) {
            $count = Logger::purge_all();
            Logger::log('purge', 'success', "Purged ALL logs: {$count}");
            delete_transient('stbp_dash_counts');            // ← add this
            wp_safe_redirect( admin_url('admin.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-logs&logs_purged_all=' . $count) );
        } else {
            $count = Logger::purge_older_than($days);
            Logger::log('purge', 'success', "Purged logs older than {$days} days: {$count}");
            delete_transient('stbp_dash_counts');            // ← and here
            wp_safe_redirect( admin_url('admin.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-logs&logs_purged=' . $count . '&days=' . $days) );
        }
        exit;
    }

    public function export_logs() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_admin_referer('stbp_convert_nonce','stbp_convert_nonce_field')) {
            wp_die(esc_html__('Invalid nonce', 'shortcode-to-blocks-pro'));
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }
        Logger::export_csv(); // exits
    }

    /** Chunked scanner: flags `_stbp_has_vc` across allowed types, 100 posts/tick */
    public function scan_vc_batch() {
        // support both JSON (in-page) and HTML fallback
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This request explicitly verifies the nonce below.
        $nonce = isset($_REQUEST['stbp_convert_nonce_field']) ? sanitize_text_field(wp_unslash($_REQUEST['stbp_convert_nonce_field'])) : '';
        if ( ! wp_verify_nonce( $nonce, 'stbp_convert_nonce' ) ) {
            wp_die( esc_html__('Invalid nonce', 'shortcode-to-blocks-pro') );
        }
        if ( ! current_user_can( Settings::required_capability() ) ) {
            wp_die( esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro') );
        }

        $json_mode = ! empty($_GET['json']); // if present, we return wp_send_json_*

        $allowed   = \STBC\admin\Admin::allowed_post_types();
        $per_page  = 100;
        $offset    = isset($_GET['offset']) ? max(0, absint(wp_unslash($_GET['offset']))) : 0;
        $processed = 0;

        $processed_total = isset($_GET['processed_total']) ? max(0, absint(wp_unslash($_GET['processed_total']))) : 0;
        $total_to_scan   = isset($_GET['total_to_scan']) ? absint(wp_unslash($_GET['total_to_scan'])) : 0;
        if ($total_to_scan <= 0) {
            $total_to_scan = 0;
            foreach ($allowed as $type) {
                $counts = wp_count_posts($type);
                $total_to_scan += (int) ($counts->publish ?? 0);
            }
            if ($total_to_scan <= 0) $total_to_scan = 1;
        }

        foreach ($allowed as $type) {
            $q = new \WP_Query([
                'post_type'      => $type,
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'offset'         => $offset,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            if (empty($q->posts)) continue;

            foreach ($q->posts as $pid) {
                $p = get_post($pid);
                \STBC\core\Detector::flag_post($pid, $p ? (string) $p->post_content : '');
                $processed++;
            }
        }

        delete_transient('stbp_dash_counts');
        $processed_total += $processed;
        $percent = min(100, (int) round(($processed_total / max(1, $total_to_scan)) * 100));

        if ($processed === 0) {
            \STBP\includes\Logger::log('scan', 'success', 'WPbakery detection scan complete');
            if ($json_mode) {
                wp_send_json_success([
                    'done'    => true,
                    'percent' => 100,
                    'label'   => __('Detection scan complete.', 'shortcode-to-blocks-pro'),
                ]);
            } else {
                wp_safe_redirect( admin_url('admin.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '&scan=done') );
                exit;
            }
        }

        // next hop
        $next = add_query_arg([
            'action'                 => 'stbp_scan_vc',
            'offset'                 => $offset + $per_page,
            'processed_total'        => $processed_total,
            'total_to_scan'          => max(1, $total_to_scan),
            'stbp_convert_nonce_field' => rawurlencode($nonce),
            'json'                   => (int) $json_mode,
        ], admin_url('admin-ajax.php'));

        if ($json_mode) {
            wp_send_json_success([
                'done'    => false,
                'next'    => $next,
                'percent' => $percent,
                'label'   => sprintf(
                    /* translators: 1: scanned, 2: total, 3: percent */
                    __('Scanning… %1$d of ~%2$d (%3$d%%)', 'shortcode-to-blocks-pro'),
                    (int)$processed_total, (int)$total_to_scan, (int)$percent
                ),
            ]);
        } else {
            // keep your old minimal HTML fallback if someone hits it directly
            echo '<div class="wrap"><h1>' . esc_html__('Scanning for WPBakery content…', 'shortcode-to-blocks-pro') . '</h1>';
            echo '<p>' . sprintf(
                /* translators: 1: scanned, 2: total, 3: percent */
                esc_html__('%1$d of ~%2$d posts scanned (%3$d%%). Processing next batch…', 'shortcode-to-blocks-pro'),
                (int)$processed_total, (int)$total_to_scan, (int)$percent
            ) . '</p>';
            echo '<p><a class="button" href="' . esc_url($next) . '">' . esc_html__('Continue now', 'shortcode-to-blocks-pro') . '</a></p>';
            echo '<script>setTimeout(function(){location.href=' . json_encode($next) . ';},600);</script></div>';
            exit;
        }
    }


    public function export_dry_run_csv() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_admin_referer('stbp_convert_nonce','stbp_convert_nonce_field')) {
            wp_die(esc_html__('Invalid nonce', 'shortcode-to-blocks-pro'));
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }
        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field(wp_unslash($_GET['batch_id'])) : '';
        if ($batch_id === '') wp_die(esc_html__('Missing batch ID', 'shortcode-to-blocks-pro'));

        // reuse the same store key logic as Batch
        $u = get_current_user_id() ?: 0;
        $key = "stbp_dryrun_{$u}_{$batch_id}";
        $report = get_transient($key);
        if (!$report || empty($report['posts'])) wp_die(esc_html__('No dry run results found (expired or empty).', 'shortcode-to-blocks-pro'));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stbp-dry-run-'.$batch_id.'.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['post_id','title','permalink','post_type','would_change']);
        foreach ($report['posts'] as $row) {
            fputcsv($out, [$row['post_id'], $row['title'], $row['permalink'], $row['type'], $row['would_change']]);
        }
        exit;
    }
    public function export_converted_csv() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_admin_referer('stbp_convert_nonce','stbp_convert_nonce_field')) {
            wp_die(esc_html__('Invalid nonce','shortcode-to-blocks-pro'));
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_die(esc_html__('Insufficient permissions','shortcode-to-blocks-pro'));
        }

        global $wpdb;

        $types          = \STBC\admin\Admin::allowed_post_types();
        $requested_type  = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $type            = in_array($requested_type, $types, true) ? $requested_type : '';
        $search          = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $batch_id        = isset($_GET['batch_id']) ? sanitize_text_field(wp_unslash($_GET['batch_id'])) : '';

        $wheres = [];
        $params = [];

        $wheres[] = "
        (
            EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmc WHERE pmc.post_id = p.ID AND pmc.meta_key = '_stbp_original_content')
            OR
            EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmf WHERE pmf.post_id = p.ID AND pmf.meta_key = '_stbp_converted' AND pmf.meta_value = '1')
        )";
        if ($type) { $wheres[] = "p.post_type = %s"; $params[] = $type; }
        else {
            // restrict to allowed post types
            $escaped = array_map('esc_sql', $types);
            $in      = "'" . implode("','", $escaped) . "'";
            $wheres[] = "p.post_type IN ($in)";
        }
        $wheres[] = "p.post_status IN ('publish','draft','pending','private','future')";
        if ($search !== '') {
            if (is_numeric($search)) { $wheres[] = "(p.ID = %d OR p.post_title LIKE %s)"; $params[] = (int)$search; $params[] = '%' . $wpdb->esc_like($search) . '%'; }
            else { $wheres[] = "p.post_title LIKE %s"; $params[] = '%' . $wpdb->esc_like($search) . '%'; }
        }
        if ($batch_id !== '') {
            $wheres[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmb WHERE pmb.post_id = p.ID AND pmb.meta_key = '_stbp_batch_id' AND pmb.meta_value = %s)";
            $params[] = $batch_id;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $wheres);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin export query for plugin-managed converted content with prepared search/type filters.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type, p.post_status,
                    (SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_stbp_converted_ts' LIMIT 1) AS converted_ts,
                    (SELECT pm2.meta_value FROM {$wpdb->postmeta} pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = '_stbp_original_content_ts' LIMIT 1) AS backup_ts,
                    (SELECT pm3.meta_value FROM {$wpdb->postmeta} pm3 WHERE pm3.post_id = p.ID AND pm3.meta_key = '_wp_page_template' LIMIT 1) AS page_template
                FROM {$wpdb->posts} p
                $whereSQL
                ORDER BY p.post_type, p.ID DESC",
                ...$params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stbp-converted-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['post_id','title','post_type','status','permalink','converted_ts','backup_ts','page_template']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['ID'],
                $r['post_title'],
                $r['post_type'],
                $r['post_status'],
                get_permalink((int)$r['ID']),
                $r['converted_ts'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $r['converted_ts']) : '',
                $r['backup_ts'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $r['backup_ts']) : '',
                $r['page_template'] ?? '',
            ]);
        }
        exit;
    }
}