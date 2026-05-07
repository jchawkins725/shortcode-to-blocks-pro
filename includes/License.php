<?php
namespace STBP\includes;

defined('ABSPATH') || exit;

/**
 * License-key management for the Pro plugin.
 *
 * Stores the key in stbp_license_key and status in stbp_license_status.
 * Override the two constants below via wp-config.php or the bootstrap to point
 * at your own licensing server:
 *
 *   define( 'STBP_LICENSE_API_URL', 'https://yoursite.com' );
 *   define( 'STBP_LICENSE_ITEM_ID', 42 );
 */
class License {

    const OPTION_KEY    = 'stbp_license_key';
    const STATUS_OPTION = 'stbp_license_status'; // 'valid' | 'invalid' | ''

    /* ----- Accessors ----- */

    public static function get_key(): string {
        return trim((string) get_option(self::OPTION_KEY, ''));
    }

    public static function get_status(): string {
        return (string) get_option(self::STATUS_OPTION, '');
    }

    public static function is_valid(): bool {
        return self::get_status() === 'valid';
    }

    /* ----- Admin settings integration ----- */

    /**
     * Register the license-key field inside the Pro settings section.
     */
    public static function register_settings(string $page = ''): void {
        if (empty($page)) {
            $page = defined('STBC_SLUG') ? STBC_SLUG . '-settings' : 'shortcode-to-blocks-settings';
        }

        register_setting('stbc_settings', self::OPTION_KEY, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        add_settings_section(
            'stbp_license_section',
            __('License', 'shortcode-to-blocks-pro'),
            '__return_false',
            $page
        );

        add_settings_field(
            self::OPTION_KEY,
            __('License Key', 'shortcode-to-blocks-pro'),
            [__CLASS__, 'render_field'],
            $page,
            'stbp_license_section'
        );
    }

    /**
     * Render the license key input + Activate / Deactivate buttons.
     */
    public static function render_field(): void {
        $key    = self::get_key();
        $status = self::get_status();
        ?>
        <input type="text"
               id="<?php echo esc_attr(self::OPTION_KEY); ?>"
               name="<?php echo esc_attr(self::OPTION_KEY); ?>"
               value="<?php echo esc_attr($key); ?>"
               class="regular-text"
               <?php echo $status === 'valid' ? 'readonly' : ''; ?>
        />
        <?php if ($status === 'valid') : ?>
            <span class="dashicons dashicons-yes-alt" style="color: green; vertical-align: middle;"></span>
            <span style="color: green;"><?php esc_html_e('Active', 'shortcode-to-blocks-pro'); ?></span>
            <?php submit_button(__('Deactivate License', 'shortcode-to-blocks-pro'), 'secondary', 'stbp_license_deactivate', false); ?>
        <?php else : ?>
            <?php if ($status === 'invalid') : ?>
                <span class="dashicons dashicons-warning" style="color: red; vertical-align: middle;"></span>
                <span style="color: red;"><?php esc_html_e('Invalid', 'shortcode-to-blocks-pro'); ?></span>
            <?php endif; ?>
            <?php submit_button(__('Activate License', 'shortcode-to-blocks-pro'), 'secondary', 'stbp_license_activate', false); ?>
        <?php endif;
    }

    /* ----- Activation / Deactivation ----- */

    /**
     * Call from admin_init to handle the activate / deactivate buttons.
     */
    public static function handle_actions(): void {
        if (!isset($_POST['stbp_license_activate']) && !isset($_POST['stbp_license_deactivate'])) {
            return;
        }

        // The license fields live inside the stbc_settings form.
        check_admin_referer('stbc_settings-options');

        if (isset($_POST['stbp_license_activate'])) {
            self::activate();
        } else {
            self::deactivate();
        }
    }

    private static function activate(): void {
        if (!current_user_can('manage_options')) return;

        $key = sanitize_text_field($_POST[self::OPTION_KEY] ?? '');
        if (empty($key)) {
            update_option(self::STATUS_OPTION, '');
            return;
        }

        update_option(self::OPTION_KEY, $key);

        // Remote validation (EDD Software Licensing compatible).
        $api_url = defined('STBP_LICENSE_API_URL') ? STBP_LICENSE_API_URL : '';
        $item_id = defined('STBP_LICENSE_ITEM_ID') ? STBP_LICENSE_ITEM_ID : 0;

        if ($api_url && $item_id) {
            $response = wp_remote_post($api_url, [
                'timeout' => 15,
                'body'    => [
                    'edd_action' => 'activate_license',
                    'license'    => $key,
                    'item_id'    => $item_id,
                    'url'        => home_url(),
                ],
            ]);

            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                update_option(self::STATUS_OPTION, ($data['license'] ?? '') === 'valid' ? 'valid' : 'invalid');
                return;
            }
        }

        // Fallback: accept any non-empty key as valid (no remote server configured).
        update_option(self::STATUS_OPTION, 'valid');
    }

    private static function deactivate(): void {
        if (!current_user_can('manage_options')) return;

        $key     = self::get_key();
        $api_url = defined('STBP_LICENSE_API_URL') ? STBP_LICENSE_API_URL : '';
        $item_id = defined('STBP_LICENSE_ITEM_ID') ? STBP_LICENSE_ITEM_ID : 0;

        if ($api_url && $item_id && $key) {
            wp_remote_post($api_url, [
                'timeout' => 15,
                'body'    => [
                    'edd_action' => 'deactivate_license',
                    'license'    => $key,
                    'item_id'    => $item_id,
                    'url'        => home_url(),
                ],
            ]);
        }

        delete_option(self::OPTION_KEY);
        delete_option(self::STATUS_OPTION);
    }

    /* ----- Cleanup ----- */

    public static function clean(): void {
        delete_option(self::OPTION_KEY);
        delete_option(self::STATUS_OPTION);
    }
}
