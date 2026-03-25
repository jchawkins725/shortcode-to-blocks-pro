<?php
namespace STBP\includes;

defined('ABSPATH') || exit;

class Logger {
    public static function log(string $action, string $status = 'success', string $message = '', ?int $post_id = null): void {
        global $wpdb;
        $table = $wpdb->prefix . 'stbp_logs';
        $wpdb->insert($table, [
            'created_at' => current_time('mysql'),
            'user_id'    => get_current_user_id() ?: null,
            'post_id'    => $post_id,
            'action'     => sanitize_key($action),
            'status'     => sanitize_key($status),
            'message'    => $message,
        ]);
    }

    public static function get_logs(array $args = []): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'stbp_logs';
        $limit  = isset($args['limit']) ? max(1, (int)$args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

        $where = '1=1';
        $params = [];

        if (!empty($args['search'])) {
            $where .= ' AND (message LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        if (!empty($args['action'])) {
            $where .= ' AND action = %s';
            $params[] = sanitize_key($args['action']);
        }
        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = sanitize_key($args['status']);
        }

        $args = array_merge($params, [$limit, $offset]);
        $sql  = $wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $sql_count = $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where", ...$params);
        $total = (int) $wpdb->get_var($sql_count);
        return ['rows' => $rows, 'total' => $total];
    }

    public static function purge_older_than(int $days): int {
        global $wpdb;
        $table = $wpdb->prefix . 'stbp_logs';
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < %s", $cutoff));
    }

    public static function purge_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'stbp_logs';
        return (int) $wpdb->query("DELETE FROM $table");
    }

    public static function export_csv(): void {
        $filename = 'stbp-logs-' . gmdate('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['created_at','user_id','post_id','action','status','message']);

        $page = 0; $per = 500;
        while (true) {
            $data = self::get_logs(['limit'=>$per, 'offset'=>$page*$per]);
            foreach ($data['rows'] as $r) {
                fputcsv($out, [$r['created_at'],$r['user_id'],$r['post_id'],$r['action'],$r['status'],$r['message']]);
            }
            if (count($data['rows']) < $per) break;
            $page++;
        }
        fclose($out);
        exit;
    }

    public static function maybe_install() {
        global $wpdb;
        $table = $wpdb->prefix . 'stbp_logs';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists === $table) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            post_id BIGINT UNSIGNED NULL,
            action VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            message TEXT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY action (action),
            KEY status (status),
            KEY post_id (post_id),
            KEY user_id (user_id)
        ) {$charset_collate};";
        dbDelta($sql);
    }

}
