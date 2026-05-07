<?php
namespace STBP\admin;

defined('ABSPATH') || exit;

/**
 * Pro Settings — adds Pro-specific fields (backup TTL, auto-purge) to the
 * Free plugin's settings page via the stbc_register_settings hook.
 *
 * Shared settings (post_types, required_cap, retain_data) live in the Free
 * plugin's stbc_options option. Pro-only settings live in stbp_options.
 */
class Settings {

    /* --- Pro-only defaults --- */
    public static function defaults(): array {
        return apply_filters('stbp_settings_defaults', [
            'backup_ttl_days'       => 30,
            'auto_purge_backups_1y' => false,
        ]);
    }

    /**
     * Merged settings: Free shared options + Pro-only options.
     * Callers can still do Settings::get()['post_types'] and it works.
     */
    public static function get(): array {
        $free = class_exists('\\STBC\\admin\\Settings') ? \STBC\admin\Settings::get() : [];
        $pro  = wp_parse_args(get_option('stbp_options', []), self::defaults());
        return array_merge($free, $pro);
    }

    /** Delegate to Free for capability. */
    public static function required_capability(): string {
        if (class_exists('\\STBC\\admin\\Settings')) {
            return \STBC\admin\Settings::required_capability();
        }
        return 'edit_others_posts';
    }

    public static function tools_capability() {
        return apply_filters('stbp_tools_capability', 'manage_options');
    }

    /* --- Registration (hooked to stbc_register_settings) --- */

    /**
     * Register Pro-only settings on the Free settings page.
     * The Pro option is registered under the stbc_settings group so that
     * settings_fields('stbc_settings') includes its nonce.
     */
    public static function register_settings(string $page): void {
        register_setting('stbc_settings', 'stbp_options', [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
        ]);

        add_settings_section(
            'stbp_backups',
            __('Backups', 'shortcode-to-blocks-pro'),
            function () {
                echo '<p>' . esc_html__(
                    'Control manual and automatic cleanup of stored conversion backups.',
                    'shortcode-to-blocks-pro'
                ) . '</p>';
            },
            $page
        );

        add_settings_field(
            'backup_ttl_days',
            __('Old-backup threshold (days)', 'shortcode-to-blocks-pro'),
            [self::class, 'field_backup_ttl'],
            $page,
            'stbp_backups'
        );

        add_settings_field(
            'auto_purge_backups_1y',
            __('Auto-purge backups > 1 year', 'shortcode-to-blocks-pro'),
            [self::class, 'field_auto_purge'],
            $page,
            'stbp_backups'
        );
    }

    /* --- Sanitize --- */

    public static function sanitize($in) {
        $out = wp_parse_args(get_option('stbp_options', []), self::defaults());

        $ttl = isset($in['backup_ttl_days']) ? (int) $in['backup_ttl_days'] : 30;
        $out['backup_ttl_days'] = max(0, $ttl);

        $out['auto_purge_backups_1y'] = ! empty($in['auto_purge_backups_1y']);

        return apply_filters('stbp_settings_sanitize', $out, $in);
    }

    /* --- Field renderers --- */

    public static function field_backup_ttl(): void {
        $opts = self::get();
        printf(
            '<input type="number" name="stbp_options[backup_ttl_days]" value="%d" min="0" step="1" style="width:90px;">',
            (int) ($opts['backup_ttl_days'] ?? 30)
        );
        echo '<p class="description">' . esc_html__(
            'Used by the "Purge old backups" button on the Tools page. Set 0 to keep backups indefinitely.',
            'shortcode-to-blocks-pro'
        ) . '</p>';
    }

    public static function field_auto_purge(): void {
        $opts    = self::get();
        $checked = ! empty($opts['auto_purge_backups_1y']);
        printf(
            '<label><input type="checkbox" name="stbp_options[auto_purge_backups_1y]" value="1" %s> %s</label>',
            checked($checked, true, false),
            esc_html__('Daily WP-Cron job to delete backups older than 1 year.', 'shortcode-to-blocks-pro')
        );
        echo '<p class="description">' . esc_html__(
            'Safety net for very old snapshots. The manual "Purge old backups" button still uses the threshold above.',
            'shortcode-to-blocks-pro'
        ) . '</p>';
    }
}
