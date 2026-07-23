<?php
$filepath = '/Users/mpa014/Code/mpawoo/wp/wp-content/plugins/myparcel-asia/myparcel-asia.php';
$code = file_get_contents($filepath);

// ----------------------------------------------------
// 1. ADD HELPER METHOD search_order_ids_by_customer_name
// ----------------------------------------------------
$target_helper_anchor = '    /**
     * Render the Manage Batch page
     */';
$helper_method = '    /**
     * Search order IDs by customer name (billing or shipping) supporting HPOS & standard CPT.
     *
     * @param string $search Search term
     * @return array Array of order IDs
     */
    public function search_order_ids_by_customer_name($search)
    {
        global $wpdb;
        $search_like = \'%\' . $wpdb->esc_like(sanitize_text_field($search)) . \'%\';

        // Check if HPOS is enabled
        $hpos_enabled = false;
        if (class_exists(\'\\Automattic\\WooCommerce\\Utilities\\OrderUtil\') &&
            method_exists(\'\\Automattic\\WooCommerce\\Utilities\\OrderUtil\', \'custom_orders_table_usage_is_enabled\')) {
            $hpos_enabled = \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled();
        } else {
            $hpos_enabled = !empty($wpdb->get_col("SHOW TABLES LIKE \'{$wpdb->prefix}wc_orders\'"));
        }

        if ($hpos_enabled) {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_addresses 
                 WHERE first_name LIKE %s OR last_name LIKE %s",
                $search_like, $search_like
            );
            return $wpdb->get_col($sql);
        } else {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key IN (\'_billing_first_name\', \'_billing_last_name\', \'_shipping_first_name\', \'_shipping_last_name\') 
                 AND meta_value LIKE %s",
                $search_like
            );
            return $wpdb->get_col($sql);
        }
    }

';
$code = str_replace($target_helper_anchor, $helper_method . $target_helper_anchor, $code);

// ----------------------------------------------------
// 2. ADD CUSTOM CSS STYLES FOR PAGINATION
// ----------------------------------------------------
$css_pagination = '
                /* Custom Pagination styles to match image */
                .mpa-page-btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 32px;
                    height: 32px;
                    border-radius: 4px;
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 14px;
                    box-sizing: border-box;
                    user-select: none;
                }
                .mpa-page-disabled {
                    background-color: #f1f5f9;
                    border: 1px solid #cbd5e1;
                    color: #94a3b8;
                    cursor: not-allowed;
                }
                .mpa-page-active {
                    background-color: #ffffff;
                    border: 1px solid #2563eb;
                    color: #2563eb;
                    transition: all 0.15s ease-in-out;
                }
                .mpa-page-active:hover {
                    background-color: #eff6ff;
                    border-color: #1d4ed8;
                    color: #1d4ed8;
                }
                .mpa-page-info {
                    font-size: 14px;
                    color: #1e293b;
                    font-weight: 500;
                    margin: 0 4px;
                }
';
// Let's insert this CSS inside the To Process style block (under .mpa-batch-wrap rule)
$code = str_replace("                .mpa-batch-wrap {\n                    font-family: 'Inter', sans-serif;\n                    margin: 20px 20px 0 0;\n                    color: #1e293b;\n                }", "                .mpa-batch-wrap {\n                    font-family: 'Inter', sans-serif;\n                    margin: 20px 20px 0 0;\n                    color: #1e293b;\n                }" . $css_pagination, $code);

// Also insert inside the List View style block (under .mpa-batch-table rule or similar)
$code = str_replace("                    .mpa-batch-table {\n                        width: 100%;\n                        border-collapse: collapse;\n                        background: #ffffff;\n                        border: 1px solid #e2e8f0;\n                        font-size: 13px;\n                        margin-top: 15px;\n                    }", "                    .mpa-batch-table {\n                        width: 100%;\n                        border-collapse: collapse;\n                        background: #ffffff;\n                        border: 1px solid #e2e8f0;\n                        font-size: 13px;\n                        margin-top: 15px;\n                    }" . $css_pagination, $code);


