<?php
/**
 * Plugin Name: Shortcode to Blocks Pro
 * Description: Pro add-on for Shortcode to Blocks — adds batch/bulk convert, advanced shortcode converters, logging, tools, and license key activation.
 * Version: 1.0.3
 * Author: Jonathan Hawkins
 * Author URI: https://www.jonathanchawkins.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shortcode-to-blocks-pro
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

// Composer autoloader for dependencies (Plugin Update Checker, etc.)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

define('STBP_VERSION', '1.0.3');
define('STBP_FILE', __FILE__);
define('STBP_PATH', plugin_dir_path(__FILE__));
define('STBP_URL', plugin_dir_url(__FILE__));
define('STBP_SLUG', 'shortcode-to-blocks-pro');

/* ───────────────────────────────────────────
 * PSR-4 autoloader for the STBP\ namespace
 * ─────────────────────────────────────────── */
spl_autoload_register(function ($class) {
    $prefix   = 'STBP\\';
    $base_dir = STBP_PATH;
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $base_dir . $rel . '.php';
    if (file_exists($file)) require_once $file;
});

/* ───────────────────────────────────────────
 * Dependency: Free plugin must be active
 * ─────────────────────────────────────────── */
add_action('plugins_loaded', function () {
    if (!defined('STBC_VERSION')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            printf(
                /* translators: %s = free plugin name */
                esc_html__('%s requires the free Shortcode to Blocks plugin to be installed and activated.', 'shortcode-to-blocks-pro'),
                '<strong>Shortcode to Blocks Pro</strong>'
            );
            echo '</p></div>';
        });
        return; // bail — free plugin is missing
    }

    /* ---- Initialize auto-updates via GitHub Releases ---- */
    \STBP\includes\Updater::init();

    /* ---- Admin hooks — license settings always available ---- */
    if (is_admin()) {
        add_action('stbc_register_settings', [\STBP\includes\License::class, 'register_settings']);

        // Keep Settings at the end of the left sidebar submenu list.
        add_action('admin_menu', function () {
            $stbp_parent_slug = defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks';
            $stbp_settings_slug = $stbp_parent_slug . '-settings';

            global $submenu;
            if (empty($submenu[$stbp_parent_slug]) || !is_array($submenu[$stbp_parent_slug])) {
                return;
            }

            foreach ($submenu[$stbp_parent_slug] as $stbp_idx => $stbp_item) {
                if (!isset($stbp_item[2]) || $stbp_item[2] !== $stbp_settings_slug) {
                    continue;
                }

                $stbp_settings_item = $stbp_item;
                unset($submenu[$stbp_parent_slug][$stbp_idx]);
                $submenu[$stbp_parent_slug][] = $stbp_settings_item;
                break;
            }
        }, 999);
    }

    /* ---- Gate Pro features behind a valid license ---- */
    if (!\STBP\includes\License::is_active()) {
        if (is_admin()) {
            add_action('admin_notices', function () {
                $settings_url = admin_url('admin.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-settings');
                echo '<div class="notice notice-warning is-dismissible"><p>';
                printf(
                    // translators: 1: opening anchor tag, 2: closing anchor tag
                    esc_html__('Shortcode to Blocks Pro: please %1$sactivate your license%2$s to unlock Pro features.', 'shortcode-to-blocks-pro'),
                    '<a href="' . esc_url($settings_url) . '">',
                    '</a>'
                );
                echo '</p></div>';
            });
        }
        return; // Stop here — no Pro features without a valid license.
    }

    /* ---- Override the converter class to use ConverterPro ---- */
    add_filter('stbc_converter_class', function () {
        return '\\STBP\\includes\\ConverterPro';
    });

    /* ---- Use the Pro dashboard view ---- */
    add_filter('stbc_dashboard_view', function () {
        return STBP_PATH . 'admin/views/dashboard.php';
    });

    /* ---- Log individual convert / revert from the Free plugin ---- */
    add_action('stbc_post_converted', function ($post_id) {
        \STBP\includes\Logger::log('convert', 'success', sprintf('Converted post ID %d', $post_id), $post_id);
    });
    add_action('stbc_post_reverted', function ($post_id) {
        \STBP\includes\Logger::log('revert', 'success', sprintf('Reverted post ID %d', $post_id), $post_id);
    });
    add_action('stbc_convert_error', function ($post_id, $message) {
        \STBP\includes\Logger::log('convert', 'error', $message, $post_id);
    }, 10, 2);

    /* ---- Admin hooks (only when in wp-admin) ---- */
    if (is_admin()) {
        require_once __DIR__ . '/admin/BulkActions.php';
        (new \STBP\admin\Admin())->init();
        (new \STBP\admin\Batch())->init();
        (new \STBP\admin\Tools())->init();

        // Pro settings sections on Free's settings page
        add_action('stbc_register_settings', [\STBP\admin\Settings::class, 'register_settings']);

        // Add Pro submenus under the Free menu
        add_action('stbc_register_admin_menus', function (string $parent_slug, string $cap) {
            add_submenu_page($parent_slug, __('Convert', 'shortcode-to-blocks-pro'),        __('Convert', 'shortcode-to-blocks-pro'),        $cap, $parent_slug . '-convert',   [\STBP\admin\Batch::class, 'render_convert_page']);
            add_submenu_page($parent_slug, __('Revert', 'shortcode-to-blocks-pro'),         __('Revert', 'shortcode-to-blocks-pro'),         $cap, $parent_slug . '-revert',    [\STBP\admin\Batch::class, 'render_revert_page']);
            add_submenu_page($parent_slug, __('Tools', 'shortcode-to-blocks-pro'),          __('Tools', 'shortcode-to-blocks-pro'),          $cap, $parent_slug . '-tools',     [\STBP\admin\Tools::class, 'render_tools_page']);
            add_submenu_page($parent_slug, __('Logs', 'shortcode-to-blocks-pro'),           __('Logs', 'shortcode-to-blocks-pro'),           $cap, $parent_slug . '-logs',      [\STBP\admin\Logs::class, 'render_logs_page']);
            add_submenu_page($parent_slug, __('Converted Posts', 'shortcode-to-blocks-pro'),__('Converted Posts', 'shortcode-to-blocks-pro'), $cap, $parent_slug . '-converted', [\STBP\admin\Converted::class, 'render_page']);
        }, 10, 2);

        // Add Pro tabs to the Free tab bar
        add_filter('stbc_admin_tabs', function (array $tabs) {
            $slug = defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks';
            $pro_tabs = [
                $slug . '-convert'   => [__('Convert',        'shortcode-to-blocks-pro'), admin_url('admin.php?page=' . $slug . '-convert')],
                $slug . '-revert'    => [__('Revert',         'shortcode-to-blocks-pro'), admin_url('admin.php?page=' . $slug . '-revert')],
                $slug . '-tools'     => [__('Tools',          'shortcode-to-blocks-pro'), admin_url('admin.php?page=' . $slug . '-tools')],
                $slug . '-logs'      => [__('Logs',           'shortcode-to-blocks-pro'), admin_url('admin.php?page=' . $slug . '-logs')],
                $slug . '-converted' => [__('Converted Posts','shortcode-to-blocks-pro'), admin_url('admin.php?page=' . $slug . '-converted')],
            ];
            // Insert Pro tabs before Settings tab
            $new = [];
            foreach ($tabs as $key => $val) {
                if ($key === $slug . '-settings') {
                    foreach ($pro_tabs as $pk => $pv) $new[$pk] = $pv;
                }
                $new[$key] = $val;
            }
            // If Settings wasn't found, just append
            if (count($new) === count($tabs)) {
                $new = array_merge($tabs, $pro_tabs);
            }
            return $new;
        });
    }
}, 10); // Free boots at priority 9, Pro at 10

