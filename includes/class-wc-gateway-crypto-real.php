<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WC_Gateway_Crypto_Real extends WC_Payment_Gateway
{

    protected $TIMEOUT_CHECK_PAYMENT_SECONDS = 90;
    protected $TIMEOUT_GENERATE_DEPIX = 30;

    protected $DEBUG_CONSTRUCTOR = false;
    protected $DEBUG_INITIALIZATION = false;
    protected $DEBUG_CALLS_FUNCTION = false;
    protected $DEBUG_GENERAL = false;

    protected $DEBUG_API = false;


    public function __construct()
    {
        $this->init_settings();

        if ($this->DEBUG_INITIALIZATION) {
            error_log('Crypto Real Depix Gateway Constructor called.');
        }

        $this->id                 = 'crypto_real_depix';
        $this->method_title       = __('Crypto Real Depix', 'crypto-real-depix');
        $this->title              = __('Pagamento via Pix', 'crypto-real-depix');
        $this->has_fields         = false;
        $this->supports           = array(
            'products',
        );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title              = $this->get_option('title');
        $this->store_code_depix  = $this->get_option('store_code_depix');
        $this->production          = $this->get_option('production', 'no');
        $this->enabled = $this->get_option('enabled');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        add_action('woocommerce_thankyou', [$this, 'display_qr_code_on_thankyou_page'], 10, 1);

        add_filter('woocommerce_gateway_description', array($this, 'filter_gateway_description'), 10, 2);

        if ($this->DEBUG_CONSTRUCTOR) {
            error_log('Enabled: ' . $this->enabled);
            error_log('Store Code Depix: ' . $this->store_code_depix);
            error_log('Production: ' . $this->production);
        }
    }

    public function init_form_fields()
    {
        if ($this->DEBUG_INITIALIZATION) {
            error_log('Crypto Real Depix init_form_fields() called.');
        }
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'crypto-real-depix'),
                'label'       => __('Enable Crypto Real Depix', 'crypto-real-depix'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
            ),
            'title' => array(
                'title'       => __('Title', 'crypto-real-depix'),
                'type'        => 'text',
                'description' => __('The title which the user sees during checkout.', 'crypto-real-depix'),
                'default'     => __('Pagamento via Pix', 'crypto-real-depix'),
                'desc_tip'    => true,
            ),
            'store_code_depix' => array(
                'title'       => __('Store Code (Depix)', 'crypto-real-depix'),
                'type'        => 'text',
                'description' => __('Enter your unique store code to generate Pix payments.', 'crypto-real-depix'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'production' => array(
                'title'       => __('Production Mode', 'crypto-real-depix'),
                'label'       => __('Enable Production Mode', 'crypto-real-depix'),
                'type'        => 'checkbox',
                'description' => __('Enable this when you are ready to accept real payments.', 'crypto-real-depix'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
        );

        $this->instructions = __('You will receive a payment address shortly.', 'crypto-real-depix');

        add_action('wp_enqueue_scripts', function () {
            if (is_checkout()) {
                wp_enqueue_script('custom-crypto-real-script', plugin_dir_url(__FILE__) . 'assets/js/custom-scripts.js', array('jquery'), null, true);
            }
        });
    }

    public function get_payment_description()
    {
        return __($this->getHtmlPix(), 'crypto-real-depix');
    }

    public function filter_gateway_description($description, $payment_id)
    {
        if ($payment_id === $this->id) {
            return $this->get_payment_description();
        }
        return $description;
    }


    public function process_payment($order_id)
    {
        if ($this->DEBUG_INITIALIZATION) {
            error_log('Crypto Real Depix process_payment() called. Order ID: ' . $order_id);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Invalid order ID.', 'crypto-real-depix'), 'error');
            return;
        }

        // Chamada da API
        $payment_response = $this->crypto_real_depix_initiate_payment($order_id);
        if (is_wp_error($payment_response)) {
            wc_add_notice($payment_response->get_error_message(), 'error');
            return;
        }

        // Pegando os dados do QR Code
        $qr_code = $payment_response['pix']['data']['response']['qrCopyPaste'];
        $depixid = $payment_response['pix']['data']['response']['id'];

        $txid = isset($payment_response['pix']['data']['response']['id']) ? $payment_response['pix']['data']['response']['id'] : '';

        // Atualiza o status do pedido
        $order->update_status('on-hold', sprintf(__('Aguardando pagamento via Pix. Transaction ID: %s', 'crypto-real-depix'), $txid));

        // Reduz o estoque (se necessário)
        wc_reduce_stock_levels($order_id);

        // Armazena o QR Code no pedido
        update_post_meta($order_id, '_pix_qr_code', $qr_code);
        update_post_meta($order_id, '_depixid', $depixid);
        update_post_meta($order_id, '_orderid', $order_id);



        if ($this->DEBUG_GENERAL) {
            error_log('QR Code salvo no pedido. Aguardando usuário...');
        }

        // Redireciona para a página de agradecimento
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }




    public function is_available()
    {
        if ($this->DEBUG_INITIALIZATION) {
            error_log('Verificando disponibilidade do gateway: ' . $this->id); // Adicione esta linha
        }

        if ($this->DEBUG_GENERAL) {
            error_log('is_available() - Store Code: ' . $this->store_code_depix);
            error_log('is_available() - Currency: ' . get_woocommerce_currency());
        }

        $is_available = parent::is_available();

        if ($is_available) {
            if (empty($this->store_code_depix)) {
                error_log('Store code is empty.');
                $is_available = false;
            }

            if (get_woocommerce_currency() !== 'BRL') {
                error_log('Currency is not BRL.');
                $is_available = false;
            }
        }

        if ($this->DEBUG_GENERAL) {
            error_log('is_available(): ' . ($is_available ? 'TRUE' : 'FALSE'));
        }
        return $is_available;
    }



    public function process_checkout()
    {
        if ($this->DEBUG_CALLS_FUNCTION) {
            error_log('process_checkout() called.');
        }

        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

        if ($this->DEBUG_GENERAL) {
            error_log('Available Payment Gateways:' . print_r(array_keys($available_gateways), true)); // Add this line
        }

        if (empty($available_gateways)) {
            wc_add_notice(__('There are no payment methods available. This may be an error on our side. Please contact us if you need any help placing your order.', 'woocommerce'), 'error');
            return false;
        }
    }


    public function crypto_real_depix_initiate_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('payment_error', __('Invalid order.', 'crypto-real-depix'));
        }

        $transaction_id = $order->get_transaction_id();

        if (!$transaction_id) {
            $transaction_id = $order->get_meta('_transaction_id');
        }

        $order_meta = $order->get_meta_data();
        foreach ($order_meta as $meta) {
            echo $meta->key . ': ' . $meta->value . '<br>';
        }

        $description = 'Pagamento para o pedido #' . $order->get_order_number();
        $value = number_format($order->get_total(), 2, '.', ''); // Format to 2 decimal places, no thousands separator

        $code  = $this->store_code_depix;

        if (empty($code)) {
            return new WP_Error('payment_error', __('Store code not configured.', 'crypto-real-depix'));
        }

        $production = $this->production === 'yes';
        $originRequest = get_home_url();
        $urlResponsePayment = $originRequest . "/wp-json/crypto-real-depix/v1/update-status-order";

        $server_ip = gethostbyname(gethostname());
        if ($production) {
            // YOUR PRODUCTION API URL
            $api_url = 'http://rodolforomao.com.br/finances/public/api/integrated-payment';
        } else {
            // YOUR TEST/SANDBOX API URL
            $api_url = 'http://localhost:8000/api/integrated-payment';
        }

        // Prepare the POST data (instead of URL parameters)
        $post_data = array(
            'description' => $description,
            'code' => $code,
            'value' => $value,
            'order_id' => $order_id,
            'url_response_payment' => $urlResponsePayment,
            'transaction_id' => $transaction_id,
            'origin' => $originRequest,
            'ip_origin' => $server_ip
        );

        if ($this->DEBUG_API) {
            error_log('url: ' . $api_url);
        }

        $response = wp_remote_post($api_url, array(
            'timeout' => $this->TIMEOUT_GENERATE_DEPIX,
            'body'    => $post_data,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Depix API Error: ' . $error_message);
        } else {
            $body = wp_remote_retrieve_body($response);
        }

        if (is_wp_error($response)) {
            error_log('Depix API Error: ' . $response->get_error_message());
            return new WP_Error('payment_error', sprintf(__("Error generating Pix payment: %s", 'crypto-real-depix'), $response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);


        if (empty($data) || !is_array($data)) {
            error_log('Depix API Response Error: Invalid JSON response.  Response Body: ' . $body);
            return new WP_Error('payment_error', __('Error processing payment, invalid API response.', 'crypto-real-depix'));
        }

        if (isset($data['error'])) {
            error_log('Depix API Error: ' . $data['error']);
            return new WP_Error('payment_error', sprintf(__('Error from Depix API: %s', 'crypto-real-depix'), $data['error']));
        }

        if (isset($data['pix']['data']['response']['qrCopyPaste'])) {
            if ($this->DEBUG_API) {
                error_log('Returning success ');
            }
            return $data;
        } else {
            error_log('Depix API Response Error: Missing qr_code.  Response Data: ' . print_r($data, true));
            return new WP_Error('payment_error', __('Error processing payment, incomplete data from API.', 'crypto-real-depix'));
        }
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page($order_id)
    {
        if ($this->instructions) {
            echo wpautop(wptexturize($this->instructions)); // Display instructions
            // You could also display the QR code here if you have it.
        }
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {

        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status('on-hold')) {
            echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            // You could also display the QR code here if you have it.
        }
    }

    public function display_qr_code_on_thankyou_page($order_id)
    {
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Recupera o QR Code salvo
        $qr_code = get_post_meta($order_id, '_pix_qr_code', true);
        if (!$qr_code) return;

        $depixid = get_post_meta($order_id, '_depixid', true);
        if (!$depixid) return;

        $order_id_meta = get_post_meta($order_id, '_orderid', true); // Renomeando para evitar conflito
        if (!$order_id_meta) return;

        // Gera o link para exibição do QR Code
        $qr_code_url = 'https://quickchart.io/qr?text=' . urlencode($qr_code) . '&size=300';

        // --- Início das modificações ---

        // Estilos CSS inline (para garantir que funcionem mesmo sem um arquivo CSS externo)
        echo '<style>
        #pix-container {
            text-align: center;
            margin-top: 20px;
        }
        #pix-container img {
            max-width: 100%;
            height: auto;
        }
        #pix-container button {
            margin: 10px;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
        }
        #pix-container #pixCodeText {
            margin-top: 20px;
            overflow-wrap: anywhere;
        }
        #pix-container #alert-container {
            margin-top: 10px;
        }
        .waiting-payment-button{
           background-color: #4c74af;
        }
        #pix-container .alert {
            padding: 10px;
            margin: 10px;
            border-radius: 5px;
            font-size: 16px;
        }
        #pix-container .alert-danger {
            background-color: red;
            color: white;
        }
        #pix-container .alert-success {
            background-color: green;
            color: white;
        }
        #pix-container .alert-warning {
            background-color: yellow;
            color: black;
        }
    </style>';

        // HTML para exibir o QR Code e os botões diretamente na página
        echo '<div id="pix-container" style="text-align: -webkit-center;">';

        echo '<h3>' . __('Pagamento Pix - QR Code', 'crypto-real-depix') . '</h3>';
        echo '<img src="' . esc_url($qr_code_url) . '" alt="Pix Payment QR Code" />';
        echo '<p>' . __('Escaneie este QR Code para efetuar o pagamento.', 'crypto-real-depix') . '</p>';
        echo '<p id="pixCodeText">' . esc_html($qr_code) . '</p>';
        echo '<div id="alert-container"></div>';
        echo '<button type="button" class="waiting-payment-button">' . __('Verificar pagamento', 'crypto-real-depix') . '</button>';
        echo '<button type="button" id="copyPixButton">' . __('Copiar Pix Copia e Cola', 'crypto-real-depix') . '</button>';
        echo '<img src="' . esc_url(plugins_url("crypto-real-depix/assets/images/checkouts/pix/pix.png")) . '" 
        alt="Pix Logo" 
        style="position: absolute; right: 10%; top: 50%; width: 15%;">';

        echo '</div>';

        // JavaScript para copiar o código Pix e verificar o pagamento
        echo '<script>
        if (typeof jQuery == "undefined") {
            console.error("jQuery não está carregado! O plugin Crypto Real Depix não funcionará corretamente.");
        } else {
            jQuery.noConflict();
            (function($) {
                

                $(document).ready(function() {
                    // Função para copiar o código Pix
                    $("#copyPixButton").on("click", function() {
                        var pixCodeText = $("#pixCodeText").text();
                        var tempInput = $("<textarea>");
                        tempInput.val(pixCodeText);
                        $("body").append(tempInput);
                        tempInput.select();
                        document.execCommand("copy");
                        tempInput.remove();
                        alert("' . __('Código Pix copiado para a área de transferência!', 'crypto-real-depix') . '");
                    });

                    // Variável para controlar se o botão já foi clicado
                    var clicked = false;

                    // Função para verificar o pagamento
                    $(".waiting-payment-button").on("click", function() {
                        if (!clicked) {
                            clicked = true;
                            const button = this;
                            button.disabled = true;
                            button.innerHTML = "<span class=\'spinner-border spinner-border-sm\' role=\'status\' aria-hidden=\'true\'></span> Aguardando...";

                            // Função para exibir alertas (movida para fora do $(document).ready())
                            function displayAlert(message, className) {
                                const alertContainer = document.getElementById("alert-container");
                                alertContainer.innerHTML = "";
                                const alertDiv = jQuery("<div>").addClass("alert " + className).text(message); // Cria uma nova div jQuery
                                jQuery(alertContainer).append(alertDiv); // Adiciona a nova div ao container
                            }

                            // Chamada AJAX para verificar o pagamento
                            $.ajax({
                                url: "/wp-json/crypto-real-depix/v1/check-payment?depixId=' . urlencode($depixid) . '&orderId=' . urlencode($order_id_meta) . '" ,
                                method: "GET",
                                contentType: "application/json",
                                dataType: "json",
                                async: true,
                                success: function(response) {
                                    console.log("Raw response:", response);
                                    if (typeof response === "string") {
                                        response = JSON.parse(response);
                                    }
                                    console.log("Parsed response:", response);
                                    globalResponse = response;
                                    if (response.success && response.response) {
                                        const status = response.response[0].status;
                                        if (status === "paid") {
                                            displayAlert("Pagamento confirmado.", "alert-success");
                                        }
                                        else
                                        {
                                        displayAlert("Pagamento não confirmado. nº 1001", "alert-warning");

                                        }
                                    } else {
                                        displayAlert("Pagamento não confirmado. nº 1002", "alert-warning");
                                    }
                                    clicked = false;
                                    button.innerHTML = "Verificar pagamento";
                                    button.disabled = false;
                                },
                                error: function(xhr, status, error) {
                                    console.error("Erro na chamada AJAX:", error);
                                    displayAlert("Erro ao verificar o pagamento. Tente novamente mais tarde.", "alert-danger");
                                    clicked = false;
                                    button.innerHTML = "Verificar pagamento";
                                    button.disabled = false;
                                }
                            });
                        }
                    });
                });
            })(jQuery);
        }
    </script>';

        // --- Fim das modificações ---
    }





    private function getHtmlPix()
    {

        $site_url = get_site_url(); // Obtém dinamicamente o endereço base do site

        return wp_kses_post('
        <div class="payment_box payment_method_woo-mercado-pago-pix">
            <div class="mp-checkout-container">
                <div class="mp-checkout-pix-container" style="text-align: -webkit-center;">
                    <pix-template 
                        title="Pague de forma segura e instantânea" 
                        subtitle="Ao confirmar a compra, nós vamos te mostrar o código para fazer o pagamento." 
                        alt="Logo Pix" 
                        src="' . esc_url($site_url . '/wp-content/plugins/crypto-real-depix/assets/images/checkouts/pix/pix.png?ver=7.9.4') . '">
                        <div class="mp-pix-template-container">
                            <img class="mp-pix-template-image" src="' . esc_url($site_url . '/wp-content/plugins/crypto-real-depix/assets/images/checkouts/pix/pix.png?ver=7.9.4') . '" alt="Logo Pix" loading="lazy">
                            <p class="mp-pix-template-title">Pague de forma segura e instantânea</p>
                            <p class="mp-pix-template-subtitle">Ao confirmar a compra, nós vamos te mostrar o código para fazer o pagamento.</p>
                        </div>
                    </pix-template>
                    <div class="mp-checkout-pix-terms-and-conditions">
                        <terms-and-conditions 
                            description="Ao continuar, você concorda com nossos" 
                            link-text="Termos e condições" 
                            link-src="' . esc_url("https://www.mercadopago.com.br/ajuda/termos-e-politicas_194") . '">
                            <div class="mp-terms-and-conditions-container">
                                <span class="mp-terms-and-conditions-text">Ao continuar, você concorda com nossos</span>
                                <a class="mp-terms-and-conditions-link" href="' . esc_url("https://www.mercadopago.com.br/ajuda/termos-e-politicas_194") . '" target="_blank">Termos e condições</a>
                            </div>
                        </terms-and-conditions>
                    </div>
                </div>
            </div>
        </div>
    ');
    }
}
