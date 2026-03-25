<?php
namespace STBP\admin;

use STBP\includes\Logger;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Logs extends \WP_List_Table {
    private $items_total = 0;

    public function __construct() {
        parent::__construct([
            'singular' => 'stbp_log',
            'plural'   => 'stbp_logs',
            'ajax'     => false,
        ]);
    }

    /** Top-level renderer for the page */
   public static function render_logs_page() {
    if (! current_user_can(Settings::required_capability())) {
        wp_die(__('Insufficient permissions', 'shortcode-to-blocks-pro'));
    }
    // show admin notice if redirected from install_logs()
    if (isset($_GET['logs']) && $_GET['logs'] === 'installed') {
        echo '<div class="notice notice-success is-dismissible" style="margin:12px 0;">';
        echo '<p>' . esc_html__('Logs table has been installed or repaired (already existed).', 'shortcode-to-blocks-pro') . '</p>';
        echo '</div>';
    }
    if (isset($_GET['logs_purged_all'])) {
        $n = max(0, (int) $_GET['logs_purged_all']);
        if ($n > 0) {
            echo '<div class="notice notice-success is-dismissible" style="margin:12px 0;"><p>'
            . esc_html__('All logs were purged.', 'shortcode-to-blocks-pro')
            . '</p></div>';
        } else {
            echo '<div class="notice notice-info is-dismissible" style="margin:12px 0;"><p>'
            . esc_html__('No logs to purge.', 'shortcode-to-blocks-pro')
            . '</p></div>';
        }
    } elseif (isset($_GET['logs_purged'], $_GET['days'])) {
        $n = max(0, (int) $_GET['logs_purged']);
        $d = max(0, (int) $_GET['days']);
        if ($n > 0) {
            printf(
                '<div class="notice notice-success is-dismissible" style="margin:12px 0;"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: 1: number of logs, 2: number of days */
                    _n('%1$d log older than %2$d day was purged.', '%1$d logs older than %2$d days were purged.', $n, 'shortcode-to-blocks-pro'),
                    $n, $d
                ) )
            );
        } else {
            printf(
                '<div class="notice notice-info is-dismissible" style="margin:12px 0;"><p>%s</p></div>',
                esc_html( sprintf( __('No logs older than %d days to purge.', 'shortcode-to-blocks-pro'), $d ) )
            );
        }
    }
    $page_slug = (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-logs';
    $nonce     = wp_create_nonce('stbp_convert_nonce');

    // Action buttons (routes already implemented in Tools.php / AJAX)
    $export_url  = wp_nonce_url(
        admin_url('admin-post.php?action=stbp_export_logs'),
        'stbp_convert_nonce','stbp_convert_nonce_field'
    );
    $purge_url   = wp_nonce_url(
        admin_url('admin-ajax.php?action=stbp_purge_logs&all=1'),
        'stbp_convert_nonce','stbp_convert_nonce_field'
    );
    $install_url = wp_nonce_url(
        admin_url('admin-ajax.php?action=stbp_install_logs'),
        'stbp_convert_nonce','stbp_convert_nonce_field'
    );

    // read filters
    $action  = isset($_GET['log_action']) ? sanitize_text_field($_GET['log_action']) : '';
    $status  = isset($_GET['log_status']) ? sanitize_text_field($_GET['log_status']) : '';
    $search  = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $paged   = max(1, (int)($_GET['paged'] ?? 1));

    // table
    $list_table = new self();
    $list_table->prepare_items([
        'paged'  => $paged,
        'search' => $search,
        'action' => $action,
        'status' => $status,
    ]);
    // Optional quick stats — safely guarded (or delete this whole block)
    $counts = null;
    if (method_exists('\STBP\includes\Logger', 'counts_by_action_status')) {
        $counts = \STBP\includes\Logger::counts_by_action_status();
    }
    ?>
            <?php \STB\admin\Admin::render_tabs( (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-logs' ); ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Logs', 'shortcode-to-blocks-pro'); ?></h1>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'stbp_logs';
        $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s", $wpdb->esc_like($table)
        ));
        if ($exists !== $table) {
            $install_url = wp_nonce_url(
                admin_url('admin-ajax.php?action=stbp_install_logs'),
                'stbp_convert_nonce',
                'stbp_convert_nonce_field'
            );
            echo '<div class="notice notice-warning" style="margin:12px 0;">';
            echo '<p>' . esc_html__('Logs table is missing. Click "Install/repair logs table" to create it.', 'shortcode-to-blocks-pro') . '</p>';
            echo '<p><a class="button" href="' . esc_url($install_url) . '">' . esc_html__('Install/repair logs table', 'shortcode-to-blocks-pro') . '</a></p>';
            echo '</div>';
        }
        ?>
        <div class="stbp-actions" style="margin:12px 0 16px; display:flex; gap:8px; flex-wrap:wrap;">
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">
                <?php esc_html_e('Export logs (CSV)', 'shortcode-to-blocks-pro'); ?>
            </a>
            <?php if ( current_user_can( Settings::tools_capability() ) ) : ?>
                <a href="<?php echo esc_url($purge_url); ?>" class="button"
                    onclick="return confirm('<?php echo esc_js(__('This will permanently delete all logs. Continue?', 'shortcode-to-blocks-pro')); ?>');">
                    <?php esc_html_e('Purge all logs', 'shortcode-to-blocks-pro'); ?>
                </a>
                <a href="<?php echo esc_url($install_url); ?>" class="button">
                    <?php esc_html_e('Install/repair logs table', 'shortcode-to-blocks-pro'); ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if (is_array($counts) && !empty($counts)) : ?>
            <div class="stbp-badges" style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px;">
                <?php foreach ($counts as $act => $row): 
                    $succ = (int)($row['success'] ?? 0);
                    $err  = (int)($row['error'] ?? 0); ?>
                    <span class="badge" style="background:#f0f0f1;border:1px solid #dcdcde;border-radius:999px;padding:2px 8px;">
                        <?php echo esc_html("$act: $succ✓ / $err✗"); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="get" style="margin-bottom:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
            <?php
                // action filter
                $actions = [
                    ''              => __('All actions', 'shortcode-to-blocks-pro'),
                    'convert'       => __('convert', 'shortcode-to-blocks-pro'),
                    'revert'        => __('revert', 'shortcode-to-blocks-pro'),
                    'batch'         => __('batch', 'shortcode-to-blocks-pro'),
                    'batch-revert'  => __('batch-revert', 'shortcode-to-blocks-pro'),
                    'scan'          => __('scan', 'shortcode-to-blocks-pro'),
                    'error'         => __('error', 'shortcode-to-blocks-pro'),
                    'purge'         => __('purge', 'shortcode-to-blocks-pro'),
                ];
                echo '<label class="screen-reader-text" for="stbp_log_action">'.esc_html__('Filter by action','shortcode-to-blocks-pro').'</label>';
                echo '<select name="log_action" id="stbp_log_action">';
                foreach ($actions as $val => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($action,$val,false), esc_html($label));
                }
                echo '</select>';

                // status filter
                $statuses = [
                    ''         => __('All statuses', 'shortcode-to-blocks-pro'),
                    'success'  => __('success', 'shortcode-to-blocks-pro'),
                    'error'    => __('error', 'shortcode-to-blocks-pro'),
                    'warning'  => __('warning', 'shortcode-to-blocks-pro'),
                ];
                echo '<label class="screen-reader-text" for="stbp_log_status">'.esc_html__('Filter by status','shortcode-to-blocks-pro').'</label>';
                echo '<select name="log_status" id="stbp_log_status">';
                foreach ($statuses as $val => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($status,$val,false), esc_html($label));
                }
                echo '</select>';
            ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search messages…','shortcode-to-blocks-pro'); ?>">
            <button class="button"><?php esc_html_e('Filter', 'shortcode-to-blocks-pro'); ?></button>
            <?php
                $has_filters = ($action || $status || $search !== '');
                if ($has_filters):
                    $clear = add_query_arg('page', $page_slug, admin_url('admin.php'));
            ?>
                <a class="button button-secondary" href="<?php echo esc_url($clear); ?>">
                    <?php esc_html_e('Clear filters', 'shortcode-to-blocks-pro'); ?>
                </a>
            <?php endif; ?>
        </form>

        <div class="stbp-table">
            <?php $list_table->display(); ?>
        </div>
    </div>
    <?php
}

    /** Columns */
    public function get_columns() {
        return [
            'created_at' => __('Time', 'shortcode-to-blocks-pro'),
            'user'       => __('User', 'shortcode-to-blocks-pro'),
            'post'       => __('Post', 'shortcode-to-blocks-pro'),
            'action'     => __('Action', 'shortcode-to-blocks-pro'),
            'status'     => __('Status', 'shortcode-to-blocks-pro'),
            'message'    => __('Message', 'shortcode-to-blocks-pro'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'created_at' => ['created_at', true],
            'action'     => ['action', false],
            'status'     => ['status', false],
        ];
    }

    /** Fetch + paginate items from Logger (or the logs table) */
    public function prepare_items($args = []) {
        global $wpdb;

        $per_page = 20;
        $paged    = max(1, (int)($args['paged'] ?? ($_GET['paged'] ?? 1)));
        $offset   = ($paged - 1) * $per_page;

        $search   = sanitize_text_field($args['search'] ?? ($_GET['s'] ?? ''));
        $action   = sanitize_text_field($args['action'] ?? ($_GET['log_action'] ?? ''));
        $status   = sanitize_text_field($args['status'] ?? ($_GET['log_status'] ?? ''));

        // Build SQL
        $table  = $wpdb->prefix . 'stbp_logs';
        $where  = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND (message LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if ($action !== '') {
            $where .= ' AND action = %s';
            $params[] = sanitize_key($action);
        }
        if ($status !== '') {
            $where .= ' AND status = %s';
            $params[] = sanitize_key($status);
        }

        // Sorting
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'created_at';
        $order   = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';
        $allowed = ['created_at','action','status'];
        if (! in_array($orderby, $allowed, true)) { $orderby = 'created_at'; }

        // Total
        $total = (int) $wpdb->get_var(
            $params ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params)
                    : "SELECT COUNT(*) FROM {$table} WHERE {$where}"
        );
        $this->items_total = $total;

        // Rows
        $sql  = "SELECT created_at, user_id, post_id, action, status, message
                 FROM {$table}
                 WHERE {$where}
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge($params, [$per_page, $offset])),
            ARRAY_A
        );

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->items = array_map([$this,'map_row'], $rows ?: []);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => max(1, (int)ceil($total / $per_page)),
        ]);
    }

    private function map_row(array $r): array {
        $user = $r['user_id'] ? get_user_by('id', (int)$r['user_id']) : null;
        $post_link = $r['post_id'] ? sprintf(
            '<a href="%s">%s</a>',
            esc_url(get_edit_post_link((int)$r['post_id'])),
            esc_html(get_the_title((int)$r['post_id']) ?: ('#'.(int)$r['post_id']))
        ) : null;

        return [
            'created_at' => esc_html( \STB\core\Helpers::format_admin_datetime($r['created_at']) ),
            'user'       => $user ? esc_html($user->display_name) : '—',
            'post'       => $post_link ?: '—',
            'action'     => esc_html($r['action']),
            'status'     => esc_html($r['status']),
            'message'    => esc_html(wp_trim_words($r['message'] ?? '', 30)),
        ];
    }

    public function column_default($item, $column_name) {
        return $item[$column_name] ?? '';
    }
}
