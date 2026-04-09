<?php
namespace STBP\admin;

use STBP\includes\Logger;

defined('ABSPATH') || exit;

class Batch {
    /**
     * Convert a single post (page) from WPBakery shortcodes to blocks.
     * Returns true on success, false or WP_Error on failure.
     */
    public static function convert_post($post_id, $batch_id = '') {
        $post = get_post($post_id);
        if (! $post) {
            return false;
        }
        if (! self::can_edit_target_post((int) $post_id)) {
            return new \WP_Error('forbidden', __('Insufficient permissions to edit this post.', 'shortcode-to-blocks-pro'));
        }
        // Only allow post types defined in settings
        if (class_exists('STBP\\admin\\Settings')) {
            $allowed = \STBP\admin\Settings::get()['post_types'] ?? ['post', 'page'];
            if (!in_array($post->post_type, $allowed, true)) {
                return false;
            }
        }

        // Backup original content if not already backed up
        if (! get_post_meta($post_id, '_stbp_original_content', true)) {
            update_post_meta($post_id, '_stbp_original_content', $post->post_content);
            update_post_meta($post_id, '_stbp_original_content_ts', time());
        }

        // Load converter
        $converter = new \STBP\includes\ConverterPro();
        $new_content = $converter->convert_vc_shortcodes_recursive($post->post_content);

        // Only update if content changes
        if ($new_content !== $post->post_content) {
            // Handle page template validation (same logic as handle_batch method)
            $tpl = get_page_template_slug($post_id);
            if ($tpl && 'default' !== $tpl) {
                $post_type = get_post_type($post_id);
                $allowed   = wp_get_theme()->get_page_templates(null, $post_type);
                if (! isset($allowed[$tpl])) {
                    update_post_meta($post_id, '_wp_page_template', 'default');
                }
            }

            $res = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
            if (is_wp_error($res)) {
                Logger::log('batch', 'error', 'Failed to update: ' . $res->get_error_message(), $post_id);
                return $res;
            }
            update_post_meta($post_id, '_stbp_converted', 1);
            update_post_meta($post_id, '_stbp_converted_ts', time());
            if ($batch_id) {
                update_post_meta($post_id, '_stbp_batch_id', sanitize_text_field($batch_id));
            }
            Logger::log('batch', 'success', 'Converted via bulk action', $post_id);
            return true;
        } else {
            // Mark as converted even if no change
            update_post_meta($post_id, '_stbp_converted', 1);
            update_post_meta($post_id, '_stbp_converted_ts', time());
            if ($batch_id) {
                update_post_meta($post_id, '_stbp_batch_id', sanitize_text_field($batch_id));
            }
            Logger::log('batch', 'success', 'No changes needed (already converted)', $post_id);
            return true;
        }
    }

    /**
     * Register AJAX routes.
     * Call $batch->init() from your admin bootstrap (e.g., on admin_init or when wiring admin menu).
     */
    public function init() {
        // AJAX: convert + revert
        add_action('wp_ajax_stbp_batch_convert', [$this, 'handle_batch']);
        add_action('wp_ajax_stbp_batch_revert',  [$this, 'handle_batch_revert']);
        add_action('wp_ajax_stbp_list_batches', [$this, 'list_batches']);
        add_action('wp_ajax_stbp_revert_posts', [$this, 'revert_posts']);
        add_action('wp_ajax_stbp_parent_batch_convert', [$this, 'handle_parent_batch']);
        
        // Admin-post handlers for CSV downloads
        add_action('admin_post_stbp_parent_dry_run_csv', [$this, 'download_parent_dry_run_csv']);
    }

    /**
     * Stable key for per-user, per-batch transient storage
     */
    private function get_report_store_key(string $batch_id): string {
        $u = get_current_user_id() ?: 0;
        return "stbp_dryrun_{$u}_{$batch_id}";
    }

    /**
     * Check whether the current user may edit a specific post through Pro tools.
     */
    private static function can_edit_target_post(int $post_id): bool {
        return $post_id > 0
            && current_user_can(Settings::required_capability())
            && current_user_can('edit_post', $post_id);
    }