// ----------------------------------------------------
// 3. TO PROCESS PAGE CHANGES
// ----------------------------------------------------
// Replaces the query block in render_manage_batch
$to_process_query_old = '        // Fetch WooCommerce processing orders
        $orders = array();
        if (class_exists(\'WooCommerce\')) {
            $orders = wc_get_orders(array(
                \'status\' => array(\'wc-processing\'),
                \'limit\' => -1,
                \'meta_query\' => array(
                    \'relation\' => \'OR\',
                    array(
                        \'key\'     => \'_mpa_batch_id\',
                        \'compare\' => \'NOT EXISTS\',
                    ),
                    array(
                        \'key\'     => \'_mpa_batch_id\',
                        \'value\'   => \'\',
                        \'compare\' => \'=\',
                    ),
                ),
            ));
        }';
$to_process_query_new = '        // Fetch WooCommerce processing orders with search and pagination
        $orders = array();
        $total_pages = 1;
        $total_orders = 0;
        $paged = isset($_GET[\'paged\']) ? max(1, intval($_GET[\'paged\'])) : 1;
        $search = isset($_GET[\'mpa_search\']) ? sanitize_text_field($_GET[\'mpa_search\']) : \'\';

        if (class_exists(\'WooCommerce\')) {
            $query_args = array(
                \'status\' => array(\'wc-processing\'),
                \'limit\' => 10,
                \'page\' => $paged,
                \'paginate\' => true,
                \'meta_query\' => array(
                    \'relation\' => \'OR\',
                    array(
                        \'key\'     => \'_mpa_batch_id\',
                        \'compare\' => \'NOT EXISTS\',
                    ),
                    array(
                        \'key\'     => \'_mpa_batch_id\',
                        \'value\'   => \'\',
                        \'compare\' => \'=\',
                    ),
                ),
            );

            if (!empty($search)) {
                $matching_ids = $this->search_order_ids_by_customer_name($search);
                if (!empty($matching_ids)) {
                    $query_args[\'post__in\'] = $matching_ids;
                } else {
                    $query_args[\'post__in\'] = array(0);
                }
            }

            $results = wc_get_orders($query_args);
            $orders = $results->orders;
            $total_pages = $results->max_num_pages;
            $total_orders = $results->total;
        }';
$code = str_replace($to_process_query_old, $to_process_query_new, $code);

// Replaces the summary header bar with the search form combined
$to_process_header_old = '            <!-- Sticky Summary bar -->
            <div class="mpa-sticky-header">
                <div class="mpa-sticky-info">
                    <div class="mpa-sticky-stat">
                        <span class="mpa-sticky-label"><?php esc_html_e(\'Topup Balance\', \'myparcel-asia\'); ?></span>
                        <span class="mpa-sticky-val">RM <?php echo esc_html(number_format($balance, 2)); ?></span>
                    </div>
                    <div class="mpa-sticky-stat">
                        <span class="mpa-sticky-label"><?php esc_html_e(\'Selected Total Price\', \'myparcel-asia\'); ?></span>
                        <span class="mpa-sticky-val" id="mpa-selected-total">RM 0.00</span>
                    </div>
                    <div id="mpa-status-msg" style="display:none;"></div>
                </div>
                <div>
                    <button type="button" class="button button-primary" id="mpa-btn-checkout" disabled>
                        <?php esc_html_e(\'Add to Batch\', \'myparcel-asia\'); ?>
                    </button>
                </div>
            </div>';
$to_process_header_new = '            <!-- Sticky Summary bar with Search and Stats combined -->
            <div class="mpa-sticky-header" style="display: flex; align-items: center; justify-content: space-between; gap: 20px;">
                <!-- Left: Search Box -->
                <form method="get" action="" style="display: flex; gap: 8px; align-items: center; margin: 0; flex: 1; max-width: 420px;">
                    <input type="hidden" name="page" value="myparcel-asia-batch">
                    <input type="text" name="mpa_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e(\'Search customer name...\', \'myparcel-asia\'); ?>" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100%; max-width: 250px; height: 32px; line-height: 30px; box-sizing: border-box;">
                    <button type="submit" class="button button-secondary" style="height: 32px; line-height: 30px;"><?php esc_html_e(\'Search\', \'myparcel-asia\'); ?></button>
                    <?php if (!empty($search)): ?>
                        <a href="<?php echo esc_url(admin_url(\'admin.php?page=myparcel-asia-batch\')); ?>" class="button button-link" style="color: #ef4444; height: 32px; line-height: 32px; text-decoration: none;"><?php esc_html_e(\'Clear\', \'myparcel-asia\'); ?></a>
                    <?php endif; ?>
                </form>

                <!-- Right: Stats & Action Button -->
                <div style="display: flex; align-items: center; gap: 30px;">
                    <div class="mpa-sticky-info" style="margin-bottom: 0;">
                        <div class="mpa-sticky-stat">
                            <span class="mpa-sticky-label"><?php esc_html_e(\'Topup Balance\', \'myparcel-asia\'); ?></span>
                            <span class="mpa-sticky-val">RM <?php echo esc_html(number_format($balance, 2)); ?></span>
                        </div>
                        <div class="mpa-sticky-stat">
                            <span class="mpa-sticky-label"><?php esc_html_e(\'Selected Total Price\', \'myparcel-asia\'); ?></span>
                            <span class="mpa-sticky-val" id="mpa-selected-total">RM 0.00</span>
                        </div>
                        <div id="mpa-status-msg" style="display:none;"></div>
                    </div>
                    <div>
                        <button type="button" class="button button-primary" id="mpa-btn-checkout" disabled style="height: 32px; line-height: 30px;">
                            <?php esc_html_e(\'Add to Batch\', \'myparcel-asia\'); ?>
                        </button>
                    </div>
                </div>
            </div>';
$code = str_replace($to_process_header_old, $to_process_header_new, $code);

// Replaces table header & loop for To Process table
$to_process_table_old = '            <table class="mpa-batch-table">
                <thead>
                    <tr>
                        <th width="160"><?php esc_html_e(\'Order\', \'myparcel-asia\'); ?></th>
                        <th><?php esc_html_e(\'Shipping Details\', \'myparcel-asia\'); ?></th>
                        <th width="220"><?php esc_html_e(\'Item Details\', \'myparcel-asia\'); ?></th>
                        <th width="140"><?php esc_html_e(\'Courier\', \'myparcel-asia\'); ?></th>
                        <th width="110" class="mpa-col-right"><?php esc_html_e(\'AWB Price\', \'myparcel-asia\'); ?></th>
                        <th width="40" style="text-align:center;"><input type="checkbox" id="mpa-select-all"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($orders)):
                        ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 30px; color: #94a3b8;">
                                <?php esc_html_e(\'No processing or pending orders found.\', \'myparcel-asia\'); ?>
                            </td>
                        </tr>
                        <?php
                    else:
                        foreach ($orders as $order):';
$to_process_table_new = '            <table class="mpa-batch-table">
                <thead>
                    <tr>
                        <th width="50" style="text-align:center;">#</th>
                        <th width="160"><?php esc_html_e(\'Order\', \'myparcel-asia\'); ?></th>
                        <th><?php esc_html_e(\'Shipping Details\', \'myparcel-asia\'); ?></th>
                        <th width="220"><?php esc_html_e(\'Item Details\', \'myparcel-asia\'); ?></th>
                        <th width="140"><?php esc_html_e(\'Courier\', \'myparcel-asia\'); ?></th>
                        <th width="110" class="mpa-col-right"><?php esc_html_e(\'AWB Price\', \'myparcel-asia\'); ?></th>
                        <th width="40" style="text-align:center;"><input type="checkbox" id="mpa-select-all"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($orders)):
                        ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 30px; color: #94a3b8;">
                                <?php esc_html_e(\'No processing or pending orders found.\', \'myparcel-asia\'); ?>
                            </td>
                        </tr>
                        <?php
                    else:
                        $row_count = 1;
                        foreach ($orders as $order):';
$code = str_replace($to_process_table_old, $to_process_table_new, $code);

// Inject row index in To Process table rows
$to_process_row_old = '                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url(\'post.php?post=\' . $order->get_id() . \'&action=edit\')); ?>"';
$to_process_row_new = '                            <?php $row_index = (($paged - 1) * 10) + $row_count; ?>
                            <tr>
                                <td style="text-align:center; font-weight: 600; color: #64748b;"><?php echo $row_index; ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url(\'post.php?post=\' . $order->get_id() . \'&action=edit\')); ?>"';
$code = str_replace($to_process_row_old, $to_process_row_new, $code);

// Increment row count at To Process loop end
$to_process_loop_end_old = '                            </tr>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </tbody>
            </table>';
$to_process_loop_end_new = '                            </tr>
                            <?php
                            $row_count++;
                        endforeach;
                    endif;
                    ?>
                </tbody>
            </table>

            <!-- Custom Pagination to match the user\'s mockup -->
            <?php if ($total_orders > 0): ?>
                <div class="mpa-pagination-container" style="display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; font-size: 14px; color: #334155; padding-right: 10px;">
                    <span class="mpa-pagination-total" style="margin-right: auto; color: #475569; font-weight: 500; font-size: 15px;"><?php echo esc_html($total_orders); ?> <?php _e(\'items\', \'myparcel-asia\'); ?></span>
                    
                    <!-- First Page Button («) -->
                    <?php if ($paged > 1): ?>
                        <a href="<?php echo esc_url(add_query_arg(\'paged\', 1)); ?>" class="mpa-page-btn mpa-page-active">«</a>
                    <?php else: ?>
                        <span class="mpa-page-btn mpa-page-disabled">«</span>
                    <?php endif; ?>

                    <!-- Previous Page Button (‹) -->
                    <?php if ($paged > 1): ?>
                        <a href="<?php echo esc_url(add_query_arg(\'paged\', $paged - 1)); ?>" class="mpa-page-btn mpa-page-active">‹</a>
                    <?php else: ?>
                        <span class="mpa-page-btn mpa-page-disabled">‹</span>
                    <?php endif; ?>

                    <!-- Page info text (e.g. 1 of 2) -->
                    <span class="mpa-page-info" style="font-weight: 500; color: #1e293b; padding: 0 4px;"><?php printf(__(\'%d of %d\', \'myparcel-asia\'), $paged, $total_pages); ?></span>

                    <!-- Next Page Button (›) -->
                    <?php if ($paged < $total_pages): ?>
                        <a href="<?php echo esc_url(add_query_arg(\'paged\', $paged + 1)); ?>" class="mpa-page-btn mpa-page-active">›</a>
                    <?php else: ?>
                        <span class="mpa-page-btn mpa-page-disabled">›</span>
                    <?php endif; ?>

                    <!-- Last Page Button (») -->
                    <?php if ($paged < $total_pages): ?>
                        <a href="<?php echo esc_url(add_query_arg(\'paged\', $total_pages)); ?>" class="mpa-page-btn mpa-page-active">»</a>
                    <?php else: ?>
                        <span class="mpa-page-btn mpa-page-disabled">»</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>';
$code = str_replace($to_process_loop_end_old, $to_process_loop_end_new, $code);


// ----------------------------------------------------
// 4. DETAIL VIEW CHANGES (Inside render_manage_batch_dashboard)
// ----------------------------------------------------
// Setup Detail View pagination & search variables
$detail_setup_old = '        if (!empty($batch_id) && isset($batches[$batch_id])) {
            // Detail View
            $batch = $batches[$batch_id];
            ?>';
$detail_setup_new = '        if (!empty($batch_id) && isset($batches[$batch_id])) {
            // Detail View
            $batch = $batches[$batch_id];

            $search = isset($_GET[\'mpa_search\']) ? sanitize_text_field($_GET[\'mpa_search\']) : \'\';
            $paged = isset($_GET[\'paged\']) ? max(1, intval($_GET[\'paged\'])) : 1;
            $limit = 10;

            $all_batch_order_ids = $batch[\'orders\'];
            if (!empty($search)) {
                $search_ids = $this->search_order_ids_by_customer_name($search);
                $filtered_order_ids = array_intersect($all_batch_order_ids, $search_ids);
            } else {
                $filtered_order_ids = $all_batch_order_ids;
            }

            $total_orders_in_batch = count($filtered_order_ids);
            $total_pages = ceil($total_orders_in_batch / $limit);
            $offset = ($paged - 1) * $limit;
            $paginated_order_ids = array_slice($filtered_order_ids, $offset, $limit);
            ?>';
$code = str_replace($detail_setup_old, $detail_setup_new, $code);

// Combined search and stats in Detail View header
$detail_header_old = '                <div class="mpa-batch-header">
                    <div class="mpa-batch-meta-grid">
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e(\'Status\', \'myparcel-asia\'); ?></span>
                            <span class="mpa-meta-val"
                                style="color: <?php echo \'completed\' === $batch[\'status\'] ? \'#059669\' : \'#d97706\'; ?>;">
                                <?php echo esc_html(ucfirst($batch[\'status\'])); ?>
                            </span>
                        </div>
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e(\'Created By\', \'myparcel-asia\'); ?></span>
                            <span class="mpa-meta-val"><?php echo esc_html($batch[\'created_by\']); ?></span>
                        </div>
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e(\'Total Orders\', \'myparcel-asia\'); ?></span>
                            <span class="mpa-meta-val"><?php echo esc_html($batch[\'total_order\']); ?></span>
                        </div>
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e(\'Total Price\', \'myparcel-asia\'); ?></span>
                            <span class="mpa-meta-val">RM
                                <?php echo esc_html(number_format($batch[\'total_awb_price\'], 2)); ?></span>
                        </div>
                    </div>

                    <div>
                        <?php if (\'completed\' === $batch[\'status\']): ?>
                            <?php if (!empty($batch[\'thermal_awb_url\'])): ?>
                                <a href="<?php echo esc_url($batch[\'thermal_awb_url\']); ?>" target="_blank" class="button button-primary"
                                    style="background:#059669; border-color:#059669;">
                                    <?php esc_html_e(\'Download AWB\', \'myparcel-asia\'); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php $api_key = get_option(\'mpa_api_key\', \'\'); ?>
                            <?php if (empty($api_key)): ?>
                                <button type="button" class="button button-primary" disabled title="<?php esc_attr_e(\'Please configure a valid API Key in Settings.\', \'myparcel-asia\'); ?>">
                                    <?php esc_html_e(\'Invalid API Key\', \'myparcel-asia\'); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="button button-primary" id="mpa-btn-create-batch-awb">
                                    <?php esc_html_e(\'Create AWB\', \'myparcel-asia\'); ?>
                                </button>
                            <?php endif; ?>
                             <button type="button" class="button" id="mpa-btn-delete-batch" style="background:#ef4444; border-color:#ef4444; color:#ffffff;">
                                 <?php esc_html_e(\'Delete Batch\', \'myparcel-asia\'); ?>
                             </button>
                        <?php endif; ?>
                    </div>
                </div>';
$detail_header_new = '                <div class="mpa-batch-header" style="display: flex; align-items: center; justify-content: space-between; gap: 20px;">
                    <!-- Left: Search Box -->
                    <form method="get" action="" style="display: flex; gap: 8px; align-items: center; margin: 0; flex: 1; max-width: 420px;">
                        <input type="hidden" name="page" value="myparcel-asia-manage-batch">
                        <input type="hidden" name="batch_id" value="<?php echo esc_attr($batch_id); ?>">
                        <input type="text" name="mpa_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e(\'Search customer name...\', \'myparcel-asia\'); ?>" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100%; max-width: 250px; height: 32px; line-height: 30px; box-sizing: border-box;">
                        <button type="submit" class="button button-secondary" style="height: 32px; line-height: 30px;"><?php esc_html_e(\'Search\', \'myparcel-asia\'); ?></button>
                        <?php if (!empty($search)): ?>
                            <a href="<?php echo esc_url(admin_url(\'admin.php?page=myparcel-asia-manage-batch&batch_id=\' . $batch_id)); ?>" class="button button-link" style="color: #ef4444; height: 32px; line-height: 32px; text-decoration: none;"><?php esc_html_e(\'Clear\', \'myparcel-asia\'); ?></a>
                        <?php endif; ?>
                    </form>

                    <!-- Right: Stats & Actions -->
                    <div style="display: flex; align-items: center; gap: 30px;">
                        <div class="mpa-batch-meta-grid" style="margin-bottom: 0;">
                            <div class="mpa-meta-stat">
                                <span class="mpa-meta-label"><?php esc_html_e(\'Status\', \'myparcel-asia\'); ?></span>
                                <span class="mpa-meta-val"
                                    style="color: <?php echo \'completed\' === $batch[\'status\'] ? \'#059669\' : \'#d97706\'; ?>; font-size: 15px;">
                                    <?php echo esc_html(ucfirst($batch[\'status\'])); ?>
                                </span>
                            </div>
                            <div class="mpa-meta-stat">
                                <span class="mpa-meta-label"><?php esc_html_e(\'Created By\', \'myparcel-asia\'); ?></span>
                                <span class="mpa-meta-val" style="font-size: 15px;"><?php echo esc_html($batch[\'created_by\']); ?></span>
                            </div>
                            <div class="mpa-meta-stat">
                                <span class="mpa-meta-label"><?php esc_html_e(\'Total Orders\', \'myparcel-asia\'); ?></span>
                                <span class="mpa-meta-val" style="font-size: 15px;"><?php echo esc_html($batch[\'total_order\']); ?></span>
                            </div>
                            <div class="mpa-meta-stat">
                                <span class="mpa-meta-label"><?php esc_html_e(\'Total Price\', \'myparcel-asia\'); ?></span>
                                <span class="mpa-meta-val" style="font-size: 15px;">RM <?php echo esc_html(number_format($batch[\'total_awb_price\'], 2)); ?></span>
                            </div>
                        </div>

                        <div>
                            <?php if (\'completed\' === $batch[\'status\']): ?>
                                <?php if (!empty($batch[\'thermal_awb_url\'])): ?>
                                    <a href="<?php echo esc_url($batch[\'thermal_awb_url\']); ?>" target="_blank" class="button button-primary"
                                        style="background:#059669; border-color:#059669; height: 32px; line-height: 30px;">
                                        <?php esc_html_e(\'Download AWB\', \'myparcel-asia\'); ?>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php $api_key = get_option(\'mpa_api_key\', \'\'); ?>
                                <?php if (empty($api_key)): ?>
                                    <button type="button" class="button button-primary" disabled title="<?php esc_attr_e(\'Please configure a valid API Key in Settings.\', \'myparcel-asia\'); ?>" style="height: 32px; line-height: 30px;">
                                        <?php esc_html_e(\'Invalid API Key\', \'myparcel-asia\'); ?>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="button button-primary" id="mpa-btn-create-batch-awb" style="height: 32px; line-height: 30px;">
                                        <?php esc_html_e(\'Create AWB\', \'myparcel-asia\'); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="button" id="mpa-btn-delete-batch" style="background:#ef4444; border-color:#ef4444; color:#ffffff; height: 32px; line-height: 30px;">
                                    <?php esc_html_e(\'Delete Batch\', \'myparcel-asia\'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>';
$code = str_replace($detail_header_old, $detail_header_new, $code);

// Detail view table head and loop start
$detail_table_old = '                <!-- Orders Table -->
                <table class="mpa-batch-table">
                    <thead>
                        <tr>
                            <th width="160"><?php esc_html_e(\'Order\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Shipping Details\', \'myparcel-asia\'); ?></th>
                            <th width="220"><?php esc_html_e(\'Item Details\', \'myparcel-asia\'); ?></th>
                            <th width="140"><?php esc_html_e(\'Courier\', \'myparcel-asia\'); ?></th>
                            <th width="110" style="text-align:right;"><?php esc_html_e(\'AWB Price\', \'myparcel-asia\'); ?></th>
                            <th width="150" style="text-align:center;"><?php esc_html_e(\'Action\', \'myparcel-asia\'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batch[\'orders\'] as $order_id):
                            $order = wc_get_order($order_id);
                            if (!$order)
                                continue;';
$detail_table_new = '                <!-- Orders Table -->
                <table class="mpa-batch-table">
                    <thead>
                        <tr>
                            <th width="50" style="text-align:center;">#</th>
                            <th width="160"><?php esc_html_e(\'Order\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Shipping Details\', \'myparcel-asia\'); ?></th>
                            <th width="220"><?php esc_html_e(\'Item Details\', \'myparcel-asia\'); ?></th>
                            <th width="140"><?php esc_html_e(\'Courier\', \'myparcel-asia\'); ?></th>
                            <th width="110" style="text-align:right;"><?php esc_html_e(\'AWB Price\', \'myparcel-asia\'); ?></th>
                            <th width="150" style="text-align:center;"><?php esc_html_e(\'Action\', \'myparcel-asia\'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paginated_order_ids)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #94a3b8; padding: 30px;">
                                    <?php esc_html_e(\'No orders found matching the search criteria.\', \'myparcel-asia\'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $row_count = 1;
                            foreach ($paginated_order_ids as $order_id):
                                $order = wc_get_order($order_id);
                                if (!$order)
                                    continue;';
$code = str_replace($detail_table_old, $detail_table_new, $code);

// Detail view row index column output
$detail_row_old = '                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url(\'post.php?post=\' . $order->get_id() . \'&action=edit\')); ?>"';
$detail_row_new = '                            <?php $row_index = (($paged - 1) * $limit) + $row_count; ?>
                            <tr>
                                <td style="text-align:center; font-weight: 600; color: #64748b;"><?php echo $row_index; ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url(\'post.php?post=\' . $order->get_id() . \'&action=edit\')); ?>"';
$code = str_replace($detail_row_old, $detail_row_new, $code);

// Detail view loop end and pagination block
$detail_loop_end_old = '                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>';
$detail_loop_end_new = '                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php 
                            $row_count++;
                        endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Custom Pagination to match the user\'s mockup -->
                <?php if ($total_orders_in_batch > 0): ?>
                    <div class="mpa-pagination-container" style="display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; font-size: 14px; color: #334155; padding-right: 10px;">
                        <span class="mpa-pagination-total" style="margin-right: auto; color: #475569; font-weight: 500; font-size: 15px;"><?php echo esc_html($total_orders_in_batch); ?> <?php _e(\'items\', \'myparcel-asia\'); ?></span>
                        
                        <!-- First Page Button («) -->
                        <?php if ($paged > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', 1)); ?>" class="mpa-page-btn mpa-page-active">«</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">«</span>
                        <?php endif; ?>

                        <!-- Previous Page Button (‹) -->
                        <?php if ($paged > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', $paged - 1)); ?>" class="mpa-page-btn mpa-page-active">‹</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">‹</span>
                        <?php endif; ?>

                        <!-- Page info text (e.g. 1 of 2) -->
                        <span class="mpa-page-info" style="font-weight: 500; color: #1e293b; padding: 0 4px;"><?php printf(__(\'%d of %d\', \'myparcel-asia\'), $paged, $total_pages); ?></span>

                        <!-- Next Page Button (›) -->
                        <?php if ($paged < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', $paged + 1)); ?>" class="mpa-page-btn mpa-page-active">›</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">›</span>
                        <?php endif; ?>

                        <!-- Last Page Button (») -->
                        <?php if ($paged < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', $total_pages)); ?>" class="mpa-page-btn mpa-page-active">»</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">»</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>';
$code = str_replace($detail_loop_end_old, $detail_loop_end_new, $code);


// ----------------------------------------------------
// 5. MANAGE BATCHES LIST VIEW CHANGES
// ----------------------------------------------------
// Setup List View pagination sliced array
$list_setup_old = '        } else {
            // List View
            ?>';
$list_setup_new = '        } else {
            // List View
            $reversed_batches = array_reverse($batches);
            $total_batches = count($reversed_batches);
            $limit = 10;
            $total_pages = ceil($total_batches / $limit);
            $paged = isset($_GET[\'paged\']) ? max(1, intval($_GET[\'paged\'])) : 1;
            $offset = ($paged - 1) * $limit;
            $paginated_batches = array_slice($reversed_batches, $offset, $limit);
            ?>';
$code = str_replace($list_setup_old, $list_setup_new, $code);

// List View table head and loop start
$list_table_old = '                <table class="mpa-batch-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e(\'Batch Label\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Date Created\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Created By\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Orders Count\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Total AWB Cost\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Status\', \'myparcel-asia\'); ?></th>
                            <th width="100"><?php esc_html_e(\'Actions\', \'myparcel-asia\'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #94a3b8; padding: 30px;">
                                    <?php esc_html_e(\'No batch records found.\', \'myparcel-asia\'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_reverse($batches) as $b): ?>';
$list_table_new = '                <table class="mpa-batch-table">
                    <thead>
                        <tr>
                            <th width="50" style="text-align:center;">#</th>
                            <th><?php esc_html_e(\'Batch Label\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Date Created\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Created By\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Orders Count\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Total AWB Cost\', \'myparcel-asia\'); ?></th>
                            <th><?php esc_html_e(\'Status\', \'myparcel-asia\'); ?></th>
                            <th width="100"><?php esc_html_e(\'Actions\', \'myparcel-asia\'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paginated_batches)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: #94a3b8; padding: 30px;">
                                    <?php esc_html_e(\'No batch records found.\', \'myparcel-asia\'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $row_count = 1;
                            foreach ($paginated_batches as $b): 
                                $row_index = (($paged - 1) * $limit) + $row_count;
                                ?>';
$code = str_replace($list_table_old, $list_table_new, $code);

// List View row index output
$list_row_old = '                                <tr>
                                    <td style="font-weight:700; color:#4f46e5;">';
$list_row_new = '                                <tr>
                                    <td style="text-align:center; font-weight: 600; color: #64748b;"><?php echo $row_index; ?></td>
                                    <td style="font-weight:700; color:#4f46e5;">';
$code = str_replace($list_row_old, $list_row_new, $code);

// List View loop end and pagination block
$list_loop_end_old = '                                    <td>
                                        <a href="<?php echo esc_url(admin_url(\'admin.php?page=myparcel-asia-manage-batch&batch_id=\' . $b[\'id\'])); ?>"
                                            class="button button-secondary">
                                            <?php esc_html_e(\'View\', \'myparcel-asia\'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>';
$list_loop_end_new = '                                    <td>
                                        <a href="<?php echo esc_url(admin_url(\'admin.php?page=myparcel-asia-manage-batch&batch_id=\' . $b[\'id\'])); ?>"
                                            class="button button-secondary">
                                            <?php esc_html_e(\'View\', \'myparcel-asia\'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php 
                                $row_count++;
                            endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Custom Pagination to match the user\'s mockup -->
                <?php if ($total_batches > 0): ?>
                    <div class="mpa-pagination-container" style="display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; font-size: 14px; color: #334155; padding-right: 10px;">
                        <span class="mpa-pagination-total" style="margin-right: auto; color: #475569; font-weight: 500; font-size: 15px;"><?php echo esc_html($total_batches); ?> <?php _e(\'batches\', \'myparcel-asia\'); ?></span>
                        
                        <!-- First Page Button («) -->
                        <?php if ($paged > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', 1)); ?>" class="mpa-page-btn mpa-page-active">«</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">«</span>
                        <?php endif; ?>

                        <!-- Previous Page Button (‹) -->
                        <?php if ($paged > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', $paged - 1)); ?>" class="mpa-page-btn mpa-page-active">‹</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">‹</span>
                        <?php endif; ?>

                        <!-- Page info text (e.g. 1 of 2) -->
                        <span class="mpa-page-info" style="font-weight: 500; color: #1e293b; padding: 0 4px;"><?php printf(__(\'%d of %d\', \'myparcel-asia\'), $paged, $total_pages); ?></span>

                        <!-- Next Page Button (›) -->
                        <?php if ($paged < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', $paged + 1)); ?>" class="mpa-page-btn mpa-page-active">›</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">›</span>
                        <?php endif; ?>

                        <!-- Last Page Button (») -->
                        <?php if ($paged < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg(\'paged\', $total_pages)); ?>" class="mpa-page-btn mpa-page-active">»</a>
                        <?php else: ?>
                            <span class="mpa-page-btn mpa-page-disabled">»</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>';
$code = str_replace($list_loop_end_old, $list_loop_end_new, $code);

file_put_contents($filepath, $code);
echo "Successfully updated myparcel-asia.php\n";
