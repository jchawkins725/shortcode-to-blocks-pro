<?php
namespace STBP\includes;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined('ABSPATH') || exit;

/**
 * Plugin auto-updates via GitHub Releases.
 * 
 * Checks for new versions and prompts users to update from within WordPress admin.
 * Integrates with license validation to restrict updates to active license holders.
 */
class Updater {

	/**
	 * Initialize the update checker.
	 */
	public static function init(): void {
		// Only load update checker if the library is available
		if (!class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
			return;
		}

		$updateChecker = PucFactory::buildUpdateChecker(
			'https://github.com/jchawkins725/shortcode-to-blocks-pro', // TODO: Update with your GitHub repo URL
			STBP_FILE,
			'shortcode-to-blocks-pro'
		);

		// Use releases for versioning (recommended for GitHub)
		$updateChecker->getVcsApi()->enableReleaseAssets();

		// Add license key to update requests for tracking/validation (optional)
		$updateChecker->addQueryArgFilter(function ($queryArgs) {
			$license_key = License::get_key();
			if (!empty($license_key)) {
				$queryArgs['license_key'] = $license_key;
				$queryArgs['site_url'] = home_url();
			}
			return $queryArgs;
		});

		// Only allow updates if license is active
		add_filter('puc_request_info_result-shortcode-to-blocks-pro', function ($pluginInfo, $result) {
			if (!License::is_active()) {
				// If license is inactive, hide the update
				return null;
			}
			return $pluginInfo;
		}, 10, 2);

		// Add notice if update available but license is inactive
		add_action('admin_notices', [__CLASS__, 'inactive_license_update_notice']);
	}

	/**
	 * Show notice if an update is available but license is inactive.
	 */
	public static function inactive_license_update_notice(): void {
		// Skip if license is active
		if (License::is_active()) {
			return;
		}

		// Check if an update is available
		$update_checker = apply_filters('puc_get_update_checker-shortcode-to-blocks-pro', null);
		if (!$update_checker) {
			return;
		}

		$update = $update_checker->getUpdate();
		if (!$update) {
			return;
		}

		// Only show on plugin page
		$screen = get_current_screen();
		if (!$screen || $screen->id !== 'plugins') {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e('Shortcode to Blocks Pro update available', 'shortcode-to-blocks-pro'); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %1$s: new version number, %2$s: link to settings page */
					esc_html__('Version %1$s is available, but your license is inactive. %2$s to enable updates.', 'shortcode-to-blocks-pro'),
					esc_html($update->version),
					'<a href="' . esc_url(admin_url('options-general.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG . '-settings' : 'shortcode-to-blocks-settings'))) . '">' . esc_html__('Activate your license', 'shortcode-to-blocks-pro') . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
