<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WC_Gateway_Crypto_Real extends WC_Payment_Gateway
{


    public function __construct()
    {
        $this->init_settings();
        // parent::__construct(); // Add this line to ensure proper initialization
        //error_log('Crypto Real Depix Gateway Constructor called.');

        $this->id                 = 'crypto_real_depix';
        $this->method_title       = __('Crypto Real Depix', 'crypto-real-depix');
        $this->method_description = __('Pagamento via Pix usando o Crypto Real Depix', 'crypto-real-depix');
        $this->title              = __('Pagamento via Pix - Depix', 'crypto-real-depix'); // Default title.  User can override.
        $this->has_fields         = false; // We don't need custom credit card fields.
        $this->supports           = array(
            'products',
        );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title              = $this->get_option('title');
        $this->description        = $this->get_option('description');
        $this->instructions       = $this->get_option('instructions', $this->description); // Use description as default instructions
        $this->store_code_depix  = $this->get_option('store_code_depix');
        $this->production          = $this->get_option('production', 'no'); // Add a production/sandbox toggle

        $this->enabled = $this->get_option('enabled');
        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        // add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));  // Display info on thank you page
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3); // Add instructions to emails
        add_action('woocommerce_thankyou', [$this, 'display_qr_code_on_thankyou_page'], 10, 1);

        // error_log('Enabled: ' . $this->enabled);
        // error_log('Store Code Depix: ' . $this->store_code_depix);
    }


    public function init_form_fields()
    {
        //error_log('Crypto Real Depix init_form_fields() called.');
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
                'default'     => __('Pagamento via Pix - Depix - Crypto', 'crypto-real-depix'), // More user-friendly default
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'crypto-real-depix'),
                'type'        => 'textarea',
                'description' => __('The description which the user sees during checkout.', 'crypto-real-depix'),
                'default'     => __('Pague em reais através do PIX usando a rede cripto.', 'crypto-real-depix'),
                'desc_tip'    => true,
            ),
            'instructions' => array(
                'title'       => __('Instructions', 'crypto-real-depix'),
                'type'        => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'crypto-real-depix'),
                'default'     => __('You will receive a payment address shortly.', 'crypto-real-depix'),
                'desc_tip'    => true,
            ),
            'store_code_depix' => array(
                'title'       => __('Store Code (Depix)', 'crypto-real-depix'),
                'type'        => 'text',
                'description' => __('Enter your unique store code to generate Pix payments.', 'crypto-real-depix'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'production' => array( // Added production/sandbox toggle
                'title'       => __('Production Mode', 'crypto-real-depix'),
                'label'       => __('Enable Production Mode', 'crypto-real-depix'),
                'type'        => 'checkbox',
                'description' => __('Enable this when you are ready to accept real payments.', 'crypto-real-depix'),
                'default'     => 'no', // Default to sandbox mode
                'desc_tip'    => true,
            ),

        );
    }


    public function process_payment($order_id)
    {
        // error_log('Crypto Real Depix process_payment() called. Order ID: ' . $order_id);

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



        // error_log('QR Code salvo no pedido. Aguardando usuário...');

        // Redireciona para a página de agradecimento
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }




    public function is_available()
    {
        // error_log('Verificando disponibilidade do gateway: ' . $this->id); // Adicione esta linha

        // error_log('is_available() - Store Code: ' . $this->store_code_depix);
        // error_log('is_available() - Currency: ' . get_woocommerce_currency());

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

        // error_log('is_available(): ' . ($is_available ? 'TRUE' : 'FALSE'));
        return $is_available;
    }



    public function process_checkout()
    {
        // ... existing code ...
        // error_log('process_checkout() called.');


        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

        // error_log('Available Payment Gateways:' . print_r(array_keys($available_gateways), true)); // Add this line

        if (empty($available_gateways)) {
            wc_add_notice(__('There are no payment methods available. This may be an error on our side. Please contact us if you need any help placing your order.', 'woocommerce'), 'error');
            return false;
        }

        // ... existing code ...
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
        
        error_log('url: ' . $api_url);


        $response = wp_remote_post($api_url, array(
            'timeout' => 30, // Set timeout to 30 seconds
            'body'    => $post_data, // Send data as POST body
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded', // Send as form data
            ),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            // Lógica para lidar com o erro
            error_log('Depix API Error: ' . $error_message);
        } else {
            // Lógica para processar a resposta
            $body = wp_remote_retrieve_body($response);
            // Processar a resposta da API
        }
        //$response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            // Log the error for debugging.  Use error_log() for server-side logging.
            error_log('Depix API Error: ' . $response->get_error_message());
            return new WP_Error('payment_error', sprintf(__("Error generating Pix payment: %s", 'crypto-real-depix'), $response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);


        if (empty($data) || !is_array($data)) {
            error_log('Depix API Response Error: Invalid JSON response.  Response Body: ' . $body);
            return new WP_Error('payment_error', __('Error processing payment, invalid API response.', 'crypto-real-depix'));
        }

        // Check for a specific error indicator from the API (adjust based on your API's response format)
        if (isset($data['error'])) {
            error_log('Depix API Error: ' . $data['error']);
            return new WP_Error('payment_error', sprintf(__('Error from Depix API: %s', 'crypto-real-depix'), $data['error']));
        }

        // error_log('Data: ' . print_r($data, true));


        if (isset($data['pix']['data']['response']['qrCopyPaste'])) {
            // Return the entire data array, not just the QR code.  This allows for more flexibility.
            error_log('Returning success ');
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

        $order_id = get_post_meta($order_id, '_orderid', true);
        if (!$order_id) return;


        // Gera o link para exibição do QR Code
        $qr_code_url = 'https://quickchart.io/qr?text=' . urlencode($qr_code) . '&size=300';

        // Exibir o conteúdo do modal com botão para abrir
        echo '
    <button id="openPixModalButton" onclick="openPixModal()" style="margin-top: 20px; padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer;">
        ' . __('Ver QR Code Pix', 'crypto-real-depix') . '
    </button>
    
    <div id="pixModal" class="modal" style="display:block;">
        <div class="modal-content" style="display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
            <span class="close" id="closeModal">&times;</span>
            <h3>' . __('Pix Payment QR Code', 'crypto-real-depix') . '</h3>
            <img src="' . esc_url($qr_code_url) . '" alt="Pix Payment QR Code" style="max-width: 100%; height: auto;"/>
            <p>' . __('Escaneie este QR Code para efetuar o pagamento.', 'crypto-real-depix') . '</p>
    
            <p id="pixCodeText" style="margin-top: 20px; overflow-wrap: anywhere;">' . esc_html($qr_code) . '</p>
            <div id="alert-container">
            </div>
    
            <div class="modal-footer">
                <button type="button" style="margin-top: 20px; padding: 10px 20px; background-color: #4c74af; color: white; border: none; cursor: pointer;" id="waiting-payment">Verificar pagamento</button>
                <button id="copyPixButton" onclick="copyPixCode()" style="margin-top: 20px; padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer;">
                    ' . __('Copiar Pix Copia e Cola', 'crypto-real-depix') . '
                </button>
                <button type="button" id="closeButton" style="margin-top: 20px; padding: 10px 20px; background-color: #b33434; color: white; border: none; cursor: pointer;" class="btn btn-secondary">
                    ' . __('Fechar', 'crypto-real-depix') . '
                </button>
            </div>
        </div>
    </div>';

        // CSS para o modal
        echo '
    <style>
        .modal {
            display: block;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            text-align: center;
            border-radius: 10px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
    </style>';

        $production = $this->production === 'yes'; // Check the production mode setting
        if ($production) {
            $api_url_base_url = "https://rodolforomao.com.br/finances/public";
        } else {
            $api_url_base_url = "http://localhost:8000";
        }

        echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/crypto-js.js"></script>';

        // Then, load jQuery if needed
        echo '<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>';

        echo '
        
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                var modal = document.getElementById("pixModal");
                var closeButton = document.getElementById("closeButton");
                var closeModal = document.getElementById("closeModal");
                var openButton = document.getElementById("openPixModalButton");
    
                // Função para abrir o modal
                window.openPixModal = function() {
                    modal.style.display = "block";
                };
    
                // Fecha o modal ao clicar no "X" ou no botão "Fechar"
                closeButton.onclick = function() {
                    modal.style.display = "none";
                };
                closeModal.onclick = function () {
                    modal.style.display = "none";
                };
    
                // Fecha o modal ao clicar fora dele
                window.onclick = function (event) {
                    if (event.target == modal) {
                        modal.style.display = "none";
                    }
                };
    
                // Função para copiar o código Pix
                window.copyPixCode = function() {
                    var copyText = document.getElementById("pixCodeText");
    
                    // Cria um elemento de input temporário
                    var tempInput = document.createElement("input");
                    tempInput.value = copyText.textContent || copyText.innerText;
    
                    // Adiciona o input ao body, seleciona e copia
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    tempInput.setSelectionRange(0, 99999); // Para dispositivos móveis
    
                    // Copia o texto
                    document.execCommand("copy");
    
                    // Remove o input temporário
                    document.body.removeChild(tempInput);
    
                    // Exibe uma mensagem de sucesso (opcional)
                    alert("' . __('Texto copiado com sucesso!', 'crypto-real-depix') . '");
                };
    
                // Função para verificar o pagamento
                var clicked = false;
                document.getElementById("waiting-payment").addEventListener("click", function() {
                    if (!clicked) {
                        clicked = true;
                        const button = this;
                        button.disabled = true;
                        button.innerHTML = "<span class=\'spinner-border spinner-border-sm\' role=\'status\' aria-hidden=\'true\'></span> Aguardando...";
    
                        // Chamar a função de verificação de pagamento
                        checkPayment();
                    }
                });
    
                function generateSignature(payload) {
                    const secret = "6s5df4g8sdf7h65fg4fg-dfghdf54gh6df"; // Substitua pela chave secreta real usada para o hash
                    const payloadString = JSON.stringify(payload); // Converte o payload em uma string
                    const signature = CryptoJS.HmacSHA256(payloadString, secret).toString(CryptoJS.enc.Base64);
                    return signature;
                }
    
                function checkPayment() {
                    const payload = {
                        boletoId: "' . $depixid . '"
                    };

                function displayAlert(message, className) {
                        // Create the div for the alert
                        const alertDiv = document.createElement("div");
                        alertDiv.classList.add("alert", className);
                        alertDiv.textContent = message;
                    
                        // Append the alert to the alert container
                        const alertContainer = document.getElementById("alert-container");
                        alertContainer.innerHTML = "";
                        alertContainer.appendChild(alertDiv);
                    }
    
    
                    $.ajax({
                        url: "/wp-json/crypto-real-depix/v1/check-payment?depixId=' . urlencode($depixid) . '&orderId=' . urlencode($order_id) . '" ,
                        method: "GET",
                        success: function(response) { 
                            if (response.success && response.response) {
                                const status = response.response[0].status; // Extract the status
                                if (status === "paid") {
                                    displayAlert("Paid successful.", "alert-success");
                                }
                                else
                                {
                                    displayAlert("Not paid yet.", "alert-danger");
                                }
                            } else {
                                displayAlert("Pagamento não confirmado.", "alert-warning");
                            }
                            clicked = false;
                            document.getElementById("waiting-payment").innerHTML = "Verificar pagamento";
                            document.getElementById("waiting-payment").disabled = false;
                        }
                    });
                    /*
                    $.ajax({
                        url: "' . $api_url_base_url . '/check-bank-slip-paid-by-id?depixId=' . urlencode($depixid) . '&orderId=' . urlencode($order_id) . '",
                        method: "GET",
                        contentType: "application/json", // Set content type to JSON
                        dataType: "json", // Expect JSON response
                        success: function(response) {
                            debugger
                            // Aqui, você pode atualizar a interface com base na resposta
                            if (response.success && response.response) {
                                const status = response.response[0].status; // Extract the status
                                if (status === "paid") {
                                    displayAlert("Paid successful.", "alert-success");

                                }
                                else
                                {
                                    displayAlert("Pagamento não confirmado. nº 01001", "alert-danger");

                                }
                            } else {
                                displayAlert("Pagamento não confirmado. nº 01002", "alert-danger");
                            }
                            clicked = false;
                            document.getElementById("waiting-payment").innerHTML = "Verificar pagamento";
                            document.getElementById("waiting-payment").disabled = false;
                        },
                        error: function() {
                            alert("Erro ao verificar pagamento.");
                            clicked = false;
                            document.getElementById("waiting-payment").innerHTML = "Verificar pagamento";
                            document.getElementById("waiting-payment").disabled = false;
                        }
                    });
                    */
                }
            });
        </script>
        
        <style>
        .alert {
            padding: 10px;
            margin: 10px;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .alert-danger {
            background-color: red;
            color: white;
        }
        .alert-success {
            background-color: green;
            color: white;
        }
        .alert-warning{
            background-color: yellow;
            color: black;
        }
        </style>';
    }
}
