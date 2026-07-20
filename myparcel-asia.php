<?php
/**
 * Plugin Name: MYPARCEL ASIA
 * Plugin URI: https://myparcelasia.com
 * Description: WooCommerce fulfillment plugin by MYPARCEL ASIA.
 * Version: 1.0.3
 * Author: MYPARCEL ASIA
 * Author URI: https://myparcelasia.com
 * License: GPL2
 * Text Domain: myparcel-asia
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MyParcel_Asia_Plugin
 */
class MyParcel_Asia_Plugin
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('admin_init', array($this, 'handle_url_api_key_capture'));
        add_action('admin_init', array($this, 'clean_myparcel_asia_shipping_from_zones'));
        add_action('wp_ajax_mpa_get_order_shipping_price', array($this, 'ajax_get_order_shipping_price'));
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('wp_ajax_mpa_save_order_courier', array($this, 'ajax_save_order_courier'));
        add_action('wp_ajax_mpa_create_single_awb', array($this, 'ajax_create_single_awb'));
        add_action('wp_ajax_mpa_create_batch', array($this, 'ajax_create_batch'));
        add_action('wp_ajax_mpa_execute_batch_awb', array($this, 'ajax_execute_batch_awb'));
        add_action('wp_ajax_mpa_remove_order_from_batch', array($this, 'ajax_remove_order_from_batch'));
        add_action('wp_ajax_mpa_delete_batch', array($this, 'ajax_delete_batch'));
        add_action('woocommerce_checkout_create_order_shipping_item', array($this, 'save_courier_on_item_creation'), 10, 4);
        add_filter('woocommerce_package_rates', array($this, 'inject_myparcel_asia_rates'), 10, 2);
        add_action('woocommerce_before_checkout_form', array($this, 'clear_wc_shipping_cache'));
        add_action('woocommerce_before_cart', array($this, 'clear_wc_shipping_cache'));
        add_action('template_redirect', array($this, 'force_shipping_recalculation_on_page_load'));
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_checkout_shipping_rate'), 10, 2);

        if (class_exists('MyParcel_Asia_Updater')) {
            new MyParcel_Asia_Updater();
        }
    }

    /**
     * Capture API Key from URL redirect if present
     */
    public function handle_url_api_key_capture()
    {
        if (isset($_GET['mpa_api_key']) && isset($_GET['page']) && 'myparcel-asia-settings' === $_GET['page']) {
            $api_key = sanitize_text_field($_GET['mpa_api_key']);
            update_option('mpa_api_key', $api_key);

            // Sync user details using the newly captured key
            $this->sync_user_details($api_key);

            // Clean URL query argument to hide key from history and address bar
            $redirect_url = remove_query_arg(array('mpa_api_key', 'mpa_login_email'));
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Get Courier Logo URL
     *
     * @param string $code Courier code.
     * @return string URL of the logo.
     */
    public function courier_logo($code)
    {
        if ('none' === $code || empty($code)) {
            return '';
        }
        // Normalize code format if needed (e.g. poslaju/jnt/dhle)
        return 'https://app.myparcelasia.com/assets/img/vendor_logo/' . esc_attr($code) . '.png';
    }

    /**
     * Reusable helper to compute/calculate order shipping price from API
     */
    public function get_order_shipping_price_helper($order)
    {
        $api_key = get_option('mpa_api_key', '');
        $sender_postcode = get_option('mpa_sender_postcode', '');

        // Get shipping info
        $receiver_postcode = $order->get_shipping_postcode();
        $receiver_country_code = $order->get_shipping_country();
        $state_code = $order->get_shipping_state();

        // Calculate total weight
        $weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $weight += floatval($product->get_weight()) * $item->get_quantity();
            }
        }
        if ($weight <= 0) {
            $weight = 0.5; // fallback
        }

        // Match lanes
        $lanes_json = get_option('mpa_lanes', '');
        $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

        $is_domestic = ('MY' === strtoupper($receiver_country_code));
        $resolved_lane = null;

        if ($is_domestic && !empty($state_code)) {
            foreach ($lanes as $id => $lane) {
                if (isset($lane['type']) && 'override' === $lane['type'] && 'state' === $lane['scope']) {
                    if (strtoupper($lane['details']) === strtoupper($state_code)) {
                        $resolved_lane = $lane;
                        break;
                    }
                }
            }
        }

        if (!$resolved_lane && $is_domestic) {
            $peninsular = array('JHR', 'KDH', 'KTN', 'MLK', 'NSN', 'PHG', 'PNG', 'PRK', 'PLS', 'SGR', 'TRG', 'KUL', 'PJY');
            $sabah_sarawak = array('SBH', 'SRW', 'LBN');
            $is_peninsular = in_array(strtoupper($state_code), $peninsular);
            $is_em = in_array(strtoupper($state_code), $sabah_sarawak);

            foreach ($lanes as $id => $lane) {
                if (isset($lane['type']) && 'override' === $lane['type']) {
                    if ('peninsular' === $lane['scope'] && $is_peninsular) {
                        $resolved_lane = $lane;
                        break;
                    }
                    if ('sabah_sarawak' === $lane['scope'] && $is_em) {
                        $resolved_lane = $lane;
                        break;
                    }
                }
            }
        }

        if (!$resolved_lane && !$is_domestic) {
            foreach ($lanes as $id => $lane) {
                if (isset($lane['type']) && 'override' === $lane['type'] && 'country' === $lane['scope']) {
                    if (strtoupper($lane['details']) === strtoupper($receiver_country_code)) {
                        $resolved_lane = $lane;
                        break;
                    }
                }
            }
        }

        if (!$resolved_lane) {
            if ($is_domestic) {
                $resolved_lane = isset($lanes['fallback_my']) ? $lanes['fallback_my'] : array('courier' => 'none', 'markup' => null);
            } else {
                $resolved_lane = isset($lanes['fallback_int']) ? $lanes['fallback_int'] : array('courier' => 'none', 'markup' => null);
            }
        }

        $selected_courier = $order->get_meta('_mpa_selected_courier', true);
        $courier_key = !empty($selected_courier) ? $selected_courier : (isset($resolved_lane['courier']) ? $resolved_lane['courier'] : 'none');

        if ('none' === $courier_key) {
            return array('success' => false, 'message' => __('Not Available', 'myparcel-asia'), 'price' => 0);
        }

        // Call /check_price
        $endpoint = '/check_price';
        $params = array(
            'api_key' => $api_key,
            'sender_postcode' => $sender_postcode,
            'declared_weight' => $weight,
        );

        if ($is_domestic) {
            $params['receiver_postcode'] = $receiver_postcode;
        } else {
            $params['receiver_country_code'] = $receiver_country_code;
        }

        $data = $this->mpa_post($endpoint, $params);

        if (is_wp_error($data)) {
            return array('success' => false, 'message' => $data->get_error_message(), 'price' => 0);
        }

        if (!isset($data['status']) || !$data['status'] || empty($data['data']['prices'])) {
            $err_msg = isset($data['message']) ? $data['message'] : __('Failed to check price from API.', 'myparcel-asia');
            return array('success' => false, 'message' => $err_msg, 'price' => 0);
        }

        $matched_price = null;
        foreach ($data['data']['prices'] as $price_item) {
            $provider = strtolower($price_item['provider_code']);
            if (
                $provider === strtolower($courier_key) ||
                (strpos($provider, strtolower($courier_key)) !== false) ||
                (strpos(strtolower($courier_key), $provider) !== false)
            ) {

                $matched_price = floatval(isset($price_item['exclusive_price']) ? $price_item['exclusive_price'] : $price_item['normal_price']);
                break;
            }
        }

        if ($matched_price === null) {
            $first_price = reset($data['data']['prices']);
            $matched_price = floatval(isset($first_price['exclusive_price']) ? $first_price['exclusive_price'] : $first_price['normal_price']);
        }


        return array(
            'success' => true,
            'price' => round($matched_price, 2),
            'courier' => $courier_key
        );
    }

    /**
     * AJAX handler to fetch order shipping price from MYPARCEL ASIA
     */
    public function ajax_get_order_shipping_price()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid Order ID.', 'myparcel-asia')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'myparcel-asia')));
        }

        $res = $this->get_order_shipping_price_helper($order);
        if (!$res['success']) {
            wp_send_json_success(array(
                'status' => false,
                'message' => $res['message'],
                'price' => 0
            ));
        }

        wp_send_json_success(array(
            'status' => true,
            'price' => $res['price'],
            'formatted_price' => 'RM ' . number_format($res['price'], 2)
        ));
    }

    /**
     * AJAX handler to save custom courier override to order metadata
     */
    public function ajax_save_order_courier()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $courier = isset($_POST['courier']) ? sanitize_text_field($_POST['courier']) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid Order ID.', 'myparcel-asia')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'myparcel-asia')));
        }

        if (!empty($courier)) {
            $order->update_meta_data('_mpa_selected_courier', $courier);
        } else {
            $order->delete_meta_data('_mpa_selected_courier');
        }
        $order->save();

        wp_send_json_success(array('status' => true));
    }

    /**
     * AJAX handler to create/generate single AWB mock tracking number
     */
    public function ajax_create_single_awb()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid Order ID.', 'myparcel-asia')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'myparcel-asia')));
        }

        $api_key = get_option('mpa_api_key', '');
        $sender_name = get_option('mpa_sender_name', '');
        $sender_phone = get_option('mpa_sender_phone', '');
        $sender_address_1 = get_option('mpa_sender_address_1', '');
        $sender_postcode = get_option('mpa_sender_postcode', '');
        $sender_city = get_option('mpa_sender_city', '');
        $sender_state = get_option('mpa_sender_state', '');

        $missing_sender_fields = array();
        if (empty($sender_name)) {
            $missing_sender_fields[] = __('Sender Name', 'myparcel-asia');
        }
        if (empty($sender_phone)) {
            $missing_sender_fields[] = __('Sender Phone', 'myparcel-asia');
        }
        if (empty($sender_address_1)) {
            $missing_sender_fields[] = __('Sender Address Line 1', 'myparcel-asia');
        }
        if (empty($sender_postcode)) {
            $missing_sender_fields[] = __('Sender Postcode', 'myparcel-asia');
        }
        if (empty($sender_city)) {
            $missing_sender_fields[] = __('Sender City', 'myparcel-asia');
        }
        if (empty($sender_state)) {
            $missing_sender_fields[] = __('Sender State', 'myparcel-asia');
        }

        if (!empty($missing_sender_fields)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Please configure the following missing fields in MYPARCEL ASIA settings (Default Config tab): %s.', 'myparcel-asia'),
                    implode(', ', $missing_sender_fields)
                )
            ));
        }

        // Get shipping info
        $receiver_postcode = $order->get_shipping_postcode();
        $receiver_country_code = $order->get_shipping_country();
        $state_code = $order->get_shipping_state();

        // Calculate total weight
        $weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $weight += floatval($product->get_weight()) * $item->get_quantity();
            }
        }
        if ($weight <= 0) {
            $weight = 0.5; // fallback
        }

        // Match lanes
        $lanes_json = get_option('mpa_lanes', '');
        $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

        $get_lane_match = function ($country_code, $state_code) use ($lanes) {
            $is_domestic = ('MY' === strtoupper($country_code));
            if ($is_domestic && !empty($state_code)) {
                foreach ($lanes as $id => $lane) {
                    if (isset($lane['type']) && 'override' === $lane['type'] && 'state' === $lane['scope']) {
                        if (strtoupper($lane['details']) === strtoupper($state_code)) {
                            return $lane;
                        }
                    }
                }
            }

            if ($is_domestic) {
                $peninsular = array('JHR', 'KDH', 'KTN', 'MLK', 'NSN', 'PHG', 'PNG', 'PRK', 'PLS', 'SGR', 'TRG', 'KUL', 'PJY');
                $sabah_sarawak = array('SBH', 'SRW', 'LBN');
                $is_peninsular = in_array(strtoupper($state_code), $peninsular);
                $is_em = in_array(strtoupper($state_code), $sabah_sarawak);

                foreach ($lanes as $id => $lane) {
                    if (isset($lane['type']) && 'override' === $lane['type']) {
                        if ('peninsular' === $lane['scope'] && $is_peninsular) {
                            return $lane;
                        }
                        if ('sabah_sarawak' === $lane['scope'] && $is_em) {
                            return $lane;
                        }
                    }
                }
            } else {
                foreach ($lanes as $id => $lane) {
                    if (isset($lane['type']) && 'override' === $lane['type'] && 'country' === $lane['scope']) {
                        if (strtoupper($lane['details']) === strtoupper($country_code)) {
                            return $lane;
                        }
                    }
                }
            }

            if ($is_domestic) {
                return isset($lanes['fallback_my']) ? $lanes['fallback_my'] : array('courier' => 'none', 'markup' => null);
            } else {
                return isset($lanes['fallback_int']) ? $lanes['fallback_int'] : array('courier' => 'none', 'markup' => null);
            }
        };

        $lane = $get_lane_match($receiver_country_code, $state_code);
        $selected_courier = $order->get_meta('_mpa_selected_courier', true);
        $courier_key = !empty($selected_courier) ? $selected_courier : (isset($lane['courier']) ? $lane['courier'] : 'none');

        if ('none' === $courier_key) {
            wp_send_json_error(array('message' => __('No courier configured for this shipping lane.', 'myparcel-asia')));
        }

        // Send Date cutoff time logic (11.45am cutoff)
        $now = current_time('timestamp');
        $cutoff_time = strtotime('11:45 am', $now);
        if ($now < $cutoff_time) {
            $send_date = date('Y-m-d', $now);
        } else {
            $send_date = date('Y-m-d', strtotime('+1 day', $now));
        }

        // Generate content description from item list
        $desc_items = array();
        $line_items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            $name = !empty($sku) ? $sku : $item->get_name();
            $qty = $item->get_quantity();
            $desc_items[] = $name . ' x' . $qty;

            $item_weight = $product ? floatval($product->get_weight()) : 0.5;
            if ($item_weight <= 0) {
                $item_weight = 0.5;
            }

            $line_items[] = array(
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'sku' => $sku,
                'hscode' => '',
                'duty_percent' => 0,
                'duty_currency' => $order->get_currency(),
                'duty_amount' => 0,
                'weight' => strval($item_weight),
                'sub_weight' => $item_weight * $qty,
                'currency' => $order->get_currency(),
                'quantity' => $qty,
                'price' => strval(round($item->get_subtotal() / $qty, 2)),
                'tax' => strval(round($item->get_subtotal_tax(), 2)),
                'sub_total' => strval(round($item->get_subtotal(), 2))
            );
        }
        $content_description = substr(implode(', ', $desc_items), 0, 99);

        // Configurations & Overrides
        $send_method = get_option('mpa_default_send_method', 'dropoff');
        $size = get_option('mpa_default_parcel_size', 'flyers_s');

        $suffix = isset($_POST['suffix']) ? intval($_POST['suffix']) : 0;
        $order_num = $order->get_order_number();
        $integration_order_id = $order_num;
        if ($suffix > 0) {
            $integration_order_id .= '-' . $suffix;
        }

        $shipment = array(
            'integration_order_id' => $integration_order_id,
            'receiver_postcode' => $receiver_postcode,
            'receiver_country_code' => $receiver_country_code,
            'declared_weight' => $weight,
            'type' => 'parcel',
            'provider_code' => $courier_key,
            'size' => $size,
            'send_method' => $send_method,
            'send_date' => $send_date,
            'content_type' => get_option('mpa_default_content_type', 'papers'),
            'content_description' => $content_description,
            'content_value' => round($order->get_total(), 2),
            'sender_name' => get_option('mpa_sender_name', ''),
            'sender_phone' => get_option('mpa_sender_phone', ''),
            'sender_email' => get_option('mpa_sender_email', ''),
            'sender_company_name' => get_option('mpa_sender_company', ''),
            'sender_address_line_1' => get_option('mpa_sender_address_1', ''),
            'sender_address_line_2' => get_option('mpa_sender_address_2', ''),
            'sender_address_line_3' => get_option('mpa_sender_address_3', ''),
            'sender_address_line_4' => get_option('mpa_sender_address_4', ''),
            'sender_postcode' => $sender_postcode,
            'sender_city' => get_option('mpa_sender_city', ''),
            'sender_state' => get_option('mpa_sender_state', ''),
            'sender_country_code' => 'MY',
            'receiver_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'receiver_phone' => $order->get_billing_phone(),
            'receiver_email' => $order->get_billing_email(),
            'receiver_company_name' => $order->get_shipping_company(),
            'receiver_address_line_1' => $order->get_shipping_address_1(),
            'receiver_address_line_2' => $order->get_shipping_address_2(),
            'receiver_city' => $order->get_shipping_city(),
            'receiver_state' => $order->get_shipping_state(),
            'integration_order_data' => array(
                'connote_show_invoice' => 'yes',
                'total_weight' => $weight,
                'line_item' => $line_items
            )
        );

        // Volumetric calculation logic for Box sizes
        if ('box' === $size) {
            // side = ceil((weight * 5000) ^ (1/3))
            $side = ceil(pow($weight * 5000, 1 / 3));
            if ($side <= 0) {
                $side = 1;
            }
            $shipment['length'] = $side;
            $shipment['width'] = $side;
            $shipment['height'] = $side;
        }

        // Call the API endpoint /create_bulk_awb
        $endpoint = '/create_bulk_awb';
        $params = array(
            'api_key' => $api_key,
            'shipments' => array($shipment)
        );

        $data = $this->mpa_post($endpoint, $params);

        error_log('MPA CREATE AWB RESPONSE: ' . print_r($data, true));

        if (is_wp_error($data)) {
            wp_send_json_error(array('message' => $data->get_error_message()));
        }

        $success = false;
        if (isset($data['status']) && $data['status']) {
            $success = true;
        } elseif (isset($data['success']) && $data['success']) {
            $success = true;
        }

        if (!$success) {
            $api_messages = isset($data['data']['message']) ? $data['data']['message'] : array();
            $err_msg = isset($data['message']) ? $data['message'] : __('Failed to create AWB from API.', 'myparcel-asia');
            wp_send_json_error(array(
                'message' => $err_msg,
                'api_messages' => $api_messages
            ));
        }

        // Extract tracking connote number and price
        $tracking_no = '';
        $price = '';
        if (!empty($data['data']['tracking_no']) && is_array($data['data']['tracking_no'])) {
            $first_res = reset($data['data']['tracking_no']);
            if (!empty($first_res['tracking_no'])) {
                $tracking_no = $first_res['tracking_no'];
            }
            if (isset($first_res['total_price'])) {
                $price = floatval($first_res['total_price']);
            }
        }
        if (empty($tracking_no) && !empty($data['data']['shipments']) && is_array($data['data']['shipments'])) {
            $first_res = reset($data['data']['shipments']);
            if (!empty($first_res['connote_number'])) {
                $tracking_no = $first_res['connote_number'];
            }
            if ('' === $price) {
                if (isset($first_res['price'])) {
                    $price = floatval($first_res['price']);
                } elseif (isset($first_res['shipment_price'])) {
                    $price = floatval($first_res['shipment_price']);
                }
            }
        }
        if (empty($tracking_no) && !empty($data['data']['connotes']) && is_array($data['data']['connotes'])) {
            $tracking_no = reset($data['data']['connotes']);
        }

        if (empty($tracking_no)) {
            wp_send_json_error(array(
                'message' => __('AWB created successfully, but tracking number was not returned.', 'myparcel-asia'),
                'api_response' => $data
            ));
        }

        $order->update_meta_data('_mpa_tracking_no', $tracking_no);
        if ('' !== $price) {
            $order->update_meta_data('_mpa_actual_price', $price);
        }
        $order->update_status('completed', sprintf(__('AWB shipment created successfully via MYPARCEL ASIA. Tracking No: %s', 'myparcel-asia'), $tracking_no));
        $order->save();

        wp_send_json_success(array('status' => true, 'tracking_no' => $tracking_no));
    }

    /**
     * AJAX handler to create a new shipment batch from selected orders
     */
    public function ajax_create_batch()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $order_ids = isset($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : array();
        if (empty($order_ids)) {
            wp_send_json_error(array('message' => __('No orders selected.', 'myparcel-asia')));
        }

        // Generate Label: YYMMDD-NN
        $today_prefix = current_time('ymd');
        $batches = get_option('mpa_batches', array());
        if (!is_array($batches)) {
            $batches = array();
        }

        // Count batches created today
        $today_count = 0;
        foreach ($batches as $b) {
            if (isset($b['label']) && strpos($b['label'], $today_prefix) === 0) {
                $today_count++;
            }
        }
        $running_no = sprintf('%02d', $today_count + 1);
        $label = $today_prefix . '-' . $running_no;

        // Compute total price and details
        $total_price = 0;
        foreach ($order_ids as $id) {
            $order = wc_get_order($id);
            if ($order) {
                // If actual price exists, use it, otherwise recalculate
                $actual_price = $order->get_meta('_mpa_actual_price', true);
                if ('' !== $actual_price) {
                    $total_price += floatval($actual_price);
                } else {
                    $res = $this->get_order_shipping_price_helper($order);
                    if ($res['success']) {
                        $total_price += $res['price'];
                    }
                }
            }
        }

        // Create batch ID
        $batch_id = 'batch_' . time() . '_' . rand(100, 999);
        $current_user = wp_get_current_user();

        $batches[$batch_id] = array(
            'id' => $batch_id,
            'label' => $label,
            'created_by' => $current_user ? $current_user->user_login : 'system',
            'created_at' => current_time('mysql'),
            'total_order' => count($order_ids),
            'total_awb_price' => $total_price,
            'orders' => $order_ids,
            'status' => 'pending',
            'awb_url' => '',
            'thermal_awb_url' => ''
        );

        // Update batch ID metadata on the orders
        foreach ($order_ids as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $order->update_meta_data('_mpa_batch_id', $batch_id);
                $order->save();
            }
        }

        update_option('mpa_batches', $batches);

        wp_send_json_success(array(
            'redirect_url' => admin_url('admin.php?page=myparcel-asia-manage-batch&batch_id=' . $batch_id)
        ));
    }

    /**
     * AJAX handler to execute bulk AWB creation for a batch
     */
    public function ajax_execute_batch_awb()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        if (empty($batch_id)) {
            wp_send_json_error(array('message' => __('Invalid Batch ID.', 'myparcel-asia')));
        }

        $batches = get_option('mpa_batches', array());
        if (!isset($batches[$batch_id])) {
            wp_send_json_error(array('message' => __('Batch not found.', 'myparcel-asia')));
        }

        $batch = $batches[$batch_id];
        if ('completed' === $batch['status']) {
            wp_send_json_error(array('message' => __('This batch is already completed.', 'myparcel-asia')));
        }

        $api_key = get_option('mpa_api_key', '');
        $sender_postcode = get_option('mpa_sender_postcode', '');
        $send_method = get_option('mpa_default_send_method', 'dropoff');
        $size = get_option('mpa_default_parcel_size', 'flyers_s');
        $content_type = get_option('mpa_default_content_type', 'papers');

        // Sender details from options
        $sender_name = get_option('mpa_sender_name', '');
        $sender_phone = get_option('mpa_sender_phone', '');
        $sender_email = get_option('mpa_sender_email', '');
        $sender_company_name = get_option('mpa_sender_company', '');
        $sender_address_line_1 = get_option('mpa_sender_address_1', '');
        $sender_address_line_2 = get_option('mpa_sender_address_2', '');
        $sender_address_line_3 = get_option('mpa_sender_address_3', '');
        $sender_address_line_4 = get_option('mpa_sender_address_4', '');
        $sender_city = get_option('mpa_sender_city', '');
        $sender_state = get_option('mpa_sender_state', '');

        // Validate sender details
        $missing_sender_fields = array();
        if (empty($sender_name)) {
            $missing_sender_fields[] = __('Sender Name', 'myparcel-asia');
        }
        if (empty($sender_phone)) {
            $missing_sender_fields[] = __('Sender Phone', 'myparcel-asia');
        }
        if (empty($sender_address_line_1)) {
            $missing_sender_fields[] = __('Sender Address Line 1', 'myparcel-asia');
        }
        if (empty($sender_postcode)) {
            $missing_sender_fields[] = __('Sender Postcode', 'myparcel-asia');
        }
        if (empty($sender_city)) {
            $missing_sender_fields[] = __('Sender City', 'myparcel-asia');
        }
        if (empty($sender_state)) {
            $missing_sender_fields[] = __('Sender State', 'myparcel-asia');
        }

        if (!empty($missing_sender_fields)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Please configure the following missing fields in MYPARCEL ASIA settings (Default Config tab) before creating AWB: %s.', 'myparcel-asia'),
                    implode(', ', $missing_sender_fields)
                )
            ));
        }

        // Cutoff time logic
        $now = current_time('timestamp');
        $cutoff_time = strtotime('11:45 am', $now);
        if ($now < $cutoff_time) {
            $send_date = date('Y-m-d', $now);
        } else {
            $send_date = date('Y-m-d', strtotime('+1 day', $now));
        }

        $shipments = array();
        $orders_map = array();
        $suffix = isset($_POST['suffix']) ? intval($_POST['suffix']) : 0;

        foreach ($batch['orders'] as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            // Calculate total weight
            $weight = 0;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $weight += floatval($product->get_weight()) * $item->get_quantity();
                }
            }
            if ($weight <= 0) {
                $weight = 0.5;
            }

            // Match courier
            $res = $this->get_order_shipping_price_helper($order);
            $courier_key = $res['success'] ? $res['courier'] : 'none';
            if ('none' === $courier_key) {
                wp_send_json_error(array('message' => sprintf(__('Order #%s has no courier configured.', 'myparcel-asia'), $order->get_order_number())));
            }

            // Content Description
            $desc_items = array();
            $line_items = array();
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $sku = $product ? $product->get_sku() : '';
                $name = !empty($sku) ? $sku : $item->get_name();
                $qty = $item->get_quantity();
                $desc_items[] = $name . ' x' . $qty;

                $item_weight = $product ? floatval($product->get_weight()) : 0.5;
                if ($item_weight <= 0) {
                    $item_weight = 0.5;
                }

                $line_items[] = array(
                    'product_id' => $item->get_product_id(),
                    'name' => $item->get_name(),
                    'sku' => $sku,
                    'hscode' => '',
                    'duty_percent' => 0,
                    'duty_currency' => $order->get_currency(),
                    'duty_amount' => 0,
                    'weight' => strval($item_weight),
                    'sub_weight' => $item_weight * $qty,
                    'currency' => $order->get_currency(),
                    'quantity' => $qty,
                    'price' => strval(round($item->get_subtotal() / $qty, 2)),
                    'tax' => strval(round($item->get_subtotal_tax(), 2)),
                    'sub_total' => strval(round($item->get_subtotal(), 2))
                );
            }
            $content_description = substr(implode(', ', $desc_items), 0, 99);

            // Suffix overrides
            $order_num = $order->get_order_number();
            $integration_order_id = $order_num;
            if ($suffix > 0) {
                $integration_order_id .= '-' . $suffix;
            }

            $shipment = array(
                'integration_order_id' => $integration_order_id,
                'receiver_postcode' => $order->get_shipping_postcode(),
                'receiver_country_code' => $order->get_shipping_country(),
                'declared_weight' => $weight,
                'type' => 'parcel',
                'provider_code' => $courier_key,
                'size' => $size,
                'send_method' => $send_method,
                'send_date' => $send_date,
                'content_type' => $content_type,
                'content_description' => $content_description,
                'content_value' => round($order->get_total(), 2),
                'sender_name' => $sender_name,
                'sender_phone' => $sender_phone,
                'sender_email' => $sender_email,
                'sender_company_name' => $sender_company_name,
                'sender_address_line_1' => $sender_address_line_1,
                'sender_address_line_2' => $sender_address_line_2,
                'sender_address_line_3' => $sender_address_line_3,
                'sender_address_line_4' => $sender_address_line_4,
                'sender_postcode' => $sender_postcode,
                'sender_city' => $sender_city,
                'sender_state' => $sender_state,
                'sender_country_code' => 'MY',
                'receiver_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'receiver_phone' => $order->get_billing_phone(),
                'receiver_email' => $order->get_billing_email(),
                'receiver_company_name' => $order->get_shipping_company(),
                'receiver_address_line_1' => $order->get_shipping_address_1(),
                'receiver_address_line_2' => $order->get_shipping_address_2(),
                'receiver_city' => $order->get_shipping_city(),
                'receiver_state' => $order->get_shipping_state(),
                'integration_order_data' => array(
                    'connote_show_invoice' => 'yes',
                    'total_weight' => $weight,
                    'line_item' => $line_items
                )
            );

            if ('box' === $size) {
                $side = ceil(pow($weight * 5000, 1 / 3));
                if ($side <= 0) {
                    $side = 1;
                }
                $shipment['length'] = $side;
                $shipment['width'] = $side;
                $shipment['height'] = $side;
            }

            $shipments[] = $shipment;
            $orders_map[$integration_order_id] = $order;
        }

        // Call API endpoint
        $endpoint = '/create_bulk_awb';
        $params = array(
            'api_key' => $api_key,
            'shipments' => $shipments
        );

        $data = $this->mpa_post($endpoint, $params);

        if (is_wp_error($data)) {
            wp_send_json_error(array('message' => $data->get_error_message()));
        }

        $success = false;
        if (isset($data['status']) && $data['status']) {
            $success = true;
        } elseif (isset($data['success']) && $data['success']) {
            $success = true;
        }

        if (!$success) {
            $api_messages = isset($data['data']['message']) ? $data['data']['message'] : array();
            $err_msg = isset($data['message']) ? $data['message'] : __('Failed to execute bulk AWB creation.', 'myparcel-asia');
            wp_send_json_error(array(
                'message' => $err_msg,
                'api_messages' => $api_messages
            ));
        }

        // Loop response and save tracking numbers
        $connotes_saved = 0;
        if (!empty($data['data']['tracking_no']) && is_array($data['data']['tracking_no'])) {
            foreach ($data['data']['tracking_no'] as $t) {
                $int_id = isset($t['integration_order_id']) ? $t['integration_order_id'] : '';
                $connote = isset($t['tracking_no']) ? $t['tracking_no'] : '';
                $price = isset($t['total_price']) ? floatval($t['total_price']) : '';

                if ($int_id && isset($orders_map[$int_id])) {
                    $order = $orders_map[$int_id];
                    $order->update_meta_data('_mpa_tracking_no', $connote);
                    if ('' !== $price) {
                        $order->update_meta_data('_mpa_actual_price', $price);
                    }
                    $order->update_status('completed', sprintf(__('Bulk AWB created successfully. Tracking No: %s', 'myparcel-asia'), $connote));
                    $order->save();
                    $connotes_saved++;
                }
            }
        }

        // fallback connote check if tracking_no key wasn't fully populated
        if ($connotes_saved === 0 && !empty($data['data']['connotes']) && is_array($data['data']['connotes'])) {
            $idx = 0;
            foreach ($data['data']['connotes'] as $connote) {
                if (isset($shipments[$idx])) {
                    $int_id = $shipments[$idx]['integration_order_id'];
                    if (isset($orders_map[$int_id])) {
                        $order = $orders_map[$int_id];
                        $order->update_meta_data('_mpa_tracking_no', $connote);
                        $order->update_status('completed', sprintf(__('Bulk AWB created successfully. Tracking No: %s', 'myparcel-asia'), $connote));
                        $order->save();
                        $connotes_saved++;
                    }
                }
                $idx++;
            }
        }

        // Update Batch registry details
        $batch['status'] = 'completed';
        $batch['awb_url'] = isset($data['data']['awb_url']) ? $data['data']['awb_url'] : '';
        $batch['thermal_awb_url'] = isset($data['data']['thermal_awb_url']) ? $data['data']['thermal_awb_url'] : '';
        $batches[$batch_id] = $batch;
        update_option('mpa_batches', $batches);

        wp_send_json_success(array('status' => true, 'message' => __('Bulk AWB created successfully.', 'myparcel-asia')));
    }

    public function ajax_delete_batch()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';

        if (empty($batch_id)) {
            wp_send_json_error(array('message' => __('Missing batch ID.', 'myparcel-asia')));
        }

        $batches = get_option('mpa_batches', array());
        if (!isset($batches[$batch_id])) {
            wp_send_json_error(array('message' => __('Batch not found.', 'myparcel-asia')));
        }

        $batch_orders = isset($batches[$batch_id]['orders']) ? $batches[$batch_id]['orders'] : array();
        foreach ($batch_orders as $o_id) {
            $order = wc_get_order($o_id);
            if ($order) {
                $order->delete_meta_data('_mpa_batch_id');
                $order->save();
            }
        }

        unset($batches[$batch_id]);
        update_option('mpa_batches', $batches);

        wp_send_json_success(array(
            'redirect_url' => admin_url('admin.php?page=myparcel-asia-manage-batch'),
        ));
    }

    public function ajax_remove_order_from_batch()
    {
        check_ajax_referer('mpa_batch_nonce', 'security');

        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (empty($batch_id) || empty($order_id)) {
            wp_send_json_error(array('message' => __('Missing batch ID or order ID.', 'myparcel-asia')));
        }

        $batches = get_option('mpa_batches', array());
        if (!isset($batches[$batch_id])) {
            wp_send_json_error(array('message' => __('Batch not found.', 'myparcel-asia')));
        }

        $batch_orders = $batches[$batch_id]['orders'];
        $key = array_search($order_id, $batch_orders);
        if ($key === false) {
            wp_send_json_error(array('message' => __('Order not found in this batch.', 'myparcel-asia')));
        }

        unset($batch_orders[$key]);
        $batch_orders = array_values($batch_orders);

        $order = wc_get_order($order_id);
        if ($order) {
            $order->delete_meta_data('_mpa_batch_id');
            $order->save();
        }

        $batches[$batch_id]['orders'] = $batch_orders;
        $batches[$batch_id]['total_order'] = count($batch_orders);

        $total_price = 0;
        foreach ($batch_orders as $o_id) {
            $o = wc_get_order($o_id);
            if ($o) {
                $res = $this->get_order_shipping_price_helper($o);
                if ($res['success']) {
                    $total_price += floatval($res['price']);
                }
            }
        }
        $batches[$batch_id]['total_awb_price'] = $total_price;

        $batch_deleted = false;
        if (empty($batch_orders)) {
            unset($batches[$batch_id]);
            $batch_deleted = true;
        }

        update_option('mpa_batches', $batches);

        wp_send_json_success(array(
            'batch_deleted' => $batch_deleted,
            'total_order' => count($batch_orders),
            'total_price' => number_format($total_price, 2),
            'redirect_url' => admin_url('admin.php?page=myparcel-asia-manage-batch'),
        ));
    }

    /**
     * Register Admin Menu
     */
    public function register_admin_menu()
    {
        // Parent Menu Page
        add_menu_page(
            __('MYPARCEL ASIA', 'myparcel-asia'),
            'MYPARCEL ASIA',
            'manage_options',
            'myparcel-asia',
            array($this, 'render_dashboard'),
            'dashicons-portfolio', // Icon URL or Dashicon class
            56 // Position in sidebar
        );

        // Default Dashboard Submenu Page (renamed first child)
        add_submenu_page(
            'myparcel-asia',
            __('Dashboard', 'myparcel-asia'),
            __('Dashboard', 'myparcel-asia'),
            'manage_options',
            'myparcel-asia',
            array($this, 'render_dashboard')
        );

        // Manage Batch Submenu Page
        add_submenu_page(
            'myparcel-asia',
            __('To Process', 'myparcel-asia'),
            __('To Process', 'myparcel-asia'),
            'manage_options',
            'myparcel-asia-batch',
            array($this, 'render_manage_batch')
        );

        // Settings Submenu Page
        add_submenu_page(
            'myparcel-asia',
            __('Manage Batch', 'myparcel-asia'),
            __('Manage Batch', 'myparcel-asia'),
            'manage_options',
            'myparcel-asia-manage-batch',
            array($this, 'render_manage_batch_dashboard')
        );

        // Settings Submenu Page
        add_submenu_page(
            'myparcel-asia',
            __('Settings', 'myparcel-asia'),
            __('Settings', 'myparcel-asia'),
            'manage_options',
            'myparcel-asia-settings',
            array($this, 'render_settings')
        );
    }

    /**
     * Enqueue Admin Styles
     */
    public function enqueue_admin_styles($hook)
    {
        if ('toplevel_page_myparcel-asia' !== $hook) {
            return;
        }

        // Add Google Fonts - Inter
        wp_enqueue_style('myparcel-asia-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null);
    }

    /**
     * Render the onboarding dashboard
     */
    public function render_dashboard()
    {
        $current_user = wp_get_current_user();
        $display_name = !empty($current_user->display_name) ? $current_user->display_name : 'Merchant';
        $assets_url = plugin_dir_url(__FILE__) . 'assets/';

        // 1. Calculate metrics dynamically
        $unfulfilled_orders_count = 0;
        $completed_orders_count = 0;
        if (class_exists('WooCommerce')) {
            $unfulfilled_orders = wc_get_orders(array(
                'status' => array('wc-processing'),
                'return' => 'ids',
                'limit' => -1,
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_mpa_batch_id',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_mpa_batch_id',
                        'value'   => '',
                        'compare' => '=',
                    ),
                ),
            ));
            $unfulfilled_orders_count = count($unfulfilled_orders);

            $today_prefix = current_time('Y-m-d');
            $batches = get_option('mpa_batches', array());
            if (!is_array($batches)) {
                $batches = array();
            }
            foreach ($batches as $b) {
                if (isset($b['status']) && 'completed' === $b['status']) {
                    if (isset($b['created_at']) && strpos($b['created_at'], $today_prefix) === 0) {
                        $completed_orders_count += intval($b['total_order']);
                    }
                }
            }
        }

        // Fetch topup balance (mock if none is saved)
        $topup_balance = get_option('mpa_balance', '3,625.63');

        // Fetch active lanes count
        $lanes_json = get_option('mpa_lanes', '');
        $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();
        $active_lanes_count = empty($lanes) ? 2 : count($lanes);
        ?>
        <div class="wrap mpa-dashboard-wrap">
            <style>
                .mpa-dashboard-wrap {
                    font-family: 'Inter', sans-serif;
                    margin: 20px 20px 0 0;
                    color: #1e293b;
                }

                /* Metrics Grid */
                .mpa-metrics-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 20px;
                    margin-top: 20px;
                }

                @media (max-width: 900px) {
                    .mpa-metrics-grid {
                        grid-template-columns: repeat(2, 1fr);
                    }
                }

                @media (max-width: 600px) {
                    .mpa-metrics-grid {
                        grid-template-columns: 1fr;
                    }
                }

                .mpa-metric-card {
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 0;
                    padding: 20px 24px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    transition: border-color 0.2s ease, box-shadow 0.2s ease;
                    border-top: 4px solid #cbd5e1;
                }

                .mpa-metric-card:hover {
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                }

                .mpa-metric-card.unfilled {
                    border-top-color: #FD5E5E;
                }

                .mpa-metric-card.awb {
                    border-top-color: #7CD4FD;
                }

                .mpa-metric-card.balance {
                    border-top-color: #10b981;
                }

                .mpa-metric-card.lanes {
                    border-top-color: #FFC542;
                }

                .mpa-metric-label {
                    font-size: 11px;
                    font-weight: 700;
                    color: #64748b;
                    text-transform: uppercase;
                    letter-spacing: 0.07em;
                    margin-bottom: 8px;
                }

                .mpa-metric-value {
                    font-size: 28px;
                    font-weight: 800;
                    color: #0f172a;
                    line-height: 1;
                    letter-spacing: -0.02em;
                    text-align: right;
                }

                .mpa-metric-link {
                    font-size: 11px;
                    color: #4f46e5;
                    text-decoration: none;
                    font-weight: 600;
                    margin-top: 12px;
                    display: inline-block;
                    transition: color 0.2s ease;
                    text-align: right;
                    align-self: flex-end;
                }

                .mpa-metric-link:hover {
                    color: #312e81;
                    text-decoration: underline;
                }
            </style>

            <h1 class="wp-heading-inline"><?php esc_html_e('MYPARCEL ASIA Dashboard', 'myparcel-asia'); ?></h1>
            <hr class="wp-header-end">

            <!-- Metrics Bar -->
            <div class="mpa-metrics-grid">
                <!-- Card 1: To Process -->
                <div class="mpa-metric-card unfilled">
                    <span class="mpa-metric-label"><?php esc_html_e('To Process', 'myparcel-asia'); ?></span>
                    <span class="mpa-metric-value"><?php echo esc_html($unfulfilled_orders_count); ?></span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=myparcel-asia-batch')); ?>"
                        class="mpa-metric-link"><?php esc_html_e('Process Now', 'myparcel-asia'); ?> &rarr;</a>
                </div>

                <!-- Card 2: Total Created AWB -->
                <div class="mpa-metric-card awb">
                    <span class="mpa-metric-label"><?php esc_html_e('Total Created AWB', 'myparcel-asia'); ?></span>
                    <span class="mpa-metric-value"><?php echo esc_html($completed_orders_count); ?></span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=myparcel-asia-batch')); ?>" class="mpa-metric-link"
                        target="_blank"><?php esc_html_e('View More', 'myparcel-asia'); ?> &rarr;</a>
                </div>

                <!-- Card 3: Batch -->
                <div class="mpa-metric-card lanes">
                    <?php
                    $batches = get_option('mpa_batches', array());
                    $today_prefix = current_time('Y-m-d');
                    $batches_count = 0;
                    if (is_array($batches)) {
                        foreach ($batches as $b) {
                            if (isset($b['created_at']) && strpos($b['created_at'], $today_prefix) === 0) {
                                $batches_count++;
                            }
                        }
                    }
                    ?>
                    <span class="mpa-metric-label"><?php esc_html_e('Batch', 'myparcel-asia'); ?></span>
                    <span class="mpa-metric-value"><?php echo esc_html($batches_count); ?></span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=myparcel-asia-manage-batch')); ?>"
                        class="mpa-metric-link"><?php esc_html_e('View More', 'myparcel-asia'); ?> &rarr;</a>
                </div>

                <!-- Card 4: Topup Balance (moved to right most position) -->
                <div class="mpa-metric-card balance">
                    <span class="mpa-metric-label"><?php esc_html_e('Topup Balance', 'myparcel-asia'); ?></span>
                    <span class="mpa-metric-value">RM <?php echo esc_html($topup_balance); ?></span>
                    <a href="<?php echo esc_url('https://' . get_option('mpa_host', 'app.myparcelasia.com') . '/secure/topup_packages'); ?>"
                        class="mpa-metric-link" target="_blank"><?php esc_html_e('Topup Now', 'myparcel-asia'); ?> &rarr;</a>
                </div>
            </div>

            <!-- Tip Notification -->
            <div
                style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; margin: 20px 0 0 0; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-info" style="color: #3b82f6;"></span>
                <span style="font-size: 13px; color: #1e3a8a; font-weight: 500;">
                    <?php esc_html_e('Notice: The "Total Created AWB" and "Batch" metrics are limited to the current date only.', 'myparcel-asia'); ?>
                </span>
            </div>

            <?php if (empty(get_option('mpa_api_key', ''))): ?>
            <!-- API Key Danger Notification -->
            <div
                style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 12px 16px; margin: 10px 0 0 0; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-warning" style="color: #ef4444;"></span>
                <span style="font-size: 13px; color: #7f1d1d; font-weight: 500;">
                    <?php esc_html_e('Warning: Your API Key is missing or invalid. Please configure a valid API Key in Settings to use core features.', 'myparcel-asia'); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Manage Batch page
     */
    public function render_manage_batch()
    {
        // Fetch balance
        $balance_str = get_option('mpa_balance', '3,625.63');
        $balance = floatval(str_replace(',', '', $balance_str));

        // Fetch lanes config
        $lanes_json = get_option('mpa_lanes', '');
        $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

        // Courier lists
        $domestic_couriers = array(
            'none' => 'Not Available',
            'jnt' => 'J&T',
            'poslaju' => 'Poslaju',
            'dhle' => 'DHL',
            'ninjavan' => 'Ninjavan',
            'flash' => 'Flash',
            'citylink' => 'Citylink Express',
            'lex' => 'LEX Express',
            'spx' => 'SPX Express',
        );

        $int_couriers = array(
            'none' => 'Not Available',
            'jnti' => 'J&T International',
            'ninjavani' => 'Ninjavan',
            'ems' => 'EMS',
            'aramex' => 'Aramex',
            'fedex' => 'Fedex',
            'airparcel' => 'AirParcel',
        );

        // State & Country labels
        $states = array(
            'JHR' => 'Johor',
            'KDH' => 'Kedah',
            'KTN' => 'Kelantan',
            'MLK' => 'Melaka',
            'NSN' => 'Negeri Sembilan',
            'PHG' => 'Pahang',
            'PNG' => 'Penang',
            'PRK' => 'Perak',
            'PLS' => 'Perlis',
            'SBH' => 'Sabah',
            'SRW' => 'Sarawak',
            'SGR' => 'Selangor',
            'TRG' => 'Terengganu',
            'KUL' => 'WP Kuala Lumpur',
            'LBN' => 'WP Labuan',
            'PJY' => 'WP Putrajaya',
        );

        $countries = array(
            'SG' => 'Singapore',
            'ID' => 'Indonesia',
            'TH' => 'Thailand',
            'PH' => 'Philippines',
            'BN' => 'Brunei',
            'KH' => 'Cambodia',
            'VN' => 'Vietnam',
            'MM' => 'Myanmar',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'CN' => 'China',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'AU' => 'Australia',
            'MY' => 'Malaysia',
        );

        // Helper to match courier
        $get_lane_match = function ($country_code, $state_code) use ($lanes) {
            $is_domestic = ('MY' === strtoupper($country_code));
            if ($is_domestic && !empty($state_code)) {
                foreach ($lanes as $id => $lane) {
                    if (isset($lane['type']) && 'override' === $lane['type'] && 'state' === $lane['scope']) {
                        if (strtoupper($lane['details']) === strtoupper($state_code)) {
                            return $lane;
                        }
                    }
                }
            }

            if ($is_domestic) {
                $peninsular = array('JHR', 'KDH', 'KTN', 'MLK', 'NSN', 'PHG', 'PNG', 'PRK', 'PLS', 'SGR', 'TRG', 'KUL', 'PJY');
                $sabah_sarawak = array('SBH', 'SRW', 'LBN');
                $is_peninsular = in_array(strtoupper($state_code), $peninsular);
                $is_em = in_array(strtoupper($state_code), $sabah_sarawak);

                foreach ($lanes as $id => $lane) {
                    if (isset($lane['type']) && 'override' === $lane['type']) {
                        if ('peninsular' === $lane['scope'] && $is_peninsular) {
                            return $lane;
                        }
                        if ('sabah_sarawak' === $lane['scope'] && $is_em) {
                            return $lane;
                        }
                    }
                }
            } else {
                foreach ($lanes as $id => $lane) {
                    if (isset($lane['type']) && 'override' === $lane['type'] && 'country' === $lane['scope']) {
                        if (strtoupper($lane['details']) === strtoupper($country_code)) {
                            return $lane;
                        }
                    }
                }
            }

            if ($is_domestic) {
                return isset($lanes['fallback_my']) ? $lanes['fallback_my'] : array('courier' => 'none', 'markup' => null);
            } else {
                return isset($lanes['fallback_int']) ? $lanes['fallback_int'] : array('courier' => 'none', 'markup' => null);
            }
        };

        // Fetch WooCommerce processing orders
        $orders = array();
        if (class_exists('WooCommerce')) {
            $orders = wc_get_orders(array(
                'status' => array('wc-processing'),
                'limit' => -1,
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_mpa_batch_id',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_mpa_batch_id',
                        'value'   => '',
                        'compare' => '=',
                    ),
                ),
            ));
        }
        ?>
        <div class="wrap mpa-batch-wrap">
            <style>
                .mpa-batch-wrap {
                    font-family: 'Inter', sans-serif;
                    margin: 20px 20px 0 0;
                    color: #1e293b;
                }

                /* Sticky Summary Header */
                .mpa-sticky-header {
                    position: sticky;
                    top: 32px;
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-left: 4px solid #4f46e5;
                    padding: 16px 24px;
                    z-index: 99;
                    margin-bottom: 20px;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }

                .mpa-sticky-info {
                    display: flex;
                    gap: 30px;
                    align-items: center;
                }

                .mpa-sticky-stat {
                    display: flex;
                    flex-direction: column;
                }

                .mpa-sticky-label {
                    font-size: 11px;
                    font-weight: 700;
                    color: #64748b;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }

                .mpa-sticky-val {
                    font-size: 20px;
                    font-weight: 800;
                    color: #0f172a;
                }

                #mpa-status-msg {
                    font-size: 13px;
                    font-weight: 600;
                    padding: 4px 10px;
                    border-radius: 4px;
                }

                .mpa-status-sufficient {
                    background-color: #d1fae5;
                    color: #065f46;
                }

                .mpa-status-insufficient {
                    background-color: #fee2e2;
                    color: #991b1b;
                }

                /* Table styling */
                .mpa-batch-table {
                    width: 100%;
                    border-collapse: collapse;
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    font-size: 13px;
                }

                .mpa-batch-table th,
                .mpa-batch-table td {
                    border: 1px solid #e2e8f0;
                    padding: 10px 12px;
                    text-align: left;
                    vertical-align: middle;
                }

                .mpa-batch-table th.mpa-col-right,
                .mpa-batch-table td.mpa-col-right {
                    text-align: right !important;
                }

                .mpa-batch-table th {
                    background-color: #f8fafc;
                    font-weight: 600;
                }

                .mpa-batch-table tbody tr:nth-child(even) {
                    background-color: #f8fafc;
                }

                .mpa-batch-table tbody tr:hover {
                    background-color: #f1f5f9;
                }

                .mpa-shipping-box {
                    font-size: 12px;
                    line-height: 1.4;
                    color: #475569;
                }

                .mpa-shipping-box strong {
                    color: #0f172a;
                }

                @keyframes mpa-spin {
                    0% {
                        transform: rotate(0deg);
                    }

                    100% {
                        transform: rotate(360deg);
                    }
                }

                .mpa-price-spinner {
                    display: inline-block;
                    animation: mpa-spin 1s linear infinite;
                    color: #64748b;
                }
            </style>

            <h1 class="wp-heading-inline"><?php esc_html_e('To Process', 'myparcel-asia'); ?></h1>
            <hr class="wp-header-end">

            <!-- Sticky Summary bar -->
            <div class="mpa-sticky-header">
                <div class="mpa-sticky-info">
                    <div class="mpa-sticky-stat">
                        <span class="mpa-sticky-label"><?php esc_html_e('Topup Balance', 'myparcel-asia'); ?></span>
                        <span class="mpa-sticky-val">RM <?php echo esc_html(number_format($balance, 2)); ?></span>
                    </div>
                    <div class="mpa-sticky-stat">
                        <span class="mpa-sticky-label"><?php esc_html_e('Selected Total Price', 'myparcel-asia'); ?></span>
                        <span class="mpa-sticky-val" id="mpa-selected-total">RM 0.00</span>
                    </div>
                    <div id="mpa-status-msg" style="display:none;"></div>
                </div>
                <div>
                    <button type="button" class="button button-primary" id="mpa-btn-checkout" disabled>
                        <?php esc_html_e('Add to Batch', 'myparcel-asia'); ?>
                    </button>
                </div>
            </div>

            <!-- Orders Table -->
            <table class="mpa-batch-table">
                <thead>
                    <tr>
                        <th width="160"><?php esc_html_e('Order', 'myparcel-asia'); ?></th>
                        <th><?php esc_html_e('Shipping Details', 'myparcel-asia'); ?></th>
                        <th width="220"><?php esc_html_e('Item Details', 'myparcel-asia'); ?></th>
                        <th width="140"><?php esc_html_e('Courier', 'myparcel-asia'); ?></th>
                        <th width="110" class="mpa-col-right"><?php esc_html_e('AWB Price', 'myparcel-asia'); ?></th>
                        <th width="40" style="text-align:center;"><input type="checkbox" id="mpa-select-all"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($orders)):
                        ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 30px; color: #94a3b8;">
                                <?php esc_html_e('No processing or pending orders found.', 'myparcel-asia'); ?>
                            </td>
                        </tr>
                        <?php
                    else:
                        foreach ($orders as $order):
                            $country_code = $order->get_shipping_country();
                            $state_code = $order->get_shipping_state();

                            // Match lane settings
                            $lane = $get_lane_match($country_code, $state_code);
                            $selected_courier = $order->get_meta('_mpa_selected_courier', true);
                            $courier_key = !empty($selected_courier) ? $selected_courier : (isset($lane['courier']) ? $lane['courier'] : 'none');

                            $is_domestic = ('MY' === strtoupper($country_code));
                            $courier_name = $is_domestic ? (isset($domestic_couriers[$courier_key]) ? $domestic_couriers[$courier_key] : 'Not Available')
                                : (isset($int_couriers[$courier_key]) ? $int_couriers[$courier_key] : 'Not Available');

                            // Total weight and quantity calculation
                            $weight = 0;
                            $total_qty = 0;
                            foreach ($order->get_items() as $item) {
                                $product = $item->get_product();
                                if ($product) {
                                    $weight += floatval($product->get_weight()) * $item->get_quantity();
                                }
                                $total_qty += $item->get_quantity();
                            }
                            if ($weight <= 0) {
                                $weight = 0.5; // fallback
                            }

                            $price_text = __('N/A (Not Available)', 'myparcel-asia');

                            // Calculate relative time since payment
                            $paid_date = $order->get_date_paid();
                            $time_diff = '';
                            if ($paid_date) {
                                $time_diff = human_time_diff($paid_date->getTimestamp(), time()) . ' ' . __('ago', 'myparcel-asia');
                            } else {
                                $created_date = $order->get_date_created();
                                if ($created_date) {
                                    $time_diff = human_time_diff($created_date->getTimestamp(), time()) . ' ' . __('ago', 'myparcel-asia');
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>"
                                        target="_blank" style="text-decoration:none; font-weight:700;">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                        <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?>
                                    </a>
                                    <?php if (!empty($time_diff)): ?>
                                        <div style="font-size: 11px; color: #64748b; margin-top: 4px; font-weight: normal;">
                                            Paid <?php echo esc_html($time_diff); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    $status = $order->get_status();
                                    $status_name = wc_get_order_status_name($status);
                                    $status_color = '#64748b'; // default gray
                                    if ('processing' === $status) {
                                        $status_color = '#059669'; // green
                                    } elseif ('on-hold' === $status) {
                                        $status_color = '#d97706'; // orange
                                    } elseif ('pending' === $status) {
                                        $status_color = '#b45309'; // yellow-brown
                                    }
                                    ?>
                                    <div
                                        style="font-size: 11px; color: <?php echo esc_attr($status_color); ?>; font-weight: 600; margin-top: 2px;">
                                        <?php echo esc_html($status_name); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mpa-shipping-box">
                                        <?php echo esc_html($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()); ?><br>
                                        (<?php echo esc_html($order->get_billing_phone()); ?> /
                                        <?php echo esc_html($order->get_billing_email()); ?>)<br>
                                        <?php echo esc_html(trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2())); ?>,
                                        <?php echo esc_html($order->get_shipping_postcode()); ?>
                                        <?php echo esc_html($order->get_shipping_city()); ?>,
                                        <?php echo esc_html(isset($states[$state_code]) ? $states[$state_code] : $state_code); ?>,
                                        <?php echo esc_html(isset($countries[$country_code]) ? $countries[$country_code] : $country_code); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 12px; color: #475569; line-height: 1.4; font-weight: normal;">
                                        Weight: <strong><?php echo esc_html(number_format($weight, 2)); ?> kg</strong><br>
                                        Quantity: <?php echo esc_html($total_qty); ?> item<br>
                                        Value: RM <?php echo esc_html(number_format($order->get_total(), 2)); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $logo_url = $this->courier_logo($courier_key);
                                    if (!empty($logo_url)):
                                        ?>
                                        <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($courier_name); ?>"
                                            style="max-height: 20px; display: block; border-radius: 2px; margin-bottom: 4px;">
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #94a3b8; font-weight: 600;">N/A</span>
                                    <?php endif; ?>
                                    <?php
                                    $batch_tracking = $order->get_meta('_mpa_tracking_no', true);
                                    if (!empty($batch_tracking) && 'N/A' !== $batch_tracking):
                                        ?>
                                        <div
                                            style="font-size: 10px; font-family: monospace; color: #64748b; margin-top: 4px; font-weight: 600;">
                                            <?php echo esc_html($batch_tracking); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="mpa-price-cell mpa-col-right" data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                    style="font-weight: 700; color: <?php echo 'none' === $courier_key ? '#cbd5e1' : '#0f172a'; ?>;">
                                    <?php
                                    $actual_price = $order->get_meta('_mpa_actual_price', true);
                                    if ('' !== $actual_price && !empty($batch_tracking) && 'N/A' !== $batch_tracking):
                                        echo 'RM ' . esc_html(number_format(floatval($actual_price), 2));
                                    elseif ('none' === $courier_key):
                                        echo esc_html($price_text);
                                    else:
                                        ?>
                                        <span class="mpa-price-spinner dashicons dashicons-update"></span>
                                        <?php
                                    endif;
                                    ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (!empty($batch_tracking) && 'N/A' !== $batch_tracking): ?>
                                        <span class="dashicons dashicons-yes" style="color: #059669;"
                                            title="<?php esc_attr_e('AWB already created.', 'myparcel-asia'); ?>"></span>
                                    <?php elseif ('none' !== $courier_key): ?>
                                        <input type="checkbox" class="mpa-order-cb" data-price="0"
                                            value="<?php echo esc_attr($order->get_id()); ?>" disabled>
                                    <?php else: ?>
                                        <input type="checkbox" disabled
                                            title="<?php esc_attr_e('No courier configured for this lane.', 'myparcel-asia'); ?>">
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </tbody>
            </table>
        </div>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var balance = <?php echo floatval($balance); ?>;
                var selectAll = document.getElementById('mpa-select-all');
                var checkboxes = document.querySelectorAll('.mpa-order-cb');
                var totalDisplay = document.getElementById('mpa-selected-total');
                var statusMsg = document.getElementById('mpa-status-msg');
                var btnCheckout = document.getElementById('mpa-btn-checkout');

                function calculateTotal() {
                    var total = 0.00;
                    var selectedCount = 0;
                    checkboxes.forEach(function (cb) {
                        if (cb.checked) {
                            total += parseFloat(cb.getAttribute('data-price'));
                            selectedCount++;
                        }
                    });

                    totalDisplay.textContent = 'RM ' + total.toFixed(2);

                    if (selectedCount > 0) {
                        btnCheckout.disabled = false;
                        statusMsg.style.display = 'inline-block';
                        if (total <= balance) {
                            statusMsg.textContent = 'Balance Sufficient';
                            statusMsg.className = 'mpa-status-sufficient';
                            btnCheckout.disabled = false;
                        } else {
                            statusMsg.textContent = 'Insufficient Balance';
                            statusMsg.className = 'mpa-status-insufficient';
                            btnCheckout.disabled = true;
                        }
                    } else {
                        btnCheckout.disabled = true;
                        statusMsg.style.display = 'none';
                    }
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function () {
                        checkboxes.forEach(function (cb) {
                            if (!cb.disabled) {
                                cb.checked = selectAll.checked;
                            }
                        });
                        calculateTotal();
                    });
                }

                checkboxes.forEach(function (cb) {
                    cb.addEventListener('change', calculateTotal);
                });

                // Sequential AJAX price checking
                var priceCells = document.querySelectorAll('.mpa-price-cell');
                var cellIndex = 0;

                function fetchNextPrice() {
                    if (cellIndex >= priceCells.length) {
                        return; // Queue finished
                    }

                    var cell = priceCells[cellIndex];
                    var orderId = cell.getAttribute('data-order-id');
                    var row = cell.closest('tr');
                    var cb = row.querySelector('.mpa-order-cb');

                    // If it is N/A or has no checkbox/disabled placeholder
                    if (!cb || (cb.disabled && cb.getAttribute('title'))) {
                        cellIndex++;
                        fetchNextPrice();
                        return;
                    }

                    // If price is already precalculated (contains no spinner)
                    var spinner = cell.querySelector('.mpa-price-spinner');
                    if (!spinner && cell.textContent.trim().indexOf('RM') !== -1) {
                        var priceVal = parseFloat(cell.textContent.replace('RM', '').trim());
                        if (!isNaN(priceVal)) {
                            cb.setAttribute('data-price', priceVal);
                            cb.disabled = false;
                        }
                        cellIndex++;
                        fetchNextPrice();
                        calculateTotal();
                        return;
                    }

                    jQuery.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mpa_get_order_shipping_price',
                            order_id: orderId,
                            security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                if (response.data.status) {
                                    cell.textContent = response.data.formatted_price;
                                    cb.setAttribute('data-price', response.data.price);
                                    cb.disabled = false;
                                } else {
                                    cell.innerHTML = '<span style="color:#ef4444;">' + response.data.message + '</span>';
                                }
                            } else {
                                var msg = response.data && response.data.message ? response.data.message : 'Error';
                                cell.innerHTML = '<span style="color:#ef4444;" title="' + msg + '">Error</span>';
                            }
                            cellIndex++;
                            fetchNextPrice();
                            calculateTotal();
                        },
                        error: function () {
                            cell.innerHTML = '<span style="color:#ef4444;">Error</span>';
                            cellIndex++;
                            fetchNextPrice();
                            calculateTotal();
                        }
                    });
                }

                // Checkout Add to Batch Click
                btnCheckout.addEventListener('click', function () {
                    var selectedOrderIds = [];
                    checkboxes.forEach(function (cb) {
                        if (cb.checked) {
                            selectedOrderIds.push(cb.value);
                        }
                    });

                    if (selectedOrderIds.length === 0) {
                        alert('No orders selected.');
                        return;
                    }

                    btnCheckout.disabled = true;
                    btnCheckout.textContent = 'Processing...';

                    jQuery.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mpa_create_batch',
                            order_ids: selectedOrderIds,
                            security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                        },
                        success: function (response) {
                            if (response.success && response.data.redirect_url) {
                                window.location.href = response.data.redirect_url;
                            } else {
                                alert(response.data.message || 'Failed to create batch.');
                                btnCheckout.disabled = false;
                                btnCheckout.textContent = 'Add to Batch';
                            }
                        },
                        error: function () {
                            alert('Connection error.');
                            btnCheckout.disabled = false;
                            btnCheckout.textContent = 'Add to Batch';
                        }
                    });
                });

                // Start loading sequentially
                fetchNextPrice();
            });
        </script>
        <?php
    }

    /**
     * Render the Settings page
     */
    public function render_settings()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api_connection';
        ?>
        <div class="wrap mpa-settings-wrap">
            <style>
                .mpa-settings-wrap {
                    font-family: 'Inter', sans-serif;
                    margin-top: 15px;
                    font-size: 13px;
                }

                .mpa-settings-title {
                    font-size: 22px;
                    font-weight: 700;
                    margin-bottom: 15px;
                    color: #0f172a;
                }

                .mpa-tab-content-card {
                    margin-top: 15px;
                }

                /* Condense WP Form Tables and inputs */
                .mpa-settings-wrap table.form-table {
                    margin-top: 5px;
                }

                .mpa-settings-wrap table.form-table th {
                    font-size: 13px;
                    padding: 8px 10px 8px 0;
                    width: 160px;
                    font-weight: 600;
                }

                .mpa-settings-wrap table.form-table td {
                    font-size: 13px;
                    padding: 8px 0;
                }

                .mpa-settings-wrap input[type="text"],
                .mpa-settings-wrap select {
                    font-size: 13px !important;
                    height: 28px !important;
                    padding: 2px 8px !important;
                    min-height: auto !important;
                    line-height: 1 !important;
                }

                .mpa-settings-wrap .description {
                    font-size: 11px !important;
                    margin-top: 4px !important;
                }

                .mpa-settings-wrap h3 {
                    font-size: 16px;
                    margin: 0 0 10px 0;
                }

                .mpa-settings-wrap p {
                    margin: 0 0 15px 0;
                }

                .mpa-settings-wrap .submit {
                    padding: 10px 0 0 0;
                    margin: 0;
                }
            </style>

            <h1 class="mpa-settings-title"><?php esc_html_e('MYPARCEL ASIA Settings', 'myparcel-asia'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=myparcel-asia-settings&tab=api_connection"
                    class="nav-tab <?php echo $active_tab === 'api_connection' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('API Connection', 'myparcel-asia'); ?>
                </a>
                <a href="?page=myparcel-asia-settings&tab=default_config"
                    class="nav-tab <?php echo $active_tab === 'default_config' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Default Config', 'myparcel-asia'); ?>
                </a>
                <a href="?page=myparcel-asia-settings&tab=lane_management"
                    class="nav-tab <?php echo $active_tab === 'lane_management' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Lane Management', 'myparcel-asia'); ?>
                </a>
                <a href="?page=myparcel-asia-settings&tab=customer_choose_courier"
                    class="nav-tab <?php echo $active_tab === 'customer_choose_courier' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Customer Checkout Config', 'myparcel-asia'); ?>
                </a>
            </h2>

            <div class="mpa-tab-content-card">
                <?php
                switch ($active_tab) {
                    case 'default_config':
                        $this->render_default_config_tab();
                        break;
                    case 'lane_management':
                        $this->render_lane_management_tab();
                        break;
                    case 'customer_choose_courier':
                        $this->render_customer_choose_courier_tab();
                        break;
                    case 'api_connection':
                    default:
                        $this->render_api_connection_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Customer Checkout Config Tab Content
     */
    protected function render_customer_choose_courier_tab()
    {
        if (isset($_POST['mpa_save_customer_choose_courier'])) {
            check_admin_referer('mpa_customer_choose_courier_action', 'mpa_customer_choose_courier_nonce');
            $price_option = sanitize_text_field($_POST['mpa_checkout_shipping_price']);
            $default_price = sanitize_text_field($_POST['mpa_default_shipping_price']);
            $fixed_price = isset($_POST['mpa_default_fixed_price']) ? floatval($_POST['mpa_default_fixed_price']) : 10.00;
            if ($fixed_price <= 0) {
                $fixed_price = 10.00;
            }
            $flat_price = isset($_POST['mpa_checkout_flat_price']) ? floatval($_POST['mpa_checkout_flat_price']) : 10.00;
            if ($flat_price <= 0) {
                $flat_price = 10.00;
            }
            update_option('mpa_checkout_shipping_price', $price_option);
            update_option('mpa_default_shipping_price', $default_price);
            update_option('mpa_default_fixed_price', round($fixed_price, 2));
            update_option('mpa_checkout_flat_price', round($flat_price, 2));

            $lane_price_type = isset($_POST['mpa_lane_price_type']) ? sanitize_text_field($_POST['mpa_lane_price_type']) : 'markup';
            $lane_markup = isset($_POST['mpa_lane_price_markup']) ? floatval($_POST['mpa_lane_price_markup']) : 0.00;
            $lane_flat_price = isset($_POST['mpa_lane_flat_price']) ? floatval($_POST['mpa_lane_flat_price']) : 0.00;
            update_option('mpa_lane_price_type', $lane_price_type);
            update_option('mpa_lane_price_markup', round($lane_markup, 2));
            update_option('mpa_lane_flat_price', round($lane_flat_price, 2));

            if (class_exists('WC_Cache_Helper')) {
                WC_Cache_Helper::get_transient_version('shipping', true);
            }
            $this->clear_wc_shipping_cache();

            // Process dynamic lanes
            $saved_lanes = array();
            if (isset($_POST['cc_lane_name']) && is_array($_POST['cc_lane_name'])) {
                foreach ($_POST['cc_lane_name'] as $key => $name) {
                    if (empty($name)) {
                        continue;
                    }
                    $saved_lanes[] = array(
                        'name' => sanitize_text_field($name),
                        'type' => sanitize_text_field($_POST['cc_lane_type'][$key]),
                        'courier' => sanitize_text_field($_POST['cc_lane_courier'][$key]),
                        'markup' => ($_POST['cc_lane_markup'][$key] !== '') ? round(floatval($_POST['cc_lane_markup'][$key]), 2) : 0.00,
                        'price_type' => sanitize_text_field($_POST['cc_lane_price_type'][$key]),
                        'flat_price' => ($_POST['cc_lane_flat_price'][$key] !== '') ? round(floatval($_POST['cc_lane_flat_price'][$key]), 2) : 0.00
                    );
                }
            }
            update_option('mpa_customer_choose_courier_lanes', wp_json_encode($saved_lanes));

            echo '<div class="notice notice-success is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('Customer Checkout Config settings saved!', 'myparcel-asia') . '</p></div>';
        }

        $price_option = get_option('mpa_checkout_shipping_price', 'free');
        $default_price = get_option('mpa_default_shipping_price', 'free');
        $default_fixed_price = get_option('mpa_default_fixed_price', '10.00');
        $checkout_flat_price = get_option('mpa_checkout_flat_price', '10.00');
        $lane_price_type = get_option('mpa_lane_price_type', 'markup');
        $lane_markup = get_option('mpa_lane_price_markup', '0.00');
        $lane_flat_price = get_option('mpa_lane_flat_price', '0.00');
        $lanes_json = get_option('mpa_customer_choose_courier_lanes', '');
        $cc_lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

        $domestic_couriers = array(
            'jnt' => 'J&T',
            'poslaju' => 'Poslaju',
            'dhle' => 'DHL',
            'ninjavan' => 'Ninjavan',
            'flash' => 'Flash',
            'citylink' => 'Citylink Express',
            'lex' => 'LEX Express',
            'spx' => 'SPX Express',
        );

        $int_couriers = array(
            'jnti' => 'J&T International',
            'ninjavani' => 'Ninjavan',
            'ems' => 'EMS',
            'aramex' => 'Aramex',
            'fedex' => 'Fedex',
            'airparcel' => 'AirParcel',
        );

        $all_couriers = array_merge($domestic_couriers, $int_couriers);
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('mpa_customer_choose_courier_action', 'mpa_customer_choose_courier_nonce'); ?>

            <h3 style="border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:15px;">
                <?php esc_html_e('General Config', 'myparcel-asia'); ?></h3>
            <table class="form-table" role="presentation" style="margin-bottom: 25px;">
                <tbody>
                    <tr>
                        <th scope="row"><label
                                for="mpa_checkout_shipping_price"><?php esc_html_e('Checkout Shipping Price', 'myparcel-asia'); ?></label>
                        </th>
                        <td>
                            <select name="mpa_checkout_shipping_price" id="mpa_checkout_shipping_price" style="width: 240px;">
                                <option value="woo" <?php selected($price_option, 'woo'); ?>>
                                    <?php esc_html_e('Use WooCommerce Shipping Zone', 'myparcel-asia'); ?></option>
                                <option value="choose" <?php selected($price_option, 'choose'); ?>>
                                    <?php esc_html_e('Customer Choose Courier', 'myparcel-asia'); ?></option>
                                <option value="lane" <?php selected($price_option, 'lane'); ?>>
                                    <?php esc_html_e('Follow Lane AWB price', 'myparcel-asia'); ?></option>
                                <option value="flat" <?php selected($price_option, 'flat'); ?>>
                                    <?php esc_html_e('Flat Shipping Price', 'myparcel-asia'); ?></option>
                                <option value="free" <?php selected($price_option, 'free'); ?>>
                                    <?php esc_html_e('FREE', 'myparcel-asia'); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Select the pricing behavior for checkout shipping rates.', 'myparcel-asia'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr id="mpa-flat-price-row" style="<?php echo 'flat' === $price_option ? '' : 'display:none;'; ?>">
                        <th scope="row"><label
                                for="mpa_checkout_flat_price"><?php esc_html_e('Checkout Flat Price (RM)', 'myparcel-asia'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="mpa_checkout_flat_price" id="mpa_checkout_flat_price"
                                value="<?php echo esc_attr(number_format(floatval($checkout_flat_price), 2, '.', '')); ?>"
                                style="width: 240px;" required>
                            <p class="description">
                                <?php esc_html_e('Flat price to apply for all checkout orders when Flat Shipping Price is selected.', 'myparcel-asia'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr id="mpa-lane-price-row" style="<?php echo 'lane' === $price_option ? '' : 'display:none;'; ?>">
                        <td colspan="2" style="padding: 15px 0 25px 0;">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="mpa_lane_price_type"><?php esc_html_e('Lane Price Type', 'myparcel-asia'); ?></label></th>
                                    <td>
                                        <select name="mpa_lane_price_type" id="mpa_lane_price_type" style="width: 240px;">
                                            <option value="exact_price" <?php selected($lane_price_type, 'exact_price'); ?>><?php esc_html_e('Exact Price', 'myparcel-asia'); ?></option>
                                            <option value="markup" <?php selected($lane_price_type, 'markup'); ?>><?php esc_html_e('Price + Markup', 'myparcel-asia'); ?></option>
                                            <option value="flat_price" <?php selected($lane_price_type, 'flat_price'); ?>><?php esc_html_e('Flat Price', 'myparcel-asia'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr id="mpa-lane-markup-row" style="<?php echo 'markup' === $lane_price_type ? '' : 'display:none;'; ?>">
                                    <th scope="row"><label for="mpa_lane_price_markup"><?php esc_html_e('Price Markup (RM)', 'myparcel-asia'); ?></label></th>
                                    <td>
                                        <input type="number" step="0.01" name="mpa_lane_price_markup" id="mpa_lane_price_markup" value="<?php echo esc_attr(number_format(floatval($lane_markup), 2, '.', '')); ?>" style="width: 240px;">
                                    </td>
                                </tr>
                                <tr id="mpa-lane-flat-row" style="<?php echo 'flat_price' === $lane_price_type ? '' : 'display:none;'; ?>">
                                    <th scope="row"><label for="mpa_lane_flat_price"><?php esc_html_e('Flat Price (RM)', 'myparcel-asia'); ?></label></th>
                                    <td>
                                        <input type="number" step="0.01" name="mpa_lane_flat_price" id="mpa_lane_flat_price" value="<?php echo esc_attr(number_format(floatval($lane_flat_price), 2, '.', '')); ?>" style="width: 240px;">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr id="mpa-courier-options-row" style="<?php echo 'choose' === $price_option ? '' : 'display:none;'; ?>">
                        <td colspan="2" style="padding: 15px 0 25px 0;">
                            <style>
                                #mpa-courier-options-row th,
                                #mpa-cc-lanes-tbody td {
                                    padding: 12px 10px !important;
                                    vertical-align: middle;
                                }
                            </style>
                            <div id="mpa-courier-options-wrapper">
                                <h3 style="border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:15px;">
                                    <?php esc_html_e('Courier Options / Lane Configurations', 'myparcel-asia'); ?></h3>

                                <table class="wp-list-table widefat striped"
                                    style="margin-bottom: 15px; width: auto; max-width: 100%;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Lane Name', 'myparcel-asia'); ?></th>
                                            <th width="200"><?php esc_html_e('Type / Scope', 'myparcel-asia'); ?></th>
                                            <th width="220"><?php esc_html_e('Courier Provider', 'myparcel-asia'); ?></th>
                                            <th width="150"><?php esc_html_e('Price Type', 'myparcel-asia'); ?></th>
                                            <th width="120"><?php esc_html_e('Markup (RM)', 'myparcel-asia'); ?></th>
                                            <th width="120"><?php esc_html_e('Flat Price (RM)', 'myparcel-asia'); ?></th>
                                            <th width="80" style="text-align: center;">
                                                <?php esc_html_e('Actions', 'myparcel-asia'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="mpa-cc-lanes-tbody">
                                        <?php if (empty($cc_lanes)): ?>
                                            <tr class="mpa-no-lanes-row">
                                                <td colspan="7" style="text-align: center; padding: 20px; color: #94a3b8;">
                                                    <?php esc_html_e('No courier option lanes configured. Click Add Lane to begin.', 'myparcel-asia'); ?>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($cc_lanes as $index => $lane): ?>
                                                <tr>
                                                    <td>
                                                        <input type="text" name="cc_lane_name[]"
                                                            value="<?php echo esc_attr($lane['name']); ?>" class="regular-text"
                                                            style="width: 100%;" required>
                                                    </td>
                                                    <td>
                                                        <select name="cc_lane_type[]" style="width: 100%;">
                                                            <option value="peninsular" <?php selected($lane['type'], 'peninsular'); ?>><?php esc_html_e('Peninsular Malaysia', 'myparcel-asia'); ?>
                                                            </option>
                                                            <option value="sabah_sarawak" <?php selected($lane['type'], 'sabah_sarawak'); ?>><?php esc_html_e('Sabah & Sarawak', 'myparcel-asia'); ?>
                                                            </option>
                                                            <option value="domestic" <?php selected($lane['type'], 'domestic'); ?>>
                                                                <?php esc_html_e('All Domestic (MY)', 'myparcel-asia'); ?></option>
                                                            <option value="international" <?php selected($lane['type'], 'international'); ?>><?php esc_html_e('International', 'myparcel-asia'); ?></option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select name="cc_lane_courier[]" style="width: 100%;">
                                                            <?php foreach ($all_couriers as $val => $label): ?>
                                                                <option value="<?php echo esc_attr($val); ?>" <?php selected($lane['courier'], $val); ?>><?php echo esc_html($label); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <?php $lane_price_type = isset($lane['price_type']) ? $lane['price_type'] : 'markup'; ?>
                                                        <select name="cc_lane_price_type[]" style="width: 100%;">
                                                            <option value="exact" <?php selected($lane_price_type, 'exact'); ?>>
                                                                <?php esc_html_e('Exact Price', 'myparcel-asia'); ?></option>
                                                            <option value="markup" <?php selected($lane_price_type, 'markup'); ?>>
                                                                <?php esc_html_e('Price + Markup', 'myparcel-asia'); ?></option>
                                                            <option value="flat" <?php selected($lane_price_type, 'flat'); ?>>
                                                                <?php esc_html_e('Flat Price', 'myparcel-asia'); ?></option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="cc_lane_markup[]"
                                                            value="<?php echo esc_attr($lane['markup']); ?>" step="0.01"
                                                            style="width: 75px !important; font-size: 12px !important; height: 26px !important; padding: 2px 6px !important; min-height: auto !important; line-height: 1 !important;">
                                                    </td>
                                                    <td>
                                                        <?php $lane_flat_price = isset($lane['flat_price']) ? $lane['flat_price'] : '0.00'; ?>
                                                        <input type="number" name="cc_lane_flat_price[]"
                                                            value="<?php echo esc_attr($lane_flat_price); ?>" step="0.01"
                                                            style="width: 75px !important; font-size: 12px !important; height: 26px !important; padding: 2px 6px !important; min-height: auto !important; line-height: 1 !important;">
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <button type="button" class="button mpa-delete-cc-row"
                                                            style="color: #ef4444; border-color: #ef4444; padding: 0 4px !important; height: 22px !important; line-height: 20px !important; min-height: auto !important; font-size: 11px !important;"><span
                                                                class="dashicons dashicons-trash"
                                                                style="font-size: 14px !important; width: 14px !important; height: 14px !important; line-height: 14px !important; margin: 0; vertical-align: middle;"></span></button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                                <button type="button" class="button" id="mpa-add-cc-lane-btn" style="margin-bottom: 20px;">
                                    <span class="dashicons dashicons-plus" style="margin-top: 4px; font-size: 16px;"></span>
                                    <?php esc_html_e('Add Lane', 'myparcel-asia'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label
                                for="mpa_default_shipping_price"><?php esc_html_e('Default Shipping Price', 'myparcel-asia'); ?></label>
                        </th>
                        <td>
                            <select name="mpa_default_shipping_price" id="mpa_default_shipping_price" style="width: 240px;">
                                <option value="free" <?php selected($default_price, 'free'); ?>>
                                    <?php esc_html_e('FREE', 'myparcel-asia'); ?></option>
                                <option value="no-service" <?php selected($default_price, 'no-service'); ?>>
                                    <?php esc_html_e('No Service - Block customer from checkout', 'myparcel-asia'); ?>
                                </option>
                                <option value="fixed" <?php selected($default_price, 'fixed'); ?>>
                                    <?php esc_html_e('Fixed Price', 'myparcel-asia'); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Fallback choice if no price is available.', 'myparcel-asia'); ?></p>
                        </td>
                    </tr>
                    <tr id="mpa-fixed-price-row" style="<?php echo 'fixed' === $default_price ? '' : 'display:none;'; ?>">
                        <th scope="row"><label
                                for="mpa_default_fixed_price"><?php esc_html_e('Default Fix Price (RM)', 'myparcel-asia'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="mpa_default_fixed_price" id="mpa_default_fixed_price"
                                value="<?php echo esc_attr(number_format(floatval($default_fixed_price), 2, '.', '')); ?>"
                                style="width: 240px;" required>
                            <p class="description">
                                <?php esc_html_e('Fixed price to apply when Default Shipping Price is set to Fixed Price. Must be greater than 0.', 'myparcel-asia'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    $('#mpa_checkout_shipping_price').on('change', function () {
                        if ($(this).val() === 'flat') {
                            $('#mpa-flat-price-row').show();
                        } else {
                            $('#mpa-flat-price-row').hide();
                        }
                        if ($(this).val() === 'choose') {
                            $('#mpa-courier-options-row').show();
                        } else {
                            $('#mpa-courier-options-row').hide();
                        }
                        if ($(this).val() === 'lane') {
                            $('#mpa-lane-price-row').show();
                        } else {
                            $('#mpa-lane-price-row').hide();
                        }
                    });
                    $('#mpa_lane_price_type').on('change', function () {
                        if ($(this).val() === 'markup') {
                            $('#mpa-lane-markup-row').show();
                            $('#mpa-lane-flat-row').hide();
                        } else if ($(this).val() === 'flat_price') {
                            $('#mpa-lane-flat-row').show();
                            $('#mpa-lane-markup-row').hide();
                        } else {
                            $('#mpa-lane-markup-row').hide();
                            $('#mpa-lane-flat-row').hide();
                        }
                    });
                    $('#mpa_default_shipping_price').on('change', function () {
                        if ($(this).val() === 'fixed') {
                            $('#mpa-fixed-price-row').show();
                        } else {
                            $('#mpa-fixed-price-row').hide();
                        }
                    });
                });
            </script>

            <p class="submit">
                <input type="submit" name="mpa_save_customer_choose_courier" id="submit" class="button button-primary"
                    value="<?php esc_attr_e('Save Changes', 'myparcel-asia'); ?>">
            </p>
        </form>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Add Row
                $('#mpa-add-cc-lane-btn').on('click', function (e) {
                    e.preventDefault();
                    $('.mpa-no-lanes-row').remove();

                    var html = '<tr>' +
                        '<td><input type="text" name="cc_lane_name[]" value="" class="regular-text" style="width: 100%;" required></td>' +
                        '<td>' +
                        '<select name="cc_lane_type[]" style="width: 100%;">' +
                        '<option value="peninsular"><?php esc_html_e("Peninsular Malaysia", "myparcel-asia"); ?></option>' +
                        '<option value="sabah_sarawak"><?php esc_html_e("Sabah & Sarawak", "myparcel-asia"); ?></option>' +
                        '<option value="domestic"><?php esc_html_e("All Domestic (MY)", "myparcel-asia"); ?></option>' +
                        '<option value="international"><?php esc_html_e("International", "myparcel-asia"); ?></option>' +
                        '</select>' +
                        '</td>' +
                        '<td>' +
                        '<select name="cc_lane_courier[]" style="width: 100%;">' +
                        <?php foreach ($all_couriers as $val => $label): ?>
                        '<option value="<?php echo esc_attr($val); ?>"><?php echo esc_js($label); ?></option>' +
                        <?php endforeach; ?>
                    '</select>' +
                        '</td>' +
                        '<td>' +
                        '<select name="cc_lane_price_type[]" style="width: 100%;">' +
                        '<option value="exact"><?php esc_html_e("Exact Price", "myparcel-asia"); ?></option>' +
                        '<option value="markup" selected><?php esc_html_e("Price + Markup", "myparcel-asia"); ?></option>' +
                        '<option value="flat"><?php esc_html_e("Flat Price", "myparcel-asia"); ?></option>' +
                        '</select>' +
                        '</td>' +
                        '<td><input type="number" name="cc_lane_markup[]" value="0.00" step="0.01" style="width: 75px !important; font-size: 12px !important; height: 26px !important; padding: 2px 6px !important; min-height: auto !important; line-height: 1 !important;"></td>' +
                        '<td><input type="number" name="cc_lane_flat_price[]" value="0.00" step="0.01" style="width: 75px !important; font-size: 12px !important; height: 26px !important; padding: 2px 6px !important; min-height: auto !important; line-height: 1 !important;"></td>' +
                        '<td style="text-align: center;">' +
                        '<button type="button" class="button mpa-delete-cc-row" style="color: #ef4444; border-color: #ef4444; padding: 0 4px !important; height: 22px !important; line-height: 20px !important; min-height: auto !important; font-size: 11px !important;"><span class="dashicons dashicons-trash" style="font-size: 14px !important; width: 14px !important; height: 14px !important; line-height: 14px !important; margin: 0; vertical-align: middle;"></span></button>' +
                        '</td>' +
                        '</tr>';

                    $('#mpa-cc-lanes-tbody').append(html);
                });

                // Delete Row
                $(document).on('click', '.mpa-delete-cc-row', function (e) {
                    e.preventDefault();
                    $(this).closest('tr').remove();
                    if ($('#mpa-cc-lanes-tbody tr').length === 0) {
                        $('#mpa-cc-lanes-tbody').append('<tr class="mpa-no-lanes-row"><td colspan="7" style="text-align: center; padding: 20px; color: #94a3b8;"><?php esc_html_e("No courier option lanes configured. Click Add Lane to begin.", "myparcel-asia"); ?></td></tr>');
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Render API Connection Tab Content
     */
    protected function render_api_connection_tab()
    {
        // Handle Save
        if (isset($_POST['mpa_save_api_settings'])) {
            check_admin_referer('mpa_api_settings_action', 'mpa_api_settings_nonce');
            $host = sanitize_text_field($_POST['mpa_host']);
            $api_key = sanitize_text_field($_POST['mpa_api_key']);

            update_option('mpa_host', $host);
            update_option('mpa_api_key', $api_key);

            $sync_result = $this->sync_user_details($api_key);

            if (is_wp_error($sync_result)) {
                echo '<div class="notice notice-error is-dismissible" style="margin-left:0;margin-right:0;"><p>' . sprintf(esc_html__('Settings saved, but connection error: %s', 'myparcel-asia'), esc_html($sync_result->get_error_message())) . '</p></div>';
            } elseif (!empty($sync_result['status'])) {
                echo '<div class="notice notice-success is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('Settings saved and connection verified successfully!', 'myparcel-asia') . '</p></div>';
            } else {
                if (!empty($sync_result['message'])) {
                    $msg = sprintf(esc_html__('Connection failed: %s', 'myparcel-asia'), esc_html($sync_result['message']));
                } else {
                    $msg = esc_html__('Connection failed: Unknown error. Please check your API key or contact support.', 'myparcel-asia');
                }
                echo '<div class="notice notice-error is-dismissible" style="margin-left:0;margin-right:0;"><p>' . $msg . '</p></div>';
            }
        }

        $host = get_option('mpa_host', 'app.myparcelasia.com');
        $api_key = get_option('mpa_api_key', '');

        // Generate Connect Link parameters
        $http = is_ssl() ? 'https://' : 'http://';
        $redirect_url = $http . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $param = array(
            'redirect_url' => $redirect_url,
            'vendor' => 'woocommerce',
            'label' => $_SERVER['HTTP_HOST'],
        );
        $segment = base64_encode(json_encode($param));
        $current_connect_url = "https://" . $host . "/apiv2/connect/" . $segment;
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('mpa_api_settings_action', 'mpa_api_settings_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="mpa_host"><?php esc_html_e('Host', 'myparcel-asia'); ?></label></th>
                        <td>
                            <select name="mpa_host" id="mpa_host" class="regular-text">
                                <option value="app.myparcelasia.com" <?php selected($host, 'app.myparcelasia.com'); ?>>
                                    app.myparcelasia.com</option>
                                <option value="demo.myparcelasia.com" <?php selected($host, 'demo.myparcelasia.com'); ?>>
                                    demo.myparcelasia.com</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mpa_api_key"><?php esc_html_e('API Key', 'myparcel-asia'); ?></label></th>
                        <td>
                            <input name="mpa_api_key" type="text" id="mpa_api_key" value="<?php echo esc_attr($api_key); ?>"
                                class="regular-text" style="font-family: monospace;">
                            <p class="description" style="margin-top: 8px;">
                                <a id="mpa_connect_link" href="<?php echo esc_url($current_connect_url); ?>" target="_blank"
                                    style="font-weight: 600; text-decoration: none; color: #3b82f6;">
                                    <?php esc_html_e('Connect To Get API Key', 'myparcel-asia'); ?> &rarr;
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label
                                for="mpa_login_email"><?php esc_html_e('MPA Login Email', 'myparcel-asia'); ?></label></th>
                        <td>
                            <input name="mpa_login_email" type="text" id="mpa_login_email"
                                value="<?php echo esc_attr(get_option('mpa_login_email', '')); ?>" class="regular-text"
                                disabled>
                            <p class="description">
                                <?php esc_html_e('The email address associated with your connected MYPARCEL ASIA account.', 'myparcel-asia'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(__('Save API Settings', 'myparcel-asia'), 'primary', 'mpa_save_api_settings'); ?>
        </form>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var hostSelect = document.getElementById('mpa_host');
                var connectLink = document.getElementById('mpa_connect_link');
                var segment = "<?php echo esc_js($segment); ?>";

                if (hostSelect && connectLink) {
                    hostSelect.addEventListener('change', function () {
                        var selectedHost = hostSelect.value;
                        connectLink.href = 'https://' + selectedHost + '/apiv2/connect/' + segment;
                    });
                }
            });
        </script>
        <?php
    }

    /**
     * Render Default Config Tab Content
     */
    protected function render_default_config_tab()
    {
        // Retrieve settings list
        $fields = array(
            'mpa_sender_name' => 'Sender Name',
            'mpa_sender_company' => 'Sender Company',
            'mpa_sender_email' => 'Sender Email',
            'mpa_sender_phone' => 'Sender Phone',
            'mpa_sender_address_1' => 'Sender Address Line 1',
            'mpa_sender_address_2' => 'Sender Address Line 2',
            'mpa_sender_address_3' => 'Sender Address Line 3',
            'mpa_sender_address_4' => 'Sender Address Line 4',
            'mpa_sender_postcode' => 'Sender Postcode',
            'mpa_sender_city' => 'Sender City',
            'mpa_sender_state' => 'Sender State',
            'mpa_default_content_type' => 'Default Content Type',
            'mpa_default_send_method' => 'Default Send Method',
            'mpa_default_parcel_size' => 'Default Parcel Size',
        );

        $api_key = get_option('mpa_api_key', '');

        // Handle Save
        if (isset($_POST['mpa_save_default_config'])) {
            check_admin_referer('mpa_default_config_action', 'mpa_default_config_nonce');
            foreach ($fields as $opt_name => $label) {
                if (isset($_POST[$opt_name])) {
                    update_option($opt_name, sanitize_text_field($_POST[$opt_name]));
                }
            }
            echo '<div class="notice notice-success is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('Default configurations saved.', 'myparcel-asia') . '</p></div>';
        }

        // Check if all options are empty. If so, pull them from API.
        $all_empty = true;
        foreach ($fields as $opt_name => $label) {
            if ('mpa_default_send_method' === $opt_name || 'mpa_default_parcel_size' === $opt_name) {
                continue;
            }
            if (get_option($opt_name, '') !== '') {
                $all_empty = false;
                break;
            }
        }

        if ($all_empty && !empty($api_key)) {
            $sync_result = $this->sync_user_details($api_key);
            if (!is_wp_error($sync_result) && !empty($sync_result['status'])) {
                echo '<div class="notice notice-info is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('Imported default settings from your MYPARCEL ASIA account.', 'myparcel-asia') . '</p></div>';
            }
        }
        ?>
        <h3><?php esc_html_e('Default Shipment Configurations', 'myparcel-asia'); ?></h3>
        <p><?php esc_html_e('Configure default parcel settings and origin sender addresses.', 'myparcel-asia'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('mpa_default_config_action', 'mpa_default_config_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <?php foreach ($fields as $opt_name => $label): ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr($opt_name); ?>"><?php echo esc_html($label); ?></label>
                            </th>
                            <td>
                                <?php if ('mpa_default_content_type' === $opt_name):
                                    $content_types = array(
                                        'outdoors' => 'Lifestyle & Home - Outdoors',
                                        'general' => 'Fashion & Apparel - General',
                                        'sports' => 'Fashion & Apparel - Sports',
                                        'accessories' => 'Fashion & Apparel - Accessories',
                                        'muslimah' => 'Fashion & Apparel- Muslimah',
                                        'health' => 'Health & Beauty',
                                        'babies' => 'Babies & Toys',
                                        'gadget_general' => 'Electronic & Gadgets - General',
                                        'music' => 'Electronic & Gadgets - Music',
                                        'furniture' => 'Lifestyle & Home - Furniture',
                                        'fitness' => 'Lifestyle & Home - Health and Fitness',
                                        'papers' => 'Letters & Papers',
                                        'others' => 'Others',
                                    );
                                    $current_val = get_option($opt_name, '');
                                    ?>
                                    <select name="<?php echo esc_attr($opt_name); ?>" id="<?php echo esc_attr($opt_name); ?>"
                                        class="regular-text">
                                        <option value=""><?php esc_html_e('-- Select Content Type --', 'myparcel-asia'); ?></option>
                                        <?php foreach ($content_types as $val => $text): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($current_val, $val); ?>>
                                                <?php echo esc_html($text); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ('mpa_default_send_method' === $opt_name):
                                    $send_methods = array(
                                        'dropoff' => 'Drop Off',
                                        'pickup' => 'Pickup',
                                    );
                                    $current_val = get_option($opt_name, 'dropoff');
                                    ?>
                                    <select name="<?php echo esc_attr($opt_name); ?>" id="<?php echo esc_attr($opt_name); ?>"
                                        class="regular-text">
                                        <?php foreach ($send_methods as $val => $text): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($current_val, $val); ?>>
                                                <?php echo esc_html($text); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ('mpa_default_parcel_size' === $opt_name):
                                    $parcel_sizes = array(
                                        'box' => 'Box / Self Wrapped',
                                        'flyers_s' => 'Flyer S',
                                        'flyers_m' => 'Flyer M',
                                        'flyers_l' => 'Flyer L',
                                        'flyers_xl' => 'Flyer XL',
                                        'envelope_third' => 'Envelope 1/3 A4',
                                        'envelope_a4' => 'Envelope A4',
                                        'envelope_a5' => 'Envelope A5',
                                    );
                                    $current_val = get_option($opt_name, 'flyers_s');
                                    ?>
                                    <select name="<?php echo esc_attr($opt_name); ?>" id="<?php echo esc_attr($opt_name); ?>"
                                        class="regular-text">
                                        <?php foreach ($parcel_sizes as $val => $text): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($current_val, $val); ?>>
                                                <?php echo esc_html($text); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input name="<?php echo esc_attr($opt_name); ?>" type="text" id="<?php echo esc_attr($opt_name); ?>"
                                        value="<?php echo esc_attr(get_option($opt_name, '')); ?>" class="regular-text">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Save Configurations', 'myparcel-asia'), 'primary', 'mpa_save_default_config'); ?>
        </form>
        <?php
    }

    /**
     * Render Lane Management Tab Content
     */
    protected function render_lane_management_tab()
    {
        // Retrieve lanes
        $lanes_json = get_option('mpa_lanes', '');
        $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

        // Default structure if empty
        if (empty($lanes) || !is_array($lanes)) {
            $lanes = array(
                'fallback_my' => array(
                    'id' => 'fallback_my',
                    'name' => 'Within Malaysia',
                    'type' => 'fallback',
                    'scope' => 'my',
                    'courier' => 'none',
                ),
                'fallback_int' => array(
                    'id' => 'fallback_int',
                    'name' => 'International',
                    'type' => 'fallback',
                    'scope' => 'int',
                    'courier' => 'none',
                )
            );
        }

        // Domestic and International Couriers lists
        $domestic_couriers = array(
            'none' => 'Not Available',
            'jnt' => 'J&T',
            'poslaju' => 'Poslaju',
            'dhle' => 'DHL',
            'ninjavan' => 'Ninjavan',
            'flash' => 'Flash',
            'citylink' => 'Citylink Express',
            'lex' => 'LEX Express',
            'spx' => 'SPX Express',
        );

        $int_couriers = array(
            'none' => 'Not Available',
            'jnti' => 'J&T International',
            'ninjavani' => 'Ninjavan',
            'ems' => 'EMS',
            'aramex' => 'Aramex',
            'fedex' => 'Fedex',
            'airparcel' => 'AirParcel',
        );

        // Malaysia States
        $states = array(
            'JHR' => 'Johor',
            'KDH' => 'Kedah',
            'KTN' => 'Kelantan',
            'MLK' => 'Melaka',
            'NSN' => 'Negeri Sembilan',
            'PHG' => 'Pahang',
            'PNG' => 'Penang',
            'PRK' => 'Perak',
            'PLS' => 'Perlis',
            'SBH' => 'Sabah',
            'SRW' => 'Sarawak',
            'SGR' => 'Selangor',
            'TRG' => 'Terengganu',
            'KUL' => 'WP Kuala Lumpur',
            'LBN' => 'WP Labuan',
            'PJY' => 'WP Putrajaya',
        );

        // Countries list
        $countries = array(
            'SG' => 'Singapore',
            'ID' => 'Indonesia',
            'TH' => 'Thailand',
            'PH' => 'Philippines',
            'BN' => 'Brunei',
            'KH' => 'Cambodia',
            'VN' => 'Vietnam',
            'MM' => 'Myanmar',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'CN' => 'China',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'AU' => 'Australia',
        );

        // Handle POST Requests
        if (isset($_POST['mpa_save_lanes'])) {
            check_admin_referer('mpa_lanes_action', 'mpa_lanes_nonce');

            // Update Fallbacks
            if (isset($_POST['fallback_my_courier'])) {
                $lanes['fallback_my']['courier'] = sanitize_text_field($_POST['fallback_my_courier']);
            }
            if (isset($_POST['fallback_my_markup'])) {
                $lanes['fallback_my']['markup'] = ($_POST['fallback_my_markup'] !== '') ? round(floatval($_POST['fallback_my_markup']), 2) : null;
            }
            if (isset($_POST['fallback_int_courier'])) {
                $lanes['fallback_int']['courier'] = sanitize_text_field($_POST['fallback_int_courier']);
            }
            if (isset($_POST['fallback_int_markup'])) {
                $lanes['fallback_int']['markup'] = ($_POST['fallback_int_markup'] !== '') ? round(floatval($_POST['fallback_int_markup']), 2) : null;
            }

            // Update existing custom lanes
            if (isset($_POST['custom_courier']) && is_array($_POST['custom_courier'])) {
                foreach ($_POST['custom_courier'] as $lane_id => $courier) {
                    if (isset($lanes[$lane_id])) {
                        $lanes[$lane_id]['courier'] = sanitize_text_field($courier);
                    }
                }
            }
            if (isset($_POST['custom_markup']) && is_array($_POST['custom_markup'])) {
                foreach ($_POST['custom_markup'] as $lane_id => $markup) {
                    if (isset($lanes[$lane_id])) {
                        $lanes[$lane_id]['markup'] = ($markup !== '') ? round(floatval($markup), 2) : null;
                    }
                }
            }

            update_option('mpa_lanes', wp_json_encode($lanes));
            if (class_exists('WC_Cache_Helper')) {
                WC_Cache_Helper::get_transient_version('shipping', true);
            }
            $this->clear_wc_shipping_cache();
            echo '<div class="notice notice-success is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('Lanes configuration saved.', 'myparcel-asia') . '</p></div>';
        }

        // Handle Add Lane
        if (isset($_POST['mpa_add_lane'])) {
            check_admin_referer('mpa_add_lane_action', 'mpa_add_lane_nonce');

            $lane_name = sanitize_text_field($_POST['new_lane_name']);
            $scope = sanitize_text_field($_POST['new_lane_scope']);
            $courier = sanitize_text_field($_POST['new_lane_courier']);
            $markup = (isset($_POST['new_lane_markup']) && $_POST['new_lane_markup'] !== '') ? round(floatval($_POST['new_lane_markup']), 2) : null;
            $details = '';

            if ('state' === $scope && isset($_POST['new_lane_state'])) {
                $details = sanitize_text_field($_POST['new_lane_state']);
            } elseif ('country' === $scope && isset($_POST['new_lane_country'])) {
                $details = sanitize_text_field($_POST['new_lane_country']);
            }

            if (!empty($lane_name)) {
                $new_id = 'lane_' . time() . '_' . rand(100, 999);
                $lanes[$new_id] = array(
                    'id' => $new_id,
                    'name' => $lane_name,
                    'type' => 'override',
                    'scope' => $scope,
                    'details' => $details,
                    'courier' => $courier,
                    'markup' => $markup,
                );

                update_option('mpa_lanes', wp_json_encode($lanes));
                if (class_exists('WC_Cache_Helper')) {
                    WC_Cache_Helper::get_transient_version('shipping', true);
                }
                $this->clear_wc_shipping_cache();
                echo '<div class="notice notice-success is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('New custom override lane added.', 'myparcel-asia') . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible" style="margin-left:0;margin-right:0;"><p>' . esc_html__('Lane name is required.', 'myparcel-asia') . '</p></div>';
            }
        }

        // Handle Delete Lane
        if (isset($_GET['delete_lane'])) {
            $delete_id = sanitize_text_field($_GET['delete_lane']);
            if (isset($lanes[$delete_id]) && 'fallback' !== $lanes[$delete_id]['type']) {
                unset($lanes[$delete_id]);
                update_option('mpa_lanes', wp_json_encode($lanes));
                if (class_exists('WC_Cache_Helper')) {
                    WC_Cache_Helper::get_transient_version('shipping', true);
                }
                $this->clear_wc_shipping_cache();
                // Perform a query argument clean redirect
                wp_safe_redirect(remove_query_arg('delete_lane'));
                exit;
            }
        }
        ?>

        <style>
            .mpa-lane-section {
                margin-bottom: 30px;
            }

            .mpa-lane-table {
                width: auto;
                min-width: 600px;
                border-collapse: collapse;
                margin-top: 10px;
                font-size: 13px;
                background-color: #ffffff;
            }

            .mpa-lane-table th,
            .mpa-lane-table td {
                border: 1px solid #e2e8f0;
                padding: 8px 12px;
                text-align: left;
            }

            .mpa-lane-table th {
                background-color: #f1f5f9;
                font-weight: 600;
            }

            .mpa-lane-table tbody tr:nth-child(even) {
                background-color: #f8fafc;
            }

            .mpa-lane-table tbody tr:hover {
                background-color: #f1f5f9;
            }

            .mpa-add-lane-box {
                background: #f8fafc;
                border: 1px dashed #cbd5e1;
                border-radius: 8px;
                padding: 20px;
                margin-top: 20px;
            }

            .mpa-inline-form-group {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                align-items: flex-end;
            }

            .mpa-inline-form-item {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }

            .mpa-inline-form-item label {
                font-weight: 600;
                font-size: 12px;
            }

            .mpa-lane-table input[type="number"] {
                font-size: 12px !important;
                height: 26px !important;
                padding: 2px 6px !important;
                width: 75px !important;
                min-height: auto !important;
                line-height: 1 !important;
            }

            .mpa-lane-table select {
                font-size: 12px !important;
                height: 24px !important;
                padding: 2px 6px !important;
                min-height: auto !important;
                line-height: 1 !important;
            }

            .mpa-lane-table .button-link-delete {
                font-size: 11px !important;
                padding: 2px 8px !important;
                line-height: 1 !important;
                min-height: auto !important;
                height: 26px !important;
                display: inline-flex;
                align-items: center;
                text-decoration: none;
                background: #f6f7f7;
                border-color: #dcdcde;
                color: #2c3338;
            }

            .mpa-lane-table .button-link-delete:hover {
                background: #f0f0f1;
                border-color: #0a4b78;
                color: #0a4b78;
            }

            .mpa-add-lane-box input[type="text"],
            .mpa-add-lane-box select,
            .mpa-add-lane-box input[type="number"] {
                font-size: 12px !important;
                height: 26px !important;
                padding: 2px 6px !important;
                min-height: auto !important;
            }

            .mpa-add-lane-box input[type="submit"] {
                font-size: 11px !important;
                padding: 2px 8px !important;
                min-height: auto !important;
                height: 26px !important;
                line-height: 1 !important;
            }

            .mpa-add-lane-box {
                padding: 15px !important;
            }
        </style>

        <div class="mpa-lane-section">
            <h3><?php esc_html_e('Fallback Lanes (Required Fallbacks)', 'myparcel-asia'); ?></h3>
            <p><?php esc_html_e('These are the fallback routes if no override lane matches a customer address. Cannot be deleted.', 'myparcel-asia'); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('mpa_lanes_action', 'mpa_lanes_nonce'); ?>
                <table class="mpa-lane-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Lane Name', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Destination Type', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Assigned Courier', 'myparcel-asia'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php echo esc_html($lanes['fallback_my']['name']); ?></strong></td>
                            <td><?php esc_html_e('Domestic (Malaysia)', 'myparcel-asia'); ?></td>
                            <td>
                                <select name="fallback_my_courier">
                                    <?php foreach ($domestic_couriers as $val => $label): ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($lanes['fallback_my']['courier'], $val); ?>><?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html($lanes['fallback_int']['name']); ?></strong></td>
                            <td><?php esc_html_e('International', 'myparcel-asia'); ?></td>
                            <td>
                                <select name="fallback_int_courier">
                                    <?php foreach ($int_couriers as $val => $label): ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($lanes['fallback_int']['courier'], $val); ?>><?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
        </div>

        <div class="mpa-lane-section">
            <h3><?php esc_html_e('Override Lanes (Custom Priorities)', 'myparcel-asia'); ?></h3>
            <p><?php esc_html_e('Configure priority custom lanes to override fallback settings for specific states or countries.', 'myparcel-asia'); ?>
            </p>

            <table class="mpa-lane-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Lane Name', 'myparcel-asia'); ?></th>
                        <th><?php esc_html_e('Destination Scope', 'myparcel-asia'); ?></th>
                        <th><?php esc_html_e('Details', 'myparcel-asia'); ?></th>
                        <th><?php esc_html_e('Assigned Courier', 'myparcel-asia'); ?></th>
                        <th><?php esc_html_e('Actions', 'myparcel-asia'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $has_custom = false;
                    foreach ($lanes as $id => $lane) {
                        if ('override' !== $lane['type']) {
                            continue;
                        }
                        $has_custom = true;

                        // Detect scope text
                        $scope_label = '';
                        $details_label = '';
                        $is_domestic = true;

                        switch ($lane['scope']) {
                            case 'peninsular':
                                $scope_label = __('Peninsular Malaysia', 'myparcel-asia');
                                $details_label = '-';
                                break;
                            case 'sabah_sarawak':
                                $scope_label = __('Sabah & Sarawak', 'myparcel-asia');
                                $details_label = '-';
                                break;
                            case 'state':
                                $scope_label = __('Specific State', 'myparcel-asia');
                                $details_label = isset($states[$lane['details']]) ? $states[$lane['details']] : $lane['details'];
                                break;
                            case 'country':
                                $scope_label = __('Specific Country', 'myparcel-asia');
                                $details_label = isset($countries[$lane['details']]) ? $countries[$lane['details']] : $lane['details'];
                                $is_domestic = false;
                                break;
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($lane['name']); ?></td>
                            <td><?php echo esc_html($scope_label); ?></td>
                            <td><?php echo esc_html($details_label); ?></td>
                            <td>
                                <select name="custom_courier[<?php echo esc_attr($id); ?>]">
                                    <?php
                                    $courier_options = $is_domestic ? $domestic_couriers : $int_couriers;
                                    foreach ($courier_options as $val => $label):
                                        ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($lane['courier'], $val); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('delete_lane', $id)); ?>"
                                    class="button button-secondary button-link-delete"
                                    onclick="return confirm('Are you sure you want to delete this override lane?');">
                                    <?php esc_html_e('Delete', 'myparcel-asia'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php
                    }
                    if (!$has_custom):
                        ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #94a3b8; padding: 20px;">
                                <?php esc_html_e('No custom override lanes configured. Create one below.', 'myparcel-asia'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top: 15px;">
                <?php submit_button(__('Save All Courier Assignments', 'myparcel-asia'), 'primary', 'mpa_save_lanes', false); ?>
            </div>
            </form>
        </div>

        <!-- Add Override Lane Form -->
        <div class="mpa-add-lane-box">
            <h4><?php esc_html_e('Create New Override Lane', 'myparcel-asia'); ?></h4>
            <form method="post" action="">
                <?php wp_nonce_field('mpa_add_lane_action', 'mpa_add_lane_nonce'); ?>
                <div class="mpa-inline-form-group">
                    <div class="mpa-inline-form-item">
                        <label for="new_lane_name"><?php esc_html_e('Lane Name', 'myparcel-asia'); ?></label>
                        <input type="text" name="new_lane_name" id="new_lane_name" placeholder="e.g. Johor Premium Lane"
                            required>
                    </div>

                    <div class="mpa-inline-form-item">
                        <label for="new_lane_scope"><?php esc_html_e('Destination Scope', 'myparcel-asia'); ?></label>
                        <select name="new_lane_scope" id="new_lane_scope">
                            <option value="peninsular"><?php esc_html_e('Peninsular Malaysia', 'myparcel-asia'); ?></option>
                            <option value="sabah_sarawak"><?php esc_html_e('Sabah & Sarawak', 'myparcel-asia'); ?></option>
                            <option value="state"><?php esc_html_e('Specific Malaysia State', 'myparcel-asia'); ?></option>
                            <option value="country"><?php esc_html_e('Specific Country (International)', 'myparcel-asia'); ?>
                            </option>
                        </select>
                    </div>

                    <!-- Dynamic State Field -->
                    <div class="mpa-inline-form-item" id="mpa_state_field" style="display:none;">
                        <label for="new_lane_state"><?php esc_html_e('Select State', 'myparcel-asia'); ?></label>
                        <select name="new_lane_state" id="new_lane_state">
                            <?php foreach ($states as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dynamic Country Field -->
                    <div class="mpa-inline-form-item" id="mpa_country_field" style="display:none;">
                        <label for="new_lane_country"><?php esc_html_e('Select Country', 'myparcel-asia'); ?></label>
                        <select name="new_lane_country" id="new_lane_country">
                            <?php foreach ($countries as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mpa-inline-form-item">
                        <label for="new_lane_courier"><?php esc_html_e('Assigned Courier', 'myparcel-asia'); ?></label>
                        <!-- We will dynamically switch options between domestic and international using JS -->
                        <select name="new_lane_courier" id="new_lane_courier">
                            <?php foreach ($domestic_couriers as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" class="dom-opt"><?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php foreach ($int_couriers as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" class="int-opt" style="display:none;">
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mpa-inline-form-item" style="padding-bottom: 4px;">
                        <?php submit_button(__('Add Lane', 'myparcel-asia'), 'secondary', 'mpa_add_lane', false); ?>
                    </div>
                </div>
            </form>
        </div>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var scopeSelect = document.getElementById('new_lane_scope');
                var stateField = document.getElementById('mpa_state_field');
                var countryField = document.getElementById('mpa_country_field');
                var courierSelect = document.getElementById('new_lane_courier');

                if (scopeSelect && courierSelect) {
                    scopeSelect.addEventListener('change', function () {
                        var scope = scopeSelect.value;

                        // Toggle conditional selectors
                        stateField.style.display = (scope === 'state') ? 'block' : 'none';
                        countryField.style.display = (scope === 'country') ? 'block' : 'none';

                        // Toggle domestic vs international courier options
                        var domOpts = courierSelect.querySelectorAll('.dom-opt');
                        var intOpts = courierSelect.querySelectorAll('.int-opt');

                        if (scope === 'country') {
                            domOpts.forEach(function (opt) { opt.style.display = 'none'; });
                            intOpts.forEach(function (opt) { opt.style.display = 'block'; });
                            courierSelect.value = 'none'; // reset to Not Available
                        } else {
                            domOpts.forEach(function (opt) { opt.style.display = 'block'; });
                            intOpts.forEach(function (opt) { opt.style.display = 'none'; });
                            courierSelect.value = 'none'; // reset to Not Available
                        }
                    });
                }
            });
        </script>
        <?php
    }



    /**
     * Reusable POST request helper for MYPARCEL ASIA API
     * Always uses the currently selected host option as the base URL.
     *
     * @param string $endpoint The endpoint path (e.g. '/user')
     * @param array  $param    The body parameters
     * @return array|WP_Error  Parsed JSON response array or WP_Error on failure
     */
    public function mpa_post($endpoint, $param = array())
    {
        $host = get_option('mpa_host', 'app.myparcelasia.com');
        $url = 'https://' . $host . '/apiv2' . $endpoint;

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($param),
            'timeout' => 15,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_parse_error', __('Failed to parse API response.', 'myparcel-asia'));
        }

        return $data;
    }

    /**
     * Sync user details from MyParcel Asia API and save email.
     *
     * @param string $api_key
     * @return array|WP_Error
     */
    public function sync_user_details($api_key)
    {
        if (empty($api_key)) {
            delete_option('mpa_login_email');
            return new WP_Error('empty_api_key', __('API Key is required.', 'myparcel-asia'));
        }

        $response = $this->mpa_post('/user', array('api_key' => $api_key));

        if (is_wp_error($response)) {
            return $response;
        }

        if (!empty($response['status']) && !empty($response['data']['email'])) {
            $data = $response['data'];
            update_option('mpa_login_email', sanitize_email($data['email']));

            // Check if ALL default config inputs are empty
            $default_fields = array(
                'mpa_sender_name',
                'mpa_sender_company',
                'mpa_sender_email',
                'mpa_sender_phone',
                'mpa_sender_address_1',
                'mpa_sender_address_2',
                'mpa_sender_address_3',
                'mpa_sender_address_4',
                'mpa_sender_postcode',
                'mpa_sender_city',
                'mpa_sender_state',
                'mpa_default_content_type',
            );

            $is_empty = true;
            foreach ($default_fields as $field) {
                if (get_option($field, '') !== '') {
                    $is_empty = false;
                    break;
                }
            }

            // If empty, auto-populate from API response parameters
            if ($is_empty) {
                update_option('mpa_sender_name', sanitize_text_field(isset($data['sender_name']) ? $data['sender_name'] : ''));
                update_option('mpa_sender_company', sanitize_text_field(isset($data['company_name']) ? $data['company_name'] : ''));
                update_option('mpa_sender_email', sanitize_email(isset($data['sender_email']) ? $data['sender_email'] : ''));
                update_option('mpa_sender_phone', sanitize_text_field(isset($data['sender_phone']) ? $data['sender_phone'] : ''));
                update_option('mpa_sender_address_1', sanitize_text_field(isset($data['sender_address_line_1']) ? $data['sender_address_line_1'] : ''));
                update_option('mpa_sender_address_2', sanitize_text_field(isset($data['sender_address_line_2']) ? $data['sender_address_line_2'] : ''));
                update_option('mpa_sender_address_3', sanitize_text_field(isset($data['sender_address_line_3']) ? $data['sender_address_line_3'] : ''));
                update_option('mpa_sender_address_4', sanitize_text_field(isset($data['sender_address_line_4']) ? $data['sender_address_line_4'] : ''));
                update_option('mpa_sender_postcode', sanitize_text_field(isset($data['sender_postcode']) ? $data['sender_postcode'] : ''));
                update_option('mpa_sender_city', sanitize_text_field(isset($data['sender_city']) ? $data['sender_city'] : ''));
                update_option('mpa_sender_state', sanitize_text_field(isset($data['sender_state']) ? $data['sender_state'] : ''));
                update_option('mpa_default_content_type', sanitize_text_field(isset($data['default_content_type']) ? $data['default_content_type'] : ''));
            }
        } else {
            delete_option('mpa_login_email');
        }

    }

    /**
     * Add metabox for WooCommerce Orders
     */
    public function add_order_metabox()
    {
        $screens = array('shop_order', 'woocommerce_page_wc-orders');
        foreach ($screens as $screen) {
            add_meta_box(
                'mpa_order_fulfillment_box',
                __('MYPARCEL ASIA', 'myparcel-asia'),
                array($this, 'render_order_metabox'),
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the metabox content
     */
    public function render_order_metabox($post_or_order)
    {
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (!$order) {
            return;
        }

        $order_id = $order->get_id();

        // States and countries maps
        $states = array(
            'JHR' => 'Johor',
            'KDH' => 'Kedah',
            'KTN' => 'Kelantan',
            'MLK' => 'Melaka',
            'NSN' => 'Negeri Sembilan',
            'PHG' => 'Pahang',
            'PNG' => 'Penang',
            'PRK' => 'Perak',
            'PLS' => 'Perlis',
            'SBH' => 'Sabah',
            'SRW' => 'Sarawak',
            'SGR' => 'Selangor',
            'TRG' => 'Terengganu',
            'KUL' => 'WP Kuala Lumpur',
            'LBN' => 'WP Labuan',
            'PJY' => 'WP Putrajaya'
        );

        $countries = array(
            'SG' => 'Singapore',
            'ID' => 'Indonesia',
            'TH' => 'Thailand',
            'PH' => 'Philippines',
            'BN' => 'Brunei',
            'KH' => 'Cambodia',
            'VN' => 'Vietnam',
            'MM' => 'Myanmar',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'CN' => 'China',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'AU' => 'Australia',
            'MY' => 'Malaysia'
        );

        // Validation Check
        $validation_errors = array();

        $shipping_first_name = $order->get_shipping_first_name();
        $shipping_last_name = $order->get_shipping_last_name();
        if (empty($shipping_first_name) && empty($shipping_last_name)) {
            $validation_errors[] = __('Customer name is required.', 'myparcel-asia');
        }

        $billing_phone = $order->get_billing_phone();
        if (empty($billing_phone)) {
            $validation_errors[] = __('Phone number is required.', 'myparcel-asia');
        }

        $shipping_country = $order->get_shipping_country();
        if (empty($shipping_country)) {
            $validation_errors[] = __('Country is required.', 'myparcel-asia');
        }

        $billing_email = $order->get_billing_email();
        if (empty($billing_email) && 'MY' !== strtoupper($shipping_country)) {
            $validation_errors[] = __('Email address is required.', 'myparcel-asia');
        }

        $shipping_address = $order->get_shipping_address_1();
        if (empty($shipping_address)) {
            $validation_errors[] = __('Address is required.', 'myparcel-asia');
        }

        $shipping_postcode = $order->get_shipping_postcode();
        if (empty($shipping_postcode)) {
            $validation_errors[] = __('Postcode is required.', 'myparcel-asia');
        }

        $shipping_city = $order->get_shipping_city();
        if (empty($shipping_city)) {
            $validation_errors[] = __('City is required.', 'myparcel-asia');
        }

        $shipping_state = $order->get_shipping_state();
        if (empty($shipping_state)) {
            $validation_errors[] = __('State is required.', 'myparcel-asia');
        }

        // Calculate total weight
        $weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $weight += floatval($product->get_weight()) * $item->get_quantity();
            }
        }
        if ($weight <= 0) {
            $validation_errors[] = __('Item total weight is required.', 'myparcel-asia');
        }

        // Load lane configurations
        $lanes_json = get_option('mpa_lanes', '');
        $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

        $resolved_lane_name = 'Not Configured';
        $lane_matched = null;

        $is_domestic = ('MY' === strtoupper($shipping_country));
        if ($is_domestic && !empty($shipping_state)) {
            foreach ($lanes as $id => $lane) {
                if (isset($lane['type']) && 'override' === $lane['type'] && 'state' === $lane['scope']) {
                    if (strtoupper($lane['details']) === strtoupper($shipping_state)) {
                        $lane_matched = $lane;
                        $resolved_lane_name = 'State Override (' . esc_html($shipping_state) . ')';
                        break;
                    }
                }
            }
        }

        if (!$lane_matched && $is_domestic) {
            $peninsular = array('JHR', 'KDH', 'KTN', 'MLK', 'NSN', 'PHG', 'PNG', 'PRK', 'PLS', 'SGR', 'TRG', 'KUL', 'PJY');
            $sabah_sarawak = array('SBH', 'SRW', 'LBN');
            $is_peninsular = in_array(strtoupper($shipping_state), $peninsular);
            $is_em = in_array(strtoupper($shipping_state), $sabah_sarawak);

            foreach ($lanes as $id => $lane) {
                if (isset($lane['type']) && 'override' === $lane['type']) {
                    if ('peninsular' === $lane['scope'] && $is_peninsular) {
                        $lane_matched = $lane;
                        $resolved_lane_name = 'Peninsular Region Override';
                        break;
                    }
                    if ('sabah_sarawak' === $lane['scope'] && $is_em) {
                        $lane_matched = $lane;
                        $resolved_lane_name = 'Sabah & Sarawak Region Override';
                        break;
                    }
                }
            }
        }

        if (!$lane_matched && !$is_domestic) {
            foreach ($lanes as $id => $lane) {
                if (isset($lane['type']) && 'override' === $lane['type'] && 'country' === $lane['scope']) {
                    if (strtoupper($lane['details']) === strtoupper($shipping_country)) {
                        $lane_matched = $lane;
                        $resolved_lane_name = 'Country Override (' . esc_html($shipping_country) . ')';
                        break;
                    }
                }
            }
        }

        if (!$lane_matched) {
            if ($is_domestic) {
                $lane_matched = isset($lanes['fallback_my']) ? $lanes['fallback_my'] : array('courier' => 'none', 'markup' => null);
                $resolved_lane_name = 'Malaysia Fallback Lane';
            } else {
                $lane_matched = isset($lanes['fallback_int']) ? $lanes['fallback_int'] : array('courier' => 'none', 'markup' => null);
                $resolved_lane_name = 'International Fallback Lane';
            }
        }

        // Selected courier override
        $selected_courier = $order->get_meta('_mpa_selected_courier', true);
        $active_courier_code = !empty($selected_courier) ? $selected_courier : (isset($lane_matched['courier']) ? $lane_matched['courier'] : 'none');

        if (!empty($selected_courier)) {
            $resolved_lane_name = 'manual change';
        }

        $domestic_couriers = array(
            'jnt' => 'J&T',
            'poslaju' => 'Poslaju',
            'dhle' => 'DHL',
            'ninjavan' => 'Ninjavan',
            'flash' => 'Flash',
            'citylink' => 'Citylink Express',
            'lex' => 'LEX Express',
            'spx' => 'SPX Express',
        );

        $int_couriers = array(
            'jnti' => 'J&T International',
            'ninjavani' => 'Ninjavan',
            'ems' => 'EMS',
            'aramex' => 'Aramex',
            'fedex' => 'Fedex',
            'airparcel' => 'AirParcel',
        );

        $courier_label = $is_domestic ? (isset($domestic_couriers[$active_courier_code]) ? $domestic_couriers[$active_courier_code] : 'Not Available')
            : (isset($int_couriers[$active_courier_code]) ? $int_couriers[$active_courier_code] : 'Not Available');

        $tracking_no = $order->get_meta('_mpa_tracking_no', true);
        if (empty($tracking_no)) {
            $tracking_no = 'N/A';
        }
        ?>
        <div class="mpa-metabox-wrapper" style="font-family:'Inter',sans-serif; color:#334155; line-height: 1.4;">
            <style>
                .mpa-metabox-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 8px;
                    font-size: 12px;
                }

                .mpa-metabox-label {
                    font-weight: 600;
                    color: #64748b;
                }

                .mpa-metabox-value {
                    text-align: right;
                    color: #0f172a;
                    font-weight: 700;
                }

                .mpa-modal-option:hover {
                    background-color: #f1f5f9 !important;
                    border-color: #cbd5e1 !important;
                    color: #0f172a !important;
                }

                @keyframes mpa-spin {
                    0% {
                        transform: rotate(0deg);
                    }

                    100% {
                        transform: rotate(360deg);
                    }
                }

                .mpa-price-spinner {
                    display: inline-block;
                    animation: mpa-spin 1s linear infinite;
                    color: #64748b;
                }
            </style>

            <?php if (!empty($validation_errors)): ?>
                <div
                    style="background-color: #fef2f2; border: 1px solid #fee2e2; border-left: 4px solid #ef4444; padding: 10px; border-radius: 6px; margin-bottom: 12px;">
                    <h5 style="margin: 0 0 4px 0; color: #991b1b; font-size: 12px; font-weight: 700;">
                        <?php esc_html_e('Missing Shipping Info:', 'myparcel-asia'); ?></h5>
                    <ul style="margin: 0; padding-left: 14px; font-size: 11px; color: #b91c1c; list-style-type: disc;">
                        <?php foreach ($validation_errors as $err): ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Courier Info -->
            <div class="mpa-metabox-row"
                style="align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 10px;">
                <span class="mpa-metabox-label"><?php esc_html_e('Courier', 'myparcel-asia'); ?></span>
                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                    <div class="mpa-courier-logo-container" style="display: flex; align-items: center; gap: 6px;">
                        <?php
                        $logo_url = $this->courier_logo($active_courier_code);
                        if (!empty($logo_url)):
                            ?>
                            <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($courier_label); ?>"
                                style="max-height: 18px; border-radius: 2px;">
                        <?php else: ?>
                            <span style="font-weight: 700; color: #94a3b8; font-size: 12px;">N/A</span>
                        <?php endif; ?>
                    </div>
                    <a href="#" id="mpa-change-courier-btn"
                        style="font-size: 11px; text-decoration: none; font-weight: 600; color: #4f46e5;"><?php esc_html_e('Change', 'myparcel-asia'); ?></a>
                </div>
            </div>

            <!-- AWB Price -->
            <div class="mpa-metabox-row">
                <span class="mpa-metabox-label"><?php esc_html_e('AWB Price', 'myparcel-asia'); ?></span>
                <span id="mpa-sidebar-price" class="mpa-metabox-value">
                    <?php
                    $actual_price = $order->get_meta('_mpa_actual_price', true);
                    if ('' !== $actual_price && 'N/A' !== $tracking_no) {
                        echo 'RM ' . esc_html(number_format(floatval($actual_price), 2));
                    } else {
                        ?>
                        <span class="mpa-price-spinner dashicons dashicons-update"></span>
                        <?php
                    }
                    ?>
                </span>
            </div>

            <!-- Lane Configured -->
            <div class="mpa-metabox-row">
                <span class="mpa-metabox-label"><?php esc_html_e('Lane Match', 'myparcel-asia'); ?></span>
                <span class="mpa-metabox-value mpa-lane-match-val"
                    style="font-size: 11px; color:#475569; font-weight: 600;"><?php echo esc_html($resolved_lane_name); ?></span>
            </div>

            <!-- Tracking No -->
            <div class="mpa-metabox-row"
                style="border-top: 1px solid #f1f5f9; padding-top: 10px; margin-top: 10px; align-items: flex-start;">
                <span class="mpa-metabox-label"><?php esc_html_e('Tracking No', 'myparcel-asia'); ?></span>
                <span class="mpa-metabox-value" style="display: flex; flex-direction: column; align-items: flex-end; gap: 2px;">
                    <?php if ('N/A' === $tracking_no): ?>
                        <a href="#" id="mpa-create-awb-link"
                            style="color: #4f46e5; text-decoration: none; font-weight: 600;"><?php esc_html_e('Create AWB', 'myparcel-asia'); ?></a>
                    <?php else: ?>
                        <span style="color: #059669; font-weight: 700;"><?php echo esc_html($tracking_no); ?></span>
                        <div style="display: flex; gap: 6px; align-items: center; margin-top: 2px;">
                            <a href="#" id="mpa-copy-tracking-btn" data-tracking="<?php echo esc_attr($tracking_no); ?>"
                                style="font-size: 10px; text-decoration: none; font-weight: 600; color: #4f46e5;"><?php esc_html_e('copy', 'myparcel-asia'); ?></a>
                            <span style="font-size: 10px; color: #cbd5e1;">|</span>
                            <a href="https://myparcelasia.com/track?tno=<?php echo esc_attr($tracking_no); ?>" target="_blank"
                                style="font-size: 10px; text-decoration: none; font-weight: 600; color: #4f46e5;"><?php esc_html_e('trace', 'myparcel-asia'); ?></a>
                        </div>
                    <?php endif; ?>
                </span>
            </div>

            <!-- Courier Options Selector Modal Overlay -->
            <div id="mpa-courier-modal"
                style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
                <div
                    style="background-color:#fff; padding:20px; border-radius:8px; width:300px; box-shadow:0 10px 25px rgba(0,0,0,0.15);">
                    <h4
                        style="margin:0 0 12px 0; font-size:14px; font-weight:700; color:#0f172a; border-bottom:1px solid #f1f5f9; padding-bottom:8px; text-align:left;">
                        <?php esc_html_e('Select Courier Override', 'myparcel-asia'); ?></h4>
                    <div
                        style="display:flex; flex-direction:column; gap:8px; max-height:260px; overflow-y:auto; padding-right:4px;">
                        <?php
                        $options = $is_domestic ? $domestic_couriers : $int_couriers;
                        foreach ($options as $code => $name):
                            $logo = $this->courier_logo($code);
                            ?>
                            <button type="button" class="mpa-modal-option" data-code="<?php echo esc_attr($code); ?>"
                                style="display:flex; align-items:center; gap:10px; width:100%; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; cursor:pointer; text-align:left; font-size:12px; font-weight:600; color:#334155;">
                                <?php if (!empty($logo)): ?>
                                    <img src="<?php echo esc_url($logo); ?>" style="max-height:14px; width:auto; border-radius:2px;">
                                <?php endif; ?>
                                <?php echo esc_html($name); ?>
                            </button>
                        <?php endforeach; ?>
                        <button type="button" class="mpa-modal-option" data-code=""
                            style="display:flex; align-items:center; justify-content:center; width:100%; padding:6px 10px; border:1px dashed #cbd5e1; border-radius:6px; background:#f8fafc; cursor:pointer; font-size:11px; font-weight:600; color:#64748b;"><?php esc_html_e('Reset to Default Lane Courier', 'myparcel-asia'); ?></button>
                    </div>
                    <div style="margin-top:12px; text-align:right;">
                        <button type="button" id="mpa-close-modal"
                            class="button button-secondary"><?php esc_html_e('Cancel', 'myparcel-asia'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Custom AWB Confirm Modal Overlay -->
            <div id="mpa-awb-confirm-modal"
                style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
                <div
                    style="background-color:#fff; padding:20px; border-radius:8px; width:300px; box-shadow:0 10px 25px rgba(0,0,0,0.15); font-family:'Inter', sans-serif;">
                    <h4
                        style="margin:0 0 12px 0; font-size:14px; font-weight:700; color:#0f172a; border-bottom:1px solid #f1f5f9; padding-bottom:8px; text-align:left;">
                        <?php esc_html_e('Create Single AWB', 'myparcel-asia'); ?></h4>
                    <p style="font-size:12px; color:#475569; margin: 0 0 15px 0; text-align: left; line-height: 1.4;">
                        <?php esc_html_e('Are you sure to create this one AWB only and not in a Batch?', 'myparcel-asia'); ?>
                    </p>
                    <div style="text-align:left; margin-bottom:15px;">
                        <label
                            style="font-size:12px; color:#475569; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" id="mpa-skip-confirm-cb" style="margin:0;">
                            <?php esc_html_e('do not ask again', 'myparcel-asia'); ?>
                        </label>
                    </div>
                    <div style="text-align:right; display:flex; justify-content:flex-end; gap:8px;">
                        <button type="button" id="mpa-close-awb-modal"
                            class="button button-secondary"><?php esc_html_e('Cancel', 'myparcel-asia'); ?></button>
                        <button type="button" id="mpa-confirm-awb-btn"
                            class="button button-primary"><?php esc_html_e('Yes, Create', 'myparcel-asia'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Custom Error Modal Overlay -->
            <div id="mpa-error-modal"
                style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
                <div
                    style="background-color:#fff; padding:20px; border-radius:8px; width:300px; box-shadow:0 10px 25px rgba(0,0,0,0.15); font-family:'Inter', sans-serif;">
                    <h4
                        style="margin:0 0 12px 0; font-size:14px; font-weight:700; color:#ef4444; border-bottom:1px solid #fee2e2; padding-bottom:8px; text-align:left;">
                        <?php esc_html_e('AWB Creation Failed', 'myparcel-asia'); ?></h4>
                    <div id="mpa-error-content"
                        style="font-size:12px; color:#475569; margin: 0 0 15px 0; text-align: left; line-height: 1.4; max-height: 150px; overflow-y: auto;">
                    </div>
                    <div style="text-align:right; display:flex; justify-content:flex-end; gap:8px;">
                        <button type="button" id="mpa-close-error-modal"
                            class="button button-secondary"><?php esc_html_e('Close', 'myparcel-asia'); ?></button>
                        <button type="button" id="mpa-retry-anyway-btn" class="button button-primary"
                            style="background:#ef4444; border-color:#ef4444; color:#fff; display:none;"><?php esc_html_e('Proceed anyway', 'myparcel-asia'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                var orderId = <?php echo intval($order_id); ?>;

                function fetchSidebarPrice(courierOverride) {
                    $('#mpa-sidebar-price').html('<span class="mpa-price-spinner dashicons dashicons-update"></span>');

                    var postData = {
                        action: 'mpa_get_order_shipping_price',
                        order_id: orderId,
                        security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                    };

                    if (courierOverride) {
                        postData.courier_override = courierOverride;
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: postData,
                        success: function (response) {
                            if (response.success) {
                                if (response.data.status) {
                                    $('#mpa-sidebar-price').text(response.data.formatted_price);
                                } else {
                                    $('#mpa-sidebar-price').html('<span style="color:#ef4444;">' + response.data.message + '</span>');
                                }
                            } else {
                                $('#mpa-sidebar-price').html('<span style="color:#ef4444;">Error</span>');
                            }
                        },
                        error: function () {
                            $('#mpa-sidebar-price').html('<span style="color:#ef4444;">Error</span>');
                        }
                    });
                }

                // Initial fetch
                var initialCourier = '<?php echo esc_js($selected_courier); ?>';
                if ($('#mpa-sidebar-price').find('.mpa-price-spinner').length > 0) {
                    fetchSidebarPrice(initialCourier);
                }

                // Modal triggers
                $('#mpa-change-courier-btn').on('click', function (e) {
                    e.preventDefault();
                    $('#mpa-courier-modal').css('display', 'flex');
                });

                $('#mpa-close-modal').on('click', function () {
                    $('#mpa-courier-modal').css('display', 'none');
                });

                // Click on option
                $('.mpa-modal-option').on('click', function () {
                    var code = $(this).attr('data-code');
                    $('#mpa-courier-modal').css('display', 'none');

                    // Save override
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mpa_save_order_courier',
                            order_id: orderId,
                            courier: code,
                            security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                // Update matched lane label inline
                                if (code) {
                                    $('.mpa-lane-match-val').text('manual change');
                                } else {
                                    location.reload();
                                    return;
                                }

                                // Update Courier Logo inline
                                var selectedImgSrc = $('.mpa-modal-option[data-code="' + code + '"] img').attr('src');
                                if (selectedImgSrc) {
                                    $('.mpa-courier-logo-container').html('<img src="' + selectedImgSrc + '" style="max-height: 18px; border-radius: 2px;">');
                                } else {
                                    $('.mpa-courier-logo-container').html('<span style="font-weight: 700; color: #94a3b8; font-size: 12px;">N/A</span>');
                                }

                                // Recalculate shipping rate
                                fetchSidebarPrice(code);
                            } else {
                                alert('Failed to save courier override.');
                            }
                        },
                        error: function () {
                            alert('Connection error.');
                        }
                    });
                });

                var currentSuffix = 0;

                // Create AWB link click
                $('#mpa-create-awb-link').on('click', function (e) {
                    e.preventDefault();
                    if (localStorage.getItem('mpa_skip_awb_confirm') === 'true') {
                        triggerCreateAWB(currentSuffix);
                    } else {
                        $('#mpa-awb-confirm-modal').css('display', 'flex');
                    }
                });

                $('#mpa-close-awb-modal').on('click', function () {
                    $('#mpa-awb-confirm-modal').css('display', 'none');
                });

                // Copy Tracking Number to Clipboard
                $('#mpa-copy-tracking-btn').on('click', function (e) {
                    e.preventDefault();
                    var trackingVal = $(this).attr('data-tracking');
                    if (trackingVal) {
                        navigator.clipboard.writeText(trackingVal).then(function () {
                            var btn = $('#mpa-copy-tracking-btn');
                            btn.text('copied!');
                            setTimeout(function () {
                                btn.text('copy');
                            }, 1500);
                        }).catch(function (err) {
                            alert('Failed to copy tracking number: ' + err);
                        });
                    }
                });

                $('#mpa-close-error-modal').on('click', function () {
                    $('#mpa-error-modal').css('display', 'none');
                });

                $('#mpa-confirm-awb-btn').on('click', function () {
                    if ($('#mpa-skip-confirm-cb').is(':checked')) {
                        localStorage.setItem('mpa_skip_awb_confirm', 'true');
                    }
                    $('#mpa-awb-confirm-modal').css('display', 'none');
                    triggerCreateAWB(currentSuffix);
                });

                // Proceed anyway retry handler
                $('#mpa-retry-anyway-btn').on('click', function () {
                    currentSuffix++;
                    $('#mpa-error-modal').css('display', 'none');
                    triggerCreateAWB(currentSuffix);
                });

                function triggerCreateAWB(suffixVal) {
                    $('#mpa-create-awb-link').html('<span class="mpa-price-spinner dashicons dashicons-update"></span>');
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mpa_create_single_awb',
                            order_id: orderId,
                            suffix: suffixVal,
                            security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                $('#mpa-create-awb-link').text('Create AWB');

                                var html = '';
                                var hasDuplicateError = false;

                                var errorsList = [];
                                if (response.data && response.data.api_messages && response.data.api_messages.length > 0) {
                                    errorsList = response.data.api_messages;
                                } else if (response.data && Array.isArray(response.data.message)) {
                                    errorsList = response.data.message;
                                } else if (response.data && response.data.data && Array.isArray(response.data.data.message)) {
                                    errorsList = response.data.data.message;
                                }

                                if (errorsList.length > 0) {
                                    $.each(errorsList, function (i, item) {
                                        var msgText = (typeof item === 'object' && item.message) ? item.message : String(item);
                                        var orderIdRef = (typeof item === 'object' && item.integration_order_id) ? item.integration_order_id : '';

                                        html += '<div style="margin-bottom:8px; border-left: 3px solid #ef4444; padding-left: 8px;">';
                                        if (orderIdRef) {
                                            html += '<strong>[' + orderIdRef + ']:</strong> ';
                                        }
                                        html += msgText + '</div>';

                                        var msgLower = msgText.toLowerCase();
                                        if (msgLower.indexOf('already exist') !== -1 || msgLower.indexOf('duplicate') !== -1) {
                                            hasDuplicateError = true;
                                        }
                                    });
                                } else {
                                    var mainMsg = '';
                                    if (response.data) {
                                        if (typeof response.data.message === 'string') {
                                            mainMsg = response.data.message;
                                        } else if (response.data.message && typeof response.data.message.message === 'string') {
                                            mainMsg = response.data.message.message;
                                        } else {
                                            mainMsg = 'Failed to create AWB.';
                                        }
                                    } else {
                                        mainMsg = 'Failed to create AWB.';
                                    }
                                    html = '<div style="border-left: 3px solid #ef4444; padding-left: 8px;">' + mainMsg + '</div>';
                                    var mainMsgLower = mainMsg.toLowerCase();
                                    if (mainMsgLower.indexOf('already exist') !== -1 || mainMsgLower.indexOf('duplicate') !== -1) {
                                        hasDuplicateError = true;
                                    }
                                }

                                if (response.data && response.data.api_response) {
                                    html += '<div style="border-left: 3px solid #eab308; padding-left: 8px; margin-top:8px; font-weight:600; color:#854d0e;">API Response:</div>';
                                    html += '<pre style="font-size:10px; background:#f8fafc; padding:8px; border-radius:4px; max-height:120px; overflow:auto; margin-top:4px; font-family:monospace; color:#334155;">' + JSON.stringify(response.data.api_response, null, 2) + '</pre>';
                                }
                                $('#mpa-error-content').html(html);
                                if (hasDuplicateError) {
                                    $('#mpa-retry-anyway-btn').show();
                                } else {
                                    $('#mpa-retry-anyway-btn').hide();
                                }
                                $('#mpa-error-modal').css('display', 'flex');
                            }
                        },
                        error: function () {
                            $('#mpa-create-awb-link').text('Create AWB');
                            $('#mpa-error-content').html('<div style="border-left: 3px solid #ef4444; padding-left: 8px;">Connection error or server failure.</div>');
                            $('#mpa-retry-anyway-btn').hide();
                            $('#mpa-error-modal').css('display', 'flex');
                        }
                    });
                }
            });
        </script>
        <?php
    }

    /**
     * Render the Manage Batch dashboard page
     */
    public function render_manage_batch_dashboard()
    {
        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field($_GET['batch_id']) : '';

        // Handle Remove Order from Batch
        if (!empty($batch_id) && isset($_GET['remove_order'])) {
            $remove_order_id = intval($_GET['remove_order']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mpa_remove_order_' . $remove_order_id)) {
                $batches = get_option('mpa_batches', array());
                if (isset($batches[$batch_id])) {
                    $batch_orders = $batches[$batch_id]['orders'];
                    $key = array_search($remove_order_id, $batch_orders);
                    if ($key !== false) {
                        unset($batch_orders[$key]);
                        $batch_orders = array_values($batch_orders);
                        
                        $order = wc_get_order($remove_order_id);
                        if ($order) {
                            $order->delete_meta_data('_mpa_batch_id');
                            $order->save();
                        }
                        
                        $batches[$batch_id]['orders'] = $batch_orders;
                        $batches[$batch_id]['total_order'] = count($batch_orders);
                        
                        $total_price = 0;
                        foreach ($batch_orders as $o_id) {
                            $o = wc_get_order($o_id);
                            if ($o) {
                                $res = $this->get_order_shipping_price_helper($o);
                                if ($res['success']) {
                                    $total_price += floatval($res['price']);
                                }
                            }
                        }
                        $batches[$batch_id]['total_awb_price'] = $total_price;
                        
                        if (empty($batch_orders)) {
                            unset($batches[$batch_id]);
                            update_option('mpa_batches', $batches);
                            wp_safe_redirect(admin_url('admin.php?page=myparcel-asia-manage-batch'));
                            exit;
                        } else {
                            update_option('mpa_batches', $batches);
                            wp_safe_redirect(admin_url('admin.php?page=myparcel-asia-manage-batch&batch_id=' . $batch_id));
                            exit;
                        }
                    }
                }
            }
        }

        // Handle Delete Batch is now processed via AJAX

        $batches = get_option('mpa_batches', array());
        if (!is_array($batches)) {
            $batches = array();
        }

        $states = array(
            'JHR' => 'Johor',
            'KDH' => 'Kedah',
            'KTN' => 'Kelantan',
            'MLK' => 'Melaka',
            'NSN' => 'Negeri Sembilan',
            'PHG' => 'Pahang',
            'PNG' => 'Pulau Pinang',
            'PRK' => 'Perak',
            'PLS' => 'Perlis',
            'SBH' => 'Sabah',
            'SRW' => 'Sarawak',
            'SGR' => 'Selangor',
            'TRG' => 'Terengganu',
            'KUL' => 'WP Kuala Lumpur',
            'LBN' => 'WP Labuan',
            'PJY' => 'WP Putrajaya',
        );

        $countries = array(
            'SG' => 'Singapore',
            'ID' => 'Indonesia',
            'TH' => 'Thailand',
            'PH' => 'Philippines',
            'BN' => 'Brunei',
            'KH' => 'Cambodia',
            'VN' => 'Vietnam',
            'MM' => 'Myanmar',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'CN' => 'China',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'AU' => 'Australia',
            'MY' => 'Malaysia',
        );

        $domestic_couriers = array(
            'jnt' => 'J&T',
            'poslaju' => 'Poslaju',
            'dhle' => 'DHL',
            'ninjavan' => 'Ninjavan',
            'flash' => 'Flash',
            'citylink' => 'Citylink Express',
            'lex' => 'LEX Express',
            'spx' => 'SPX Express',
        );

        $int_couriers = array(
            'jnti' => 'J&T International',
            'ninjavani' => 'Ninjavan',
            'ems' => 'EMS',
            'aramex' => 'Aramex',
            'fedex' => 'Fedex',
            'airparcel' => 'AirParcel',
        );

        if (!empty($batch_id) && isset($batches[$batch_id])) {
            // Detail View
            $batch = $batches[$batch_id];
            ?>
            <div class="wrap mpa-batch-wrap" style="font-family: 'Inter', sans-serif; margin: 20px 20px 0 0; color: #1e293b;">
                <style>
                    .mpa-batch-header {
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-left: 4px solid #4f46e5;
                        padding: 16px 24px;
                        margin-bottom: 20px;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                    }

                    .mpa-batch-meta-grid {
                        display: flex;
                        gap: 30px;
                    }

                    .mpa-meta-stat {
                        display: flex;
                        flex-direction: column;
                    }

                    .mpa-meta-label {
                        font-size: 11px;
                        font-weight: 700;
                        color: #64748b;
                        text-transform: uppercase;
                    }

                    .mpa-meta-val {
                        font-size: 18px;
                        font-weight: 800;
                        color: #0f172a;
                        margin-top: 4px;
                    }

                    .mpa-batch-table {
                        width: 100%;
                        border-collapse: collapse;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        font-size: 13px;
                    }

                    .mpa-batch-table th,
                    .mpa-batch-table td {
                        border: 1px solid #e2e8f0;
                        padding: 10px 12px;
                        text-align: left;
                        vertical-align: middle;
                    }

                    .mpa-batch-table th {
                        background-color: #f8fafc;
                        font-weight: 600;
                    }

                    .mpa-batch-table tbody tr:nth-child(even) {
                        background-color: #f8fafc;
                    }

                    .mpa-batch-table tbody tr:hover {
                        background-color: #f1f5f9;
                    }

                    /* Confirmation Modal Styles */
                    .mpa-modal-overlay {
                        display: none;
                        position: fixed;
                        z-index: 999999;
                        left: 0;
                        top: 0;
                        width: 100%;
                        height: 100%;
                        background-color: rgba(0, 0, 0, 0.5);
                        align-items: center;
                        justify-content: center;
                    }

                    .mpa-modal-content {
                        background-color: #fff;
                        padding: 24px;
                        border-radius: 8px;
                        width: 320px;
                        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
                        text-align: center;
                    }

                    .mpa-modal-title {
                        margin: 0 0 12px 0;
                        font-size: 16px;
                        font-weight: 700;
                        color: #0f172a;
                    }

                    .mpa-modal-desc {
                        font-size: 12px;
                        color: #475569;
                        margin-bottom: 20px;
                        line-height: 1.5;
                    }

                    .mpa-modal-actions {
                        display: flex;
                        justify-content: flex-end;
                        gap: 8px;
                    }

                    @keyframes mpa-spin {
                        0% {
                            transform: rotate(0deg);
                        }

                        100% {
                            transform: rotate(360deg);
                        }
                    }

                    .mpa-loading-spinner {
                        display: inline-block;
                        animation: mpa-spin 1s linear infinite;
                    }
                </style>

                <h1 class="wp-heading-inline">
                    <?php echo sprintf(__('Batch: %s', 'myparcel-asia'), esc_html($batch['label'])); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=myparcel-asia-manage-batch')); ?>"
                    class="page-title-action"><?php esc_html_e('Back to List', 'myparcel-asia'); ?></a>
                <hr class="wp-header-end">

                <div class="mpa-batch-header">
                    <div class="mpa-batch-meta-grid">
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e('Status', 'myparcel-asia'); ?></span>
                            <span class="mpa-meta-val"
                                style="color: <?php echo 'completed' === $batch['status'] ? '#059669' : '#d97706'; ?>;">
                                <?php echo esc_html(ucfirst($batch['status'])); ?>
                            </span>
                        </div>
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e('Created By', 'myparcel-asia'); ?></span>
                            <span class="mpa-meta-val"><?php echo esc_html($batch['created_by']); ?></span>
                        </div>
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e('Total Orders', 'myparcel-asia'); ?></span>
                            <span class="mpa-meta-val"><?php echo esc_html($batch['total_order']); ?></span>
                        </div>
                        <div class="mpa-meta-stat">
                            <span class="mpa-meta-label"><?php esc_html_e('Total Price', 'myparcel-asia'); ?></span>
                            <span class="mpa-meta-val">RM
                                <?php echo esc_html(number_format($batch['total_awb_price'], 2)); ?></span>
                        </div>
                    </div>

                    <div>
                        <?php if ('completed' === $batch['status']): ?>
                            <?php if (!empty($batch['thermal_awb_url'])): ?>
                                <a href="<?php echo esc_url($batch['thermal_awb_url']); ?>" target="_blank" class="button button-primary"
                                    style="background:#059669; border-color:#059669;">
                                    <?php esc_html_e('Download AWB', 'myparcel-asia'); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php $api_key = get_option('mpa_api_key', ''); ?>
                            <?php if (empty($api_key)): ?>
                                <button type="button" class="button button-primary" disabled title="<?php esc_attr_e('Please configure a valid API Key in Settings.', 'myparcel-asia'); ?>">
                                    <?php esc_html_e('Invalid API Key', 'myparcel-asia'); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="button button-primary" id="mpa-btn-create-batch-awb">
                                    <?php esc_html_e('Create AWB', 'myparcel-asia'); ?>
                                </button>
                            <?php endif; ?>
                             <button type="button" class="button" id="mpa-btn-delete-batch" style="background:#ef4444; border-color:#ef4444; color:#ffffff;">
                                 <?php esc_html_e('Delete Batch', 'myparcel-asia'); ?>
                             </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Orders Table -->
                <table class="mpa-batch-table">
                    <thead>
                        <tr>
                            <th width="160"><?php esc_html_e('Order', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Shipping Details', 'myparcel-asia'); ?></th>
                            <th width="220"><?php esc_html_e('Item Details', 'myparcel-asia'); ?></th>
                            <th width="140"><?php esc_html_e('Courier', 'myparcel-asia'); ?></th>
                            <th width="110" style="text-align:right;"><?php esc_html_e('AWB Price', 'myparcel-asia'); ?></th>
                            <th width="150" style="text-align:center;"><?php esc_html_e('Action', 'myparcel-asia'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batch['orders'] as $order_id):
                            $order = wc_get_order($order_id);
                            if (!$order)
                                continue;

                            $country_code = $order->get_shipping_country();
                            $state_code = $order->get_shipping_state();

                            // Retrieve matched courier logic
                            $res = $this->get_order_shipping_price_helper($order);
                            $courier_key = $res['success'] ? $res['courier'] : 'none';
                            $is_domestic = ('MY' === strtoupper($country_code));
                            $courier_name = $is_domestic ? (isset($domestic_couriers[$courier_key]) ? $domestic_couriers[$courier_key] : 'Not Available')
                                : (isset($int_couriers[$courier_key]) ? $int_couriers[$courier_key] : 'Not Available');

                            $weight = 0;
                            $total_qty = 0;
                            foreach ($order->get_items() as $item) {
                                $product = $item->get_product();
                                if ($product) {
                                    $weight += floatval($product->get_weight()) * $item->get_quantity();
                                }
                                $total_qty += $item->get_quantity();
                            }
                            if ($weight <= 0) {
                                $weight = 0.5;
                            }

                            $tracking_no = $order->get_meta('_mpa_tracking_no', true);
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>"
                                        target="_blank" style="text-decoration:none; font-weight:700;">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                        <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="mpa-shipping-box" style="font-size:12px; color:#475569;">
                                        <?php echo esc_html($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()); ?><br>
                                        <?php echo esc_html(trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2())); ?>,
                                        <?php echo esc_html($order->get_shipping_postcode()); ?>
                                        <?php echo esc_html($order->get_shipping_city()); ?>,
                                        <?php echo esc_html(isset($states[$state_code]) ? $states[$state_code] : $state_code); ?>,
                                        <?php echo esc_html(isset($countries[$country_code]) ? $countries[$country_code] : $country_code); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 12px; color: #475569;">
                                        Weight: <strong><?php echo esc_html(number_format($weight, 2)); ?> kg</strong><br>
                                        Quantity: <?php echo esc_html($total_qty); ?> item
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $logo_url = $this->courier_logo($courier_key);
                                    if (!empty($logo_url)):
                                        ?>
                                        <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($courier_name); ?>"
                                            style="max-height: 20px; display: block; border-radius: 2px; margin-bottom: 4px;">
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #94a3b8; font-weight: 600;">N/A</span>
                                    <?php endif; ?>
                                    <div style="font-size: 10px; font-family: monospace; color: #64748b; margin-top: 4px; font-weight: 600;">
                                        <?php echo esc_html(ucfirst(get_option('mpa_default_send_method', 'dropoff'))); ?>
                                    </div>
                                    <?php if (!empty($tracking_no) && 'N/A' !== $tracking_no): ?>
                                        <div
                                            style="font-size: 10px; font-family: monospace; color: #64748b; margin-top: 4px; font-weight: 600;">
                                            <?php echo esc_html($tracking_no); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; font-weight:700;">
                                    <?php
                                    $actual_price = $order->get_meta('_mpa_actual_price', true);
                                    if ('' !== $actual_price) {
                                        echo 'RM ' . esc_html(number_format(floatval($actual_price), 2));
                                    } elseif ($res['success']) {
                                        echo 'RM ' . esc_html(number_format($res['price'], 2));
                                    } else {
                                        echo esc_html($res['message']);
                                    }
                                    ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ('completed' !== $batch['status']): ?>
                                        <button type="button" class="button button-secondary button-small mpa-remove-order-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>" style="color: #ef4444; border-color: #fca5a5;">
                                            <?php esc_html_e('Remove from Batch', 'myparcel-asia'); ?>
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Confirmation Modal -->
                <div id="mpa-batch-awb-modal" class="mpa-modal-overlay">
                    <div class="mpa-modal-content">
                        <h4 class="mpa-modal-title"><?php esc_html_e('Create Batch AWB', 'myparcel-asia'); ?></h4>
                        <p class="mpa-modal-desc">
                            <?php echo sprintf(__('Are you sure you want to create AWB for this batch (%s orders)? This will charge your topup balance.', 'myparcel-asia'), count($batch['orders'])); ?>
                        </p>
                        <div class="mpa-modal-actions">
                            <button type="button" class="button"
                                id="mpa-btn-cancel-batch-awb"><?php esc_html_e('Cancel', 'myparcel-asia'); ?></button>
                            <button type="button" class="button button-primary"
                                id="mpa-btn-confirm-batch-awb"><?php esc_html_e('Yes, Create', 'myparcel-asia'); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Error Modal (Duplicate bypass retry) -->
                <div id="mpa-error-modal"
                    style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
                    <div
                        style="background-color:#fff; padding:20px; border-radius:8px; width:340px; box-shadow:0 10px 25px rgba(0,0,0,0.15); text-align:left;">
                        <h4
                            style="margin:0 0 12px 0; font-size:14px; font-weight:700; color:#ef4444; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">
                            <?php esc_html_e('AWB Creation Failed', 'myparcel-asia'); ?></h4>
                        <div id="mpa-error-content" style="font-size:12px; color:#475569; margin-bottom:20px; line-height:1.5;">
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="button"
                                id="mpa-close-error-modal"><?php esc_html_e('Close', 'myparcel-asia'); ?></button>
                            <button type="button" class="button button-primary" id="mpa-retry-anyway-btn"
                                style="display:none; background:#ef4444; border-color:#ef4444; color:#fff;"><?php esc_html_e('Proceed anyway', 'myparcel-asia'); ?></button>
                        </div>
                    </div>
                </div>

                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        var currentSuffix = 0;

                        $('#mpa-btn-create-batch-awb').on('click', function () {
                            $('#mpa-batch-awb-modal').css('display', 'flex');
                        });

                        $('#mpa-btn-delete-batch').on('click', function () {
                            if (!confirm('Are you sure you want to delete this batch? All orders in this batch will be returned to To Process.')) {
                                return;
                            }
                            var $btn = $(this);
                            $btn.prop('disabled', true).html('<span class="mpa-loading-spinner dashicons dashicons-update" style="color:#ffffff;"></span> Deleting...');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'mpa_delete_batch',
                                    batch_id: '<?php echo esc_js($batch_id); ?>',
                                    security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                                },
                                success: function (response) {
                                    if (response.success) {
                                        window.location.href = response.data.redirect_url;
                                    } else {
                                        alert(response.data.message || 'Failed to delete batch.');
                                        $btn.prop('disabled', false).text('Delete Batch');
                                    }
                                },
                                error: function () {
                                    alert('Request failed. Please try again.');
                                    $btn.prop('disabled', false).text('Delete Batch');
                                }
                            });
                        });

                        $('.mpa-remove-order-btn').on('click', function () {
                            var $btn = $(this);
                            var orderId = $btn.data('order-id');
                            var $row = $btn.closest('tr');
                            
                            $btn.prop('disabled', true).html('<span class="mpa-loading-spinner dashicons dashicons-update" style="color:#ef4444;"></span>');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'mpa_remove_order_from_batch',
                                    batch_id: '<?php echo esc_js($batch_id); ?>',
                                    order_id: orderId,
                                    security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                                },
                                success: function (response) {
                                    if (response.success) {
                                        $row.fadeOut(400, function () {
                                            $row.remove();
                                            if (response.data.batch_deleted) {
                                                window.location.href = response.data.redirect_url;
                                            } else {
                                                $('.mpa-batch-header .mpa-meta-stat:nth-child(3) .mpa-meta-val').text(response.data.total_order);
                                                $('.mpa-batch-header .mpa-meta-stat:nth-child(4) .mpa-meta-val').text('RM ' + response.data.total_price);
                                            }
                                        });
                                    } else {
                                        alert(response.data.message || 'Failed to remove order.');
                                        $btn.prop('disabled', false).text('Remove from Batch');
                                    }
                                },
                                error: function () {
                                    alert('Request failed. Please try again.');
                                    $btn.prop('disabled', false).text('Remove from Batch');
                                }
                            });
                        });

                        $('#mpa-btn-cancel-batch-awb').on('click', function () {
                            $('#mpa-batch-awb-modal').css('display', 'none');
                        });

                        $('#mpa-close-error-modal').on('click', function () {
                            $('#mpa-error-modal').css('display', 'none');
                        });

                        $('#mpa-btn-confirm-batch-awb').on('click', function () {
                            $('#mpa-batch-awb-modal').css('display', 'none');
                            triggerBulkCreateAWB(0);
                        });

                        $('#mpa-retry-anyway-btn').on('click', function () {
                            $('#mpa-error-modal').css('display', 'none');
                            currentSuffix++;
                            triggerBulkCreateAWB(currentSuffix);
                        });

                        function triggerBulkCreateAWB(suffix) {
                            var btn = $('#mpa-btn-create-batch-awb');
                            btn.prop('disabled', true).html('<span class="mpa-loading-spinner dashicons dashicons-update"></span> Creating...');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'mpa_execute_batch_awb',
                                    batch_id: '<?php echo esc_js($batch_id); ?>',
                                    suffix: suffix,
                                    security: '<?php echo esc_js(wp_create_nonce("mpa_batch_nonce")); ?>'
                                },
                                success: function (response) {
                                    if (response.success) {
                                        window.location.reload();
                                    } else {
                                        btn.prop('disabled', false).text('Create AWB');

                                        var html = '';
                                        var hasDuplicateError = false;

                                        if (response.data && response.data.api_messages) {
                                            var errorsList = response.data.api_messages;
                                            $.each(errorsList, function (i, item) {
                                                var msgText = (typeof item === 'object' && item.message) ? item.message : String(item);
                                                var orderIdRef = (typeof item === 'object' && item.integration_order_id) ? item.integration_order_id : '';

                                                html += '<div style="margin-bottom:8px; border-left: 3px solid #ef4444; padding-left: 8px;">';
                                                if (orderIdRef) {
                                                    html += '<strong>[' + orderIdRef + ']:</strong> ';
                                                }
                                                html += msgText + '</div>';

                                                var msgLower = msgText.toLowerCase();
                                                if (msgLower.indexOf('already exist') !== -1 || msgLower.indexOf('duplicate') !== -1) {
                                                    hasDuplicateError = true;
                                                }
                                            });
                                        } else {
                                            var mainMsg = response.data && response.data.message ? response.data.message : 'Failed to create AWB.';
                                            html = '<div style="border-left: 3px solid #ef4444; padding-left: 8px;">' + mainMsg + '</div>';
                                            var mainMsgLower = mainMsg.toLowerCase();
                                            if (mainMsgLower.indexOf('already exist') !== -1 || mainMsgLower.indexOf('duplicate') !== -1) {
                                                hasDuplicateError = true;
                                            }
                                        }

                                        if (response.data && response.data.api_response) {
                                            html += '<div style="border-left: 3px solid #eab308; padding-left: 8px; margin-top:8px; font-weight:600; color:#854d0e;">API Response:</div>';
                                            html += '<pre style="font-size:10px; background:#f8fafc; padding:8px; border-radius:4px; max-height:120px; overflow:auto; margin-top:4px; font-family:monospace; color:#334155;">' + JSON.stringify(response.data.api_response, null, 2) + '</pre>';
                                        }

                                        $('#mpa-error-content').html(html);
                                        if (hasDuplicateError) {
                                            $('#mpa-retry-anyway-btn').show();
                                        } else {
                                            $('#mpa-retry-anyway-btn').hide();
                                        }
                                        $('#mpa-error-modal').css('display', 'flex');
                                    }
                                },
                                error: function () {
                                    btn.prop('disabled', false).text('Create AWB');
                                    $('#mpa-error-content').html('<div style="border-left: 3px solid #ef4444; padding-left: 8px;">Connection error or server failure.</div>');
                                    $('#mpa-retry-anyway-btn').hide();
                                    $('#mpa-error-modal').css('display', 'flex');
                                }
                            });
                        }
                    });
                </script>
            </div>
            <?php
        } else {
            // List View
            ?>
            <div class="wrap mpa-batch-wrap" style="font-family: 'Inter', sans-serif; margin: 20px 20px 0 0; color: #1e293b;">
                <style>
                    .mpa-batch-table {
                        width: 100%;
                        border-collapse: collapse;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        font-size: 13px;
                        margin-top: 15px;
                    }

                    .mpa-batch-table th,
                    .mpa-batch-table td {
                        border: 1px solid #e2e8f0;
                        padding: 12px 14px;
                        text-align: left;
                        vertical-align: middle;
                    }

                    .mpa-batch-table th {
                        background-color: #f8fafc;
                        font-weight: 600;
                    }

                    .mpa-batch-table tbody tr:nth-child(even) {
                        background-color: #f8fafc;
                    }

                    .mpa-batch-table tbody tr:hover {
                        background-color: #f1f5f9;
                    }
                </style>

                <h1 class="wp-heading-inline"><?php esc_html_e('Manage Batches', 'myparcel-asia'); ?></h1>
                <hr class="wp-header-end">

                <table class="mpa-batch-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Batch Label', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Date Created', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Created By', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Orders Count', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Total AWB Cost', 'myparcel-asia'); ?></th>
                            <th><?php esc_html_e('Status', 'myparcel-asia'); ?></th>
                            <th width="100"><?php esc_html_e('Actions', 'myparcel-asia'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #94a3b8; padding: 30px;">
                                    <?php esc_html_e('No batch records found.', 'myparcel-asia'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_reverse($batches) as $b): ?>
                                <tr>
                                    <td style="font-weight:700; color:#4f46e5;">
                                        <?php echo esc_html($b['label']); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($b['created_at']); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($b['created_by']); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($b['total_order']); ?>
                                    </td>
                                    <td style="font-weight:700;">
                                        RM <?php echo esc_html(number_format($b['total_awb_price'], 2)); ?>
                                    </td>
                                    <td>
                                        <span
                                            style="font-weight: 700; color: <?php echo 'completed' === $b['status'] ? '#059669' : '#d97706'; ?>;">
                                            <?php echo esc_html(ucfirst($b['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=myparcel-asia-manage-batch&batch_id=' . $b['id'])); ?>"
                                            class="button button-secondary">
                                            <?php esc_html_e('View', 'myparcel-asia'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }
    // WooCommerce Shipping integration methods removed

    /**
     * Save customer chosen courier to order metadata
     */
    public function save_courier_on_item_creation($item, $package_key, $package, $order)
    {
        $method_title = strtolower($item->get_name());
        $all_couriers = array(
            'poslaju' => 'pos',
            'jnt' => 'j&t',
            'ninjavan' => 'ninja',
            'dhl' => 'dhl',
            'citylink' => 'citylink',
            'abx' => 'abx',
            'skynet' => 'skynet',
            'flash' => 'flash',
            'teleport' => 'teleport',
            'thelorry' => 'thelorry',
            'fedex' => 'fedex',
            'airparcel' => 'airparcel',
        );
        foreach ($all_couriers as $key => $name) {
            if (strpos($method_title, $name) !== false || strpos($method_title, $key) !== false) {
                $order->update_meta_data('_mpa_selected_courier', $key);
                return;
            }
        }
    }

    /**
     * Intercept WooCommerce package rates calculation and inject dynamic rates
     */
    public function inject_myparcel_asia_rates($rates, $package)
    {
        if (!class_exists('MyParcel_Asia_Shipping_Method')) {
            return $rates;
        }

        $checkout_price_option = get_option('mpa_checkout_shipping_price', 'free');
        if ('woo' === $checkout_price_option) {
            return $rates;
        }

        $shipping_method = new MyParcel_Asia_Shipping_Method();
        $shipping_method->calculate_shipping($package);

        if (!empty($shipping_method->rates)) {
            $new_rates = array();
            foreach ($shipping_method->rates as $rate) {
                $new_rates[$rate->get_id()] = $rate;
            }
            return $new_rates;
        }

        $default_price_option = get_option('mpa_default_shipping_price', 'free');
        if ('no-service' === $default_price_option) {
            return array(); // Block checkout
        }

        return $rates;
    }

    /**
     * Clear WooCommerce session shipping packages cache to force live recalculations
     */
    public function clear_wc_shipping_cache()
    {
        if (function_exists('WC')) {
            if (isset(WC()->session)) {
                WC()->session->set('shipping_for_package_0', '');
                
                // Clear all dynamic shipping package caches in the session
                $session_data = WC()->session->get_session_data();
                if (is_array($session_data)) {
                    foreach ($session_data as $key => $value) {
                        if (0 === strpos($key, 'shipping_for_package_')) {
                            WC()->session->set($key, '');
                        }
                    }
                }
            }
            if (isset(WC()->shipping) && method_exists(WC()->shipping(), 'reset_shipping')) {
                WC()->shipping()->reset_shipping();
            }
        }
    }
    public function force_shipping_recalculation_on_page_load()
    {
        if (function_exists('is_cart') && function_exists('is_checkout') && (is_cart() || is_checkout())) {
            $this->clear_wc_shipping_cache();
        }
    }
    public function validate_checkout_shipping_rate($data, $errors)
    {
        if (function_exists('WC') && WC()->cart && WC()->cart->needs_shipping()) {
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            if (empty($chosen_methods) || empty($chosen_methods[0])) {
                $errors->add('shipping', __('No shipping options are available for this address. Please verify the address is correct or try a different address.', 'myparcel-asia'));
                return;
            }

            // Verify if any calculated packages are empty
            $packages = WC()->shipping()->get_packages();
            foreach ($packages as $package) {
                if (empty($package['rates'])) {
                    $errors->add('shipping', __('No shipping options are available for this address. Please verify the address is correct or try a different address.', 'myparcel-asia'));
                    break;
                }
            }
        }
    }
    /**
     * Clean up and remove MYPARCEL ASIA shipping method from all WooCommerce shipping zones in database
     */
    public function clean_myparcel_asia_shipping_from_zones()
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = 'myparcel_asia_shipping'");
    }
}

function mpa_register_shipping_method_class()
{
    if (class_exists('WC_Shipping_Method') && !class_exists('MyParcel_Asia_Shipping_Method')) {
        class MyParcel_Asia_Shipping_Method extends WC_Shipping_Method
        {
            public function __construct()
            {
                $this->id = 'myparcel_asia_shipping';
                $this->method_title = __('MYPARCEL ASIA', 'myparcel-asia');
                $this->method_description = __('Calculate shipping rates dynamically via MYPARCEL ASIA API', 'myparcel-asia');
                $this->enabled = 'yes';
                $this->title = __('Standard Delivery', 'myparcel-asia');

                $this->init();
            }

            public function init()
            {
                $this->init_form_fields();
                $this->init_settings();
            }

            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'myparcel-asia'),
                        'type' => 'checkbox',
                        'label' => __('Enable MYPARCEL ASIA Shipping', 'myparcel-asia'),
                        'default' => 'yes',
                    ),
                );
            }

            public function calculate_shipping($package = array())
            {
                $checkout_price_option = get_option('mpa_checkout_shipping_price', 'free');
                $default_price_option = get_option('mpa_default_shipping_price', 'free');
                $default_fixed_price = floatval(get_option('mpa_default_fixed_price', '10.00'));

                $weight = 0;
                foreach ($package['contents'] as $item_id => $values) {
                    $product = $values['data'];
                    $qty = $values['quantity'];
                    $weight += floatval($product->get_weight()) * $qty;
                }
                if ($weight <= 0) {
                    $weight = 0.5;
                }

                if ('free' === $checkout_price_option) {
                    $this->add_rate(array(
                        'id' => $this->id,
                        'label' => __('Standard Delivery', 'myparcel-asia'),
                        'cost' => 0,
                    ));
                    return;
                }

                if ('flat' === $checkout_price_option) {
                    $flat_price = floatval(get_option('mpa_checkout_flat_price', '10.00'));
                    $this->add_rate(array(
                        'id' => $this->id,
                        'label' => __('Standard Delivery', 'myparcel-asia'),
                        'cost' => $flat_price,
                    ));
                    return;
                }

                $is_domestic = ('MY' === strtoupper($package['destination']['country']));
                $state_code = $package['destination']['state'];

                if ('lane' === $checkout_price_option) {
                    $lanes_json = get_option('mpa_lanes', '');
                    $lanes = !empty($lanes_json) ? json_decode($lanes_json, true) : array();

                    $resolved_lane = null;
                    if ($is_domestic && !empty($state_code)) {
                        foreach ($lanes as $id => $lane) {
                            if (isset($lane['type']) && 'override' === $lane['type'] && 'state' === $lane['scope']) {
                                if (strtoupper($lane['details']) === strtoupper($state_code)) {
                                    $resolved_lane = $lane;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$resolved_lane && $is_domestic) {
                        $peninsular = array('JHR', 'KDH', 'KTN', 'MLK', 'NSN', 'PHG', 'PNG', 'PRK', 'PLS', 'SGR', 'TRG', 'KUL', 'PJY');
                        $sabah_sarawak = array('SBH', 'SRW', 'LBN');
                        $is_peninsular = in_array(strtoupper($state_code), $peninsular);
                        $is_em = in_array(strtoupper($state_code), $sabah_sarawak);

                        foreach ($lanes as $id => $lane) {
                            if (isset($lane['type']) && 'override' === $lane['type']) {
                                if ('peninsular' === $lane['scope'] && $is_peninsular) {
                                    $resolved_lane = $lane;
                                    break;
                                }
                                if ('sabah_sarawak' === $lane['scope'] && $is_em) {
                                    $resolved_lane = $lane;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$resolved_lane && !$is_domestic) {
                        foreach ($lanes as $id => $lane) {
                            if (isset($lane['type']) && 'override' === $lane['type'] && 'country' === $lane['scope']) {
                                if (strtoupper($lane['details']) === strtoupper($package['destination']['country'])) {
                                    $resolved_lane = $lane;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$resolved_lane) {
                        if ($is_domestic) {
                            $resolved_lane = isset($lanes['fallback_my']) ? $lanes['fallback_my'] : array('courier' => 'none', 'markup' => null);
                        } else {
                            $resolved_lane = isset($lanes['fallback_int']) ? $lanes['fallback_int'] : array('courier' => 'none', 'markup' => null);
                        }
                    }

                    $courier_key = isset($resolved_lane['courier']) ? $resolved_lane['courier'] : 'none';

                    if ('none' === $courier_key) {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                        return;
                    }

                    $api_key = get_option('mpa_api_key', '');
                    $sender_postcode = get_option('mpa_sender_postcode', '');
                    $receiver_postcode = $package['destination']['postcode'];
                    $receiver_country_code = $package['destination']['country'];

                    if ($is_domestic && empty($receiver_postcode)) {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                        return;
                    }

                    $plugin = new MyParcel_Asia_Plugin();
                    $params = array(
                        'api_key' => $api_key,
                        'sender_postcode' => $sender_postcode,
                        'declared_weight' => $weight,
                    );
                    if ($is_domestic) {
                        $params['receiver_postcode'] = $receiver_postcode;
                    } else {
                        $params['receiver_country_code'] = $receiver_country_code;
                    }

                    $data = $plugin->mpa_post('/check_price', $params);
                    error_log('MPA Checkout API Check Price params: ' . print_r($params, true));
                    error_log('MPA Checkout API Check Price response: ' . print_r($data, true));

                    // this is it
                    if (is_wp_error($data) || !isset($data['status']) || !$data['status'] || empty($data['data']['prices'])) {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                        return;
                    }

                    $prices = $data['data']['prices'];

                    $matched_price = null;
                    foreach ($prices as $price_item) {
                        $provider = strtolower($price_item['provider_code']);
                        if (
                            $provider === strtolower($courier_key) ||
                            (strpos($provider, strtolower($courier_key)) !== false) ||
                            (strpos(strtolower($courier_key), $provider) !== false)
                        ) {

                            $matched_price = floatval(isset($price_item['exclusive_price']) ? $price_item['exclusive_price'] : $price_item['normal_price']);
                            break;
                        }
                    }

                    if ($matched_price === null) {
                        $first_price = reset($prices);
                        $matched_price = floatval(isset($first_price['exclusive_price']) ? $first_price['exclusive_price'] : $first_price['normal_price']);
                    }

                    if ($matched_price !== null) {
                        $lane_price_type = get_option('mpa_lane_price_type', 'markup');
                        if ('flat_price' === $lane_price_type) {
                            $final_cost = floatval(get_option('mpa_lane_flat_price', '0.00'));
                        } elseif ('exact_price' === $lane_price_type) {
                            $final_cost = $matched_price;
                        } else {
                            $final_cost = $matched_price;
                            $global_markup = floatval(get_option('mpa_lane_price_markup', '0.00'));
                            if ($global_markup > 0) {
                                $final_cost += $global_markup;
                            }
                        }

                        $all_couriers = array(
                            'jnt' => 'J&T',
                            'poslaju' => 'Poslaju',
                            'dhle' => 'DHL',
                            'ninjavan' => 'Ninjavan',
                            'flash' => 'Flash',
                            'citylink' => 'Citylink Express',
                            'lex' => 'LEX Express',
                            'spx' => 'SPX Express',
                            'jnti' => 'J&T International',
                            'ninjavani' => 'Ninjavan International',
                            'ems' => 'EMS',
                            'aramex' => 'Aramex',
                            'fedex' => 'Fedex',
                            'airparcel' => 'AirParcel',
                        );
                        $courier_name = isset($all_couriers[$courier_key]) ? $all_couriers[$courier_key] : 'Standard Delivery';
                        $logo_url = $plugin->courier_logo($courier_key);
                        $label = !empty($logo_url) ? '<img src="' . esc_url($logo_url) . '" style="height:20px; vertical-align:middle; margin-right:8px;" /> ' . esc_html($courier_name) : esc_html($courier_name);

                        $this->add_rate(array(
                            'id' => $this->id,
                            'label' => $label,
                            'cost' => round($final_cost, 2),
                        ));
                    } else {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                    }
                    return;
                }

                if ('choose' === $checkout_price_option) {
                    $cc_lanes_json = get_option('mpa_customer_choose_courier_lanes', '');
                    $cc_lanes = !empty($cc_lanes_json) ? json_decode($cc_lanes_json, true) : array();

                    $peninsular = array('JHR', 'KDH', 'KTN', 'MLK', 'NSN', 'PHG', 'PNG', 'PRK', 'PLS', 'SGR', 'TRG', 'KUL', 'PJY');
                    $sabah_sarawak = array('SBH', 'SRW', 'LBN');
                    $is_peninsular = in_array(strtoupper($state_code), $peninsular);
                    $is_em = in_array(strtoupper($state_code), $sabah_sarawak);

                    $matched_cc_lanes = array();
                    foreach ($cc_lanes as $lane) {
                        $type = isset($lane['type']) ? $lane['type'] : '';
                        if ('domestic' === $type && $is_domestic) {
                            $matched_cc_lanes[] = $lane;
                        } elseif ('peninsular' === $type && $is_domestic && $is_peninsular) {
                            $matched_cc_lanes[] = $lane;
                        } elseif ('sabah_sarawak' === $type && $is_domestic && $is_em) {
                            $matched_cc_lanes[] = $lane;
                        } elseif ('international' === $type && !$is_domestic) {
                            $matched_cc_lanes[] = $lane;
                        }
                    }

                    if (empty($matched_cc_lanes)) {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                        return;
                    }

                    $api_key = get_option('mpa_api_key', '');
                    $sender_postcode = get_option('mpa_sender_postcode', '');
                    $receiver_postcode = $package['destination']['postcode'];
                    $receiver_country_code = $package['destination']['country'];

                    if ($is_domestic && empty($receiver_postcode)) {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                        return;
                    }

                    $plugin = new MyParcel_Asia_Plugin();
                    $params = array(
                        'api_key' => $api_key,
                        'sender_postcode' => $sender_postcode,
                        'declared_weight' => $weight,
                    );
                    if ($is_domestic) {
                        $params['receiver_postcode'] = $receiver_postcode;
                    } else {
                        $params['receiver_country_code'] = $receiver_country_code;
                    }

                    $data = $plugin->mpa_post('/check_price', $params);

                    $prices = array();
                    if (!is_wp_error($data) && isset($data['status']) && $data['status'] && !empty($data['data']['prices'])) {
                        $prices = $data['data']['prices'];
                    }

                    $rates_added = 0;
                    foreach ($matched_cc_lanes as $lane) {
                        $courier_key = $lane['courier'];
                        $matched_price = null;

                        foreach ($prices as $price_item) {
                            $provider = strtolower($price_item['provider_code']);
                            if (
                                $provider === strtolower($courier_key) ||
                                (strpos($provider, strtolower($courier_key)) !== false) ||
                                (strpos(strtolower($courier_key), $provider) !== false)
                            ) {

                                $matched_price = floatval(isset($price_item['exclusive_price']) ? $price_item['exclusive_price'] : $price_item['normal_price']);
                                break;
                            }
                        }

                        $price_type = isset($lane['price_type']) ? $lane['price_type'] : 'markup';
                        $final_cost = null;

                        if ('flat' === $price_type) {
                            $final_cost = floatval(isset($lane['flat_price']) ? $lane['flat_price'] : 0);
                        } else {
                            if ($matched_price !== null) {
                                if ('exact' === $price_type) {
                                    $final_cost = $matched_price;
                                } else {
                                    $final_cost = $matched_price + floatval(isset($lane['markup']) ? $lane['markup'] : 0);
                                }
                            }
                        }

                        if ($final_cost !== null) {
                            $logo_url = $plugin->courier_logo($courier_key);
                            $label = !empty($logo_url) ? '<img src="' . esc_url($logo_url) . '" style="height:20px; vertical-align:middle; margin-right:8px;" /> ' . esc_html($lane['name']) : esc_html($lane['name']);
                            $this->add_rate(array(
                                'id' => $this->id . '_' . $courier_key,
                                'label' => $label,
                                'cost' => round($final_cost, 2),
                            ));
                            $rates_added++;
                        }
                    }

                    if ($rates_added === 0) {
                        $this->apply_default_shipping_fallback($default_price_option, $default_fixed_price);
                    }
                    return;
                }
            }

            protected function apply_default_shipping_fallback($default_price_option, $default_fixed_price)
            {
                if ('free' === $default_price_option) {
                    $this->add_rate(array(
                        'id' => $this->id,
                        'label' => __('Standard Delivery', 'myparcel-asia'),
                        'cost' => 0,
                    ));
                } elseif ('fixed' === $default_price_option) {
                    $this->add_rate(array(
                        'id' => $this->id,
                        'label' => __('Standard Delivery', 'myparcel-asia'),
                        'cost' => $default_fixed_price,
                    ));
                }
            }
        }
    }
}

// Call helper to define class on plugins_loaded after WC loads
add_action('plugins_loaded', 'mpa_register_shipping_method_class', 20);

class MyParcel_Asia_Updater
{
    private $plugin_slug;
    private $version;
    private $cache_key;
    private $cache_allowed;
    private $update_url;

    public function __construct()
    {
        $this->plugin_slug = plugin_basename(__FILE__);
        
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_data = get_plugin_data(__FILE__);
        $this->version = $plugin_data['Version'];

        $this->cache_key = 'mpa_updater_info';
        $this->cache_allowed = false; // set to true in production
        $this->update_url = 'https://raw.githubusercontent.com/myparcelasia/wc-myparce-asia/refs/heads/main/release/wc-myparcel-asia.json';

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'check_info'), 10, 3);
    }

    public function request()
    {
        $remote = get_transient($this->cache_key);
        if (false !== $remote && $this->cache_allowed) {
            return $remote;
        }

        $remote = wp_remote_get($this->update_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote) || empty(wp_remote_retrieve_body($remote))) {
            return false;
        }

        $remote_data = json_decode(wp_remote_retrieve_body($remote));
        
        if (isset($remote_data->sections)) {
            $remote_data->sections = (array) $remote_data->sections;
        }
        if (isset($remote_data->banners)) {
            $remote_data->banners = (array) $remote_data->banners;
        }

        set_transient($this->cache_key, $remote_data, DAY_IN_SECONDS);

        return $remote_data;
    }

    public function check_info($false, $action, $arg)
    {
        if ('plugin_information' !== $action || !isset($arg->slug) || $arg->slug !== dirname($this->plugin_slug)) {
            return $false;
        }

        $remote = $this->request();
        if (!$remote) {
            return $false;
        }

        return $remote;
    }

    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->request();
        if ($remote && version_compare($this->version, $remote->version, '<')) {
            $res = new stdClass();
            $res->slug = $this->plugin_slug;
            $res->plugin = $this->plugin_slug;
            $res->new_version = $remote->version;
            $res->tested = isset($remote->tested) ? $remote->tested : '';
            $res->package = $remote->download_url;
            $res->icons = isset($remote->icons) ? (array) $remote->icons : array();
            $res->banners = isset($remote->banners) ? (array) $remote->banners : array();
            $transient->response[$this->plugin_slug] = $res;
        }

        return $transient;
    }
}

// Initialize the plugin
new MyParcel_Asia_Plugin();
