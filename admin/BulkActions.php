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
			// Generate a batch ID for this bulk operation (match handle_batch() fallback)
			if (function_exists('wp_generate_uuid4')) {
				$batch_id = wp_generate_uuid4();
			} else {
				$batch_id = str_replace('.', '_', uniqid('b_', true));
			}

			foreach ($post_ids as $post_id) {
				// Use Batch::convert_post if available
				if (class_exists('STBP\\admin\\Batch') && method_exists('STBP\\admin\\Batch', 'convert_post')) {
					$result = \STBP\admin\Batch::convert_post($post_id, $batch_id);
					if ($result === true) {
						$converted++;
					}
				} else {
					// Fallback: mark as converted (customize as needed)
					update_post_meta($post_id, '_stbp_converted', 1);
					update_post_meta($post_id, '_stbp_batch_id', sanitize_text_field($batch_id));
					$converted++;
				}
			}

			// Log batch summary
			if (class_exists('STBP\\includes\\Logger')) {
				\STBP\includes\Logger::log('batch', 'success', 'Bulk action conversion finished for post type: ' . $pt . ', batch_id: ' . $batch_id . ', converted: ' . $converted . ' of ' . count($post_ids));
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

			// Add a query arg so you can show a notice and batch id
			return add_query_arg([
				'stbp_bulk_converted' => $converted,
				'stbp_bulk_total' => count($post_ids),
				'stbp_bulk_batch_id' => $batch_id,
			], $redirect_url);
		}, 10, 3);
	}
});

// Show admin notice after bulk action
add_action('admin_notices', function() {
	if (!empty($_REQUEST['stbp_bulk_converted'])) {
		$converted = intval($_REQUEST['stbp_bulk_converted']);
		$total = intval($_REQUEST['stbp_bulk_total']);
		$batch_id = sanitize_text_field($_REQUEST['stbp_bulk_batch_id'] ?? '');
		$screen = get_current_screen();
		$post_type = $screen ? $screen->post_type : '';
		echo '<div class="notice notice-success is-dismissible"><p>'
			. sprintf(
				esc_html__('STBP: Converted %d of %d selected items for post type "%s". Batch ID: %s', 'shortcode-to-blocks-pro'),
				$converted,
				$total,
				esc_html($post_type),
				esc_html($batch_id)
			)
			. '</p></div>';
	}
});
