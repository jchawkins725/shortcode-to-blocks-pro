<?php
// BulkActions.php: Adds a custom bulk action to the Pages screen for STBP conversion
namespace STBP\admin;

defined('ABSPATH') || exit;


// Dynamically add bulk action for all post types in settings
add_action('init', function() {
	if (!class_exists('STBP\\admin\\Settings')) return;
	$post_types = \STBP\admin\Settings::get()['post_types'] ?? ['post', 'page'];
	foreach ($post_types as $pt) {
		add_filter("bulk_actions-edit-{$pt}", function($actions) {
			$actions['stbp_bulk_convert'] = __('Convert to Blocks', 'shortcode-to-blocks-pro');
			return $actions;
		});

		add_filter("handle_bulk_actions-edit-{$pt}", function($redirect_url, $action, $post_ids) use ($pt) {
			if ($action !== 'stbp_bulk_convert') return $redirect_url;

			$converted = 0;
			$skipped   = 0;
			// Generate a batch ID for this bulk operation (match handle_batch() fallback)
			if (function_exists('wp_generate_uuid4')) {
				$batch_id = wp_generate_uuid4();
			} else {
				$batch_id = str_replace('.', '_', uniqid('b_', true));
			}

			foreach ($post_ids as $post_id) {
				$post_id = absint($post_id);
				if (! $post_id || ! current_user_can(Settings::required_capability()) || ! current_user_can('edit_post', $post_id)) {
					$skipped++;
					continue;
				}

				// Use Batch::convert_post if available
				if (class_exists('STBP\\admin\\Batch') && method_exists('STBP\\admin\\Batch', 'convert_post')) {
					$result = \STBP\admin\Batch::convert_post($post_id, $batch_id);
					if ($result === true) {
						$converted++;
					}
				} else {
					// Fallback: only allow metadata changes for posts the current user can edit.
					update_post_meta($post_id, '_stbp_converted', 1);
					update_post_meta($post_id, '_stbp_batch_id', sanitize_text_field($batch_id));
					$converted++;
				}
			}

			// Log batch summary
			if (class_exists('STBP\\includes\\Logger')) {
				\STBP\includes\Logger::log('batch', 'success', 'Bulk action conversion finished for post type: ' . $pt . ', batch_id: ' . $batch_id . ', converted: ' . $converted . ' of ' . count($post_ids) . ', skipped: ' . $skipped);
			}
			// Update last batch option for revert screen
			$now = time();
			$last = [
				'id'          => $batch_id,
				'finished_at' => $now,
				'types'       => [$pt],
				'limit'       => count($post_ids),
				'processed'   => $converted,
			];
			update_option('stbp_last_batch', $last, false);

			// Add a nonce for the admin notice
			$nonce = wp_create_nonce('stbp_bulk_notice');
			// Add a query arg so you can show a notice and batch id
			return add_query_arg([
				'stbp_bulk_converted' => $converted,
				'stbp_bulk_total' => count($post_ids),
				'stbp_bulk_skipped' => $skipped,
				'stbp_bulk_batch_id' => $batch_id,
				'stbp_bulk_notice_nonce' => $nonce,
			], $redirect_url);
		}, 10, 3);
	}
});

// Show admin notice after bulk action
add_action('admin_notices', function() {
	if (
		isset($_REQUEST['stbp_bulk_converted']) &&
		isset($_REQUEST['stbp_bulk_total']) &&
		!empty($_REQUEST['stbp_bulk_notice_nonce']) &&
		wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['stbp_bulk_notice_nonce'])), 'stbp_bulk_notice')
	) {
		$converted = intval($_REQUEST['stbp_bulk_converted']);
		$total = intval($_REQUEST['stbp_bulk_total']);
		$skipped = isset($_REQUEST['stbp_bulk_skipped']) ? intval($_REQUEST['stbp_bulk_skipped']) : 0;
		$batch_id = '';
		if (isset($_REQUEST['stbp_bulk_batch_id'])) {
			$batch_id = sanitize_text_field(wp_unslash($_REQUEST['stbp_bulk_batch_id']));
		}
		$screen = get_current_screen();
		$post_type = $screen ? $screen->post_type : '';
		$extra_note = $skipped > 0
			? ' ' . sprintf(
				/* translators: %d: number of items skipped due to permissions */
				esc_html__('Skipped %d item(s) due to permissions.', 'shortcode-to-blocks-pro'),
				$skipped
			)
			: '';

		echo '<div class="notice notice-success is-dismissible"><p>'
			. sprintf(
				// translators: 1: Number converted, 2: Total selected, 3: Post type, 4: Batch ID, 5: Optional skipped note
				esc_html__('STBP: Converted %1$d of %2$d selected items for post type "%3$s". Batch ID: %4$s%5$s', 'shortcode-to-blocks-pro'),
				esc_html($converted),
				esc_html($total),
				esc_html($post_type),
				esc_html($batch_id),
				esc_html($extra_note)
			)
			. '</p></div>';
	}
});
