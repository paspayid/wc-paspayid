<?php
/*
Plugin Name: Paspay Payment Gateway
Plugin URI: https://paspay.id
Description: Payment Gateway kustom untuk integrasi Paspay API.
Version: 1.0.2
Author: ARIF ABDUL ROHIM
Author URI: https://paspay.id
*/

// Deklarasi HPOS (Wajib untuk WC baru)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Pastikan plugin tidak diakses langsung
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'add_paspay_action_links' );

function add_paspay_action_links( $links ) {
    $settings_link = array(
        'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paspay' ) . '" aria-label="Lihat Pengaturan Paspay">Settings</a>'
    );
    return array_merge( $settings_link, $links );
}

// Fungsi Inisialisasi Utama
add_action( 'plugins_loaded', 'init_paspay_gateway_plugin', 11 );

function init_paspay_gateway_plugin() {
    
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    $plugin_dir = plugin_dir_path( __FILE__ );

    // Muat file Class Gateway
    $gateway_file = $plugin_dir . 'class-wc-paspay-gateway.php';
    if ( file_exists( $gateway_file ) ) {
        require_once $gateway_file;
    } else {
        error_log('Paspay FATAL: File class-wc-paspay-gateway.php TIDAK DITEMUKAN.');
    }
    
    // Muat file Class Webhook
    $webhook_file = $plugin_dir . 'class-wc-paspay-webhook-handler.php';
    if ( file_exists( $webhook_file ) ) {
        require_once $webhook_file;
    } else {
        error_log('Paspay FATAL: File class-wc-paspay-webhook-handler.php TIDAK DITEMUKAN.');
    }

    // Daftarkan Gateway ke WooCommerce
    if ( class_exists( 'WC_Paspay_Gateway' ) ) {
        add_filter( 'woocommerce_payment_gateways', 'add_paspay_gateway_class' );
    } else {
        error_log('Paspay FATAL: class-wc-paspay-gateway.php telah dimuat, TAPI class "WC_Paspay_Gateway" tidak ditemukan. Periksa SYNTAX ERROR di file itu.');
    }
    
    // Daftarkan Rute Webhook
    if ( class_exists( 'WC_Paspay_Webhook_Handler' ) ) {
        add_action( 'woocommerce_api_paspay_webhook', 'paspay_process_webhook' );
    }
}

// Fungsi untuk menambahkan class ke array gateway WC
function add_paspay_gateway_class( $gateways ) {
    $gateways[] = 'WC_Paspay_Gateway';
    return $gateways;
}

// Fungsi untuk memproses Webhook
function paspay_process_webhook() {
    if ( class_exists( 'WC_Paspay_Webhook_Handler' ) ) {
        $handler = new WC_Paspay_Webhook_Handler();
        $handler->process_webhook();
    } else {
        status_header( 500 );
        echo 'Webhook Handler class not loaded.';
    }
}
