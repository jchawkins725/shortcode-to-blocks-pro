<?php
namespace STBP\includes;

use STBP\admin\Admin;

defined('ABSPATH') || exit;

class Hooks {
    public function init() {
        add_action('save_post', [$this, 'maybe_flag_stbp_on_save'], 10, 2);
    }

    public function maybe_flag_stbp_on_save($post_id, $post) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (! $post instanceof \WP_Post) return;
        if (! in_array($post->post_type, Admin::allowed_post_types(), true)) return;

        Detector::flag_post($post_id, (string) $post->post_content);
        delete_transient('stbp_dash_counts'); // keep dashboard fresh
    }
}
