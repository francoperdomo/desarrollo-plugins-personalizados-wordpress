<?php
/**
 * Plugin Name: WooCommerce Remitos HPOS
 * Description: Plugin para generar remitos en PDF compatible con HPOS
 * Version: 1.1.1
 * Author: Franco Perdomo
 * Text Domain: wc-remitos-hpos
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 7.0
 * WC tested up to: 8.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Declarar compatibilidad con HPOS antes de que WooCommerce se cargue
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
    }
});

// Verificar si WooCommerce está activo
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WC_Remitos_HPOS {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        // Cargar traducciones
        load_plugin_textdomain('wc-remitos-hpos', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Hooks para admin
        if (is_admin()) {
            add_action('add_meta_boxes', array($this, 'add_remito_meta_box'));
            add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_remito_column'));
            add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'remito_column_content'), 10, 2);
            
            // Para órdenes legacy (si HPOS está desactivado)
            add_filter('manage_edit-shop_order_columns', array($this, 'add_remito_column'));
            add_action('manage_shop_order_posts_custom_column', array($this, 'remito_column_content_legacy'), 10, 2);
        }
        
        // Hook para generar PDF
        add_action('wp_ajax_generate_remito_pdf', array($this, 'generate_pdf'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Página de configuración
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function admin_init() {
        // Verificar si podemos escribir en wp-content/uploads
        $upload_dir = wp_upload_dir();
        if (!is_writable($upload_dir['basedir'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>El directorio de uploads no es escribible. El plugin de remitos no funcionará correctamente.</p></div>';
            });
        }
    }
    
    public function activate() {
        // Crear directorio para PDFs si no existe
        $upload_dir = wp_upload_dir();
        $remitos_dir = $upload_dir['basedir'] . '/remitos/';
        if (!file_exists($remitos_dir)) {
            wp_mkdir_p($remitos_dir);
            // Crear .htaccess para proteger el directorio
            file_put_contents($remitos_dir . '.htaccess', 'deny from all');
        }
        
        // Crear archivo JS si no existe
        $this->create_admin_js();
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wc-orders') !== false || $hook === 'post.php') {
            wp_enqueue_script('wc-remitos-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '1.1.0', true);
            wp_localize_script('wc-remitos-admin', 'wc_remitos_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_remitos_nonce'),
                'generating_text' => __('Generando PDF...', 'wc-remitos-hpos')
            ));
        }
    }
    
    private function create_admin_js() {
        $plugin_dir = plugin_dir_path(__FILE__);
        $assets_dir = $plugin_dir . 'assets/';
        
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
        }
        
        $js_content = "
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
                    $('.remito-status').html('<span style=\"color: green;\">✓ PDF generado correctamente</span>');
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
";
        
        file_put_contents($assets_dir . 'admin.js', $js_content);
    }
    
    // Añadir metabox en la página de edición de pedido
    public function add_remito_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
            
        add_meta_box(
            'wc-remito-actions',
            __('Remito', 'wc-remitos-hpos'),
            array($this, 'remito_meta_box_content'),
            $screen,
            'side',
            'high'
        );
    }
    
    public function remito_meta_box_content($post_or_order) {
        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
        
        if (!$order) return;
        
        echo '<div class="remito-actions">';
        echo '<button type="button" class="button button-primary generate-remito" data-order-id="' . esc_attr($order->get_id()) . '">';
        echo __('Generar Remito PDF', 'wc-remitos-hpos');
        echo '</button>';
        echo '<div class="remito-status" style="margin-top: 10px;"></div>';
        echo '</div>';
    }
    
    // Añadir columna en la lista de pedidos
    public function add_remito_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status') {
                $new_columns['remito'] = __('Remito', 'wc-remitos-hpos');
            }
        }
        return $new_columns;
    }
    
    public function remito_column_content($column, $order) {
        if ($column === 'remito') {
            if (is_numeric($order)) {
                $order = wc_get_order($order);
            }
            
            if ($order) {
                echo '<button type="button" class="button button-small generate-remito" data-order-id="' . esc_attr($order->get_id()) . '">PDF</button>';
            }
        }
    }
    
    // Para órdenes legacy
    public function remito_column_content_legacy($column, $post_id) {
        if ($column === 'remito') {
            echo '<button type="button" class="button button-small generate-remito" data-order-id="' . esc_attr($post_id) . '">PDF</button>';
        }
    }
    
    // Generar PDF
    public function generate_pdf() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Sin permiso', 'wc-remitos-hpos'));
        }

        if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_remitos_nonce')) {
            wp_send_json_error(__('Error de seguridad', 'wc-remitos-hpos'));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(__('Pedido no válido', 'wc-remitos-hpos'));
        }
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Pedido no encontrado', 'wc-remitos-hpos'));
        }
        
        try {
            $html_content = $this->generate_remito_html($order);
            $filename = 'remito-' . $order_id . '-' . date('Y-m-d-H-i-s') . '.html';
            
            // Guardar HTML temporalmente (que se puede imprimir como PDF)
            $upload_dir = wp_upload_dir();
            $remitos_dir = $upload_dir['basedir'] . '/remitos/';
            
            if (!file_exists($remitos_dir)) {
                wp_mkdir_p($remitos_dir);
            }
            
            $file_path = $remitos_dir . $filename;
            file_put_contents($file_path, $html_content);
            
            $file_url = $upload_dir['baseurl'] . '/remitos/' . $filename;
            
            wp_send_json_success(array(
                'url' => $file_url,
                'filename' => $filename
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error al generar remito: ', 'wc-remitos-hpos') . $e->getMessage());
        }
    }
    
    private function generate_remito_html($order) {
        // Obtener configuraciones
        $company_name = get_option('wc_remitos_company_name', get_bloginfo('name'));
        $company_address = get_option('wc_remitos_company_address', '');
        $company_phone = get_option('wc_remitos_company_phone', '');
        $company_email = get_option('wc_remitos_company_email', get_option('admin_email'));
        $logo_url = get_option('wc_remitos_logo_url', '');
        
        // Datos del pedido
        $order_data = array(
            'number' => $order->get_order_number(),
            'date' => $order->get_date_created()->date('d/m/Y'),
            'payment_method' => $order->get_payment_method_title(),
            'shipping_method' => $order->get_shipping_method(),
            'shipping_cost' => $order->get_shipping_total(),
            'total' => $order->get_total()
        );
        
        // Datos del cliente
        $billing_address = $order->get_formatted_billing_address();
        $shipping_address = $order->get_formatted_shipping_address();
        
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $customer_address = !empty($shipping_address) ? $shipping_address : $billing_address;
        $customer_phone = $order->get_billing_phone();
        $customer_email = $order->get_billing_email();
        
        // Productos
        $items = $order->get_items();
        $total_quantity = 0;
        $products_rows = '';
        
        // Ordenar productos alfabéticamente
        $sorted_items = [];
        foreach ($items as $item) {
            $sorted_items[] = $item;
        }
        usort($sorted_items, function($a, $b) {
            return strcmp($a->get_name(), $b->get_name());
        });
        
        // ==========================================
        // ===== INICIO BLOQUE MODIFICADO =====
        // ==========================================
        foreach ($sorted_items as $item) {
            $product = $item->get_product(); // Esto puede ser un WC_Product_Variation
            $quantity = $item->get_quantity();
            $total_quantity += $quantity;
            $unit_price = $item->get_total() / max($quantity, 1);
            $subtotal = $item->get_total();
            
            // 1. Obtenemos el nombre base del item
            $product_name_html = esc_html($item->get_name());

            // 2. Verificamos si es una variación
            if ($product && $product->is_type('variation')) {
                // Obtenemos los atributos de la variación
                $variation_attributes = $product->get_variation_attributes();
                
                // 3. Obtenemos los atributos formateados en una lista HTML (<dl class="variation">)
                // El 'false' al final es para que devuelva la lista HTML en lugar de texto plano
                $attributes_html = wc_get_formatted_variation($product, false);
                
                if (!empty($attributes_html)) {
                    $product_name_html .= $attributes_html;
                }
            }
            // Si no es una variación, no se añade nada más, solo el nombre.
            
            $products_rows .= '<tr>';
            $products_rows .= '<td>' . $product_name_html . '</td>';
            $products_rows .= '<td class="text-right">$' . number_format($unit_price, 2, ',', '.') . '</td>';
            $products_rows .= '<td class="text-center">' . $quantity . '</td>';
            $products_rows .= '<td class="text-right">$' . number_format($subtotal, 2, ',', '.') . '</td>';
            $products_rows .= '</tr>';
        }
        // ==========================================
        // ===== FIN BLOQUE MODIFICADO ======
        // ==========================================
        
        // Notas del pedido
        $customer_note = $order->get_customer_note();
        $order_notes = wc_get_order_notes(array('order_id' => $order->get_id(), 'type' => 'customer'));

        $notes_html = '';
        if (!empty($customer_note) || !empty($order_notes)) {
            $notes_html = '<div class="notes-section">';
            $notes_html .= '<div class="section-title">Notas del Pedido</div>';
            if (!empty($customer_note)) {
                $notes_html .= '<div class="note-item"><span class="note-label">Nota del cliente:</span> <span class="note-content">' . nl2br(esc_html($customer_note)) . '</span></div>';
            }
            foreach ($order_notes as $note) {
                $notes_html .= '<div class="note-item"><span class="note-label">Nota:</span> <span class="note-content">' . nl2br(esc_html($note->content)) . '</span></div>';
            }
            $notes_html .= '</div>';
        }

        // Logo
        $logo_html = '';
        if (!empty($logo_url)) {
            $logo_html = '<img src="' . esc_url($logo_url) . '" class="logo" alt="Logo">';
        }
        
        // HTML completo del remito
        $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remito #' . esc_html($order_data['number']) . '</title>
    <style>
        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        body { 
            font-family: "Roboto", sans-serif; 
            font-size: 13px; 
            line-height: 1.4;
            color: #333;
            background-color: #fff;
        }
        @media print {
            body { 
                margin: 0; 
                padding: 20px; 
            }
            .no-print { display: none; }
            @page { 
                margin: 1.5cm; 
                size: A4; 
            }
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 5px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .company-info {
            flex: 1;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        .logo-container {
            text-align: right;
        }
        .logo {
            max-width: 165px;
        }
        .title {
            font-size: 22px;
            font-weight: 300;
            margin: 5px 0;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .section {
            margin-bottom: 0px;
        }
        .section-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .info-box {
            padding-right:20px;
        }
        .info-label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
        }
        .info-value {
            color: #333;
        }
        .products-summary {
            font-size: 13px;
            font-weight:600;
            margin: 5px 0 5px 0;
        }
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .products-table th {
            background-color: #000;
            color: #fff;
            font-weight: 600;
            padding: 12px 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .products-table th.quantity { text-align: center; }
        .products-table th.price { text-align: right; }
        .products-table td {
            padding: 5px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        /* ===== INICIO CSS MODIFICADO ===== */
        .products-table td dl.variation {
            font-size: 11px;
            color: #555;
            margin-top: 5px;
            margin-bottom: 0;
            margin-left: 0; 
            padding-left: 15px; /* Indentación */
        }
        .products-table td dl.variation dt,
        .products-table td dl.variation dd {
            display: inline; /* Los ponemos en línea */
            margin: 0;
            padding: 0;
        }
        .products-table td dl.variation dt:after {
            content: ": "; /* Añadimos los dos puntos (ej: "Talle:") */
        }
        .products-table td dl.variation dd:after {
            content: ""; /* Para crear un salto de línea "falso" */
            display: block;
            margin-bottom: 3px;
        }
        .products-table td dl.variation dd:last-child:after {
            content: ""; /* El último no necesita salto */
            display: inline;
        }
        /* ===== FIN CSS MODIFICADO ===== */
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals {
            margin-top: 20px;
            text-align: right;
        }
        .total-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 8px;
        }
        .total-label {
            width: 150px;
            font-weight: 500;
        }
        .total-value {
            width: 120px;
            font-weight: 600;
        }
        .grand-total {
            font-size: 16px;
            color: #2c3e50;
            border-top: 2px solid #eee;
            padding-top: 10px;
            margin-top: 10px;
        }
        .footer-info {
            margin-top: 40px;
            font-size: 10px;
            text-align: center;
            color: #999;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background-color: #0366d6;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .print-button:hover {
            background-color: #0256bb;
        }
        .regenerate-button {
            display: block;
            margin: 20px auto;
            padding: 10px 20px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .regenerate-button:hover {
            background-color: #218838;
        }
        .notes-section {
            margin-top: 25px;
            padding: 12px 15px;
            background: #f9f9f9;
            border-left: 3px solid #2c3e50;
        }
        .notes-section .section-title {
            margin-bottom: 8px;
        }
        .note-item {
            margin-bottom: 6px;
            font-size: 12px;
            line-height: 1.5;
        }
        .note-label {
            font-weight: 600;
            color: #555;
        }
        .note-content {
            color: #333;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">🖨️ Imprimir</button>
    
    <div class="container">
    
        <div class="title">Remito – ' . esc_html($company_name) . '</div>
        
        <div class="header">
            <div class="company-info">
                
                <div>' . nl2br(esc_html($company_address)) . '</div>
                <div class="company-name"></div>
                ' . (!empty($company_phone) ? '<div>Tel: ' . esc_html($company_phone) . '</div>' : '') . '
                ' . (!empty($company_email) ? '<div>Email: ' . esc_html($company_email) . '</div>' : '') . '
            </div>
            <div class="logo-container">
                ' . $logo_html . '
            </div>
        </div>
        
        
        
        <div class="info-grid">
            <div class="section">
                <div class="section-title">Datos del Cliente</div>
                <div class="info-box">
                    <div class="info-label">Nombre: <span class="info-value">' . esc_html($customer_name) . '</span></div>
                    
                    <div class="info-label">Dirección: <span class="info-value">' . $customer_address . '</span></div>
                    
                    ' . (!empty($customer_phone) ? '<div class="info-label">Teléfono: <span class="info-value">' . esc_html($customer_phone) . '</span></div>' : '') . '
                    <div class="info-label">Email: <span class="info-value">' . esc_html($customer_email) . '</span></div>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">Información del Pedido</div>
                <div class="info-box">
                    <div class="info-label">Número de pedido: <span class="info-value">' . esc_html($order_data['number']) . '</span></div>
                    
                    <div class="info-label">Fecha: <span class="info-value">' . esc_html($order_data['date']) . '</span></div>
                    
                    <div class="info-label">Método de pago:</div>
                    <div class="info-value">' . esc_html($order_data['payment_method']) . '</div>
                    ' . (!empty($order_data['shipping_method']) ? '<div class="info-label">Método de envío: <span class="info-value">' . esc_html($order_data['shipping_method']) . '</span></div>' : '') . '
                </div>
            </div>
        </div>
        
        <div class="products-summary">
            Cantidad total de productos: ' . $total_quantity . '
        </div>
        
        <table class="products-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="price">Precio Unitario</th>
                    <th class="quantity">Cantidad</th>
                    <th class="price">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                ' . $products_rows . '
            </tbody>
        </table>
        
        <div class="totals">
            <div class="total-row">
                <div class="total-label">Subtotal:</div>
                <div class="total-value">$' . number_format($order->get_subtotal(), 2, ',', '.') . '</div>
            </div>';
            
            if ($order_data['shipping_cost'] > 0) {
                $html .= '
                <div class="total-row">
                    <div class="total-label">Envío:</div>
                    <div class="total-value">$' . number_format($order_data['shipping_cost'], 2, ',', '.') . '</div>
                </div>';
            }
            
            $html .= '
            <div class="total-row grand-total">
                <div class="total-label">Total:</div>
                <div class="total-value">$' . number_format($order_data['total'], 2, ',', '.') . '</div>
            </div>
        </div>
        
        ' . $notes_html . '

        <div class="footer-info">
            <p>Documento generado el ' . date('d/m/Y H:i:s') . ' | Visitá nuestra tienda online: www.growsocks.com.ar</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    // Página de configuración
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Configuración Remitos', 'wc-remitos-hpos'),
            __('Remitos', 'wc-remitos-hpos'),
            'manage_woocommerce',
            'wc-remitos-settings',
            array($this, 'settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('wc_remitos_settings', 'wc_remitos_company_name');
        register_setting('wc_remitos_settings', 'wc_remitos_company_address');
        register_setting('wc_remitos_settings', 'wc_remitos_company_phone');
        register_setting('wc_remitos_settings', 'wc_remitos_company_email');
        register_setting('wc_remitos_settings', 'wc_remitos_logo_url');
    }
    
    public function settings_page() {
        if (isset($_POST['submit']) && check_admin_referer('wc_remitos_settings_save')) {
            update_option('wc_remitos_company_name', sanitize_text_field($_POST['wc_remitos_company_name']));
            update_option('wc_remitos_company_address', sanitize_textarea_field($_POST['wc_remitos_company_address']));
            update_option('wc_remitos_company_phone', sanitize_text_field($_POST['wc_remitos_company_phone']));
            update_option('wc_remitos_company_email', sanitize_email($_POST['wc_remitos_company_email']));
            update_option('wc_remitos_logo_url', esc_url_raw($_POST['wc_remitos_logo_url']));
            echo '<div class="notice notice-success"><p>' . __('Configuración guardada.', 'wc-remitos-hpos') . '</p></div>';
        }
        
        $company_name = get_option('wc_remitos_company_name', get_bloginfo('name'));
        $company_address = get_option('wc_remitos_company_address', '');
        $company_phone = get_option('wc_remitos_company_phone', '');
        $company_email = get_option('wc_remitos_company_email', get_option('admin_email'));
        $logo_url = get_option('wc_remitos_logo_url', '');
        ?>
        <div class="wrap">
            <h1><?php _e('Configuración de Remitos', 'wc-remitos-hpos'); ?></h1>
            <p><?php _e('Configure los datos de su empresa para los remitos.', 'wc-remitos-hpos'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('wc_remitos_settings_save'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Nombre de la empresa', 'wc-remitos-hpos'); ?></th>
                        <td>
                            <input type="text" name="wc_remitos_company_name" value="<?php echo esc_attr($company_name); ?>" class="regular-text" />
                            <p class="description"><?php _e('Nombre de su empresa que aparecerá en el remito', 'wc-remitos-hpos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Dirección de la empresa', 'wc-remitos-hpos'); ?></th>
                        <td>
                            <textarea name="wc_remitos_company_address" rows="4" cols="50" class="large-text"><?php echo esc_textarea($company_address); ?></textarea>
                            <p class="description"><?php _e('Dirección completa de su empresa', 'wc-remitos-hpos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Teléfono', 'wc-remitos-hpos'); ?></th>
                        <td>
                            <input type="text" name="wc_remitos_company_phone" value="<?php echo esc_attr($company_phone); ?>" class="regular-text" />
                            <p class="description"><?php _e('Teléfono de contacto', 'wc-remitos-hpos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Email', 'wc-remitos-hpos'); ?></th>
                        <td>
                            <input type="email" name="wc_remitos_company_email" value="<?php echo esc_attr($company_email); ?>" class="regular-text" />
                            <p class="description"><?php _e('Email de contacto de la empresa', 'wc-remitos-hpos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('URL del logo', 'wc-remitos-hpos'); ?></th>
                        <td>
                            <input type="url" name="wc_remitos_logo_url" value="<?php echo esc_attr($logo_url); ?>" class="regular-text" />
                            <button type="button" class="button" id="upload-logo-button"><?php _e('Subir Logo', 'wc-remitos-hpos'); ?></button>
                            <p class="description"><?php _e('URL completa del logo de la empresa. Recomendado: 250x80 píxeles máximo.', 'wc-remitos-hpos'); ?></p>
                            <?php if (!empty($logo_url)): ?>
                                <div style="margin-top: 10px;">
                                    <img src="<?php echo esc_url($logo_url); ?>" style="max-width: 250px; max-height: 80px; border: 1px solid #ddd; padding: 5px;">
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Guardar Configuración', 'wc-remitos-hpos')); ?>
            </form>
            
            <div style="margin-top: 30px; padding: 15px; background: #f1f1f1; border-left: 4px solid #007cba;">
                <h3><?php _e('Instrucciones de Uso', 'wc-remitos-hpos'); ?></h3>
                <ul>
                    <li><?php _e('Configure los datos de su empresa en este formulario', 'wc-remitos-hpos'); ?></li>
                    <li><?php _e('Vaya a WooCommerce > Pedidos para ver todos los pedidos', 'wc-remitos-hpos'); ?></li>
                    <li><?php _e('En la columna "Remito" haga clic en el botón "PDF" para generar el remito', 'wc-remitos-hpos'); ?></li>
                    <li><?php _e('También puede generar el remito desde la página de edición del pedido individual', 'wc-remitos-hpos'); ?></li>
                    <li><?php _e('El remito se abrirá en una nueva ventana donde podrá imprimirlo o guardarlo como PDF', 'wc-remitos-hpos'); ?></li>
                </ul>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                <h3><?php _e('Compatibilidad HPOS', 'wc-remitos-hpos'); ?></h3>
                <p><?php _e('Este plugin es totalmente compatible con High-Performance Order Storage (HPOS) de WooCommerce.', 'wc-remitos-hpos'); ?></p>
                <p>
                    <strong><?php _e('Estado HPOS:', 'wc-remitos-hpos'); ?></strong> 
                    <?php if (wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()): ?>
                        <span style="color: green;">✓ <?php _e('Activado', 'wc-remitos-hpos'); ?></span>
                    <?php else: ?>
                        <span style="color: orange;">⚠ <?php _e('Desactivado (usando modo legacy)', 'wc-remitos-hpos'); ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#upload-logo-button').click(function(e) {
                e.preventDefault();
                
                var mediaUploader = wp.media({
                    title: 'Seleccionar Logo',
                    button: {
                        text: 'Usar este logo'
                    },
                    multiple: false,
                    library: {
                        type: 'image'
                    }
                });
                
                mediaUploader.on('select', function() {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    $('input[name="wc_remitos_logo_url"]').val(attachment.url);
                });
                
                mediaUploader.open();
            });
        });
        </script>
        <?php
    }
}

