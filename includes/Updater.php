<?php
namespace STBP\includes;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined('ABSPATH') || exit;

/**
 * Plugin auto-updates via GitHub Releases.
 *
 * Pro features remain licensed per site. On multisite, plugin files are updated
 * once for the entire network, so update discovery must not depend on whichever
 * subsite happens to be the current admin context.
 */
class Updater {

	private const REPOSITORY_URL = 'https://github.com/jchawkins725/shortcode-to-blocks-pro';
	private const RELEASES_URL = self::REPOSITORY_URL . '/releases';
	private const REQUEST_TIMEOUT = 15;

	/**
	 * Keep the checker available for notices and offline plugin-details fallback.
	 *
	 * @var object|null
	 */
	private static $update_checker = null;

	/**
	 * Initialize the update checker.
	 */
	public static function init(): void {
		if (!class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
			return;
		}

		self::$update_checker = PucFactory::buildUpdateChecker(
			self::REPOSITORY_URL,
			STBP_FILE,
			'shortcode-to-blocks-pro'
		);

		// Only install the versioned production ZIP attached to the GitHub release.
		self::$update_checker->getVcsApi()->enableReleaseAssets(
			'/^shortcode-to-blocks-pro-[0-9A-Za-z.+-]+\.zip$/i'
		);

		// WP Engine and other managed hosts can take longer than PUC's 3-second
		// admin-request default when contacting the GitHub API.
		self::$update_checker->addHttpRequestArgFilter([__CLASS__, 'extend_request_timeout']);

		add_filter(
			'puc_request_info_result-shortcode-to-blocks-pro',
			[__CLASS__, 'filter_update_info_by_license'],
			10,
			2
		);

		// PUC runs at priority 20. If its live GitHub request fails, provide useful
		// local details instead of allowing WordPress.org to report "Plugin not found".
		add_filter('plugins_api', [__CLASS__, 'fallback_plugin_information'], 30, 3);
		add_filter(
			'puc_manual_check_message-shortcode-to-blocks-pro',
			[__CLASS__, 'filter_manual_check_message'],
			10,
			2
		);

		add_action('admin_notices', [__CLASS__, 'inactive_license_update_notice']);
	}

	/**
	 * Give managed hosts enough time to complete GitHub API requests.
	 */
	public static function extend_request_timeout(array $options): array {
		$current_timeout = isset($options['timeout']) ? (int) $options['timeout'] : 0;
		$options['timeout'] = max(self::REQUEST_TIMEOUT, $current_timeout);

		return $options;
	}

	/**
	 * Apply license rules at the correct installation scope.
	 *
	 * A single-site install has one plugin copy and one site license, so inactive
	 * licenses do not receive update information. A multisite network has one
	 * shared plugin copy but separate site licenses. The shared copy must remain
	 * updateable from Network Admin regardless of the current subsite; each site
	 * still calls License::is_active() independently before loading Pro features.
	 *
	 * @param mixed $plugin_info Plugin metadata returned by PUC.
	 * @param mixed $result      Underlying HTTP result, unused for VCS updates.
	 * @return mixed
	 */
	public static function filter_update_info_by_license($plugin_info, $result) {
		unset($result);

		if (is_multisite()) {
			return $plugin_info;
		}

		return License::is_active() ? $plugin_info : null;
	}

	/**
	 * Supply plugin details when a live GitHub metadata request cannot complete.
	 *
	 * @param mixed  $result Existing plugins_api result.
	 * @param string $action Requested API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function fallback_plugin_information($result, $action, $args) {
		if (
			'plugin_information' !== $action
			|| !is_object($args)
			|| empty($args->slug)
			|| 'shortcode-to-blocks-pro' !== $args->slug
		) {
			return $result;
		}

		// Preserve successful metadata returned by PUC or another provider.
		if (false !== $result && null !== $result && !is_wp_error($result)) {
			return $result;
		}

		$update = self::get_cached_update();
		$version = !empty($update->version) ? (string) $update->version : STBP_VERSION;
		$download_url = !empty($update->download_url) ? (string) $update->download_url : '';
		$changelog = !empty($update->upgrade_notice)
			? wp_kses_post((string) $update->upgrade_notice)
			: sprintf(
				/* translators: %s: GitHub releases URL. */
				__('Live release details are temporarily unavailable. <a href="%s" target="_blank" rel="noopener noreferrer">View release notes on GitHub</a>.', 'shortcode-to-blocks-pro'),
				esc_url(self::RELEASES_URL)
			);

		return (object) [
			'name'          => 'Shortcode to Blocks Pro',
			'slug'          => 'shortcode-to-blocks-pro',
			'version'       => $version,
			'author'        => '<a href="https://www.jonathanchawkins.com/">Jonathan Hawkins</a>',
			'homepage'      => self::REPOSITORY_URL,
			'requires'      => '6.0',
			'tested'        => '7.0',
			'requires_php'  => '7.4',
			'download_link' => $download_url,
			'sections'      => [
				'description' => __('Pro add-on for Shortcode to Blocks with batch conversion, advanced WPBakery converters, logging, and tools.', 'shortcode-to-blocks-pro'),
				'changelog'   => $changelog,
			],
		];
	}

	/**
	 * Add a useful destination when a manual GitHub check fails.
	 */
	public static function filter_manual_check_message(string $message, string $status): string {
		if ('error' !== $status) {
			return $message;
		}

		return sprintf(
			/* translators: 1: original error message, 2: GitHub releases URL. */
			__('%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">View published releases on GitHub</a>.', 'shortcode-to-blocks-pro'),
			$message,
			esc_url(self::RELEASES_URL)
		);
	}

	/**
	 * Return the cached update without making another GitHub request.
	 *
	 * @return object|null
	 */
	private static function get_cached_update() {
		if (!self::$update_checker || !is_callable([self::$update_checker, 'getUpdate'])) {
			return null;
		}

		$update = self::$update_checker->getUpdate();
		return is_object($update) ? $update : null;
	}

	/**
	 * Show a notice when a single-site installation has an inactive license.
	 *
	 * Multisite updates are managed from Network Admin. Per-site feature-access
	 * notices are handled by the main plugin bootstrap instead.
	 */
	public static function inactive_license_update_notice(): void {
		if (is_multisite()) {
			return;
		}

		if (License::is_active()) {
			return;
		}

		$update = self::get_cached_update();
		if (!$update) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || 'plugins' !== $screen->id) {
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
					/* translators: %1$s: new version number, %2$s: link to settings page. */
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
