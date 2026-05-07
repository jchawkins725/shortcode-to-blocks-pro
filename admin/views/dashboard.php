<?php
/**
 * Dashboard view (shortcode content stats)
 */
use STBC\admin\Admin;
use STBP\admin\Settings;
use STBP\includes\Logger;

defined('ABSPATH') || exit;
global $wpdb;

/** Dashboard data */
$stbp_dashboard = get_transient('stbp_dash_counts');
if (false === $stbp_dashboard || ! is_array($stbp_dashboard) || ! array_key_exists('has_scanned', $stbp_dashboard)) {
    $stbp_dashboard = (static function () use ($wpdb): array {
        $types               = Admin::allowed_post_types();
        $counts              = [];
        $total_converted_all = 0;
        $total_all           = 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cached admin dashboard queries for plugin stats and logs.
        foreach ($types as $post_type) {
            $post_type_obj = get_post_type_object($post_type);
            $label         = $post_type_obj ? $post_type_obj->labels->name : $post_type;

            // Only VC posts (flagged).
            $total = (int) ($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} m1
                  ON m1.post_id = p.ID
                AND m1.meta_key = '_stbp_has_vc'
                AND m1.meta_value = '1'
                LEFT JOIN {$wpdb->postmeta} m2
                  ON m2.post_id = p.ID
                AND m2.meta_key = '_stbp_original_content'
                WHERE p.post_type = %s
                  AND p.post_status = 'publish'
                  AND (m1.post_id IS NOT NULL OR m2.post_id IS NOT NULL)
            ", $post_type)) ?: 0);

            // Converted VC posts = have a backup.
            $converted = (int) ($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} m2
                  ON m2.post_id = p.ID
                AND m2.meta_key = '_stbp_original_content'
                WHERE p.post_type = %s
                  AND p.post_status = 'publish'
            ", $post_type)) ?: 0);

            $percent = ($total > 0) ? round(($converted / $total) * 100) : 0;

            $counts[$post_type] = [
                'label'     => $label,
                'total'     => $total,
                'converted' => $converted,
                'percent'   => $percent,
            ];
            $total_converted_all += $converted;
            $total_all           += $total;
        }

        $opts          = Settings::get();
        $ttl           = (int) ($opts['backup_ttl_days'] ?? 30);
        $cutoff        = time() - ($ttl * DAY_IN_SECONDS);
        $backups_total = (int) ($wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_stbp_original_content'
        ") ?: 0);
        $backups_old   = (int) ($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_stbp_original_content_ts' AND meta_value <> '' AND CAST(meta_value AS UNSIGNED) < %d
        ", $cutoff)) ?: 0);

        $logs_table        = esc_sql($wpdb->prefix . 'stbp_logs');
        $logs_table_exists = Logger::table_exists();
        $recent_logs       = [];
        $last_convert      = null;
        $recent_errors_7d  = 0;
        $has_scanned       = false;

        if ($logs_table_exists) {
            $recent_logs = $wpdb->get_results("SELECT * FROM `{$logs_table}` ORDER BY created_at DESC LIMIT 8", ARRAY_A);
            $last_convert = $wpdb->get_var("SELECT created_at FROM `{$logs_table}` WHERE action='convert' AND status='success' ORDER BY created_at DESC LIMIT 1");
            $recent_errors_7d = (int) ($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM `{$logs_table}` WHERE status='error' AND created_at >= %s
            ", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))) ?: 0);
            $has_scanned = (bool) ($wpdb->get_var("
                SELECT COUNT(*) FROM `{$logs_table}`
                WHERE action = 'scan' AND status = 'success'
                LIMIT 1
            ") ?: 0);
        }

        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return [
            'types'             => $types,
            'counts'            => $counts,
            'totalConvertedAll' => $total_converted_all,
            'totalAll'          => $total_all,
            'opts'              => $opts,
            'ttl'               => $ttl,
            'backups_total'     => $backups_total,
            'backups_old'       => $backups_old,
            'logs_table_exists' => $logs_table_exists,
            'recent_logs'       => $recent_logs,
            'last_convert'      => $last_convert,
            'recent_errors_7d'  => $recent_errors_7d,
            'has_scanned'       => $has_scanned,
        ];
    })();

    set_transient('stbp_dash_counts', $stbp_dashboard, 15 * MINUTE_IN_SECONDS);
}

$stbp_types             = is_array($stbp_dashboard['types'] ?? null) ? $stbp_dashboard['types'] : [];
$stbp_counts            = is_array($stbp_dashboard['counts'] ?? null) ? $stbp_dashboard['counts'] : [];
$stbp_total_converted   = (int) ($stbp_dashboard['totalConvertedAll'] ?? 0);
$stbp_total_all         = (int) ($stbp_dashboard['totalAll'] ?? 0);
$stbp_opts              = is_array($stbp_dashboard['opts'] ?? null) ? $stbp_dashboard['opts'] : [];
$stbp_ttl               = (int) ($stbp_dashboard['ttl'] ?? 30);
$stbp_backups_total     = (int) ($stbp_dashboard['backups_total'] ?? 0);
$stbp_backups_old       = (int) ($stbp_dashboard['backups_old'] ?? 0);
$stbp_logs_table_exists = ! empty($stbp_dashboard['logs_table_exists']);
$stbp_recent_logs       = is_array($stbp_dashboard['recent_logs'] ?? null) ? $stbp_dashboard['recent_logs'] : [];
$stbp_last_convert      = $stbp_dashboard['last_convert'] ?? null;
$stbp_recent_errors_7d  = (int) ($stbp_dashboard['recent_errors_7d'] ?? 0);
$stbp_has_scanned       = ! empty($stbp_dashboard['has_scanned']);

/** Quick links */
$stbp_purge_backups_url = wp_nonce_url(admin_url('admin-ajax.php?action=stbp_purge_backups'), 'stbp_convert_nonce', 'stbp_convert_nonce_field');
$stbp_purge_logs_url    = wp_nonce_url(admin_url('admin-ajax.php?action=stbp_purge_logs'), 'stbp_convert_nonce', 'stbp_convert_nonce_field');
$stbp_export_logs_url   = wp_nonce_url(admin_url('admin-post.php?action=stbp_export_logs'), 'stbp_convert_nonce', 'stbp_convert_nonce_field');
$stbp_base_slug         = defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks';
$stbp_convert_page      = admin_url('admin.php?page=' . $stbp_base_slug . '-convert');
$stbp_settings_page     = admin_url('admin.php?page=' . $stbp_base_slug . '-settings');
$stbp_logs_page         = admin_url('admin.php?page=' . $stbp_base_slug . '-logs');
$stbp_scan_url          = wp_nonce_url(
    admin_url('admin-ajax.php?action=stbp_scan_vc&json=1'),
    'stbp_convert_nonce',
    'stbp_convert_nonce_field'
);

?>
<style>
  .stbp-grid{display:grid;grid-template-columns:1fr;gap:16px}
  @media (min-width:1100px){.stbp-grid{grid-template-columns:2fr 1fr}}
  .stbp-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px}
  .stbp-col-left .stbp-card,.stbp-col-right .stbp-card{margin-bottom:16px}
  .stbp-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .stbp-kpi{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;text-align:center}
  .stbp-kpi .num{font-size:20px;font-weight:600}
  .stbp-progress{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin-top:6px}
  .stbp-progress>span{display:block;height:100%;background:#007cba}
  .stbp-type-row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:10px 0}
  .stbp-type-actions a{margin-left:6px}
  .stbp-muted{color:#646970}
  .stbp-table{width:100%}
  .stbp-table th,.stbp-table td{padding:8px}
</style>

<div class="wrap">
  <h1><?php esc_html_e('Shortcode to Blocks','shortcode-to-blocks-pro'); ?></h1>
  <?php
  $stbp_all_zero = true;
  if (! empty($stbp_counts)) {
    foreach ($stbp_counts as $stbp_count) {
      if ((int) $stbp_count['total'] > 0) {
        $stbp_all_zero = false;
        break;
      }
    }
  }
  if ($stbp_all_zero): ?>
    <div class="notice notice-info">
      <p>
        <?php esc_html_e('It looks like we haven’t detected any WPBakery content yet. Start by scanning your selected post types.','shortcode-to-blocks-pro'); ?>
        <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-ajax.php?action=stbp_scan_vc'), 'stbp_convert_nonce','stbp_convert_nonce_field') ); ?>">
          <?php esc_html_e('Scan for WPBakery content','shortcode-to-blocks-pro'); ?>
        </a>
      </p>
    </div>
  <?php endif; ?>

  <?php if (empty($stbp_types)) : ?>
    <div class="notice notice-warning"><p><?php esc_html_e('No post types selected. Choose them in Settings.','shortcode-to-blocks-pro'); ?></p></div>
  <?php endif; ?>

  <?php
  $stbp_dismiss_key   = 'stbp_dismiss_old_backups';
  $stbp_dismiss_count = (int) get_user_meta(get_current_user_id(), $stbp_dismiss_key, true);
  if ($stbp_backups_old > 0 && $stbp_dismiss_count !== $stbp_backups_old) : ?>
    <div class="notice notice-info is-dismissible" id="stbp-old-backups-notice">
      <p>
      <?php // translators: 1: Number of old backups, 2: Number of days ?>
      <?php printf(esc_html__('You have %1$d backups older than %2$d days. Consider purging old backups.','shortcode-to-blocks-pro'), (int) $stbp_backups_old, (int) $stbp_ttl); ?>
      <a class="button button-secondary" href="<?php echo esc_url($stbp_purge_backups_url); ?>"><?php esc_html_e('Purge old backups','shortcode-to-blocks-pro'); ?></a></p>
    </div>
    <script>
    jQuery(function($){
      $('#stbp-old-backups-notice').on('click','.notice-dismiss',function(){
        $.post(ajaxurl,{action:'stbp_dismiss_old_backups',count:<?php echo (int) $stbp_backups_old; ?>,_wpnonce:'<?php echo esc_attr(wp_create_nonce('stbp_dismiss_old_backups')); ?>'});
      });
    });
    </script>
  <?php endif; ?>

  <?php if ($stbp_logs_table_exists && $stbp_recent_errors_7d > 0) : ?>
    <div class="notice notice-error">
      <p>
      <?php // translators: %d: Number of errors in the last 7 days ?>
      <?php printf(esc_html__('%d errors recorded in the last 7 days. Review Logs.','shortcode-to-blocks-pro'), (int) $stbp_recent_errors_7d); ?>
      <a class="button" href="<?php echo esc_url($stbp_logs_page); ?>"><?php esc_html_e('Open Logs','shortcode-to-blocks-pro'); ?></a></p>
    </div>
  <?php endif; ?>

  <div class="stbp-grid">
    <div class="stbp-col-left">
    <?php if (! $stbp_has_scanned): ?>
      <div class="stbp-card" style="border-left:4px solid #007cba;">
        <h2><?php esc_html_e('Start here','shortcode-to-blocks-pro'); ?></h2>
        <p class="stbp-muted" style="margin-top:0">
          <?php esc_html_e('Before converting, scan your site to detect all WPBakery content.','shortcode-to-blocks-pro'); ?>
        </p>
        <p>
          <button id="stbp-scan-now" class="button button-primary">
            <?php esc_html_e('Run detection scan','shortcode-to-blocks-pro'); ?>
          </button>
          <span id="stbp-scan-status" class="stbp-muted" style="margin-left:8px;"></span>
        </p>
      </div>
    <?php endif; ?>
      <div class="stbp-card">
        <h2><?php esc_html_e('Overview','shortcode-to-blocks-pro'); ?></h2>
          <p class="stbp-muted stbp-small" style="margin-top:0;margin-bottom:12px">
            <?php esc_html_e('Only posts containing WPBakery shortcodes will be shown after scan.', 'shortcode-to-blocks-pro'); ?>
          </p>
        <div class="stbp-kpis" style="margin-bottom:12px">
          <div class="stbp-kpi">
            <div class="label stbp-muted"><?php esc_html_e('Selected post types','shortcode-to-blocks-pro'); ?></div>
            <div class="num"><?php echo count($stbp_types); ?></div>
          </div>
          <div class="stbp-kpi">
            <div class="label stbp-muted"><?php esc_html_e('Posts with backups','shortcode-to-blocks-pro'); ?></div>
            <div class="num"><?php echo (int) $stbp_backups_total; ?></div>
          </div>
          <div class="stbp-kpi">
            <div class="label stbp-muted"><?php esc_html_e('Last conversion','shortcode-to-blocks-pro'); ?></div>
            <div class="num">
              <?php
                echo $stbp_last_convert
                  ? esc_html( \STBC\core\Helpers::format_admin_datetime($stbp_last_convert) )
                  : '—';
              ?>
            </div>          
          </div>
        </div>

        <?php foreach ($stbp_counts as $stbp_type => $stbp_info): ?>
          <div class="stbp-type-row">
            <div style="flex:1 1 auto">
              <strong><?php echo esc_html($stbp_info['label']); ?></strong>
              <span class="stbp-muted">• <?php echo (int) $stbp_info['converted']; ?>/<?php echo (int) $stbp_info['total']; ?> (<?php echo (int) $stbp_info['percent']; ?>%)</span>
              <div class="stbp-progress" aria-hidden="true">
                <span style="width:<?php echo (int) $stbp_info['percent']; ?>%"></span>
              </div>
            </div>
            <div class="stbp-type-actions" style="white-space:nowrap">
              <a class="button button-primary" href="<?php echo esc_url( add_query_arg(['type' => $stbp_type], $stbp_convert_page) ); ?>">
                <?php esc_html_e('Convert this type','shortcode-to-blocks-pro'); ?>
              </a>
              <a class="button" href="<?php echo esc_url($stbp_settings_page); ?>">
                <?php esc_html_e('Settings','shortcode-to-blocks-pro'); ?>
              </a>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($stbp_total_all > 0): ?>
          <hr />
          <p class="stbp-muted" style="margin:0">
            <?php
            $stbp_overall = round(($stbp_total_converted / max(1, $stbp_total_all)) * 100);
            /* translators: 1: Number converted, 2: Total, 3: Percent converted */
            printf(esc_html__('Overall: %1$d of %2$d converted (%3$d%%).','shortcode-to-blocks-pro'),
              (int) $stbp_total_converted, (int) $stbp_total_all, (int) $stbp_overall);
            ?>
          </p>
          <p class="stbp-muted" style="margin:8px 0 0">
            <?php esc_html_e('Totals include only posts that contain WPBakery shortcodes (detected & cached).', 'shortcode-to-blocks-pro'); ?>
          </p>
        <?php endif; ?>
      </div>

      <div class="stbp-card">
        <h2><?php esc_html_e('Recent activity','shortcode-to-blocks-pro'); ?></h2>
        <?php if (! $stbp_logs_table_exists): ?>
          <div class="notice notice-warning">
            <p><?php esc_html_e('The logs table is missing. Click below to create it.','shortcode-to-blocks-pro'); ?></p>
            <p>
              <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-ajax.php?action=stbp_install_logs'), 'stbp_convert_nonce','stbp_convert_nonce_field') ); ?>">
                <?php esc_html_e('Create logs table','shortcode-to-blocks-pro'); ?>
              </a>
            </p>
          </div>
        <?php elseif (empty($stbp_recent_logs)): ?>
          <p class="stbp-muted">—</p>
        <?php else: ?>
          <table class="widefat striped stbp-table">
            <thead><tr>
              <th><?php esc_html_e('Time','shortcode-to-blocks-pro'); ?></th>
              <th><?php esc_html_e('Action','shortcode-to-blocks-pro'); ?></th>
              <th><?php esc_html_e('Status','shortcode-to-blocks-pro'); ?></th>
              <th><?php esc_html_e('Post','shortcode-to-blocks-pro'); ?></th>
              <th><?php esc_html_e('User','shortcode-to-blocks-pro'); ?></th>
            </tr></thead>
            <tbody>
              <?php foreach ($stbp_recent_logs as $stbp_log_row): ?>
                <tr>
                  <td><?php echo esc_html( \STBC\core\Helpers::format_admin_datetime($stbp_log_row['created_at']) ); ?></td>
                  <td><?php echo esc_html($stbp_log_row['action']); ?></td>
                  <td><?php echo esc_html($stbp_log_row['status']); ?></td>
                  <td>
                    <?php
                      if (! empty($stbp_log_row['post_id'])) {
                            $stbp_post_id = (int) $stbp_log_row['post_id'];
                            $stbp_link = get_edit_post_link($stbp_post_id);
                            echo $stbp_link
                              ? '<a href="' . esc_url($stbp_link) . '">' . esc_html($stbp_post_id) . '</a>'
                              : esc_html((string) $stbp_post_id);
                      } else {
                          echo '—';
                      }
                    ?>
                  </td>
                  <td>
                    <?php
                      if (! empty($stbp_log_row['user_id'])) {
                          $stbp_user = get_userdata((int) $stbp_log_row['user_id']);
                          echo $stbp_user ? esc_html($stbp_user->display_name) : '—';
                      } else {
                          echo '—';
                      }
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p><a class="button" href="<?php echo esc_url($stbp_logs_page); ?>"><?php esc_html_e('View all logs','shortcode-to-blocks-pro'); ?></a>
             <a class="button" href="<?php echo esc_url($stbp_export_logs_url); ?>"><?php esc_html_e('Download CSV','shortcode-to-blocks-pro'); ?></a>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="stbp-col-right">
      <div class="stbp-card">
        <h2><?php esc_html_e('Quick actions','shortcode-to-blocks-pro'); ?></h2>
        <p>
          <a class="button button-primary" href="<?php echo esc_url($stbp_convert_page); ?>">
            <?php esc_html_e('Open Convert screen','shortcode-to-blocks-pro'); ?>
          </a>
        </p>
        <p>
          <a id="stbp-scan-now-qa" class="button">
            <?php esc_html_e('Run detection scan','shortcode-to-blocks-pro'); ?>
          </a>
          <span id="stbp-scan-status-qa" class="stbp-muted" style="margin-left:6px;"></span>
        </p>
      </div>

      <div class="stbp-card">
        <h2><?php esc_html_e('Environment','shortcode-to-blocks-pro'); ?></h2>
        <ul class="stbp-muted" style="margin:0">
          <?php /* translators: %s: WordPress version */ ?>
          <li><?php printf(esc_html__('WordPress: %s','shortcode-to-blocks-pro'), esc_html(get_bloginfo('version'))); ?></li>
          <?php /* translators: %s: PHP version */ ?>
          <li><?php printf(esc_html__('PHP: %s','shortcode-to-blocks-pro'), esc_html(PHP_VERSION)); ?></li>
          <?php /* translators: %s: Site timezone */ ?>
          <li><?php printf(esc_html__('Site timezone: %s','shortcode-to-blocks-pro'), esc_html(wp_timezone_string())); ?></li>
          <?php /* translators: %d: Number of days for backup TTL */ ?>
          <li><?php printf(esc_html__('Backup TTL: %d days','shortcode-to-blocks-pro'), (int) ($stbp_opts['backup_ttl_days'] ?? 30)); ?></li>
        </ul>
      </div>

      <div class="stbp-card">
        <h2><?php esc_html_e('Help','shortcode-to-blocks-pro'); ?></h2>
        <p class="stbp-muted" style="margin-top:0">
          <?php esc_html_e('Dashboard counts include only posts that contain WPBakery shortcodes (detected and cached). Use Tools → Scan to backfill detection.', 'shortcode-to-blocks-pro'); ?>
        </p>
      </div>
    </div>
  </div>
</div>

<script>
(function($){
  const ajaxUrl = <?php echo wp_json_encode($stbp_scan_url); ?>;
  const nonceKey = 'stbp_convert_nonce_field';
  const nonceVal = <?php echo wp_json_encode(wp_create_nonce('stbp_convert_nonce')); ?>;

  // Buttons (start here + quick actions)
  const buttons = [
    { btn: '#stbp-scan-now',    status: '#stbp-scan-status'    },
    { btn: '#stbp-scan-now-qa', status: '#stbp-scan-status-qa' }
  ];

  function runScan(btnSel, statusSel) {
    const $btn = $(btnSel);
    const $status = $(statusSel);
    if (!$btn.length) return;

    $btn.prop('disabled', true);
    $status.text('Starting scan...');

    // Build the initial URL with proper parameters
    const initialUrl = ajaxUrl + '?' + $.param({
      action: 'stbp_scan_vc',
      json: '1',
      [nonceKey]: nonceVal
    });

    async function tick(url) {
      try {
        const res = await fetch(url, { 
          method: 'GET',
          credentials: 'same-origin' 
        });
        
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        
        const data = await res.json();
        
        if (!data || !data.success) {
          const errorMsg = data && data.data ? data.data : 'Unknown error';
          throw new Error(errorMsg);
        }

        const d = data.data || {};
        
        // Update status with progress
        if (d.label) {
          $status.text(d.label);
        } else if (d.percent) {
          $status.text(`Scanning... ${d.percent}%`);
        }
        
        if (d.done) {
          $status.text('Scan complete! Refreshing...');
          setTimeout(() => { location.reload(); }, 1500);
        } else if (d.next) {
          // Continue with next chunk
          setTimeout(() => tick(d.next), 200);
        } else {
          throw new Error('Incomplete response');
        }
        
      } catch (error) {
        console.error('Scan error:', error);
        $status.text('Scan failed - try Tools page for detailed scanning');
        $btn.prop('disabled', false);
      }
    }

    // Start the scan
    tick(initialUrl);
  }

  $(document).on('click', '#stbp-scan-now',    function(e){ e.preventDefault(); runScan('#stbp-scan-now',    '#stbp-scan-status'); });
  $(document).on('click', '#stbp-scan-now-qa', function(e){ e.preventDefault(); runScan('#stbp-scan-now-qa', '#stbp-scan-status-qa'); });
})(jQuery);
</script>
