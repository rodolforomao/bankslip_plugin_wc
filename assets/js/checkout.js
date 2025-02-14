jQuery(document).ready(function($) {
    function showCryptoRealPix() {
        // Mostrar a caixa de pagamento do seu método
        $(".payment_method_woo-mercado-pago-pix").closest(".payment_box").show();
    }

    // Executa ao carregar a página
    showCryptoRealPix();

    // Quando o usuário troca de método de pagamento
    $(document).on("change", 'input[name="payment_method"]', function() {
        console.log($(this).val());
        if ($(this).val() === "woo-mercado-pago-pix") {
            showCryptoRealPix();
        }
    });

    // Observa atualizações no checkout e reexibe o box se necessário
    $(document.body).on("updated_checkout", function() {
        console.log($('input[name="payment_method"]:checked').val());

        if (
            $('input[name="payment_method"]:checked').val() === "woo-mercado-pago-pix"
        ) {
            showCryptoRealPix();
        }
    });
});