<?php
/**
 * Dashboard view (shortcode content stats)
 */
use STB\admin\Admin;
use STBP\admin\Settings;

defined('ABSPATH') || exit;
global $wpdb;

/** Cache wrapper */
$stats = get_transient('stbp_dash_counts');
if ($stats === false) {
    $types  = Admin::allowed_post_types();
    $counts = [];
    $totalConvertedAll = 0;
    $totalAll = 0;

    foreach ($types as $t) {
        $obj   = get_post_type_object($t);
        $label = $obj ? $obj->labels->name : $t;

        // Only VC posts (flagged)
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
        ", $t)) ?: 0);

        // Converted VC posts = have a backup
        $converted = (int) ($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} m2
              ON m2.post_id = p.ID
            AND m2.meta_key = '_stbp_original_content'
            WHERE p.post_type = %s
              AND p.post_status = 'publish'
        ", $t)) ?: 0);

        $percent = ($total > 0) ? round(($converted / $total) * 100) : 0;

        $counts[$t] = [
            'label'     => $label,
            'total'     => $total,
            'converted' => $converted,
            'percent'   => $percent,
        ];
        $totalConvertedAll += $converted;
        $totalAll          += $total;
    }

    // Backups/Logs info
    $opts   = Settings::get();
    $ttl    = (int) ($opts['backup_ttl_days'] ?? 30);
    $cutoff = time() - ($ttl * DAY_IN_SECONDS);

    $backups_total = (int) ($wpdb->get_var("
        SELECT COUNT(DISTINCT post_id)
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_stbp_original_content'
    ") ?: 0);

    $backups_old = (int) ($wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT post_id)
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_stbp_original_content_ts' AND meta_value <> '' AND CAST(meta_value AS UNSIGNED) < %d
    ", $cutoff)) ?: 0);

    $logs_table = $wpdb->prefix . 'stbp_logs';
    $logs_table_exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table));
    $recent_logs = [];
    $last_convert = null;
    $recent_errors_7d = 0;

    if ($logs_table_exists) {
        $recent_logs = $wpdb->get_results("SELECT * FROM {$logs_table} ORDER BY created_at DESC LIMIT 8", ARRAY_A);
        $last_convert = $wpdb->get_var("SELECT created_at FROM {$logs_table} WHERE action='convert' AND status='success' ORDER BY created_at DESC LIMIT 1");
        $recent_errors_7d = (int) ($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$logs_table} WHERE status='error' AND created_at >= %s
        ", gmdate('Y-m-d H:i:s', time() - 7*DAY_IN_SECONDS))) ?: 0);
    }

    $stats = compact('types','counts','totalConvertedAll','totalAll','opts','ttl','cutoff','backups_total','backups_old','logs_table','logs_table_exists','recent_logs','last_convert','recent_errors_7d');
    set_transient('stbp_dash_counts', $stats, 15 * MINUTE_IN_SECONDS);
}
extract($stats);

/** Quick links */
$purge_backups_url = wp_nonce_url(admin_url('admin-ajax.php?action=stbp_purge_backups'),'stbp_convert_nonce','stbp_convert_nonce_field');
$purge_logs_url    = wp_nonce_url(admin_url('admin-ajax.php?action=stbp_purge_logs'),   'stbp_convert_nonce','stbp_convert_nonce_field');
$export_logs_url   = wp_nonce_url(admin_url('admin-post.php?action=stbp_export_logs'),  'stbp_convert_nonce','stbp_convert_nonce_field');
$base_slug         = defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks';
$convert_page      = admin_url('admin.php?page=' . $base_slug . '-convert');
$settings_page     = admin_url('admin.php?page=' . $base_slug . '-settings');
$logs_page         = admin_url('admin.php?page=' . $base_slug . '-logs');
/** Scan helpers */
$scan_url = wp_nonce_url(
  admin_url('admin-ajax.php?action=stbp_scan_vc&json=1'),
  'stbp_convert_nonce',
  'stbp_convert_nonce_field'
);