// Inicializar el plugin
WC_Remitos_HPOS::get_instance();

// Agregar enlace a configuración en la página de plugins
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-remitos-settings') . '">' . __('Configuración', 'wc-remitos-hpos') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Hook para cargar scripts del media uploader en la página de configuración
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'woocommerce_page_wc-remitos-settings') {
        wp_enqueue_media();
    }
});

// Agregar estilos admin
add_action('admin_head', function() {
    $current_screen = get_current_screen();
    if ($current_screen && (strpos($current_screen->id, 'wc-orders') !== false || $current_screen->id === 'shop_order')) {
        echo '<style>
        .generate-remito {
            background: #2271b1 !important;
            color: white !important;
            border-color: #2271b1 !important;
        }
        .generate-remito:hover {
            background: #135e96 !important;
            border-color: #135e96 !important;
        }
        .generate-remito:disabled {
            background: #ddd !important;
            color: #999 !important;
            border-color: #ddd !important;
        }
        .remito-status {
            font-size: 12px;
            font-weight: bold;
        }
        #wc-remito-actions .inside {
            padding: 12px;
        }
        </style>';
    }
});

// Función de utilidad para limpiar archivos antiguos
add_action('wp_scheduled_delete', function() {
    $upload_dir = wp_upload_dir();
    $remitos_dir = $upload_dir['basedir'] . '/remitos/';
    
    if (file_exists($remitos_dir)) {
        $files = glob($remitos_dir . 'remito-*.html');
        $now = time();
        
        foreach ($files as $file) {
            // Eliminar archivos de más de 24 horas
            if (filemtime($file) < $now - 86400) {
                unlink($file);
            }
        }
    }
});
?>