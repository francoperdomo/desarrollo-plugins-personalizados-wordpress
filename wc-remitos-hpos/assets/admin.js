jQuery(document).ready(function($) {
    $('.generate-remito').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var orderId = button.data('order-id');
        var originalText = button.text();
        
        button.text(wc_remitos_ajax.generating_text).prop('disabled', true);
        
        $.ajax({
            url: wc_remitos_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_remito_pdf',
                order_id: orderId,
                nonce: wc_remitos_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Abrir PDF en nueva ventana
                    window.open(response.data.url, '_blank');
                    
                    // Mostrar mensaje de éxito
                    $('.remito-status').html('<span style="color: green;">✓ PDF generado correctamente</span>');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Error al generar el PDF');
            },
            complete: function() {
                button.text(originalText).prop('disabled', false);
                setTimeout(function() {
                    $('.remito-status').html('');
                }, 3000);
            }
        });
    });
});