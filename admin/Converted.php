<?php
namespace STBP\admin;

defined('ABSPATH') || exit;

class Converted {
    public static function render_page() {
        if (! current_user_can(Settings::required_capability())) {
            wp_die(__('Insufficient permissions', 'shortcode-to-blocks-pro'));
        }

        global $wpdb;

        // sorting
        $allowed_orderby = ['id','title','type','status','converted_ts','backup_ts','batch_id'];
        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], $allowed_orderby, true) ? $_GET['orderby'] : 'converted_ts';
        $order   = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

        $types      = \STB\admin\Admin::allowed_post_types();
        $type       = isset($_GET['type']) && in_array($_GET['type'], $types, true) ? sanitize_key($_GET['type']) : '';
        $paged      = max(1, (int)($_GET['paged'] ?? 1));
        $per_page   = 20;
        $offset     = ($paged - 1) * $per_page;
        $search     = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $batch_filter = isset($_GET['batch_id']) ? sanitize_text_field($_GET['batch_id']) : '';
        $nonce      = wp_create_nonce('stbp_convert_nonce');
        // always point to admin.php and include the page slug explicitly
        $base_url = admin_url('admin.php');

        // persist filters and ensure page param is present
        $persist  = ['page' => (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-converted'];
        if ($type)                 { $persist['type']     = $type; }
        if ($search !== '')        { $persist['s']        = $search; }
        if ($batch_filter !== '')  { $persist['batch_id'] = $batch_filter; }

        // build a proper sort url that toggles order
        $sort_url = function(string $col) use ($base_url, $persist, $orderby, $order) {
            $next = ($orderby === $col && strtoupper($order) === 'ASC') ? 'desc' : 'asc';
            return add_query_arg(array_merge($persist, ['orderby' => $col, 'order' => $next]), $base_url);
        };

        // WHERE clauses
        $wheres = [];
        $params = [];

        // only posts that are converted (by backup or explicit flag)
        $convertedWhere = "
            (
                EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmc WHERE pmc.post_id = p.ID AND pmc.meta_key = '_stbp_original_content')
                OR
                EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmf WHERE pmf.post_id = p.ID AND pmf.meta_key = '_stbp_converted' AND pmf.meta_value = '1')
            )
        ";
        $wheres[] = $convertedWhere;

        if ($type) {
            $wheres[] = "p.post_type = %s";
            $params[] = $type;
        } else {
            $in = implode("','", array_map('esc_sql', $types));
            $wheres[] = "p.post_type IN ('{$in}')";
        }

        $wheres[] = "p.post_status IN ('publish','draft','pending','private','future')";

        if ($search !== '') {
            if (is_numeric($search)) {
                $wheres[] = "(p.ID = %d OR p.post_title LIKE %s)";
                $params[] = (int)$search;
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            } else {
                $wheres[] = "(p.post_title LIKE %s)";
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            }
        }

        if ($batch_filter !== '') {
            $wheres[] = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} pmb WHERE pmb.post_id = p.ID AND pmb.meta_key = '_stbp_batch_id' AND pmb.meta_value = %s)", $batch_filter);
        }

        $where_sql = implode(' AND ', $wheres);
        $params_where = $params; // snapshot BEFORE adding limit/offset

        // map sortable columns
        $orderby_sql = 'converted_ts';
        switch ($orderby) {
            case 'id':          $orderby_sql = 'p.ID'; break;
            case 'title':       $orderby_sql = 'post_title'; break;
            case 'type':        $orderby_sql = 'p.post_type'; break;
            case 'status':      $orderby_sql = 'p.post_status'; break;
            case 'backup_ts':   $orderby_sql = 'backup_ts'; break;
            case 'batch_id':    $orderby_sql = 'batch_id'; break;
        }

        // main query
        $sql = "
            SELECT 
                p.ID,
                p.post_type,
                p.post_status,
                MAX(p.post_title) AS post_title,
                MAX(pm_batch.meta_value) AS batch_id,
                UNIX_TIMESTAMP(MAX(p.post_modified_gmt)) AS converted_ts,
                UNIX_TIMESTAMP(MAX(pm_backup.meta_value)) AS backup_ts
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_backup
                ON pm_backup.post_id = p.ID AND pm_backup.meta_key = '_stbp_original_content_ts'
            LEFT JOIN {$wpdb->postmeta} pm_batch
                ON pm_batch.post_id = p.ID AND pm_batch.meta_key = '_stbp_batch_id'
            WHERE {$convertedWhere} AND {$where_sql}
            GROUP BY p.ID
            ORDER BY {$orderby_sql} {$order}
            LIMIT %d OFFSET %d
        ";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge($params_where, [$per_page, $offset]))
        );

        // count for pagination
        $count_sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE {$convertedWhere} AND {$where_sql}
        ";
        if (!empty($params_where)) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params_where));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }
        $total_pages = max(1, (int) ceil($total / $per_page));
        $base = add_query_arg('page', (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-converted', admin_url('admin.php'));
        ?>
        <?php \STB\admin\Admin::render_tabs( (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-converted' ); ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Converted Posts','shortcode-to-blocks-pro'); ?></h1>
            <hr class="wp-header-end">

            <form method="get" style="margin-bottom:12px">
                <input type="hidden" name="page" value="<?php echo esc_attr((defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-converted'); ?>">
                <label for="post_type_filter" class="screen-reader-text"><?php esc_html_e('Filter by type','shortcode-to-blocks-pro'); ?></label>
                <select name="type" id="post_type_filter">
                    <option value=""><?php esc_html_e('All Types', 'shortcode-to-blocks-pro'); ?></option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search title or ID','shortcode-to-blocks-pro'); ?>">
                <input type="text" name="batch_id" value="<?php echo esc_attr($batch_filter); ?>" placeholder="<?php esc_attr_e('Batch ID','shortcode-to-blocks-pro'); ?>" style="width:140px">
                <button class="button"><?php esc_html_e('Filter','shortcode-to-blocks-pro'); ?></button>
                <?php
                    // build a clean URL that just points to this page, no filters/sorting/pagination
                    $clear_url = add_query_arg('page', (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-converted', admin_url('admin.php'));

                    // show the button only when any filter/sort is active
                    $has_filters = ($type || $search !== '' || $batch_filter !== '' || isset($_GET['orderby']) || isset($_GET['order']) || isset($_GET['paged']));
                    ?>

                    <?php if ( $has_filters ): ?>
                    <a href="<?php echo esc_url($clear_url); ?>" class="button button-secondary" style="margin-left:8px;">
                        <?php esc_html_e('Clear filters', 'shortcode-to-blocks-pro'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <form id="stbp-converted-form" method="post">
                <?php wp_nonce_field('stbp_convert_nonce','stbp_convert_nonce_field'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Bulk actions'); ?></option>
                            <option value="revert"><?php esc_html_e('Revert'); ?></option>
                        </select>
                        <button class="button action"><?php esc_html_e('Apply'); ?></button>
                    </div>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all">
                            </td>

                            <?php
                            // helper to print a sortable th
                            $th = function($key, $label) use ($orderby, $order, $sort_url) {
                                $is_current = ($orderby === $key);
                                $cls = $is_current ? 'sorted ' . strtolower($order) : 'sortable ' . ($key === 'converted_ts' ? 'desc' : 'asc');
                                ?>
                                <th scope="col" class="manage-column <?php echo esc_attr($cls); ?>">
                                <a href="<?php echo esc_url($sort_url($key)); ?>">
                                    <span><?php echo esc_html($label); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                                </th>
                                <?php
                            };
                            ?>

                            <?php $th('id',           __('ID','shortcode-to-blocks-pro')); ?>
                            <?php $th('title',        __('Title','shortcode-to-blocks-pro')); ?>
                            <?php $th('type',         __('Type','shortcode-to-blocks-pro')); ?>
                            <?php $th('status',       __('Status','shortcode-to-blocks-pro')); ?>

                            <th scope="col" class="manage-column"><?php esc_html_e('Link','shortcode-to-blocks-pro'); ?></th>

                            <?php $th('converted_ts', __('Converted','shortcode-to-blocks-pro')); ?>
                            <?php $th('backup_ts',    __('Backup Saved','shortcode-to-blocks-pro')); ?>
                            <?php $th('batch_id',     __('Batch ID','shortcode-to-blocks-pro')); ?>
                            <th scope="col" class="manage-column"><?php esc_html_e('Actions','shortcode-to-blocks-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10"><?php esc_html_e('No converted posts found.','shortcode-to-blocks-pro'); ?></td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <th scope="row" class="check-column"><input type="checkbox" name="post_ids[]" value="<?php echo (int)$r->ID; ?>"></th>
                                <td><?php echo (int)$r->ID; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url(get_edit_post_link($r->ID)); ?>"><?php echo esc_html($r->post_title ?: get_the_title($r->ID)); ?></a></strong>
                                </td>
                                <td><?php echo esc_html($r->post_type); ?></td>
                                <td><?php echo esc_html($r->post_status); ?></td>
                                <td><a href="<?php echo esc_url(get_permalink($r->ID)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View','shortcode-to-blocks-pro'); ?></a></td>
                                <td><?php echo $r->converted_ts ? esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$r->converted_ts)) : '—'; ?></td>
                                <td><?php echo $r->backup_ts ? esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), (int)$r->backup_ts)) : '—'; ?></td>
                                <td>
                                    <?php if (! empty($r->batch_id)) : ?>
                                        <a href="<?php echo esc_url(add_query_arg(['page'=>'stbp_converted','batch_id'=>$r->batch_id], admin_url('admin.php'))); ?>"><?php echo esc_html($r->batch_id); ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="#" class="button-link-delete stbp-revert-single" data-post="<?php echo (int)$r->ID; ?>"><?php esc_html_e('Revert','shortcode-to-blocks-pro'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>

            <script>
            jQuery(function($){
                $('#cb-select-all').on('change', function(){
                    $('input[name="post_ids[]"]').prop('checked', this.checked);
                });

                $('#stbp-converted-form').on('submit', function(e){
                    var action = $('#bulk-action-selector-top').val();
                    if (action === 'revert') {
                        e.preventDefault();
                        var ids = $('input[name="post_ids[]"]:checked').map(function(){return this.value;}).get();
                        if (!ids.length) { alert('<?php echo esc_js(__('Select at least one post to revert.','shortcode-to-blocks-pro')); ?>'); return; }
                        $.post(ajaxurl, {
                            action: 'stbp_revert_posts',
                            ids: ids,
                            stbp_convert_nonce_field: '<?php echo esc_js($nonce); ?>'
                        }, function(resp){
                            if (resp && resp.success) { location.reload(); }
                            else { alert(resp && resp.data ? resp.data : 'Revert failed'); }
                        });
                    }
                });
                $('.stbp-revert-single').on('click', function(e){
                    e.preventDefault();
                    var id = $(this).data('post');
                    $.post(ajaxurl, {
                        action: 'stbp_revert_posts',
                        ids: [id],
                        stbp_convert_nonce_field: '<?php echo esc_js($nonce); ?>'
                    }, function(resp){
                        if (resp && resp.success) { location.reload(); }
                        else { alert(resp && resp.data ? resp.data : 'Revert failed'); }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
}