// whether a scan has ever been run (successfully)
$has_scanned = false;
if (! empty($logs_table_exists) && $logs_table_exists) {
  $has_scanned = (bool) ( $wpdb->get_var("
    SELECT COUNT(*) FROM {$logs_table}
    WHERE action = 'scan' AND status = 'success'
    LIMIT 1
  ") ?: 0 );
}

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
  <h1><?php esc_html_e('Shortcode to Blocks Converter','shortcode-to-blocks-pro'); ?></h1>
  <?php
  $all_zero = true;
  if (!empty($counts)) {
    foreach ($counts as $c) { if ((int)$c['total'] > 0) { $all_zero = false; break; } }
  }
  if ($all_zero): ?>
    <div class="notice notice-info">
      <p>
        <?php esc_html_e('It looks like we haven’t detected any WPBakery content yet. Start by scanning your selected post types.','shortcode-to-blocks-pro'); ?>
        <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-ajax.php?action=stbp_scan_vc'), 'stbp_convert_nonce','stbp_convert_nonce_field') ); ?>">
          <?php esc_html_e('Scan for WPBakery content','shortcode-to-blocks-pro'); ?>
        </a>
      </p>
    </div>
  <?php endif; ?>

  <?php if (empty($types)) : ?>
    <div class="notice notice-warning"><p><?php esc_html_e('No post types selected. Choose them in Settings.','shortcode-to-blocks-pro'); ?></p></div>
  <?php endif; ?>

  <?php
  $dismiss_key   = 'stbp_dismiss_old_backups';
  $dismiss_count = (int) get_user_meta(get_current_user_id(), $dismiss_key, true);
  if ($backups_old > 0 && $dismiss_count !== $backups_old) : ?>
    <div class="notice notice-info is-dismissible" id="stbp-old-backups-notice">
      <p><?php printf(esc_html__('You have %d backups older than %d days. Consider purging old backups.','shortcode-to-blocks-pro'), (int)$backups_old, (int)$ttl); ?>
      <a class="button button-secondary" href="<?php echo esc_url($purge_backups_url); ?>"><?php esc_html_e('Purge old backups','shortcode-to-blocks-pro'); ?></a></p>
    </div>
    <script>
    jQuery(function($){
      $('#stbp-old-backups-notice').on('click','.notice-dismiss',function(){
        $.post(ajaxurl,{action:'stbp_dismiss_old_backups',count:<?php echo (int)$backups_old; ?>,_wpnonce:'<?php echo wp_create_nonce('stbp_dismiss_old_backups'); ?>'});
      });
    });
    </script>
  <?php endif; ?>

  <?php if ($logs_table_exists && $recent_errors_7d > 0) : ?>
    <div class="notice notice-error">
      <p><?php printf(esc_html__('%d errors recorded in the last 7 days. Review Logs.','shortcode-to-blocks-pro'), (int)$recent_errors_7d); ?>
      <a class="button" href="<?php echo esc_url($logs_page); ?>"><?php esc_html_e('Open Logs','shortcode-to-blocks-pro'); ?></a></p>
    </div>
  <?php endif; ?>

  <div class="stbp-grid">
    <div class="stbp-col-left">
    <?php if (! $has_scanned): ?>
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
            <div class="num"><?php echo count($types); ?></div>
          </div>
          <div class="stbp-kpi">
            <div class="label stbp-muted"><?php esc_html_e('VC posts with backups','shortcode-to-blocks-pro'); ?></div>
            <div class="num"><?php echo (int)$backups_total; ?></div>
          </div>
          <div class="stbp-kpi">
            <div class="label stbp-muted"><?php esc_html_e('Last conversion','shortcode-to-blocks-pro'); ?></div>
            <div class="num">
              <?php
                echo $last_convert
                  ? esc_html( \STB\core\Helpers::format_admin_datetime($last_convert) )
                  : '—';
              ?>
            </div>          
          </div>
        </div>

        <?php foreach ($counts as $type => $info): ?>
          <div class="stbp-type-row">
            <div style="flex:1 1 auto">
              <strong><?php echo esc_html($info['label']); ?></strong>
              <span class="stbp-muted">• <?php echo (int)$info['converted']; ?>/<?php echo (int)$info['total']; ?> (<?php echo (int)$info['percent']; ?>%)</span>
              <div class="stbp-progress" aria-hidden="true">
                <span style="width:<?php echo (int)$info['percent']; ?>%"></span>
              </div>
            </div>
            <div class="stbp-type-actions" style="white-space:nowrap">
              <a class="button button-primary" href="<?php echo esc_url( add_query_arg(['type'=>$type], $convert_page) ); ?>">
                <?php esc_html_e('Convert this type','shortcode-to-blocks-pro'); ?>
              </a>
              <a class="button" href="<?php echo esc_url($settings_page); ?>">
                <?php esc_html_e('Settings','shortcode-to-blocks-pro'); ?>
              </a>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($totalAll > 0): ?>
          <hr />
          <p class="stbp-muted" style="margin:0">
            <?php
            $overall = round(($totalConvertedAll / max(1,$totalAll)) * 100);
            printf(esc_html__('Overall: %1$d of %2$d converted (%3$d%%).','shortcode-to-blocks-pro'),
              (int)$totalConvertedAll, (int)$totalAll, (int)$overall);
            ?>
          </p>
          <p class="stbp-muted" style="margin:8px 0 0">
            <?php esc_html_e('Totals include only posts that contain WPBakery shortcodes (detected & cached).', 'shortcode-to-blocks-pro'); ?>
          </p>
        <?php endif; ?>
      </div>

      <div class="stbp-card">
        <h2><?php esc_html_e('Recent activity','shortcode-to-blocks-pro'); ?></h2>
        <?php if (!$logs_table_exists): ?>
          <div class="notice notice-warning">
            <p><?php esc_html_e('The logs table is missing. Click below to create it.','shortcode-to-blocks-pro'); ?></p>
            <p>
              <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-ajax.php?action=stbp_install_logs'), 'stbp_convert_nonce','stbp_convert_nonce_field') ); ?>">
                <?php esc_html_e('Create logs table','shortcode-to-blocks-pro'); ?>
              </a>
            </p>
          </div>
        <?php elseif (empty($recent_logs)): ?>
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
              <?php foreach ($recent_logs as $r): ?>
                <tr>
                  <td><?php echo esc_html( \STB\core\Helpers::format_admin_datetime($r['created_at']) ); ?></td>
                  <td><?php echo esc_html($r['action']); ?></td>
                  <td><?php echo esc_html($r['status']); ?></td>
                  <td>
                    <?php
                      if (!empty($r['post_id'])) {
                          $pid = (int)$r['post_id'];
                          $link = get_edit_post_link($pid);
                          echo $link ? '<a href="'.esc_url($link).'">'. $pid .'</a>' : (string)$pid;
                      } else {
                          echo '—';
                      }
                    ?>
                  </td>
                  <td>
                    <?php
                      if (!empty($r['user_id'])) {
                          $u = get_userdata((int)$r['user_id']);
                          echo $u ? esc_html($u->display_name) : '—';
                      } else {
                          echo '—';
                      }
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p><a class="button" href="<?php echo esc_url($logs_page); ?>"><?php esc_html_e('View all logs','shortcode-to-blocks-pro'); ?></a>
             <a class="button" href="<?php echo esc_url($export_logs_url); ?>"><?php esc_html_e('Download CSV','shortcode-to-blocks-pro'); ?></a>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="stbp-col-right">
      <div class="stbp-card">
        <h2><?php esc_html_e('Quick actions','shortcode-to-blocks-pro'); ?></h2>
        <p>
          <a class="button button-primary" href="<?php echo esc_url($convert_page); ?>">
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
          <li><?php printf(esc_html__('WordPress: %s','shortcode-to-blocks-pro'), esc_html(get_bloginfo('version'))); ?></li>
          <li><?php printf(esc_html__('PHP: %s','shortcode-to-blocks-pro'), esc_html(PHP_VERSION)); ?></li>
          <li><?php printf(esc_html__('Site timezone: %s','shortcode-to-blocks-pro'), esc_html(wp_timezone_string())); ?></li>
          <li><?php printf(esc_html__('Backup TTL: %d days','shortcode-to-blocks-pro'), (int)($opts['backup_ttl_days'] ?? 30)); ?></li>
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
  const ajaxUrl = <?php echo wp_json_encode($scan_url); ?>;
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
