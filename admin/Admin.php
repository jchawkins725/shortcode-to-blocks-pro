<?php
namespace STBP\admin;

defined('ABSPATH') || exit;

/**
 * Pro Admin — registers only Pro-only AJAX handlers.
 *
 * Menus, tabs, editor enqueue, and metabox are handled by the free plugin;
 * Pro injects its pages via the stb_register_admin_menus action and its
 * tabs via the stb_admin_tabs filter (both wired in the bootstrap).
 */
class Admin {

    public function init() {
        add_action('wp_ajax_stbp_get_parents', [self::class, 'ajax_get_parents']);
        add_action('wp_ajax_stbp_dismiss_old_backups', [self::class, 'ajax_dismiss_old_backups']);
    }

    /**
     * AJAX: dismiss the "old backups" dashboard notice for the current user.
     * Stores the count so the notice reappears only if the number changes.
     */
    public static function ajax_dismiss_old_backups() {
        check_ajax_referer('stbp_dismiss_old_backups');
        $count = isset($_POST['count']) ? (int) $_POST['count'] : 0;
        update_user_meta(get_current_user_id(), 'stbp_dismiss_old_backups', $count);
        wp_send_json_success();
    }

    /**
     * AJAX handler for dynamic parent dropdown (used by Batch convert page).
     */
    public static function ajax_get_parents() {
        check_ajax_referer('stbp_get_parents');

        $cap = class_exists('\\STB\\admin\\Settings')
            ? \STB\admin\Settings::required_capability()
            : 'manage_options';

        if (!current_user_can($cap)) {
            wp_send_json_error(['error' => 'permission denied'], 403);
        }

        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        if (!$type) {
            wp_send_json_success(['parents' => []]);
        }

        $posts = get_posts([
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        // Build tree: map parent => children
        $by_parent = [];
        foreach ($posts as $p) {
            $by_parent[$p->post_parent][] = $p;
        }

        // Recursive flatten with depth
        $result = [];
        $add_with_depth = function ($parent_id, $depth) use (&$add_with_depth, &$by_parent, &$result) {
            if (!isset($by_parent[$parent_id])) return;
            foreach ($by_parent[$parent_id] as $p) {
                $result[] = [
                    'ID'         => $p->ID,
                    'post_title' => $p->post_title,
                    'depth'      => $depth,
                ];
                $add_with_depth($p->ID, $depth + 1);
            }
        };
        $add_with_depth(0, 0);

        wp_send_json_success(['parents' => $result]);
    }

    /**
     * Render tabs — delegates to the free plugin's render_tabs() since Pro
     * tabs are already injected via the stb_admin_tabs filter.
     */
    public static function render_tabs(string $active = ''): void {
        if (class_exists('\\STB\\admin\\Admin') && method_exists('\\STB\\admin\\Admin', 'render_tabs')) {
            \STB\admin\Admin::render_tabs($active);
        }
    }
}
