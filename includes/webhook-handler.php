<?php
// if (!defined('ABSPATH')) {
//     exit; // Exit if accessed directly.
// }

// class Crypto_Real_Depix_Webhook {
//     public static function listen() {
//         // Get the request body
//         $json = file_get_contents("php://input");
//         $data = json_decode($json, true);

//         if (!$data) {
//             error_log("Invalid webhook data");
//             wp_die("Invalid request", 400);
//         }

//         // Log for debugging (remove in production)
//         error_log("Received Webhook: " . print_r($data, true));

//         // Validate the webhook (add your gateway's secret key verification here)
//         if (!isset($data['transaction_id']) || !isset($data['order_id']) || !isset($data['status'])) {
//             error_log("Invalid webhook structure");
//             wp_die("Invalid data", 400);
//         }

//         $order_id = intval($data['order_id']);
//         $status   = $data['status'];
//         $order    = wc_get_order($order_id);

//         if (!$order) {
//             error_log("Order not found: " . $order_id);
//             wp_die("Order not found", 404);
//         }

//         // Map the payment gateway status to WooCommerce statuses
//         if ($status === 'paid') {
//             $order->payment_complete(); // Mark as paid
//             $order->add_order_note("Payment received via Crypto Real Depix. Transaction ID: " . esc_html($data['transaction_id']));
//         } elseif ($status === 'failed') {
//             $order->update_status('failed', __('Payment failed via Crypto Real Depix.', 'crypto-real-depix'));
//         }

//         // Respond to the webhook
//         wp_die("OK", 200);
//     }
// }

// // Register the webhook handler
// add_action('woocommerce_api_crypto_real_depix_webhook', array('Crypto_Real_Depix_Webhook', 'listen'));
