<?php
namespace STBP\includes;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined('ABSPATH') || exit;

/**
 * License-gated plugin auto-updates via the Shortcode to Blocks API.
 *
 * Pro features remain licensed per site. On multisite, plugin files are updated
 * once for the entire network, so update discovery must not depend on whichever
 * subsite happens to be the current admin context.
 */
class Updater {

	private const METADATA_URL = 'https://shortcodetoblocks.com/api/pro-update-manifest';
	private const SUPPORT_URL = 'https://shortcodetoblocks.com/';
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

		$metadata_url = add_query_arg('installed_version', STBP_VERSION, self::METADATA_URL);
		self::$update_checker = PucFactory::buildUpdateChecker(
			$metadata_url,
			STBP_FILE,
			'shortcode-to-blocks-pro'
		);

		self::$update_checker->addHttpRequestArgFilter([__CLASS__, 'authorize_update_request']);

		// PUC runs at priority 20. If its live metadata request fails, provide useful
		// local details instead of allowing WordPress.org to report "Plugin not found".
		add_filter('plugins_api', [__CLASS__, 'fallback_plugin_information'], 30, 3);
		add_filter(
			'puc_manual_check_message-shortcode-to-blocks-pro',
			[__CLASS__, 'filter_manual_check_message'],
			10,
			2
		);
	}

	/**
	 * Give managed hosts enough time and authorize the private metadata request.
	 *
	 * The license travels in a request header so it never appears in URLs, access
	 * logs, caches, or browser history.
	 */
	public static function authorize_update_request(array $options): array {
		$current_timeout = isset($options['timeout']) ? (int) $options['timeout'] : 0;
		$options['timeout'] = max(self::REQUEST_TIMEOUT, $current_timeout);
		$options['headers'] = isset($options['headers']) && is_array($options['headers'])
			? $options['headers']
			: [];
		$options['headers']['X-STBP-License-Key'] = License::get_key();
		$options['headers']['X-STBP-Site-Url'] = home_url();

		return $options;
	}

	/**
	 * Supply plugin details when a live metadata request cannot complete.
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
				/* translators: %s: plugin support URL. */
				__('Live release details are temporarily unavailable. <a href="%s" target="_blank" rel="noopener noreferrer">Visit Shortcode to Blocks</a>.', 'shortcode-to-blocks-pro'),
				esc_url(self::SUPPORT_URL)
			);

		return (object) [
			'name'          => 'Shortcode to Blocks Pro',
			'slug'          => 'shortcode-to-blocks-pro',
			'version'       => $version,
			'author'        => '<a href="https://www.jonathanchawkins.com/">Jonathan Hawkins</a>',
			'homepage'      => self::SUPPORT_URL,
			'requires'      => '6.0',
			'tested'        => '7.1',
			'requires_php'  => '7.4',
			'download_link' => $download_url,
			'sections'      => [
				'description' => __('Pro add-on for Shortcode to Blocks with batch conversion, advanced WPBakery converters, logging, and tools.', 'shortcode-to-blocks-pro'),
				'changelog'   => $changelog,
			],
		];
	}

	/**
	 * Add a useful destination when a manual update check fails.
	 */
	public static function filter_manual_check_message(string $message, string $status): string {
		if ('error' !== $status) {
			return $message;
		}

		return sprintf(
			/* translators: 1: original error message, 2: plugin support URL. */
			__('%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">Visit Shortcode to Blocks</a>.', 'shortcode-to-blocks-pro'),
			$message,
			esc_url(self::SUPPORT_URL)
		);
	}

	/**
	 * Return the cached update without making another remote request.
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
}
