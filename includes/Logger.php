<?php
namespace STBP\includes;

defined('ABSPATH') || exit;

class Logger {
    public static function log(string $action, string $status = 'success', string $message = '', ?int $post_id = null): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin log table write.
        $wpdb->insert(self::table_name(), [
            'created_at' => current_time('mysql'),
            'user_id'    => get_current_user_id() ?: null,
            'post_id'    => $post_id,
            'action'     => sanitize_key($action),
            'status'     => sanitize_key($status),
            'message'    => $message,
        ]);
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'stbp_logs';
    }

    public static function table_exists(): bool {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin check for this plugin's custom table.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return $exists === $table;
    }

    private static function build_log_where(array $args): array {
        global $wpdb;

        $clauses = [];
        $params  = [];

        if (! empty($args['search'])) {
            $clauses[] = 'message LIKE %s';
            $params[]  = '%' . $wpdb->esc_like((string) $args['search']) . '%';
        }
        if (! empty($args['action'])) {
            $clauses[] = 'action = %s';
            $params[]  = sanitize_key((string) $args['action']);
        }
        if (! empty($args['status'])) {
            $clauses[] = 'status = %s';
            $params[]  = sanitize_key((string) $args['status']);
        }

        $where_sql = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        return [$where_sql, $params];
    }

    private static function normalize_order(array $args): array {
        $orderby = isset($args['orderby']) ? sanitize_key((string) $args['orderby']) : 'created_at';
        $order   = isset($args['order']) && 'asc' === strtolower((string) $args['order']) ? 'ASC' : 'DESC';
        $allowed = ['created_at', 'action', 'status'];

        if (! in_array($orderby, $allowed, true)) {
            $orderby = 'created_at';
        }

        return [$orderby, $order];
    }

    public static function get_logs(array $args = []): array {
        global $wpdb;

        $table  = esc_sql(self::table_name());
        $limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        [$where_sql, $params] = self::build_log_where($args);
        [$orderby, $order]    = self::normalize_order($args);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe fixed table name, whitelisted ORDER BY, and placeholder-based filters for an admin log table.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", ...array_merge($params, [$limit, $offset])), ARRAY_A);

        if ($params) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe fixed table name and placeholder-based filters for an admin log table.
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe fixed plugin table name.
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public static function purge_older_than(int $days): int {
        global $wpdb;

        $table  = esc_sql(self::table_name());
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe fixed plugin table name.
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }

    public static function purge_all(): int {
        global $wpdb;

        $table = esc_sql(self::table_name());

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe fixed plugin table name.
        return (int) $wpdb->query("DELETE FROM {$table}");
    }

    public static function export_csv(): void {
        $filename = 'stbp-logs-' . gmdate('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['created_at','user_id','post_id','action','status','message']);

        $page = 0; $per = 500;
        while (true) {
            $data = self::get_logs(['limit' => $per, 'offset' => $page * $per]);
            foreach ($data['rows'] as $r) {
                fputcsv($out, [$r['created_at'], $r['user_id'], $r['post_id'], $r['action'], $r['status'], $r['message']]);
            }
            if (count($data['rows']) < $per) {
                break;
            }
            $page++;
        }

        exit;
    }

    public static function maybe_install() {
        global $wpdb;
        $table = self::table_name();

        if (self::table_exists()) {
            return;
        }

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