// Schedule or clear the daily cron based on the setting.
add_action('init', function () {
    $hook    = 'stbp_cron_purge_backups';
    $opts    = get_option('stbp_options', []);
    $enabled = !empty($opts['auto_purge_backups_1y']);

    if ($enabled) {
        if (!wp_next_scheduled($hook)) {
            // small offset to avoid running exactly at page load time
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $hook);
        }
    } else {
        // clear any existing schedule if user turned it off
        if (wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
    }
});

add_action('stbp_cron_purge_backups', function () {
    // Double-check the setting in case it changed since scheduling.
    $opts = get_option('stbp_options', []);
    if (empty($opts['auto_purge_backups_1y'])) {
        return;
    }

    $cut     = time() - YEAR_IN_SECONDS;
    $purged  = 0;
    $paged   = 1;
    $perpage = 500;

    // Query in batches to avoid timeouts/memory spikes.
    while (true) {
        $q = new WP_Query([
            'post_type'      => 'any',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => $perpage,
            'paged'          => $paged,
            'no_found_rows'  => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional scheduled cleanup query against plugin-managed backup timestamp meta.
            'meta_query'     => [[
                'key'     => '_stbp_original_content_ts',
                'value'   => $cut,
                'compare' => '<',
                'type'    => 'NUMERIC',
            ]],
        ]);

        if (empty($q->posts)) {
            break;
        }

        foreach ($q->posts as $pid) {
            delete_post_meta($pid, '_stbp_original_content');
            delete_post_meta($pid, '_stbp_original_content_ts');
            $purged++;
        }

        $paged++;
        wp_reset_postdata();
    }

    if ($purged > 0) {
        // Lazy-load Logger if needed, then log a summary row.
        if (!class_exists('\STBP\includes\Logger') && file_exists(STBP_PATH . 'includes/Logger.php')) {
            require_once STBP_PATH . 'includes/Logger.php';
        }
        if (class_exists('\STBP\includes\Logger')) {
            \STBP\includes\Logger::log('purge', 'success', 'Auto-purged backups >1y: ' . $purged);
        }
    }

    // Refresh dashboard counts so UI reflects the new totals.
    delete_transient('stbp_dash_counts');
});

// Clean up the scheduled event on plugin deactivation.
register_deactivation_hook(STBP_FILE, function () {
    wp_clear_scheduled_hook('stbp_cron_purge_backups');
});

register_activation_hook(STBP_FILE, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'stbp_logs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `created_at` datetime NOT NULL,
        `user_id` bigint(20) unsigned NULL,
        `post_id` bigint(20) unsigned NULL,
        `action` varchar(50) NOT NULL,       -- convert|revert|batch|purge|error
        `status` varchar(20) NOT NULL,       -- success|error
        `message` text NULL,
        PRIMARY KEY (`id`),
        KEY `created_at` (`created_at`),
        KEY `post_id` (`post_id`),
        KEY `action` (`action`)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // ensure logs table exists
    if ( ! class_exists('\STBP\includes\Logger') ) {
        require_once STBP_PATH . 'includes/Logger.php';
    }
    \STBP\includes\Logger::maybe_install();
});
