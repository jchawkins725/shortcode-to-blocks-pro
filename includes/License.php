<?php
namespace STBP\includes;

defined('ABSPATH') || exit;

/**
 * License management for the Pro plugin.
 *
 * Handles Lemon Squeezy license key validation via custom API endpoint.
 * License keys are stored locally in wp_options and validated against your backend.
 */
class License {

	/**
	 * Option key for storing the license key.
	 */
	const KEY_OPTION = 'stbp_license_key';

	/**
	 * Legacy transient key for caching validation results.
	 */
	const CACHE_KEY = 'stbp_license_valid';

	/**
	 * Prefix for key-scoped cache entries.
	 */
	const CACHE_KEY_PREFIX = 'stbp_license_valid_';

	/**
	 * Cache duration in seconds (24 hours).
	 */
	const CACHE_TTL = 86400;

	/**
	 * URL of your custom license validation API endpoint.
	 * 
	 * Defaults to the production API. Override with STBP_LICENSE_API_URL constant
	 * in wp-config.php for local development or testing.
	 * 
	 * Example: define('STBP_LICENSE_API_URL', 'http://localhost:8888/.netlify/functions/validate-license');
	 */
	public static function get_api_url(): string {
		$url = defined('STBP_LICENSE_API_URL') 
			? STBP_LICENSE_API_URL 
			: 'https://shortcodetoblocks.com/api/validate-license';
		
		/**
		 * Filter the license validation API URL.
		 *
		 * @param string $url The API endpoint URL.
		 */
		return apply_filters('stbp_license_api_url', $url);
	}

	/**
	 * Get the stored license key.
	 */
	public static function get_key(): string {
		return sanitize_text_field((string) get_option(self::KEY_OPTION, ''));
	}

	/**
	 * Save a license key.
	 */
	public static function set_key(string $key): void {
		$key = sanitize_text_field($key);
		self::invalidate_cache();

		if (empty($key)) {
			delete_option(self::KEY_OPTION);
		} else {
			update_option(self::KEY_OPTION, $key);
		}
	}

	/**
	 * Build a cache key scoped to the key + endpoint + site URL.
	 */
	private static function cache_key_for(string $key): string {
		$fingerprint = $key . '|' . self::get_api_url() . '|' . home_url();
		return self::CACHE_KEY_PREFIX . md5($fingerprint);
	}

	/**
	 * Single source of truth for feature gating.
	 */
	public static function is_active(): bool {
		$api_url = self::get_api_url();
		if (empty($api_url)) {
			return false;
		}

		$key = self::get_key();
		if (empty($key)) {
			return false;
		}

		return self::validate_key($key);
	}

	/**
	 * Validate a license key against the API.
	 * Uses transient caching to avoid hammering the API.
	 */
	public static function validate_key(string $key): bool {
		if (empty($key)) {
			return false;
		}

		$cache_key = self::cache_key_for($key);

		// Check cache first.
		$cached = get_transient($cache_key);
		if ($cached !== false) {
			return (bool) $cached;
		}

		// Call the validation API.
		$result = self::call_api($key);

		// Cache the result.
		set_transient($cache_key, (int) $result, self::CACHE_TTL);

		return $result;
	}

	/**
	 * Make the API call to validate the license key.
	 */
	private static function call_api(string $key): bool {
		$api_url = self::get_api_url();
		if (empty($api_url)) {
			return false;
		}

		$response = wp_remote_post(
			$api_url,
			[
				'timeout' => 5,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode([
					'license_key' => $key,
					'site_url'    => home_url(),
				]),
			]
		);

		if (is_wp_error($response)) {
			// API error — assume invalid rather than blocking.
			error_log('STBP License API error: ' . $response->get_error_message());
			error_log('STBP License API URL: ' . $api_url);
			return false;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code !== 200) {
			error_log(sprintf('STBP License API returned %d. Response: %s', $status_code, wp_remote_retrieve_body($response)));
			return false;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($body)) {
			return false;
		}

		return !empty($body['is_valid']);
	}

	/**
	 * Clear the cached validation result to force a fresh API call.
	 */
	public static function invalidate_cache(): void {
		// Remove legacy cache key from earlier builds.
		delete_transient(self::CACHE_KEY);

		$key = self::get_key();
		if (!empty($key)) {
			delete_transient(self::cache_key_for($key));
		}
	}

