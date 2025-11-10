<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Paspay_Gateway extends WC_Payment_Gateway {

    public $currencies;

    public function __construct() {
        $this->id               = 'paspay';
        $this->icon             = '';
        $this->has_fields       = false;
        $this->method_title       = 'Paspay Payment';
        $this->method_description = 'Terima pembayaran melalui API Paspay (VA, QRIS, dll.)';

        $this->currencies = array( 'IDR' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_ajax_paspay_test_connection', array( $this, 'test_connection_handler' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
    }

    public function is_available() {
        if ( $this->get_option( 'enabled' ) === 'no' ) {
            return false;
        }
        $api_key = $this->get_option( 'api_key' );
        $project_id = $this->get_option( 'project_id' );
        $gateway_id = $this->get_option( 'gateway_id' );
        if ( empty($api_key) || empty($project_id) || empty($gateway_id) ) {
            return false;
        }
        if ( ! parent::is_available() ) {
            return false;
        }
        return true;
    }

    public function init_form_fields() {
        $webhook_url = get_site_url(null, 'wc-api/paspay_webhook');
        
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Aktifkan/Nonaktifkan',
                'type'    => 'checkbox',
                'label'   => 'Aktifkan Paspay Payment Gateway',
                'default' => 'no',
            ),
            'title' => array(
                'title'   => 'Judul',
                'type'    => 'text',
                'default' => 'Bayar via Paspay (VA/QRIS)',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Deskripsi',
                'type' => 'textarea',
                'default' => 'Anda akan diarahkan ke halaman pembayaran setelah membuat pesanan.',
            ),
            'api_key' => array(
                'title'       => 'Paspay API Key',
                'type'        => 'password',
                'description' => 'Masukkan API Key Rahasia Anda dari dashboard Paspay.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'project_id' => array(
                'title'       => 'Project ID',
                'type'        => 'text',
                'description' => 'Masukkan ID Proyek yang akan digunakan untuk membuat transaksi.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'gateway_id' => array(
                'title'       => 'Gateway ID',
                'type'        => 'text',
                'description' => 'ID Channel/Gateway yang akan digunakan untuk transaksi (Misalnya: 3 untuk QRIS).',
                'default'     => '1',
                'desc_tip'    => true,
            ),
            'callback_token' => array(
                'title'       => 'Callback Token (Webhook)',
                'type'        => 'password',
                'description' => 'Token ini harus sesuai dengan token yang Anda gunakan di sisi backend Paspay untuk validasi Webhook.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_info' => array(
                'title'       => 'Webhook URL Anda',
                'type'        => 'title',
                'description' => 'URL ini harus Anda daftarkan di pengaturan proyek Paspay Anda untuk menerima notifikasi pembayaran: <br><code style="background: #e5e7eb; padding: 3px 6px; border-radius: 4px; font-weight: bold; color: #1f2937;">' . esc_url($webhook_url) . '</code>',
            ),
        );
    }
    
    public function admin_options() {
        parent::admin_options();
        
        $test_connection_html = '
            <div class="wrap">
                <div id="paspay-connection-test" style="max-width: 600px; margin-top: 20px; padding: 15px; border: 1px solid #ccc; border-radius: 8px; background: #f9f9f9;">
                    <h3 style="margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 10px; font-size: 1.2em;">Uji Koneksi Paspay API</h3>
                    <p>Uji apakah API Key dan Project ID Anda valid dan dapat terhubung dengan layanan Paspay.</p>
                    <button type="button" class="button button-primary" id="paspay-test-btn">Test Koneksi</button>
                    <p id="paspay-test-result" style="margin-top: 15px; font-weight: bold;"></p>
                </div>
            </div>
        ';
        
        $nonce = wp_create_nonce( 'paspay-test-connection-nonce' );

        $js_script = <<<SCRIPT
<script>
    jQuery(document).ready(function($) {
        
        $("#paspay-test-btn").on("click", function() {
            
            var button = $(this);
            var resultDiv = $("#paspay-test-result");
            
            var apiKey = $("#woocommerce_paspay_api_key").val();
            var projectId = $("#woocommerce_paspay_project_id").val();
            var gatewayId = $("#woocommerce_paspay_gateway_id").val(); 
            
            var data = {
                action: "paspay_test_connection",
                security: "{$nonce}",
                api_key: apiKey,
                project_id: projectId,
                gateway_id: gatewayId
            };

            button.prop("disabled", true).text("Testing...");
            resultDiv.removeClass().html("");

            $.post(ajaxurl, data).done(function(response) {
                
                if (response.success) {
                    resultDiv.addClass("woocommerce-message").css("color", "green").html(
                        "Koneksi Berhasil! Ditemukan User: <strong>" + response.data.user_info.name + "</strong> (<small>Project ID: " + response.data.project_info.id + "</small>)"
                    );
                } else {
                    resultDiv.addClass("woocommerce-error").css("color", "red").html("Koneksi Gagal: " + response.data);
                }
            }).fail(function(xhr, textStatus, errorThrown) {
                resultDiv.addClass("woocommerce-error").css("color", "red").html("Terjadi kesalahan jaringan atau server. Cek console log (F12) untuk detail.");
            }).always(function() {
                button.prop("disabled", false).text("Test Koneksi");
            });
        });
    });
</script>
SCRIPT;

        echo $test_connection_html;
        echo $js_script;
    }
    
    public function test_connection_handler() {
        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        $api_endpoint = 'https://payment-5a1.pages.dev/api/app/user_info'; 
        if ( empty($api_key) || empty($project_id) ) {
            wp_send_json_error( 'API Key dan Project ID tidak boleh kosong.' );
        }
        $response = wp_remote_get( $api_endpoint, array(
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Paspay-Project-ID' => $project_id 
            ),
            'timeout'   => 15,
        ));
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Gagal Terhubung: ' . $response->get_error_message() );
        }
        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );
        if ( $body === null ) {
            wp_send_json_error( 'Respons API bukan JSON yang valid. Kode Status: ' . $http_code );
        }
        if ( $http_code === 200 && is_array($body) && !empty($body) ) {
            $first_item = $body[0];
            if ( isset( $first_item['project_id'] ) && isset( $first_item['project_name'] ) ) {
                wp_send_json_success( array(
                    'user_info' => ['name' => $first_item['project_name']],
                    'project_info' => ['id' => $first_item['project_id']]
                ) );
            } else {
                wp_send_json_error( 'Respons diterima, tapi format data array tidak dikenali.' );
            }
        } else if ( isset( $body['error'] ) ) {
            wp_send_json_error( 'API Error: ' . $body['error'] );
        } else if ( isset( $body['message'] ) ) {
            wp_send_json_error( 'API Error: ' . $body['message'] );
        } else {
            $error_message = 'Kode Status: ' . $http_code . '. Respons tidak valid.';
            wp_send_json_error( $error_message );
        }
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $api_key        = $this->get_option( 'api_key' );
        $project_id     = $this->get_option( 'project_id' );
        $gateway_id     = $this->get_option( 'gateway_id' ); 
        $api_endpoint   = 'https://payment-5a1.pages.dev/api/v1/transactions'; 
        if ( empty($api_key) || empty($project_id) || empty($gateway_id) ) {
             wc_add_notice( 'Paspay Gateway Error: API Key, Project ID, atau Gateway ID belum dikonfigurasi.', 'error' );
             return;
        }
        $payload = array(
            'project_id'         => intval($project_id),
            'payment_channel_id' => [intval($gateway_id)], 
            'amount'             => intval($order->get_total()),
            'internal_ref_id'    => $order->get_order_key(),
            'description'        => 'Pembayaran Order #' . $order_id,
            'customer_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email'     => $order->get_billing_email(),
            'customer_phone'     => $order->get_billing_phone(),
        );
        $response = wp_remote_post( $api_endpoint, array(
            'method'    => 'POST',
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'      => json_encode( $payload ),
            'timeout'   => 45,
        ));
        if ( is_wp_error( $response ) ) {
            wc_add_notice( 'Paspay API Error: ' . $response->get_error_message(), 'error' );
            return;
        }
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code === 201 && isset( $body['success'] ) && $body['success'] === true ) {
            $qris_string = null;
            $bank_data = null;
            $channel_name = 'Metode Pembayaran'; 
            if ( !empty($body['payment_channels']) && is_array($body['payment_channels']) ) {
                $first_channel = $body['payment_channels'][0];
                $channel_name = $first_channel['name'] ?? $channel_name;
                if ( isset($first_channel['payment_details']) ) {
                    if ( !empty($first_channel['payment_details']['qris_raw']) ) {
                        $qris_string = $first_channel['payment_details']['qris_raw'];
                    }
                    if ( !empty($first_channel['payment_details']['bank_data']) ) {
                        $bank_data = $first_channel['payment_details']['bank_data'];
                    }
                }
            }
            $order->update_meta_data( '_paspay_reference_id', $body['reference_id'] );
            $order->update_meta_data( '_paspay_total_amount', $body['total_amount_expected'] ); 
            $order->update_meta_data( '_paspay_unique_code', $body['unique_code'] ?? 0 );
            $order->update_meta_data( '_paspay_channel_name', $channel_name );
            $order->update_meta_data( '_paspay_qris_string', $qris_string );
            $order->update_meta_data( '_paspay_bank_data', $bank_data );
            $order->save();
            $order->update_status( 'pending', 'Menunggu pembayaran dari Paspay API. Ref ID: ' . $body['reference_id'] );
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order ),
            );
        } else {
            $error_message = $body['error'] ?? 'Terjadi kesalahan saat membuat transaksi di Paspay API. Kode Status: ' . $http_code;
            wc_add_notice( 'Paspay API Error: ' . $error_message, 'error' );
            return;
        }
    }

    public function thankyou_page( $order_id ) {
        
        $order = wc_get_order( $order_id );
        
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $ref_id = $order->get_meta( '_paspay_reference_id' );
        $total_amount = $order->get_meta( '_paspay_total_amount' );
        $unique_code = $order->get_meta( '_paspay_unique_code' );
        $channel_name = $order->get_meta( '_paspay_channel_name' );
        $qris_string = $order->get_meta( '_paspay_qris_string' );
        $bank_data = $order->get_meta( '_paspay_bank_data' );

        if ( $order->get_status() == 'pending' && $ref_id && $total_amount ) {
            
            echo '<div class="woocommerce-notice woocommerce-notice--info woocommerce-info" style="border-left-color: #007cba; background-color: #eaf2f8; padding: 15px; border-radius: 4px;">Silakan selesaikan pembayaran sesuai detail di bawah. Total yang harus dibayar adalah: <strong>' . wc_price( $total_amount ) . '</strong></div>';
            
            echo '<h2 style="margin-top: 20px; border-bottom: 2px solid #eee; padding-bottom: 5px;">Rincian Pembayaran (Kode Unik: ' . esc_html($unique_code) . ')</h2>';
            
            echo '<div style="border: 1px solid #ddd; padding: 15px; border-radius: 6px; background: #fff;">';
            echo '<p style="font-size: 1.1em; margin-bottom: 10px;"><strong>Metode Pembayaran:</strong> ' . esc_html( $channel_name ) . '</p>';

            if ( !empty($qris_string) ) {
                echo '<p><strong>QRIS (Scan untuk Bayar):</strong></p>';
                
                echo '<div id="paspay-qris-code" style="width: 250px; height: 250px; margin: 15px auto; padding: 10px; border: 1px solid #ddd; background: #fff; display: flex; justify-content: center; align-items: center;"></div>';
                
                echo '<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>';
                
                echo '<script type="text/javascript">';
                echo '  var qrisDiv = document.getElementById("paspay-qris-code");';
                echo '  if (qrisDiv) {';
                echo '      new QRCode(qrisDiv, {';
                echo '          text: "' . esc_js( $qris_string ) . '",';
                echo '          width: 230,';
                echo '          height: 230,';
                echo '          correctLevel : QRCode.CorrectLevel.L';
                echo '      });';
                echo '  }';
                echo '</script>';
            } 
            
            if ( !empty($bank_data) ) {
                echo '<p><strong>Detail Transfer:</strong></p>';
                echo '<div style="background-color: #f0f8ff; padding: 10px; border: 1px solid #b8daff; border-radius: 4px;">';
                echo nl2br( esc_html( $bank_data ) );
                echo '</div>';
            }

            echo '<p style="margin-top: 15px; font-size: 0.9em; color: #666;">Setelah pembayaran berhasil, status pesanan akan diperbarui secara otomatis.</p>';
            echo '</div>';
            
        }
    }
}
