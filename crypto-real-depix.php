<?php

/**
 * Plugin Name: Crypto Real Depix
 * Plugin URI: https://www.rodolforomao.com.br
 * Description: Plugin de pagamento em criptomoedas para WooCommerce - Pagamentos através do Pix usando a moeda Depix.
 * Version: 0.01.002
 * Author: Rodolfo Romão
 * Author URI: https://www.rodolforomao.com.br
 * License: GPL2
 * Text Domain: crypto-real-depix
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Check if WooCommerce is active.  This is the correct way to do dependency checks.
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'crypto_real_depix_woocommerce_missing_notice');
    return; // Stop loading the plugin if WooCommerce is not active.
}

/**
 * Display a notice if WooCommerce is missing.
 */
function crypto_real_depix_woocommerce_missing_notice()
{
    echo '<div class="error"><p><strong>' . esc_html__('Crypto Real Depix requires WooCommerce to be installed and active.', 'crypto-real-depix') . '</strong></p></div>';
}

/**
 * Load the payment gateway class.  This is done inside a function hooked to 'plugins_loaded'.
 */
function crypto_real_depix_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        // WooCommerce is not loaded.  We've already handled this, but this is a safety check.
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-crypto-real.php';

    // Add the gateway to WooCommerce.  This MUST happen within the 'plugins_loaded' action.
    add_filter('woocommerce_payment_gateways', 'crypto_real_depix_add_gateway');
}
add_action('plugins_loaded', 'crypto_real_depix_init', 0); // Priority 0 ensures it runs *before* WooCommerce's checks.


/**
 * Adds the Crypto Real Depix gateway to the list of available gateways.
 *
 * @param array $gateways The existing list of payment gateways.
 * @return array The updated list of payment gateways.
 */
function crypto_real_depix_add_gateway($gateways)
{
    error_log('Adding Crypto Real Depix Gateway to WooCommerce.');
    $gateways[] = 'WC_Gateway_Crypto_Real'; // Add the class name here.
    return $gateways;
}

/**
 * Load plugin textdomain.
 */
function crypto_real_depix_load_textdomain()
{
    load_plugin_textdomain('crypto-real-depix', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'crypto_real_depix_load_textdomain');

/**
 * Add settings link on plugin page
 */
function crypto_real_depix_settings_link($links)
{
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=crypto_real_depix">' . __('Settings', 'crypto-real-depix') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'crypto_real_depix_settings_link');


add_action('woocommerce_before_checkout_form', 'crypto_real_force_payment_methods_refresh', 0);

function crypto_real_force_payment_methods_refresh()
{
    WC()->payment_gateways()->init(); // Re-initialize payment gateways
}


add_action('rest_api_init', function () {
    register_rest_route('crypto-real-depix/v1', '/update-status-order', [
        'methods'  => 'POST',
        'callback' => 'crypto_real_depix_webhook_handler',
        'permission_callback' => function (WP_REST_Request $request) {
            $password = $request->get_header('X-API-KEY'); // Capture the key from the header
            return $password === 'sdkfjlkadsjfl3kj45342k4j5kjasdfasdflkj456435yt'; // Replace with your actual password
        },
    ]);

    register_rest_route('crypto-real-depix/v1', '/check-payment', [
        'methods' => 'GET',
        'callback' => function (WP_REST_Request $request) {
            $depixId = $request->get_param('depixId');
            $orderId = $request->get_param('orderId');
            $production = get_option('production', 'no'); // ✅ Correct way to fetch option

            error_log("Received request with depixId: {$depixId} and orderId: {$orderId}");

            if ($production === 'yes') {
                $url = "https://rodolforomao.com.br/finances/public/check-bank-slip-paid-by-id?depixId={$depixId}&orderId={$orderId}";
            } else {
                $url = "http://localhost:8000/check-bank-slip-paid-by-id?depixId={$depixId}&orderId={$orderId}";
            }
            $args = [
                'timeout' => 90
            ];
            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                error_log("Request failed: " . print_r($response->get_error_message(), true));
                return new WP_Error('request_failed', 'Request failed', ['status' => 500]);
            }

            $body = wp_remote_retrieve_body($response);
            error_log("Response body: " . print_r($body, true));

            return rest_ensure_response($body);
        }
    ]);
});

// Webhook handler function
function crypto_real_depix_webhook_handler(WP_REST_Request $request)
{
    error_log('webhook - crypto_real_depix_webhook_handler - called.');

    $data = $request->get_json_params();

    // Log the webhook data for debugging
    error_log("Received Webhook Data: " . print_r($data, true));
    file_put_contents(__DIR__ . '/webhook_log.txt', json_encode($data, JSON_PRETTY_PRINT), FILE_APPEND);

    // Validate that the expected keys exist in the data
    if (!isset($data['transaction_id']) || !isset($data['order_id']) || !isset($data['status'])) {
        error_log("Invalid webhook structure");
        return new WP_REST_Response(["status" => "error", "message" => "Invalid webhook data"], 400);
    }

    $order_id = intval($data['order_id']);
    $status   = sanitize_text_field($data['status']); // Sanitize the status!

    $order = wc_get_order($order_id);

    if (!$order) {
        error_log("Order not found: " . $order_id);
        return new WP_REST_Response(["status" => "error", "message" => "Order not found"], 404);
    }

    // Process the order based on the status
    if ($status === 'paid' || $status === 'completed') { // Adjust status values as needed
        $order->payment_complete(); // Mark as paid
        $order->add_order_note("Payment received via Crypto Real Depix. Transaction ID: " . sanitize_text_field($data['transaction_id']));
    } elseif ($status === 'failed') {
        $order->update_status('failed', __('Payment failed via Crypto Real Depix.', 'crypto-real-depix'));
    } else {
        error_log("Unknown status: " . $status);
        return new WP_REST_Response(["status" => "error", "message" => "Unknown status: " . $status], 400);
    }

    $updated_status = $order->get_status();

    return new WP_REST_Response([
        "status" => "success",
        "message" => "Order status updated",
        "updated_status" => $updated_status  // Include the updated status in the response
    ], 200);
}


add_action('init', function () {
    error_log('flush_rewrite_rules.');
    flush_rewrite_rules();
});