    /**
     * Render the convert admin page.
     * Provides $types, $counts, $nonce, and $last_batch to the view.
     */
    public static function render_convert_page() {
        $types = \STB\admin\Admin::allowed_post_types();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page preselection from the URL.
        $pref  = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $counts = [];

        global $wpdb;
        foreach ($types as $t) {
            // VC-only total via _stbp_has_vc flag.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin count query for the convert screen.
            $total = (int) ($wpdb->get_var($wpdb->prepare("
              SELECT COUNT(DISTINCT p.ID)
              FROM {$wpdb->posts} p
              JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
              WHERE p.post_type = %s AND p.post_status = 'publish'
                AND m.meta_key = '_stbp_has_vc' AND m.meta_value = '1'
            ", $t)) ?: 0);

            $obj   = get_post_type_object($t);
            $label = $obj ? $obj->labels->name : $t;
            $counts[$t] = ['label' => $label, 'vc_total' => $total];
        }

        $nonce = wp_create_nonce('stbp_convert_nonce');
        $last_batch = get_option('stbp_last_batch'); // for "undo last batch" UI in the view

        include STBP_PATH . 'admin/views/convert.php';
    }
    public static function render_revert_page() {
        if (! current_user_can(Settings::required_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }
        // Nonce for AJAX
        $nonce = wp_create_nonce('stbp_convert_nonce');
        // Include view
        include STBP_PATH . 'admin/views/revert.php';
    }
    /**
     * Chunked batch convert handler (supports dry-run and real runs).
     * Expects: stbp_convert_nonce_field, post_types[], current_type, offset, per_page, dry_run, limit, batch_id
     */
    public function handle_batch() {
        if (! isset($_POST['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce', 'stbp_convert_nonce_field', false)) {
            wp_send_json_error('invalid or missing nonce', 403);
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_send_json_error('insufficient permissions', 403);
        }

        $allowed      = \STB\admin\Admin::allowed_post_types();
        $posted_types  = isset($_POST['post_types']) ? array_map('sanitize_key', (array) wp_unslash($_POST['post_types'])) : [];
        $selected      = array_values(array_intersect($posted_types, $allowed));
        if (empty($selected)) {
            wp_send_json_error('no post types selected', 400);
        }

        $dry_run = ! empty($_POST['dry_run']);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately via wp_unslash() and absint().
        $posted_per_page = isset($_POST['per_page']) ? wp_unslash($_POST['per_page']) : 20;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately via wp_unslash() and absint().
        $posted_offset   = isset($_POST['offset']) ? wp_unslash($_POST['offset']) : 0;
        $per_page        = max(5, min(200, absint($posted_per_page)));
        $offset          = absint($posted_offset);
        $type            = isset($_POST['current_type']) ? sanitize_key(wp_unslash($_POST['current_type'])) : $selected[0];

        // batch id bookkeeping
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
        if ($batch_id === '') {
            // generate when missing (first tick)
            if (function_exists('wp_generate_uuid4')) {
                $batch_id = wp_generate_uuid4();
            } else {
                $batch_id = uniqid('stbp_', true);
            }
        }

        /**
         * Per-batch state for limit + total processed (applies to dry-run and real runs)
         */
        $state_key = $this->get_report_store_key($batch_id) . '_state';
        $state     = get_transient($state_key);
        if (!is_array($state)) {
            $state = ['processed_total' => 0, 'limit' => null];
        }
        // On the first request, accept the 'limit' and persist it. 0 => convert all (no cap).
        if ($state['limit'] === null) {
            $limit_in       = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
            $state['limit'] = $limit_in > 0 ? $limit_in : 0;
        }
        $remaining = ($state['limit'] && $state['limit'] > 0)
            ? max(0, $state['limit'] - intval($state['processed_total']))
            : PHP_INT_MAX;

        // If we've already reached the cap, finish now. (Ensure we record last batch on real runs.)
        if ($remaining === 0) {
            if (! $dry_run) {
                $now = time();
                $last = [
                    'id'          => $batch_id,
                    'finished_at' => $now,
                    'types'       => $selected,
                    'limit'       => $state['limit'] ?? 0,
                    'processed'   => $state['processed_total'] ?? 0,
                ];
                update_option('stbp_last_batch', $last, false);
            }
            $finish_data = [
                /* translators: %d: number of items limit reached */
                'message'   => sprintf(__('Reached limit of %d items.', 'shortcode-to-blocks-pro'), $state['limit']),
                'processed' => 0,
                'done'      => true,
                'batch_id'  => $batch_id,
            ];
            if ($dry_run) {
                $report_key_cap = $this->get_report_store_key($batch_id);
                $report_cap = get_transient($report_key_cap) ?: [];
                $report_cap['finished_at'] = time();
                set_transient($report_key_cap, $report_cap, HOUR_IN_SECONDS);
                $finish_data['download_csv'] = wp_nonce_url(
                    admin_url('admin-post.php?action=stbp_dry_run_csv&batch_id='.$batch_id),
                    'stbp_convert_nonce',
                    'stbp_convert_nonce_field'
                );
                $finish_data['summary'] = $report_cap['by_type'] ?? [];
            }
            wp_send_json_success($finish_data);
        }

        $report_key = $this->get_report_store_key($batch_id);
        $report = $dry_run ? (get_transient($report_key) ?: [
            'started_at' => time(),
            'by_type'    => [],  // $type => ['would_change'=>0,'no_change'=>0,'errors'=>0]
            'posts'      => [],  // rows: ['post_id','title','permalink','type','would_change']
        ]) : null;

        // Query current type only, VC-flagged
        $q = new \WP_Query([
            'post_type'               => $type,
            'post_status'             => 'publish',
            // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional filtering on plugin-managed VC flag meta.
            'meta_key'                => '_stbp_has_vc',
            'meta_value'              => '1',
            // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'posts_per_page'          => min($per_page, $remaining), // clamp to avoid overshooting cap
            'offset'                  => $offset,
            'fields'                  => 'ids',
            'no_found_rows'           => true,
            'update_post_meta_cache'  => false,
            'update_post_term_cache'  => false,
        ]);

        if (empty($q->posts)) {
            // move to next selected type or finish
            $idx = array_search($type, $selected, true);
            if ($idx !== false && isset($selected[$idx+1])) {
                if ($dry_run) set_transient($report_key, $report, HOUR_IN_SECONDS);
                wp_send_json_success([
                    'message'     => "Finished type {$type}, moving to next",
                    'processed'   => 0,
                    'next_type'   => $selected[$idx+1],
                    'next_offset' => 0,
                    'batch_id'    => $batch_id,
                    'done'        => false,
                ]);
            } else {
                // finish
                if ($dry_run) {
                    $report['finished_at'] = time();
                    set_transient($report_key, $report, HOUR_IN_SECONDS);
                    // keep existing dry-run CSV route if your plugin already provides it elsewhere
                    $download_url = wp_nonce_url(
                        admin_url('admin-post.php?action=stbp_dry_run_csv&batch_id='.$batch_id),
                        'stbp_convert_nonce',
                        'stbp_convert_nonce_field'
                    );
                    wp_send_json_success([
                        'message'      => 'Dry run finished',
                        'download_csv' => $download_url,
                        'batch_id'     => $batch_id,
                        'summary'      => $report['by_type'],
                        'done'         => true,
                    ]);
                } else {
                    // record "last batch" for quick undo
                    $now = time();
                    $last = [
                        'id'          => $batch_id,
                        'finished_at' => $now,
                        'types'       => $selected,
                        'limit'       => $state['limit'] ?? 0,
                        'processed'   => $state['processed_total'] ?? 0,
                    ];
                    update_option('stbp_last_batch', $last, false);

                    Logger::log('batch', 'success', 'Batch conversion finished for: ' . implode(',', $selected));
                    wp_send_json_success([
                        'message'  => 'Batch conversion finished',
                        'batch_id' => $batch_id,
                        'done'     => true,
                    ]);
                }
            }
        }

        $processed = 0;
        $skipped   = 0;

        foreach ($q->posts as $pid) {
            if (! self::can_edit_target_post((int) $pid)) {
                $skipped++;
                if ($dry_run) {
                    $t =& $report['by_type'][$type];
                    if (! isset($t)) {
                        $t = ['would_change' => 0, 'no_change' => 0, 'errors' => 0];
                    }
                    $t['errors']++;
                } else {
                    Logger::log('batch', 'info', 'Skipped unauthorized post in batch conversion', $pid);
                }
                continue;
            }

            $post = get_post($pid);
            if (! $post) {
                if ($dry_run) {
                    $report['by_type'][$type]['errors'] = ($report['by_type'][$type]['errors'] ?? 0) + 1;
                } else {
                    Logger::log('batch', 'error', 'Post missing', $pid);
                }
                continue;
            }

            if ($dry_run) {
                $converter   = new \STBP\includes\ConverterPro();
                $new_content = $converter->convert_vc_shortcodes_recursive($post->post_content);

                $would_change = ($new_content !== $post->post_content);
                $processed++;

                // per-type tallies
                $t =& $report['by_type'][$type];
                if (!isset($t)) $t = ['would_change'=>0,'no_change'=>0,'errors'=>0];
                $t[$would_change ? 'would_change' : 'no_change']++;

                // per-post row
                $report['posts'][] = [
                    'post_id'      => $pid,
                    'title'        => get_the_title($pid),
                    'permalink'    => get_permalink($pid),
                    'type'         => $type,
                    'would_change' => $would_change ? 'yes' : 'no',
                ];

                continue;
            }

            // REAL conversion flow
            if (! get_post_meta($pid, '_stbp_original_content', true)) {
                update_post_meta($pid, '_stbp_original_content', $post->post_content);
                update_post_meta($pid, '_stbp_original_content_ts', time());
            }

            if (! class_exists('\STBP\includes\ConverterPro')) {
                require_once STBP_PATH . 'includes/ConverterPro.php';
            }
            $converter   = new \STBP\includes\ConverterPro();
            $new_content = $converter->convert_vc_shortcodes_recursive($post->post_content);

            if ($new_content !== $post->post_content) {
                // normalize invalid page templates
                $tpl = get_page_template_slug($pid); // e.g., 'templates/landing.php' or ''
                if ($tpl && 'default' !== $tpl) {
                    $post_type = get_post_type($pid);
                    $allowed   = wp_get_theme()->get_page_templates(null, $post_type); // [ 'templates/landing.php' => 'Landing' ]
                    if (! isset($allowed[$tpl])) {
                        update_post_meta($pid, '_wp_page_template', 'default');
                    }
                }

                $res = wp_update_post(['ID'=>$pid,'post_content'=>$new_content], true);
                if (! is_wp_error($res)) {
                    $processed++;
                    if (!empty($batch_id)) {
                        update_post_meta($pid, '_stbp_batch_id', sanitize_text_field($batch_id));
                    }
                    update_post_meta($pid, '_stbp_converted', 1);
                    update_post_meta($pid, '_stbp_converted_ts', time());
                    Logger::log('batch', 'success', 'Converted via batch', $pid);
                } else {
                    Logger::log('batch', 'error', 'Failed to update: '.$res->get_error_message(), $pid);
                }
            } else {
                if (! get_post_meta($pid, '_stbp_converted', true)) {
                    update_post_meta($pid, '_stbp_converted', 1);
                    update_post_meta($pid, '_stbp_converted_ts', time());
                }
                Logger::log('batch', 'success', 'No changes needed (already converted)', $pid);
            }
        }

        if ($dry_run) {
            set_transient($report_key, $report, HOUR_IN_SECONDS);
        } else {
            delete_transient('stbp_dash_counts');
        }

        // bump overall processed count and persist state for next tick
        $state['processed_total'] = intval($state['processed_total']) + intval($processed);
        set_transient($state_key, $state, HOUR_IN_SECONDS);
        $hit_cap = ($state['limit'] > 0) && ($state['processed_total'] >= $state['limit']);

        // NEW: record last batch if we stop because of the cap (real run only)
        if ($hit_cap && ! $dry_run) {
            $now = time();
            $last = [
                'id'          => $batch_id,
                'finished_at' => $now,
                'types'       => $selected,
                'limit'       => $state['limit'] ?? 0,
                'processed'   => $state['processed_total'] ?? 0,
            ];
            update_option('stbp_last_batch', $last, false);
        }

        $tick_data = [
            'message'     => $dry_run ? "Dry run processed {$processed}" : "Processed {$processed} posts",
            'processed'   => $processed,
            'skipped'     => $skipped,
            'next_type'   => $hit_cap ? null : $type,
            'next_offset' => $hit_cap ? null : ($offset + $per_page),
            'batch_id'    => $batch_id,
            'done'        => $hit_cap,
        ];
        if ($hit_cap && $dry_run) {
            $report['finished_at'] = time();
            set_transient($report_key, $report, HOUR_IN_SECONDS);
            $tick_data['download_csv'] = wp_nonce_url(
                admin_url('admin-post.php?action=stbp_dry_run_csv&batch_id='.$batch_id),
                'stbp_convert_nonce',
                'stbp_convert_nonce_field'
            );
            $tick_data['summary'] = $report['by_type'] ?? [];
        }
        wp_send_json_success($tick_data);
    }

    /**
     * Chunked batch revert handler.
     * Expects: stbp_convert_nonce_field, (batch_id OR converted_after), per_page, offset
     */
    public function handle_batch_revert() {
        if (! isset($_POST['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce', 'stbp_convert_nonce_field', false)) {
            wp_send_json_error('invalid or missing nonce', 403);
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_send_json_error('insufficient permissions', 403);
        }

        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately via wp_unslash() and absint().
        $posted_per_page = isset($_POST['per_page']) ? wp_unslash($_POST['per_page']) : 20;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately via wp_unslash() and absint().
        $posted_offset   = isset($_POST['offset']) ? wp_unslash($_POST['offset']) : 0;
        $per_page        = max(5, min(200, absint($posted_per_page)));
        $offset          = absint($posted_offset);

        // allow scoped reverts by batch id *or* by "converted after" timestamp
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately via wp_unslash() and absint().
        $posted_after_ts = isset($_POST['converted_after']) ? wp_unslash($_POST['converted_after']) : 0;
        $after_ts        = absint($posted_after_ts);

        if ($batch_id === '' && $after_ts <= 0) {
            wp_send_json_error('missing scope: provide batch_id or converted_after', 400);
        }

        $meta_query = [
            'relation' => 'AND',
            [ 'key' => '_stbp_original_content', 'compare' => 'EXISTS' ],   // only posts that still have a backup
            [ 'key' => '_stbp_converted', 'value' => '1' ],
        ];
        if ($batch_id !== '') {
            $meta_query[] = [ 'key' => '_stbp_batch_id', 'value' => $batch_id ];
        }
        if ($after_ts > 0) {
            $meta_query[] = [ 'key' => '_stbp_converted_ts', 'value' => $after_ts, 'compare' => '>=', 'type' => 'NUMERIC' ];
        }

        $q = new \WP_Query([
            'post_type'               => \STB\admin\Admin::allowed_post_types(),
            'post_status'             => ['publish','draft','pending','private','future'],
            'fields'                  => 'ids',
            'no_found_rows'           => true,
            'posts_per_page'          => $per_page,
            'offset'                  => $offset,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional revert filtering on plugin-managed backup/conversion meta.
            'meta_query'              => $meta_query,
            'update_post_meta_cache'  => false,
            'update_post_term_cache'  => false,
        ]);

        $processed = 0;
        $skipped = 0;
        foreach ($q->posts as $pid) {
            if (! self::can_edit_target_post((int) $pid)) {
                $skipped++;
                Logger::log('batch-revert', 'info', 'Skipped unauthorized post in batch revert', $pid);
                continue;
            }

            $orig = get_post_meta($pid, '_stbp_original_content', true);
            if ($orig === '' || $orig === null) { 
                // Post was already reverted individually
                $skipped++;
                Logger::log('batch-revert', 'info', 'Post already reverted individually', $pid);
                continue; 
            }
            $res = wp_update_post(['ID' => $pid, 'post_content' => $orig], true);
            if (!is_wp_error($res)) {
                // cleanup
                delete_post_meta($pid, '_stbp_original_content');
                delete_post_meta($pid, '_stbp_original_content_ts');
                delete_post_meta($pid, '_stbp_converted');
                delete_post_meta($pid, '_stbp_converted_ts');
                delete_post_meta($pid, '_stbp_batch_id');
                update_post_meta($pid, '_stbp_has_vc', '1');

                Logger::log('batch-revert', 'success', 'Reverted via batch', $pid);
                $processed++;
            } else {
                Logger::log('batch-revert', 'error', 'Failed revert: '.$res->get_error_message(), $pid);
            }
        }

        wp_send_json_success([
            'processed'   => $processed,
            'skipped'     => $skipped,
            'next_offset' => $offset + $per_page,
            'batch_id'    => $batch_id,
            'done'        => count($q->posts) < $per_page,
        ]);
    }
    public function list_batches() {
        if (! isset($_POST['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce', 'stbp_convert_nonce_field', false)) {
            wp_send_json_error('invalid or missing nonce', 403);
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_send_json_error('insufficient permissions', 403);
        }

        global $wpdb;
        // List up to N recent batches (by latest converted_ts), with post counts
        $limit = 50; // reasonable cap
        // Join postmeta twice: once for batch id, once to read converted_ts for ordering
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin batch listing query for revert tools.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT b.meta_value AS batch_id,
                    COUNT(*)     AS post_count,
                    MAX(CASE WHEN t.meta_key = '_stbp_converted_ts' THEN CAST(t.meta_value AS UNSIGNED) ELSE 0 END) AS last_ts
                FROM {$wpdb->postmeta} b
                LEFT JOIN {$wpdb->postmeta} t
                    ON t.post_id = b.post_id AND t.meta_key = '_stbp_converted_ts'
                WHERE b.meta_key = '_stbp_batch_id'
                GROUP BY b.meta_value
                ORDER BY last_ts DESC
                LIMIT %d
                ",
                $limit
            ),
            ARRAY_A
        );

        $out = array_map(function($r){
            return [
                'batch_id'   => (string) $r['batch_id'],
                'post_count' => (int)    $r['post_count'],
                'last_ts'    => (int)    $r['last_ts'],
            ];
        }, $rows ?: []);

        wp_send_json_success(['batches' => $out]);
    }
    public function revert_posts() {
        check_ajax_referer('stbp_convert_nonce', 'stbp_convert_nonce_field');
        if (! current_user_can(Settings::required_capability())) {
            wp_send_json_error('insufficient permissions', 403);
        }

        $ids = isset($_POST['ids']) ? array_map('intval', (array) wp_unslash($_POST['ids'])) : [];
        $ids = array_values(array_filter($ids));
        if (empty($ids)) {
            wp_send_json_error('no posts selected', 400);
        }

        $ok = 0; $fail = 0; $errors = [];
        foreach ($ids as $post_id) {
            $res = $this->revert_single_post($post_id);
            if ($res === true) {
                $ok++;
            } elseif (is_wp_error($res)) {
                $fail++; $errors[] = $res->get_error_message();
            } else {
                $fail++;
            }
        }

        if ($fail > 0 && $ok === 0) {
            wp_send_json_error( implode('; ', array_unique($errors)) ?: 'revert failed' );
        }
        wp_send_json_success(['ok' => $ok, 'failed' => $fail]);
    }

    /**
     * Helper: revert one post.
     */
    private function revert_single_post(int $post_id) {
        if (! self::can_edit_target_post($post_id)) {
            return new \WP_Error('forbidden', __('Insufficient permissions to edit this post.', 'shortcode-to-blocks-pro'));
        }

        $backup = get_post_meta($post_id, '_stbp_original_content', true);
        if ($backup === '' || $backup === null) {
            /* translators: %d: post ID */
            return new \WP_Error('no_backup', sprintf(__('No backup found for post ID %d','shortcode-to-blocks-pro'), $post_id));
        }

        $r = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $backup,
        ], true);
        if (is_wp_error($r)) {
            return $r;
        }

        delete_post_meta($post_id, '_stbp_converted');
        delete_post_meta($post_id, '_stbp_converted_ts');
        delete_post_meta($post_id, '_stbp_original_content');
        delete_post_meta($post_id, '_stbp_original_content_ts');
        delete_post_meta($post_id, '_stbp_batch_id');

        // set VC flag so it’s eligible for conversion again
        update_post_meta($post_id, '_stbp_has_vc', '1');

        Logger::log('revert', 'success', sprintf('Reverted post ID %d from backup', $post_id), $post_id);
        return true;
    }

    /**
     * Parent batch conversion handler.
     * Converts a parent post and all its descendants in one batch.
     * Expects: stbp_convert_nonce_field, parent_type, parent_id, batch_id
     */
    public function handle_parent_batch() {
        if (! isset($_POST['stbp_convert_nonce_field']) || ! check_ajax_referer('stbp_convert_nonce', 'stbp_convert_nonce_field', false)) {
            wp_send_json_error('invalid or missing nonce', 403);
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_send_json_error('insufficient permissions', 403);
        }

        $parent_type = isset($_POST['parent_type']) ? sanitize_key(wp_unslash($_POST['parent_type'])) : '';
        $parent_id   = isset($_POST['parent_id']) ? intval(wp_unslash($_POST['parent_id'])) : 0;
        
        if (empty($parent_type) || $parent_id <= 0) {
            wp_send_json_error('Parent type and ID are required', 400);
        }

        // Verify parent post exists and is of correct type
        $parent_post = get_post($parent_id);
        if (!$parent_post || $parent_post->post_type !== $parent_type) {
            wp_send_json_error('Invalid parent post or type mismatch', 400);
        }
        if (! self::can_edit_target_post($parent_id)) {
            wp_send_json_error('insufficient permissions for parent post', 403);
        }

        // Verify post type is allowed
        $allowed = \STB\admin\Admin::allowed_post_types();
        if (!in_array($parent_type, $allowed, true)) {
            wp_send_json_error('Post type not allowed for conversion', 400);
        }

        // Generate batch ID
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
        if ($batch_id === '') {
            if (function_exists('wp_generate_uuid4')) {
                $batch_id = wp_generate_uuid4();
            } else {
                $batch_id = uniqid('stbp_parent_', true);
            }
        }

        // Get all descendants (children, grandchildren, etc.)
        $post_ids = $this->get_post_descendants($parent_id, $parent_type);
        
        // Include the parent itself
        array_unshift($post_ids, $parent_id);
        
        // Filter to only editable posts that have VC content
        $vc_post_ids            = [];
        $skipped_unauthorized   = 0;
        foreach ($post_ids as $post_id) {
            if (! self::can_edit_target_post((int) $post_id)) {
                $skipped_unauthorized++;
                continue;
            }
            if (get_post_meta($post_id, '_stbp_has_vc', true) === '1') {
                $vc_post_ids[] = $post_id;
            }
        }

        if (empty($vc_post_ids)) {
            wp_send_json_error('No editable posts with WPBakery content found in this tree', 400);
        }

        $dry_run = !empty($_POST['dry_run']);
        $processed = 0;
        $errors = [];
        $dry_run_report = [];

        if ($dry_run) {
            // Store dry run report for CSV download
            $report_key = $this->get_report_store_key($batch_id);
            $report = [
                'started_at' => time(),
                'parent_id'  => $parent_id,
                'parent_title' => get_the_title($parent_id),
                'posts'      => [],
                'summary'    => ['would_change' => 0, 'no_change' => 0, 'errors' => 0]
            ];
        }

        foreach ($vc_post_ids as $post_id) {
            if ($dry_run) {
                // Dry run logic - check what would change without making changes
                $post = get_post($post_id);
                if (! $post) {
                    $errors[] = sprintf('Post ID %d not found', $post_id);
                    $report['summary']['errors']++;
                    continue;
                }

                if (! class_exists('\STBP\includes\ConverterPro')) {
                    require_once STBP_PATH . 'includes/ConverterPro.php';
                }
                $converter = new \STBP\includes\ConverterPro();
                $new_content = $converter->convert_vc_shortcodes_recursive($post->post_content);

                $would_change = ($new_content !== $post->post_content);
                $processed++;

                $report['summary'][$would_change ? 'would_change' : 'no_change']++;
                $report['posts'][] = [
                    'post_id'     => $post_id,
                    'title'       => get_the_title($post_id),
                    'permalink'   => get_permalink($post_id),
                    'type'        => $post->post_type,
                    'would_change' => $would_change ? 'yes' : 'no',
                    'depth'       => $this->get_post_depth($post_id, $parent_id),
                ];
            } else {
                // Real conversion
                $result = self::convert_post($post_id, $batch_id);
                if ($result === true) {
                    $processed++;
                } else {
                    $post_title = get_the_title($post_id);
                    $error_msg = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                    $errors[] = sprintf('Post "%s" (ID: %d): %s', $post_title, $post_id, $error_msg);
                }
            }
        }

        if ($dry_run) {
            $report['finished_at'] = time();
            set_transient($report_key, $report, HOUR_IN_SECONDS);
            
            // Generate CSV download URL
            $download_url = wp_nonce_url(
                admin_url('admin-post.php?action=stbp_parent_dry_run_csv&batch_id='.$batch_id),
                'stbp_convert_nonce',
                'stbp_convert_nonce_field'
            );
            
            $message = sprintf(
                'Dry run completed for parent tree (Parent: %s). Found %d posts with WPBakery content.', 
                get_the_title($parent_id),
                count($vc_post_ids)
            );

            Logger::log('batch', 'success', sprintf('Parent batch dry run completed. Parent ID: %d, Found: %d', $parent_id, count($vc_post_ids)));

            wp_send_json_success([
                'message'      => $message,
                'processed'    => $processed,
                'total_found'  => count($vc_post_ids),
                'skipped'      => $skipped_unauthorized,
                'batch_id'     => $batch_id,
                'parent_id'    => $parent_id,
                'errors'       => $errors,
                'done'         => true,
                'dry_run'      => true,
                'download_csv' => $download_url,
                'summary'      => $report['summary'],
            ]);
        } else {
            // Record batch for potential revert (real conversion only)
            $now = time();
            $last = [
                'id'          => $batch_id,
                'finished_at' => $now,
                'types'       => [$parent_type],
                'limit'       => 0,
                'processed'   => $processed,
                'parent_id'   => $parent_id,
                'tree_size'   => count($vc_post_ids),
            ];
            update_option('stbp_last_batch', $last, false);

            delete_transient('stbp_dash_counts');

            $message = sprintf(
                'Converted %d of %d posts in parent tree (Parent: %s)', 
                $processed, 
                count($vc_post_ids),
                get_the_title($parent_id)
            );

            if (!empty($errors)) {
                $message .= '. Errors: ' . implode('; ', array_slice($errors, 0, 3));
                if (count($errors) > 3) {
                    $message .= sprintf(' and %d more...', count($errors) - 3);
                }
            }

            Logger::log('batch', 'success', sprintf('Parent batch conversion completed. Parent ID: %d, Processed: %d', $parent_id, $processed));

            wp_send_json_success([
                'message'     => $message,
                'processed'   => $processed,
                'total_found' => count($vc_post_ids),
                'skipped'     => $skipped_unauthorized,
                'batch_id'    => $batch_id,
                'parent_id'   => $parent_id,
                'errors'      => $errors,
                'done'        => true,
                'dry_run'     => false,
            ]);
        }
    }

    /**
     * Get all descendant post IDs for a given parent post.
     * Returns array of post IDs including all children, grandchildren, etc.
     */
    private function get_post_descendants($parent_id, $post_type) {
        $descendants = [];
        
        // Get direct children
        $children = get_children([
            'post_parent' => $parent_id,
            'post_type'   => $post_type,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        foreach ($children as $child_id) {
            $descendants[] = $child_id;
            // Recursively get descendants of this child
            $grandchildren = $this->get_post_descendants($child_id, $post_type);
            $descendants = array_merge($descendants, $grandchildren);
        }

        return array_unique($descendants);
    }

    /**
     * Get the depth of a post relative to a parent post.
     * Returns 0 for the parent itself, 1 for direct children, 2 for grandchildren, etc.
     */
    private function get_post_depth($post_id, $parent_id) {
        if ($post_id == $parent_id) {
            return 0;
        }

        $depth = 0;
        $current_post = get_post($post_id);
        
        while ($current_post && $current_post->post_parent != 0) {
            $depth++;
            if ($current_post->post_parent == $parent_id) {
                return $depth;
            }
            $current_post = get_post($current_post->post_parent);
            
            // Prevent infinite loops
            if ($depth > 50) {
                break;
            }
        }
        
        return $depth;
    }

    /**
     * Handle CSV download for parent batch dry run results.
     */
    public function download_parent_dry_run_csv() {
        if (! isset($_GET['stbp_convert_nonce_field']) || ! check_admin_referer('stbp_convert_nonce', 'stbp_convert_nonce_field')) {
            wp_die(esc_html__('Invalid nonce', 'shortcode-to-blocks-pro'), 403);
        }
        if (! current_user_can(Settings::required_capability())) {
            wp_die(esc_html__('Insufficient permissions', 'shortcode-to-blocks-pro'), 403);
        }

        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field(wp_unslash($_GET['batch_id'])) : '';
        if (empty($batch_id)) {
            wp_die(esc_html__('Missing batch ID', 'shortcode-to-blocks-pro'), 400);
        }

        $report_key = $this->get_report_store_key($batch_id);
        $report = get_transient($report_key);
        
        if (!$report || !is_array($report)) {
            wp_die(esc_html__('Dry run report not found or expired. Please run the dry run again.', 'shortcode-to-blocks-pro'), 404);
        }

        // Generate CSV content
        $filename = 'stbp-parent-dryrun-' . $batch_id . '-' . gmdate('Y-m-d-H-i-s') . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add BOM for proper UTF-8 encoding in Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // CSV header row
        fputcsv($output, [
            'Post ID',
            'Title', 
            'Post Type',
            'Depth',
            'Would Change',
            'Permalink',
            'Date'
        ]);

        // Add summary info as comments
        if (isset($report['summary'])) {
            $s = $report['summary'];
            fputcsv($output, ['# SUMMARY']);
            fputcsv($output, ['# Parent:', $report['parent_title'] ?? 'Unknown']);
            fputcsv($output, ['# Parent ID:', $report['parent_id'] ?? 'Unknown']);
            fputcsv($output, ['# Would Change:', $s['would_change'] ?? 0]);
            fputcsv($output, ['# No Change:', $s['no_change'] ?? 0]);
            fputcsv($output, ['# Errors:', $s['errors'] ?? 0]);
            fputcsv($output, ['# Total Posts:', ($s['would_change'] ?? 0) + ($s['no_change'] ?? 0)]);
            fputcsv($output, ['']);
        }

        // Add post data
        if (isset($report['posts']) && is_array($report['posts'])) {
            foreach ($report['posts'] as $post_data) {
                $depth_indicator = str_repeat('→ ', $post_data['depth'] ?? 0);
                fputcsv($output, [
                    $post_data['post_id'] ?? '',
                    $depth_indicator . ($post_data['title'] ?? ''),
                    $post_data['type'] ?? '',
                    $post_data['depth'] ?? 0,
                    $post_data['would_change'] ?? 'no',
                    $post_data['permalink'] ?? '',
                    gmdate('Y-m-d H:i:s', (int) ($report['started_at'] ?? time()))
                ]);
            }
        }

        exit;
    }
}