	/**
	 * Sanitize the license key and clear cache when it changes via settings save.
	 */
	public static function sanitize_key_input($value): string {
		self::invalidate_cache();
		return sanitize_text_field((string) $value);
	}

	/* ----- Admin settings integration ----- */

	/**
	 * Register the license-key field inside the Pro settings section.
	 */
	public static function register_settings(string $page = ''): void {
		if (empty($page)) {
			$page = defined('STBC_SLUG') ? STBC_SLUG . '-settings' : 'shortcode-to-blocks-settings';
		}

		// Register the license key option so WordPress handles saving it automatically.
		register_setting('stbc_settings', self::KEY_OPTION, [
			'type'              => 'string',
			'sanitize_callback' => [__CLASS__, 'sanitize_key_input'],
			'show_in_rest'      => false,
		]);

		add_settings_section(
			'stbp_license_section',
			__('License', 'shortcode-to-blocks-pro'),
			'__return_false',
			$page
		);

		add_settings_field(
			'stbp_license_key',
			__('License Key', 'shortcode-to-blocks-pro'),
			[__CLASS__, 'render_key_field'],
			$page,
			'stbp_license_section'
		);

		add_settings_field(
			'stbp_license_status',
			__('License Status', 'shortcode-to-blocks-pro'),
			[__CLASS__, 'render_status_field'],
			$page,
			'stbp_license_section'
		);
	}

	/**
	 * Render the license key input field.
	 */
	public static function render_key_field(): void {
		$key = self::get_key();
		echo '<input type="text" name="' . esc_attr(self::KEY_OPTION) . '" ';
		echo 'value="' . esc_attr($key) . '" ';
		echo 'placeholder="' . esc_attr__('Paste your license key here', 'shortcode-to-blocks-pro') . '" ';
		echo 'style="width:100%; max-width:400px; padding:8px;" />';
		echo '<p class="description">';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: URL to customer billing portal */
				__('Find your license key in your <a href="%s" target="_blank" rel="noopener">customer account</a>.', 'shortcode-to-blocks-pro'),
				'https://shortcode-to-blocks.lemonsqueezy.com/billing'
			)
		);
		echo '</p>';
	}

	/**
	 * Render the license status field.
	 */
	public static function render_status_field(): void {
		$key = self::get_key();
		if (empty($key)) {
			echo '<span style="color:#999;">';
			esc_html_e('No license key entered.', 'shortcode-to-blocks-pro');
			echo '</span>';
			return;
		}

		if (self::is_active()) {
			echo '<span class="dashicons dashicons-yes-alt" style="color: green; vertical-align: middle;"></span> ';
			echo '<span style="color: green;">';
			esc_html_e('License Active', 'shortcode-to-blocks-pro');
			echo '</span>';
			return;
		}

		echo '<span class="dashicons dashicons-warning" style="color: #b32d2e; vertical-align: middle;"></span> ';
		echo '<span style="color: #b32d2e;">';
		esc_html_e('License Inactive', 'shortcode-to-blocks-pro');
		echo '</span>';
		echo '<p class="description">';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: URL to customer billing portal */
				__('Double-check your license key or <a href="%s" target="_blank" rel="noopener">manage your subscription</a> to reactivate.', 'shortcode-to-blocks-pro'),
				'https://shortcode-to-blocks.lemonsqueezy.com/billing'
			)
		);
		echo '</p>';
	}

	/**
	 * Handle form submission of the license key.
	 */
	public static function handle_settings_save(): void {
		// Check this is our settings page and user can manage options.
		if (!current_user_can('manage_options')) {
			return;
		}

		// Check nonce.
		if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'shortcode-to-blocks-settings')) {
			return;
		}

		// Only proceed if the license key field was submitted.
		if (!isset($_POST[self::KEY_OPTION])) {
			return;
		}

		$key = sanitize_text_field(wp_unslash($_POST[self::KEY_OPTION]));
		self::set_key($key);

		// Redirect to clear the form and show updated status.
		wp_safe_remote_post(
			admin_url('admin.php?page=' . (defined('STBC_SLUG') ? STBC_SLUG : 'shortcode-to-blocks') . '-settings'),
			['blocking' => false]
		);
	}

	/**
	 * Clean up options on plugin uninstall.
	 */
	public static function clean(): void {
		delete_option(self::KEY_OPTION);
		delete_transient(self::CACHE_KEY);

		$key = self::get_key();
		if (!empty($key)) {
			delete_transient(self::cache_key_for($key));
		}
	}
}
