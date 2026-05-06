<?php
/**
 * Plugin Name: Descuentos por Volumen para Fábrica de Medias (HPOS)
 * Plugin URI: https://francoperdomo.com.ar
 * Description: Aplica descuentos escalonados optimizados para alto rendimiento
 * Version: 1.1.0
 * Author: Franco Perdomo
 * Author URI: https://francoperdomo.com.ar
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: descuentos-volumen-medias
 * Domain Path: /languages
 * WC requires at least: 6.8
 * WC tested up to: 8.4
 * Requires PHP: 7.4
 * HPOS compatible: yes
 */

defined('ABSPATH') || exit;

// Declaración temprana de compatibilidad con HPOS
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Verificar dependencias
add_action('plugins_loaded', 'dvm_hpos_check_dependencies');

function dvm_hpos_check_dependencies() {
    // Verificar WooCommerce activo
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'dvm_hpos_woocommerce_missing_notice');
        return;
    }
    
    // Verificar versión mínima de WooCommerce para HPOS
    if (version_compare(WC_VERSION, '6.8', '<')) {
        add_action('admin_notices', 'dvm_hpos_woocommerce_version_notice');
        return;
    }
    
    // Iniciar funcionalidad principal
    add_action('woocommerce_cart_calculate_fees', 'dvm_hpos_aplicar_descuento_por_volumen', 20, 1);
}

function dvm_hpos_woocommerce_missing_notice() {
    echo '<div class="error"><p>';
    printf(
        esc_html__('Descuentos por Volumen requiere WooCommerce 6.8+. Por favor instala y activa %sWooCommerce%s.', 'descuentos-volumen-medias'),
        '<a href="' . esc_url(admin_url('plugin-install.php?tab=search&s=woocommerce')) . '">',
        '</a>'
    );
    echo '</p></div>';
}

function dvm_hpos_woocommerce_version_notice() {
    echo '<div class="error"><p>';
    printf(
        esc_html__('Descuentos por Volumen requiere WooCommerce 6.8 o superior. Tu versión actual es %s. Por favor actualiza.', 'descuentos-volumen-medias'),
        WC_VERSION
    );
    echo '</p></div>';
}

/**
 * Aplica descuento optimizado con caché de carrito
 */
function dvm_hpos_aplicar_descuento_por_volumen($cart) {
    // Verificar si el sistema está activo
    if (!get_option('dvm_hpos_active', true)) {
        return;
    }
    
    // No procesar en backend o carrito vacío
    if (is_admin() && !defined('DOING_AJAX')) return;
    if ($cart->is_empty()) return;

    // Verificar si algún cupón activo bloquea el descuento por volumen
    if (dvm_hpos_check_coupon_blocks_discount($cart)) {
        return;
    }

    // Usar caché de carrito para optimización
    $cache_key = 'dvm_total_docenas_' . md5(wp_json_encode($cart->get_cart_contents()));
    $total_docenas = wp_cache_get($cache_key, 'dvm_descuentos');

    if (false === $total_docenas) {
        $total_docenas = dvm_hpos_calcular_total_docenas($cart);
        wp_cache_set($cache_key, $total_docenas, 'dvm_descuentos', 300); // Cache 5 minutos
    }

    // Obtener escalas dinámicas desde la base de datos
    $escalas = apply_filters('dvm_hpos_escalas_descuento', dvm_hpos_get_discount_levels());

    // Determinar descuento (krsort optimizado para grandes escalas)
    $porcentaje = 0;
    krsort($escalas, SORT_NUMERIC);
    
    foreach ($escalas as $umbral => $descuento) {
        if ($total_docenas >= $umbral) {
            $porcentaje = $descuento;
            break;
        }
    }

    // Aplicar descuento si corresponde
    if ($porcentaje > 0) {
        $subtotal = $cart->get_subtotal();
        $descuento = $subtotal * ($porcentaje / 100);
        
        $fee_label = sprintf('%s (%s%%)', dvm_hpos_get_branding()['discount_name'], $porcentaje);
        $cart->add_fee($fee_label, -1 * abs($descuento), false, '');
    }
}

/**
 * Cálculo optimizado de docenas
 */
function dvm_hpos_calcular_total_docenas($cart) {
    $total = 0;
    $cart_contents = $cart->get_cart_contents();
    
    // Pre-cargar IDs de productos excluidos
    $excluidos = apply_filters('dvm_hpos_productos_excluidos', []);
    $excluidos = array_flip($excluidos);

    foreach ($cart_contents as $item) {
        // Verificación rápida de exclusión
        if (isset($excluidos[$item['product_id']]) || 
            isset($excluidos[$item['variation_id']])) {
            continue;
        }
        
        $total += $item['quantity'];
    }
    
    return $total;
}

/**
 * Manejo de cache durante actualizaciones
 */
add_action('woocommerce_cart_item_removed', 'dvm_hpos_clear_cache');
add_action('woocommerce_cart_item_restored', 'dvm_hpos_clear_cache');
add_action('woocommerce_cart_item_set_quantity', 'dvm_hpos_clear_cache');

function dvm_hpos_clear_cache() {
    dvm_hpos_clear_discount_cache();
}

/**
 * Soporte para WooCommerce Analytics
 */
add_filter('woocommerce_analytics_fee_query_filters', 'dvm_hpos_analytics_filter');

function dvm_hpos_analytics_filter($filters) {
    $filters[] = [
        'filter' => 'fee_name',
        'value' => __('Descuento por Docena', 'descuentos-volumen-medias')
    ];
    return $filters;
}

/**
 * Funciones para gestionar niveles de descuento dinámicos
 */

/**
 * Obtener niveles de descuento desde la base de datos
 */
function dvm_hpos_get_discount_levels() {
    $default_levels = [
        200 => 16.6,
        100 => 13.3,
        50  => 10.0
    ];
    
    $saved_levels = get_option('dvm_hpos_discount_levels', $default_levels);
    
    // Asegurar que sea un array válido
    if (!is_array($saved_levels) || empty($saved_levels)) {
        $saved_levels = $default_levels;
        update_option('dvm_hpos_discount_levels', $saved_levels);
    }
    
    return $saved_levels;
}

/**
 * Guardar niveles de descuento
 */
function dvm_hpos_save_discount_levels($levels) {
    // Validar y sanitizar datos
    $clean_levels = [];
    
    if (is_array($levels)) {
        foreach ($levels as $quantity => $discount) {
            $quantity = absint($quantity);
            $discount = floatval($discount);
            
            if ($quantity > 0 && $discount >= 0 && $discount <= 100) {
                $clean_levels[$quantity] = $discount;
            }
        }
    }
    
    // Ordenar por cantidad descendente
    krsort($clean_levels, SORT_NUMERIC);
    
    $result = update_option('dvm_hpos_discount_levels', $clean_levels);
    
    // Limpiar caché cuando se modifican los niveles
    if ($result) {
        dvm_hpos_clear_discount_cache();
    }
    
    return $result;
}

/**
 * Limpiar caché de descuentos
 */
function dvm_hpos_clear_discount_cache() {
    wp_cache_flush_group('dvm_descuentos');
    // También limpiar cualquier caché de objeto persistente
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

/**
 * Agregar nivel de descuento
 */
function dvm_hpos_add_discount_level($quantity, $discount) {
    $levels = dvm_hpos_get_discount_levels();
    
    $quantity = absint($quantity);
    $discount = floatval($discount);
    
    if ($quantity > 0 && $discount >= 0 && $discount <= 100) {
        $levels[$quantity] = $discount;
        return dvm_hpos_save_discount_levels($levels);
    }
    
    return false;
}

/**
 * Eliminar nivel de descuento
 */
function dvm_hpos_delete_discount_level($quantity) {
    $levels = dvm_hpos_get_discount_levels();
    $quantity = absint($quantity);
    
    if (isset($levels[$quantity])) {
        unset($levels[$quantity]);
        return dvm_hpos_save_discount_levels($levels);
    }
    
    return false;
}

/**
 * Funciones de personalización (branding / marca blanca)
 */
function dvm_hpos_get_branding_defaults() {
    return [
        'unit_label'      => 'unidades',
        'discount_name'   => 'Descuento por Volumen',
        'shortcode_title' => 'Niveles de Descuento',
        'shortcode_desc'  => 'Compra más y ahorra más.',
        'color_primary'   => '#2c3e50',
        'color_secondary' => '#3498db',
        'color_success'   => '#27ae60',
        'color_warning'   => '#f39c12',
        'color_accent'    => '#e74c3c',
        'dark_primary'         => '#ecf0f1',
        'dark_secondary'       => '#5dade2',
        'dark_success'         => '#2ecc71',
        'dark_warning'         => '#f39c12',
        'dark_accent'          => '#e74c3c',
        'coupon_active_message' => 'Tenés un cupón activo. El descuento por cantidad no aplica en este pedido.',
    ];
}

function dvm_hpos_get_branding() {
    return wp_parse_args(get_option('dvm_hpos_branding', []), dvm_hpos_get_branding_defaults());
}

function dvm_hpos_unit_label() {
    $b = dvm_hpos_get_branding();
    return sanitize_text_field($b['unit_label']);
}

function dvm_hpos_save_branding($data) {
    $defaults     = dvm_hpos_get_branding_defaults();
    $clean        = [];
    $text_fields  = ['unit_label', 'discount_name', 'shortcode_title', 'shortcode_desc', 'coupon_active_message'];
    $color_fields = ['color_primary', 'color_secondary', 'color_success', 'color_warning', 'color_accent',
                     'dark_primary', 'dark_secondary', 'dark_success', 'dark_warning', 'dark_accent'];

    foreach ($text_fields as $field) {
        $value        = isset($data[$field]) ? sanitize_text_field($data[$field]) : '';
        $clean[$field] = $value !== '' ? $value : $defaults[$field];
    }

    foreach ($color_fields as $field) {
        $value        = isset($data[$field]) ? sanitize_hex_color($data[$field]) : '';
        $clean[$field] = $value ?: $defaults[$field];
    }

    return update_option('dvm_hpos_branding', $clean);
}

function dvm_hpos_get_custom_css_vars() {
    $b = dvm_hpos_get_branding();
    return "
        :root {
            --joga-primary:   {$b['color_primary']};
            --joga-secondary: {$b['color_secondary']};
            --joga-success:   {$b['color_success']};
            --joga-warning:   {$b['color_warning']};
            --joga-accent:    {$b['color_accent']};
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --joga-primary:   {$b['dark_primary']};
                --joga-secondary: {$b['dark_secondary']};
                --joga-success:   {$b['dark_success']};
                --joga-warning:   {$b['dark_warning']};
                --joga-accent:    {$b['dark_accent']};
            }
        }
    ";
}

/**
 * Reglas de compatibilidad con cupones
 */
function dvm_hpos_get_coupon_rules_defaults() {
    return [
        'special_coupons'        => [],
        'default_behavior'       => 'combine',
        'always_combine_coupons' => [],
    ];
}

function dvm_hpos_get_coupon_rules() {
    return wp_parse_args(get_option('dvm_hpos_coupon_rules', []), dvm_hpos_get_coupon_rules_defaults());
}

function dvm_hpos_save_coupon_rules($data) {
    $clean = dvm_hpos_get_coupon_rules_defaults();

    foreach (['special_coupons', 'always_combine_coupons'] as $field) {
        $clean[$field] = [];
        $raw = !empty($data[$field]) ? $data[$field] : '';
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (is_array($raw)) {
            foreach ($raw as $code) {
                $code = strtoupper(sanitize_text_field(trim($code)));
                if ($code !== '') {
                    $clean[$field][] = $code;
                }
            }
            $clean[$field] = array_values(array_unique($clean[$field]));
        }
    }

    $clean['default_behavior'] = in_array($data['default_behavior'] ?? '', ['combine', 'no_combine'])
        ? sanitize_text_field($data['default_behavior'])
        : 'combine';

    return update_option('dvm_hpos_coupon_rules', $clean);
}

/**
 * Devuelve true si algún cupón activo en el carrito debe bloquear el descuento por volumen.
 */
function dvm_hpos_check_coupon_blocks_discount($cart) {
    $applied = $cart->get_applied_coupons();
    if (empty($applied)) {
        return false;
    }

    $rules           = dvm_hpos_get_coupon_rules();
    $applied_upper   = array_map('strtoupper', $applied);
    $special_upper   = array_map('strtoupper', $rules['special_coupons']);
    $always_upper    = array_map('strtoupper', $rules['always_combine_coupons']);
    $default_combine = $rules['default_behavior'] === 'combine';

    foreach ($applied_upper as $code) {
        if ($default_combine) {
            if (in_array($code, $special_upper, true)) {
                return true;
            }
        } else {
            if (!in_array($code, $always_upper, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Configuración en el admin
 */
add_action('admin_init', 'dvm_hpos_register_settings');
add_action('admin_menu', 'dvm_hpos_add_admin_menu');

function dvm_hpos_register_settings() {
    register_setting('dvm_hpos_settings', 'dvm_hpos_active', [
        'type' => 'boolean',
        'default' => true,
        'show_in_rest' => true
    ]);
}

/**
 * Agregar menú de administración
 */
function dvm_hpos_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        __('Niveles de Descuento', 'descuentos-volumen-medias'),
        __('Niveles de Descuento', 'descuentos-volumen-medias'),
        'manage_woocommerce',
        'dvm-discount-levels',
        'dvm_hpos_admin_page'
    );
}

/**
 * Página de administración
 */
function dvm_hpos_admin_page() {
    if (isset($_POST['dvm_action'])) {
        dvm_hpos_process_admin_actions();
    }

    $levels       = dvm_hpos_get_discount_levels();
    $is_active    = get_option('dvm_hpos_active', true);
    $branding     = dvm_hpos_get_branding();
    $coupon_rules = dvm_hpos_get_coupon_rules();
    ?>
    <div class="wrap">
        <h1><?php printf(esc_html__('Gestión de Niveles — %s', 'descuentos-volumen-medias'), esc_html($branding['discount_name'])); ?></h1>
        
        <?php if (isset($_GET['message'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($_GET['message']); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($_GET['error']); ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Configuración de activación del sistema -->
        <div class="dvm-activation-section" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px;">
            <h2><?php echo esc_html__('Configuración del Sistema', 'descuentos-volumen-medias'); ?></h2>
            
            <form method="post" style="margin-bottom: 0;">
                <?php wp_nonce_field('dvm_admin_action', 'dvm_nonce'); ?>
                <input type="hidden" name="dvm_action" value="toggle_active">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dvm_active"><?php echo esc_html__('Estado del Sistema', 'descuentos-volumen-medias'); ?></label>
                        </th>
                        <td>
                            <label for="dvm_active" style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="dvm_active" name="dvm_active" value="1"
                                       <?php checked($is_active, true); ?>
                                       onchange="this.form.submit();">
                                <span class="dvm-status-indicator" style="
                                    padding: 4px 12px;
                                    border-radius: 12px;
                                    font-size: 12px;
                                    font-weight: bold;
                                    color: white;
                                    background-color: <?php echo $is_active ? '#46b450' : '#dc3232'; ?>
                                ">
                                    <?php echo $is_active ? esc_html__('ACTIVO', 'descuentos-volumen-medias') : esc_html__('INACTIVO', 'descuentos-volumen-medias'); ?>
                                </span>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Activar o desactivar el sistema de descuentos por docena', 'descuentos-volumen-medias'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        
        <div class="dvm-admin-container" style="display: flex; gap: 20px; margin-top: 20px;">
            <!-- Lista de niveles existentes -->
            <div class="dvm-levels-list" style="flex: 2;">
                <h2><?php echo esc_html__('Niveles de Descuento Actuales', 'descuentos-volumen-medias'); ?></h2>
                
                <?php if (empty($levels)): ?>
                    <p><?php echo esc_html__('No hay niveles de descuento configurados.', 'descuentos-volumen-medias'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Cantidad Mínima', 'descuentos-volumen-medias'); ?></th>
                                <th><?php echo esc_html__('Descuento (%)', 'descuentos-volumen-medias'); ?></th>
                                <th><?php echo esc_html__('Acciones', 'descuentos-volumen-medias'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($levels as $quantity => $discount): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($quantity); ?></strong> <?php echo esc_html($branding['unit_label']); ?></td>
                                    <td><?php echo esc_html(number_format($discount, 1)); ?>%</td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <?php wp_nonce_field('dvm_admin_action', 'dvm_nonce'); ?>
                                            <input type="hidden" name="dvm_action" value="delete">
                                            <input type="hidden" name="quantity" value="<?php echo esc_attr($quantity); ?>">
                                            <button type="submit" class="button button-small dvm-delete-level"
                                                    data-quantity="<?php echo esc_attr($quantity); ?>"
                                                    data-discount="<?php echo esc_attr($discount); ?>">
                                                <?php echo esc_html__('Eliminar', 'descuentos-volumen-medias'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Formulario para agregar nuevo nivel -->
            <div class="dvm-add-level" style="flex: 1;">
                <h2><?php echo esc_html__('Agregar Nuevo Nivel', 'descuentos-volumen-medias'); ?></h2>
                
                <form method="post" class="dvm-form">
                    <?php wp_nonce_field('dvm_admin_action', 'dvm_nonce'); ?>
                    <input type="hidden" name="dvm_action" value="add">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="quantity"><?php echo esc_html__('Cantidad Mínima', 'descuentos-volumen-medias'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="quantity" name="quantity" min="1" step="1" required 
                                       class="regular-text" placeholder="ej: 25">
                                <p class="description"><?php echo esc_html__('Cantidad mínima de productos para aplicar este descuento', 'descuentos-volumen-medias'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="discount"><?php echo esc_html__('Descuento (%)', 'descuentos-volumen-medias'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="discount" name="discount" min="0" max="100" step="0.1" required 
                                       class="regular-text" placeholder="ej: 5.0">
                                <p class="description"><?php echo esc_html__('Porcentaje de descuento (0-100)', 'descuentos-volumen-medias'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Agregar Nivel', 'descuentos-volumen-medias'), 'primary', 'submit', false); ?>
                </form>
                
                <hr style="margin: 30px 0;">
                
                <h3><?php echo esc_html__('Información', 'descuentos-volumen-medias'); ?></h3>
                <div class="dvm-info" style="background: #f1f1f1; padding: 15px; border-radius: 4px;">
                    <p><strong><?php echo esc_html__('¿Cómo funciona?', 'descuentos-volumen-medias'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php echo esc_html__('Los descuentos se aplican automáticamente según la cantidad total en el carrito', 'descuentos-volumen-medias'); ?></li>
                        <li><?php echo esc_html__('Se aplica el mayor descuento disponible para la cantidad', 'descuentos-volumen-medias'); ?></li>
                        <li><?php echo esc_html__('Los niveles se ordenan automáticamente de mayor a menor cantidad', 'descuentos-volumen-medias'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <hr style="margin: 40px 0;">

        <!-- Sección de Cupones -->
        <div class="dvm-coupon-section" id="dvm-coupon-section">
            <div class="dvm-coupon-header">
                <span class="dashicons dashicons-tickets-alt dvm-coupon-icon"></span>
                <div>
                    <h2 style="margin:0 0 4px;"><?php esc_html_e('Cupones y descuentos por cantidad', 'descuentos-volumen-medias'); ?></h2>
                    <p class="dvm-coupon-subtitle"><?php esc_html_e('Controlá cómo interactúan los cupones con el descuento por cantidad.', 'descuentos-volumen-medias'); ?></p>
                </div>
            </div>

            <form method="post" id="dvm-coupon-form">
                <?php wp_nonce_field('dvm_admin_action', 'dvm_nonce'); ?>
                <input type="hidden" name="dvm_action" value="save_coupon_rules">

                <!-- Cupones de promociones especiales -->
                <div class="dvm-cfield-group">
                    <div class="dvm-cfield-label">
                        <span class="dvm-clabel-icon">🎫</span>
                        <strong><?php esc_html_e('Cupones de promociones especiales', 'descuentos-volumen-medias'); ?></strong>
                        <span class="dvm-cbadge dvm-cbadge-red"><?php esc_html_e('No combinan con el descuento por cantidad', 'descuentos-volumen-medias'); ?></span>
                    </div>
                    <p class="dvm-cfield-desc"><?php esc_html_e('Escribí los cupones de tus promos especiales: Día de la Madre, Navidad, liquidaciones, etc. Cuando un cliente use alguno de estos cupones, el descuento por cantidad se desactiva automáticamente.', 'descuentos-volumen-medias'); ?></p>
                    <div class="dvm-tag-field" id="special-tag-field" data-target="special-coupons-value" data-color="red">
                        <div class="dvm-tags-wrap" id="special-tags-wrap">
                            <?php foreach ($coupon_rules['special_coupons'] as $code): ?>
                            <span class="dvm-ctag dvm-ctag-red">
                                <?php echo esc_html($code); ?>
                                <button type="button" class="dvm-ctag-remove" data-value="<?php echo esc_attr($code); ?>" aria-label="Quitar">&times;</button>
                            </span>
                            <?php endforeach; ?>
                            <input type="text" class="dvm-ctag-input" id="special-coupon-input" placeholder="<?php esc_attr_e('Ej: DIADLAMADRE25 — presioná Enter para agregar', 'descuentos-volumen-medias'); ?>" autocomplete="off">
                        </div>
                    </div>
                    <input type="hidden" name="special_coupons" id="special-coupons-value" value="<?php echo esc_attr(implode(',', $coupon_rules['special_coupons'])); ?>">
                </div>

                <!-- Comportamiento para el resto de los cupones -->
                <div class="dvm-cfield-group">
                    <div class="dvm-cfield-label">
                        <span class="dvm-clabel-icon">⚙️</span>
                        <strong><?php esc_html_e('¿Qué pasa con el resto de los cupones?', 'descuentos-volumen-medias'); ?></strong>
                    </div>
                    <div class="dvm-radio-cards" id="dvm-radio-cards">
                        <label class="dvm-radio-card <?php echo $coupon_rules['default_behavior'] === 'combine' ? 'dvm-radio-card--active' : ''; ?>">
                            <input type="radio" name="default_behavior" value="combine" <?php checked($coupon_rules['default_behavior'], 'combine'); ?>>
                            <div class="dvm-radio-body">
                                <div class="dvm-radio-title"><?php esc_html_e('Se combinan con el descuento por cantidad', 'descuentos-volumen-medias'); ?> <span class="dvm-crecommended"><?php esc_html_e('Recomendado', 'descuentos-volumen-medias'); ?></span></div>
                                <div class="dvm-radio-desc"><?php esc_html_e('El cliente recibe el cupón Y el descuento por cantidad. Solo los cupones de la lista de arriba desactivan el descuento.', 'descuentos-volumen-medias'); ?></div>
                            </div>
                        </label>
                        <label class="dvm-radio-card <?php echo $coupon_rules['default_behavior'] === 'no_combine' ? 'dvm-radio-card--active' : ''; ?>">
                            <input type="radio" name="default_behavior" value="no_combine" <?php checked($coupon_rules['default_behavior'], 'no_combine'); ?>>
                            <div class="dvm-radio-body">
                                <div class="dvm-radio-title"><?php esc_html_e('También desactivan el descuento por cantidad', 'descuentos-volumen-medias'); ?></div>
                                <div class="dvm-radio-desc"><?php esc_html_e('Cualquier cupón desactiva el descuento por cantidad, salvo los que agregues en la lista de excepción de abajo.', 'descuentos-volumen-medias'); ?></div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Cupones que SIEMPRE combinan (solo visible en modo no_combine) -->
                <div class="dvm-cfield-group dvm-cfield-conditional" id="always-combine-group" style="<?php echo $coupon_rules['default_behavior'] === 'no_combine' ? '' : 'display:none;'; ?>">
                    <div class="dvm-cfield-label">
                        <span class="dvm-clabel-icon">✅</span>
                        <strong><?php esc_html_e('Cupones que siempre se combinan con el descuento por cantidad', 'descuentos-volumen-medias'); ?></strong>
                        <span class="dvm-cbadge dvm-cbadge-green"><?php esc_html_e('Siempre combinan', 'descuentos-volumen-medias'); ?></span>
                    </div>
                    <p class="dvm-cfield-desc"><?php esc_html_e('Estos cupones se pueden usar junto con el descuento por cantidad aunque el comportamiento general sea "no combinar". Ideal para cupones de clientes VIP, mayoristas o programas de fidelidad.', 'descuentos-volumen-medias'); ?></p>
                    <div class="dvm-tag-field" id="always-tag-field" data-target="always-coupons-value" data-color="green">
                        <div class="dvm-tags-wrap" id="always-tags-wrap">
                            <?php foreach ($coupon_rules['always_combine_coupons'] as $code): ?>
                            <span class="dvm-ctag dvm-ctag-green">
                                <?php echo esc_html($code); ?>
                                <button type="button" class="dvm-ctag-remove" data-value="<?php echo esc_attr($code); ?>" aria-label="Quitar">&times;</button>
                            </span>
                            <?php endforeach; ?>
                            <input type="text" class="dvm-ctag-input" id="always-coupon-input" placeholder="<?php esc_attr_e('Ej: FIDELIDAD10 — presioná Enter para agregar', 'descuentos-volumen-medias'); ?>" autocomplete="off">
                        </div>
                    </div>
                    <input type="hidden" name="always_combine_coupons" id="always-coupons-value" value="<?php echo esc_attr(implode(',', $coupon_rules['always_combine_coupons'])); ?>">
                </div>

                <div class="dvm-coupon-actions">
                    <?php submit_button(__('Guardar configuración de cupones', 'descuentos-volumen-medias'), 'primary', 'submit_coupon_rules', false); ?>
                </div>
            </form>
        </div>

        <hr style="margin: 40px 0;">

        <!-- Sección de personalización -->
        <div class="dvm-branding-section" style="max-width: 900px;">
            <h2><?php esc_html_e('Personalización', 'descuentos-volumen-medias'); ?></h2>

            <form method="post">
                <?php wp_nonce_field('dvm_admin_action', 'dvm_nonce'); ?>
                <input type="hidden" name="dvm_action" value="save_branding">

                <!-- Textos -->
                <h3 style="margin-top: 1.5em;"><?php esc_html_e('Textos', 'descuentos-volumen-medias'); ?></h3>
                <table class="form-table" style="max-width: 700px;">
                    <tr>
                        <th scope="row">
                            <label for="unit_label"><?php esc_html_e('Denominación de unidad', 'descuentos-volumen-medias'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="unit_label" name="unit_label"
                                   value="<?php echo esc_attr($branding['unit_label']); ?>"
                                   class="regular-text" placeholder="unidades">
                            <p class="description"><?php esc_html_e('Ej: unidades, docenas, pares, cajas. Se usa en todos los textos del plugin.', 'descuentos-volumen-medias'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="discount_name"><?php esc_html_e('Nombre del descuento', 'descuentos-volumen-medias'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="discount_name" name="discount_name"
                                   value="<?php echo esc_attr($branding['discount_name']); ?>"
                                   class="regular-text" placeholder="Descuento por Volumen">
                            <p class="description"><?php esc_html_e('Aparece en el carrito como "Nombre (X%)". Ej: Descuento por Caja, Descuento Mayorista.', 'descuentos-volumen-medias'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="shortcode_title"><?php esc_html_e('Título del shortcode', 'descuentos-volumen-medias'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="shortcode_title" name="shortcode_title"
                                   value="<?php echo esc_attr($branding['shortcode_title']); ?>"
                                   class="regular-text" placeholder="Niveles de Descuento">
                            <p class="description"><?php esc_html_e('Título que se muestra en el widget [mostrar_descuentos_volumen].', 'descuentos-volumen-medias'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="shortcode_desc"><?php esc_html_e('Descripción del shortcode', 'descuentos-volumen-medias'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="shortcode_desc" name="shortcode_desc"
                                   value="<?php echo esc_attr($branding['shortcode_desc']); ?>"
                                   class="regular-text" placeholder="Compra más y ahorra más.">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="coupon_active_message"><?php esc_html_e('Mensaje con cupón activo', 'descuentos-volumen-medias'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="coupon_active_message" name="coupon_active_message"
                                   value="<?php echo esc_attr($branding['coupon_active_message']); ?>"
                                   class="large-text">
                            <p class="description"><?php esc_html_e('Texto que ve el cliente en el indicador de progreso cuando hay un cupón que desactiva el descuento por cantidad.', 'descuentos-volumen-medias'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Colores modo claro -->
                <h3 style="margin-top: 2em;"><?php esc_html_e('Colores — Modo Claro', 'descuentos-volumen-medias'); ?></h3>
                <div class="dvm-color-grid">
                    <?php
                    $light_colors = [
                        'color_primary'   => __('Principal', 'descuentos-volumen-medias'),
                        'color_secondary' => __('Secundario', 'descuentos-volumen-medias'),
                        'color_success'   => __('Éxito', 'descuentos-volumen-medias'),
                        'color_warning'   => __('Advertencia', 'descuentos-volumen-medias'),
                        'color_accent'    => __('Acento', 'descuentos-volumen-medias'),
                    ];
                    foreach ($light_colors as $key => $label): ?>
                        <div class="dvm-color-item">
                            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                            <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr($branding[$key]); ?>"
                                   class="dvm-color-picker" data-default-color="<?php echo esc_attr(dvm_hpos_get_branding_defaults()[$key]); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Colores modo oscuro -->
                <h3 style="margin-top: 2em;"><?php esc_html_e('Colores — Modo Oscuro', 'descuentos-volumen-medias'); ?></h3>
                <div class="dvm-color-grid">
                    <?php
                    $dark_colors = [
                        'dark_primary'   => __('Principal', 'descuentos-volumen-medias'),
                        'dark_secondary' => __('Secundario', 'descuentos-volumen-medias'),
                        'dark_success'   => __('Éxito', 'descuentos-volumen-medias'),
                        'dark_warning'   => __('Advertencia', 'descuentos-volumen-medias'),
                        'dark_accent'    => __('Acento', 'descuentos-volumen-medias'),
                    ];
                    foreach ($dark_colors as $key => $label): ?>
                        <div class="dvm-color-item">
                            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                            <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr($branding[$key]); ?>"
                                   class="dvm-color-picker" data-default-color="<?php echo esc_attr(dvm_hpos_get_branding_defaults()[$key]); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top: 2em;">
                    <?php submit_button(__('Guardar Personalización', 'descuentos-volumen-medias'), 'primary', 'submit_branding', false); ?>
                </p>
            </form>
        </div>
    </div>

    <style>
        .dvm-admin-container { max-width: 1200px; }
        .dvm-form .form-table th { width: 150px; }
        .dvm-info ul { list-style-type: disc; }
        .dvm-levels-list table { margin-top: 10px; }
        .dvm-color-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 10px;
        }
        .dvm-color-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 120px;
        }
        .dvm-color-item label {
            font-weight: 600;
            font-size: 13px;
            color: #1d2327;
        }
        .wp-picker-container { display: block; }

        /* ── Sección de cupones ──────────────────────────────── */
        .dvm-coupon-section {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 6px;
            padding: 24px 28px;
            max-width: 900px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .dvm-coupon-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid #f0f0f1;
        }
        .dvm-coupon-icon {
            font-size: 26px !important;
            color: #7c3aed;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .dvm-coupon-subtitle {
            margin: 2px 0 0;
            color: #787c82;
            font-size: 13px;
        }
        /* Grupos de campo */
        .dvm-cfield-group {
            margin-bottom: 26px;
        }
        .dvm-cfield-label {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            margin-bottom: 7px;
            font-size: 14px;
            color: #1d2327;
        }
        .dvm-clabel-icon { font-size: 15px; }
        .dvm-cfield-desc {
            margin: 0 0 10px;
            color: #787c82;
            font-size: 12.5px;
            line-height: 1.55;
        }
        /* Badges */
        .dvm-cbadge {
            font-size: 10.5px;
            font-weight: 600;
            padding: 2px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .3px;
            white-space: nowrap;
        }
        .dvm-cbadge-red   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .dvm-cbadge-green { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        /* Tag field */
        .dvm-tag-field {
            border: 1.5px solid #dcdcde;
            border-radius: 5px;
            padding: 7px 9px;
            background: #fafafa;
            min-height: 42px;
            cursor: text;
            transition: border-color .18s, box-shadow .18s;
        }
        .dvm-tag-field:focus-within {
            border-color: #2271b1;
            box-shadow: 0 0 0 2.5px rgba(34,113,177,.15);
            background: #fff;
        }
        .dvm-tags-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .dvm-ctag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            letter-spacing: .6px;
            animation: dvmTagPop .14s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes dvmTagPop {
            from { transform: scale(.75); opacity:0; }
            to   { transform: scale(1);   opacity:1; }
        }
        .dvm-ctag-red   { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; }
        .dvm-ctag-green { background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
        .dvm-ctag-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            font-size: 16px;
            padding: 0;
            line-height: 1;
            opacity: .55;
            transition: opacity .15s;
            display: flex;
            align-items: center;
        }
        .dvm-ctag-remove:hover { opacity: 1; }
        .dvm-ctag-input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 13px;
            min-width: 200px;
            flex: 1;
            padding: 3px 2px;
            color: #1d2327;
        }
        .dvm-ctag-input::placeholder { color: #a7aaad; }
        /* Radio cards */
        .dvm-radio-cards {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .dvm-radio-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px 16px;
            border: 1.5px solid #e2e4e7;
            border-radius: 7px;
            cursor: pointer;
            transition: border-color .18s, background .18s, box-shadow .18s;
            background: #fff;
        }
        .dvm-radio-card:hover {
            border-color: #2271b1;
            background: #f8fbff;
        }
        .dvm-radio-card--active {
            border-color: #2271b1;
            background: #f0f7ff;
            box-shadow: 0 0 0 2px rgba(34,113,177,.1);
        }
        .dvm-radio-card input[type=radio] {
            margin-top: 2px;
            flex-shrink: 0;
            accent-color: #2271b1;
        }
        .dvm-radio-body { display:flex; flex-direction:column; gap:3px; }
        .dvm-radio-title { font-size:13px; font-weight:600; color:#1d2327; }
        .dvm-radio-desc  { font-size:12px; color:#787c82; line-height:1.45; }
        .dvm-crecommended {
            display: inline-block;
            background: #ecfdf5;
            color: #059669;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-left: 5px;
            vertical-align: middle;
        }
        /* Grupo condicional */
        .dvm-cfield-conditional {
            padding: 18px 20px;
            background: #f9fafb;
            border: 1px dashed #c8ccd0;
            border-radius: 6px;
        }
        /* Acciones */
        .dvm-coupon-actions {
            padding-top: 10px;
            margin-top: 6px;
            border-top: 1px solid #f0f0f1;
        }
    </style>
    <?php
}

/**
 * Procesar acciones del admin
 */
function dvm_hpos_process_admin_actions() {
    // Verificar nonce
    if (!isset($_POST['dvm_nonce']) || !wp_verify_nonce($_POST['dvm_nonce'], 'dvm_admin_action')) {
        wp_die(__('Error de seguridad. Por favor, recarga la página e intenta de nuevo.', 'descuentos-volumen-medias'));
    }
    
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('No tienes permisos para realizar esta acción.', 'descuentos-volumen-medias'));
    }
    
    $action = sanitize_text_field($_POST['dvm_action']);
    
    switch ($action) {
        case 'toggle_active':
            $is_active = isset($_POST['dvm_active']) && $_POST['dvm_active'] == '1';
            update_option('dvm_hpos_active', $is_active);
            
            $message = $is_active ?
                __('Sistema de descuentos activado correctamente.', 'descuentos-volumen-medias') :
                __('Sistema de descuentos desactivado correctamente.', 'descuentos-volumen-medias');
            
            // Limpiar caché cuando se cambia el estado
            dvm_hpos_clear_discount_cache();
            
            $redirect_url = add_query_arg('message', urlencode($message),
                                        remove_query_arg(['message', 'error']));
            wp_redirect($redirect_url);
            exit;
            
        case 'add':
            $quantity = absint($_POST['quantity']);
            $discount = floatval($_POST['discount']);
            
            if ($quantity <= 0) {
                $redirect_url = add_query_arg('error', urlencode(__('La cantidad debe ser mayor a 0.', 'descuentos-volumen-medias')), 
                                            remove_query_arg(['message', 'error']));
                wp_redirect($redirect_url);
                exit;
            }
            
            if ($discount < 0 || $discount > 100) {
                $redirect_url = add_query_arg('error', urlencode(__('El descuento debe estar entre 0 y 100%.', 'descuentos-volumen-medias')), 
                                            remove_query_arg(['message', 'error']));
                wp_redirect($redirect_url);
                exit;
            }
            
            if (dvm_hpos_add_discount_level($quantity, $discount)) {
                $redirect_url = add_query_arg('message', urlencode(__('Nivel de descuento agregado correctamente.', 'descuentos-volumen-medias')), 
                                            remove_query_arg(['message', 'error']));
            } else {
                $redirect_url = add_query_arg('error', urlencode(__('Error al agregar el nivel de descuento.', 'descuentos-volumen-medias')), 
                                            remove_query_arg(['message', 'error']));
            }
            
            wp_redirect($redirect_url);
            exit;
            
        case 'delete':
            $quantity = absint($_POST['quantity']);

            if (dvm_hpos_delete_discount_level($quantity)) {
                $redirect_url = add_query_arg('message', urlencode(__('Nivel de descuento eliminado correctamente.', 'descuentos-volumen-medias')),
                                            remove_query_arg(['message', 'error']));
            } else {
                $redirect_url = add_query_arg('error', urlencode(__('Error al eliminar el nivel de descuento.', 'descuentos-volumen-medias')),
                                            remove_query_arg(['message', 'error']));
            }

            wp_redirect($redirect_url);
            exit;

        case 'save_coupon_rules':
            $data = [
                'special_coupons'        => isset($_POST['special_coupons']) ? sanitize_text_field($_POST['special_coupons']) : '',
                'always_combine_coupons' => isset($_POST['always_combine_coupons']) ? sanitize_text_field($_POST['always_combine_coupons']) : '',
                'default_behavior'       => isset($_POST['default_behavior']) ? sanitize_text_field($_POST['default_behavior']) : 'combine',
            ];

            dvm_hpos_save_coupon_rules($data);
            dvm_hpos_clear_discount_cache();

            $redirect_url = add_query_arg('message', urlencode(__('Configuración de cupones guardada correctamente.', 'descuentos-volumen-medias')),
                                        remove_query_arg(['message', 'error']));
            wp_redirect($redirect_url . '#dvm-coupon-section');
            exit;

        case 'save_branding':
            $fields = ['unit_label', 'discount_name', 'shortcode_title', 'shortcode_desc', 'coupon_active_message',
                       'color_primary', 'color_secondary', 'color_success', 'color_warning', 'color_accent',
                       'dark_primary', 'dark_secondary', 'dark_success', 'dark_warning', 'dark_accent'];

            $data = [];
            foreach ($fields as $field) {
                $data[$field] = isset($_POST[$field]) ? $_POST[$field] : '';
            }

            if (dvm_hpos_save_branding($data)) {
                $redirect_url = add_query_arg('message', urlencode(__('Personalización guardada correctamente.', 'descuentos-volumen-medias')),
                                            remove_query_arg(['message', 'error']));
            } else {
                $redirect_url = add_query_arg('error', urlencode(__('Error al guardar la personalización.', 'descuentos-volumen-medias')),
                                            remove_query_arg(['message', 'error']));
            }

            wp_redirect($redirect_url . '#dvm-branding-section');
            exit;
    }
}

/**
 * Agregar scripts de administración
 */
add_action('admin_enqueue_scripts', 'dvm_hpos_admin_scripts');

function dvm_hpos_admin_scripts($hook) {
    if ($hook !== 'woocommerce_page_dvm-discount-levels') {
        return;
    }

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_script('jquery');

    $unit_label     = esc_js(dvm_hpos_unit_label());
    $existing_levels = wp_json_encode(array_keys(dvm_hpos_get_discount_levels()));

    wp_add_inline_script('wp-color-picker', '
        jQuery(document).ready(function($) {

            // Inicializar color pickers
            $(".dvm-color-picker").wpColorPicker();

            // Confirmación para eliminar nivel
            $(".dvm-delete-level").on("click", function(e) {
                e.preventDefault();
                var quantity = $(this).data("quantity");
                var discount = $(this).data("discount");
                var label    = "' . $unit_label . '";
                if (confirm("¿Estás seguro de eliminar el nivel de " + quantity + " " + label + " (" + discount + "% descuento)?")) {
                    $(this).closest("form").submit();
                }
            });

            // Validación en tiempo real del formulario de niveles
            $("#quantity, #discount").on("input", function() {
                var quantity = parseInt($("#quantity").val());
                var discount = parseFloat($("#discount").val());
                var submitBtn = $("#submit");
                var isValid  = true;
                var errorMsg = "";

                $(".dvm-validation-error").remove();

                if (quantity <= 0) {
                    isValid  = false;
                    errorMsg = "La cantidad debe ser mayor a 0";
                } else if (discount < 0 || discount > 100) {
                    isValid  = false;
                    errorMsg = "El descuento debe estar entre 0 y 100%";
                }

                if (isValid && quantity > 0) {
                    var existingLevels = ' . $existing_levels . ';
                    if (existingLevels.includes(quantity)) {
                        errorMsg = "Ya existe un nivel para " + quantity + " ' . $unit_label . '. Se actualizará el descuento.";
                        $(this).closest("td").append("<p class=\"dvm-validation-error\" style=\"color: orange; font-size: 12px; margin: 5px 0 0 0;\">" + errorMsg + "</p>");
                    }
                }

                if (!isValid && errorMsg) {
                    $(this).closest("td").append("<p class=\"dvm-validation-error\" style=\"color: red; font-size: 12px; margin: 5px 0 0 0;\">" + errorMsg + "</p>");
                }

                submitBtn.prop("disabled", !isValid);
            });

            // Auto-dismiss notices
            setTimeout(function() {
                $(".notice.is-dismissible").fadeOut();
            }, 5000);

            // ── Tag input para cupones ──────────────────────────────────────
            function dvmInitTagField(fieldId, inputId, hiddenId, colorClass) {
                var $field  = $("#" + fieldId);
                var $input  = $("#" + inputId);
                var $hidden = $("#" + hiddenId);

                function getCodes() {
                    var val = $hidden.val().trim();
                    return val ? val.split(",").map(function(c){ return c.trim().toUpperCase(); }).filter(Boolean) : [];
                }

                function saveAndRender(codes) {
                    $hidden.val(codes.join(","));
                    $field.find(".dvm-ctag").remove();
                    codes.forEach(function(code) {
                        var $tag = $("<span>").addClass("dvm-ctag dvm-ctag-" + colorClass).text(code);
                        var $btn = $("<button>").attr({type:"button","aria-label":"Quitar"}).addClass("dvm-ctag-remove").html("&times;");
                        $btn.on("click", function() {
                            var current = getCodes().filter(function(c){ return c !== code; });
                            saveAndRender(current);
                        });
                        $tag.append($btn);
                        $input.before($tag);
                    });
                }

                $input.on("keydown", function(e) {
                    if (e.key === "Enter" || e.key === "," || e.key === "Tab") {
                        e.preventDefault();
                        var val = $(this).val().trim().toUpperCase().replace(/[^A-Z0-9_\-]/g, "");
                        if (!val) return;
                        var codes = getCodes();
                        if (!codes.includes(val)) {
                            codes.push(val);
                            saveAndRender(codes);
                        }
                        $(this).val("");
                    }
                    if (e.key === "Backspace" && !$(this).val()) {
                        var codes = getCodes();
                        if (codes.length) {
                            codes.pop();
                            saveAndRender(codes);
                        }
                    }
                });

                $field.on("click", function() { $input.focus(); });
                saveAndRender(getCodes());
            }

            dvmInitTagField("special-tag-field",  "special-coupon-input", "special-coupons-value", "red");
            dvmInitTagField("always-tag-field",   "always-coupon-input",  "always-coupons-value",  "green");

            // ── Radio cards: estilos al seleccionar ────────────────────────
            $(".dvm-radio-card input[type=radio]").on("change", function() {
                $(".dvm-radio-card").removeClass("dvm-radio-card--active");
                $(this).closest(".dvm-radio-card").addClass("dvm-radio-card--active");

                var val = $(this).val();
                if (val === "no_combine") {
                    $("#always-combine-group").slideDown(220);
                } else {
                    $("#always-combine-group").slideUp(180);
                }
            });
        });
    ');
}

/**
 * Handlers AJAX para operaciones rápidas
 */
add_action('wp_ajax_dvm_quick_delete_level', 'dvm_hpos_ajax_delete_level');

function dvm_hpos_ajax_delete_level() {
    // Verificar nonce
    if (!wp_verify_nonce($_POST['nonce'], 'dvm_ajax_action')) {
        wp_die('Error de seguridad');
    }
    
    // Verificar permisos
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Sin permisos');
    }
    
    $quantity = absint($_POST['quantity']);
    
    if (dvm_hpos_delete_discount_level($quantity)) {
        wp_send_json_success([
            'message' => __('Nivel eliminado correctamente', 'descuentos-volumen-medias')
        ]);
    } else {
        wp_send_json_error([
            'message' => __('Error al eliminar el nivel', 'descuentos-volumen-medias')
        ]);
    }
}
/**
 * Widget de dashboard para mostrar resumen de descuentos
 */
add_action('wp_dashboard_setup', 'dvm_hpos_add_dashboard_widget');

function dvm_hpos_add_dashboard_widget() {
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'dvm_discount_summary',
            __('Descuentos por Volumen - Resumen', 'descuentos-volumen-medias'),
            'dvm_hpos_dashboard_widget_content'
        );
    }
}

function dvm_hpos_dashboard_widget_content() {
    $levels = dvm_hpos_get_discount_levels();
    $total_levels = count($levels);
    
    echo '<div class="dvm-dashboard-widget">';
    echo '<p><strong>' . sprintf(__('Niveles activos: %d', 'descuentos-volumen-medias'), $total_levels) . '</strong></p>';
    
    if (!empty($levels)) {
        echo '<table style="width: 100%; margin-top: 10px;">';
        echo '<thead><tr><th style="text-align: left;">' . __('Cantidad', 'descuentos-volumen-medias') . '</th><th style="text-align: left;">' . __('Descuento', 'descuentos-volumen-medias') . '</th></tr></thead>';
        echo '<tbody>';
        
        $count = 0;
        foreach ($levels as $quantity => $discount) {
            if ($count >= 3) { // Mostrar solo los primeros 3
                echo '<tr><td colspan="2"><em>' . sprintf(__('... y %d más', 'descuentos-volumen-medias'), $total_levels - 3) . '</em></td></tr>';
                break;
            }
            echo '<tr>';
            echo '<td>' . esc_html($quantity) . ' ' . esc_html(dvm_hpos_unit_label()) . '</td>';
            echo '<td>' . esc_html(number_format($discount, 1)) . '%</td>';
            echo '</tr>';
            $count++;
        }
        
        echo '</tbody></table>';
        
        echo '<p style="margin-top: 15px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=dvm-discount-levels')) . '" class="button button-primary">';
        echo __('Gestionar Niveles', 'descuentos-volumen-medias');
        echo '</a>';
        echo '</p>';
    } else {
        echo '<p>' . __('No hay niveles configurados.', 'descuentos-volumen-medias') . '</p>';
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=dvm-discount-levels')) . '" class="button button-primary">';
        echo __('Configurar Descuentos', 'descuentos-volumen-medias');
        echo '</a>';
        echo '</p>';
    }
    
    echo '</div>';
}

/**
 * Agregar enlace de configuración en la página de plugins
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'dvm_hpos_plugin_action_links');

function dvm_hpos_plugin_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=dvm-discount-levels')) . '">' . __('Configurar', 'descuentos-volumen-medias') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Cargar assets en el frontend (carrito y checkout)
 */
add_action('wp_enqueue_scripts', 'dvm_hpos_frontend_scripts');

function dvm_hpos_frontend_scripts() {
    if (!is_cart() && !is_checkout()) {
        return;
    }

    $plugin_url = plugin_dir_url(__FILE__);
    $plugin_ver = '1.1.0';

    wp_enqueue_style(
        'dvm-progress-styles',
        $plugin_url . 'dvm-progress-styles.css',
        [],
        $plugin_ver
    );

    wp_add_inline_style('dvm-progress-styles', dvm_hpos_get_custom_css_vars());

    wp_enqueue_script(
        'dvm-progress-script',
        $plugin_url . 'dvm-progress-script.js',
        ['jquery'],
        $plugin_ver,
        true
    );

    wp_localize_script('dvm-progress-script', 'dvmProgressAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('dvm_progress_nonce'),
        'debug'    => defined('WP_DEBUG') && WP_DEBUG,
        'messages' => [
            'loading'       => __('Actualizando...', 'descuentos-volumen-medias'),
            'timeout'       => __('Tiempo de espera agotado. Intenta recargar la página.', 'descuentos-volumen-medias'),
            'error'         => __('Error de conexión. Verifica tu conexión a internet.', 'descuentos-volumen-medias'),
            'parse_error'   => __('Error al procesar la respuesta del servidor.', 'descuentos-volumen-medias'),
            'generic_error' => __('Error al cargar datos. Intenta recargar la página.', 'descuentos-volumen-medias'),
        ],
    ]);
}

/**
 * Handler AJAX para datos del indicador de progreso (usuarios logueados y no logueados)
 */
add_action('wp_ajax_dvm_get_progress_data', 'dvm_hpos_ajax_get_progress_data');
add_action('wp_ajax_nopriv_dvm_get_progress_data', 'dvm_hpos_ajax_get_progress_data');

function dvm_hpos_ajax_get_progress_data() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dvm_progress_nonce')) {
        wp_send_json_error(['message' => __('Error de seguridad.', 'descuentos-volumen-medias')]);
    }

    if (!WC()->cart) {
        wp_send_json_error(['message' => __('Carrito no disponible.', 'descuentos-volumen-medias')]);
    }

    $cart          = WC()->cart;
    $current_units = dvm_hpos_calcular_total_docenas($cart);
    $levels        = dvm_hpos_get_discount_levels();
    ksort($levels, SORT_NUMERIC);

    $current_discount = 0;
    $next_level       = null;
    $next_discount    = 0;
    $is_max_level     = false;

    foreach ($levels as $threshold => $discount) {
        if ($current_units >= $threshold) {
            $current_discount = $discount;
        } elseif ($next_level === null) {
            $next_level    = $threshold;
            $next_discount = $discount;
        }
    }

    if ($next_level === null) {
        $is_max_level        = true;
        $units_needed        = 0;
        $progress_percentage = 100;
    } else {
        $units_needed = $next_level - $current_units;

        // Calcular porcentaje hacia el siguiente nivel
        $prev_level = 0;
        foreach ($levels as $threshold => $discount) {
            if ($threshold < $next_level && $current_units >= $threshold) {
                $prev_level = $threshold;
            }
        }
        $range               = $next_level - $prev_level;
        $progress_percentage = $range > 0 ? round(($current_units - $prev_level) / $range * 100, 1) : 0;
    }

    $unit_label    = dvm_hpos_unit_label();
    $coupon_active = dvm_hpos_check_coupon_blocks_discount($cart);
    $branding      = dvm_hpos_get_branding();

    if ($coupon_active) {
        $motivational_message = $branding['coupon_active_message'];
    } elseif ($is_max_level) {
        $motivational_message = __('¡Felicitaciones! Alcanzaste el máximo descuento disponible.', 'descuentos-volumen-medias');
    } elseif ($units_needed <= 5) {
        $motivational_message = sprintf(
            /* translators: %1$d = units needed, %2$s = unit label (e.g. unidades, cajas) */
            __('¡Casi! Solo %1$d %2$s más para el siguiente descuento.', 'descuentos-volumen-medias'),
            $units_needed,
            $unit_label
        );
    } else {
        $motivational_message = sprintf(
            /* translators: %1$d = units needed, %2$s = unit label, %3$s = discount percentage */
            __('Agrega %1$d %2$s más para obtener %3$s%% de descuento.', 'descuentos-volumen-medias'),
            $units_needed,
            $unit_label,
            number_format($next_discount, 1)
        );
    }

    wp_send_json_success([
        'current_units'        => $current_units,
        'units_needed'         => max(0, $units_needed ?? 0),
        'next_discount'        => $next_discount,
        'next_level'           => $next_level,
        'progress_percentage'  => max(0, min(100, $progress_percentage ?? 100)),
        'is_max_level'         => $is_max_level,
        'current_discount'     => $current_discount,
        'levels'               => $levels,
        'motivational_message' => $motivational_message,
        'coupon_active'        => $coupon_active,
    ]);
}

/**
 * Función de activación del plugin
 */
register_activation_hook(__FILE__, 'dvm_hpos_activation');

function dvm_hpos_activation() {
    // Crear niveles por defecto si no existen
    if (!get_option('dvm_hpos_discount_levels')) {
        $default_levels = [
            200 => 16.6,
            100 => 13.3,
            50  => 10.0
        ];
        update_option('dvm_hpos_discount_levels', $default_levels);
    }
    
    // Limpiar caché
    dvm_hpos_clear_discount_cache();
}

/**
 * Función de desactivación del plugin
 */
register_deactivation_hook(__FILE__, 'dvm_hpos_deactivation');

function dvm_hpos_deactivation() {
    // Limpiar caché al desactivar
    dvm_hpos_clear_discount_cache();
}

/**
 * Shortcode para mostrar niveles de descuento
 * Uso: [mostrar_descuentos_volumen]
 */
add_shortcode('mostrar_descuentos_volumen', 'dvm_hpos_mostrar_descuentos_shortcode');

function dvm_hpos_mostrar_descuentos_shortcode($atts) {
    $branding = dvm_hpos_get_branding();

    $atts = shortcode_atts([
        'titulo'             => $branding['shortcode_title'],
        'descripcion'        => $branding['shortcode_desc'],
        'mostrar_descripcion' => 'si',
        'clase_contenedora'  => 'dvm-descuentos-container',
    ], $atts, 'mostrar_descuentos_volumen');

    // Obtener niveles de descuento
    $niveles = dvm_hpos_get_discount_levels();
    
    // Verificar si hay niveles configurados
    if (empty($niveles)) {
        return '<p class="dvm-no-niveles">' . __('Actualmente no hay niveles de descuento disponibles.', 'descuentos-volumen-medias') . '</p>';
    }

    // Ordenar niveles por cantidad ascendente para mostrar correctamente
    ksort($niveles, SORT_NUMERIC);

    // Iniciar buffer de salida
    ob_start();
    ?>
    <div class="<?php echo esc_attr($atts['clase_contenedora']); ?>" role="region" aria-label="<?php echo esc_attr($atts['titulo']); ?>">
        <?php if (!empty($atts['titulo'])): ?>
            <h2 class="dvm-titulo" id="dvm-descuentos-titulo"><?php echo esc_html($atts['titulo']); ?></h2>
        <?php endif; ?>

        <?php if ($atts['mostrar_descripcion'] === 'si' && !empty($atts['descripcion'])): ?>
            <p class="dvm-descripcion"><?php echo esc_html($atts['descripcion']); ?></p>
        <?php endif; ?>

        <div class="dvm-niveles-grid" role="list">
            <?php foreach ($niveles as $cantidad => $descuento): ?>
                <div class="dvm-nivel-item" role="listitem">
                    <div class="dvm-nivel-contenido">
                        <div class="dvm-nivel-info">
                            <span class="dvm-cantidad" aria-label="<?php printf(esc_attr__('Desde %1$s %2$s', 'descuentos-volumen-medias'), esc_attr($cantidad), esc_attr($branding['unit_label'])); ?>">
                                <?php printf(esc_html__('Desde %1$s %2$s', 'descuentos-volumen-medias'), esc_html($cantidad), esc_html($branding['unit_label'])); ?>
                            </span>
                            <span class="dvm-descuento" aria-label="<?php printf(esc_attr__('Descuento del %s por ciento', 'descuentos-volumen-medias'), number_format($descuento, 1)); ?>">
                                <?php echo number_format($descuento, 1); ?>%
                            </span>
                        </div>
                        <div class="dvm-nivel-visual">
                            <div class="dvm-barra-progreso">
                                <div class="dvm-progreso" style="width: <?php echo min(100, max(10, $descuento)); ?>%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dvm-leyenda">
            <p><?php echo esc_html__('* El descuento se aplica automáticamente en el carrito según la cantidad total de productos.', 'descuentos-volumen-medias'); ?></p>
        </div>
    </div>

    <style>
        <?php echo dvm_hpos_get_custom_css_vars(); ?>

        .dvm-descuentos-container {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            max-width: 100%;
            margin: 0 auto 2rem;
            padding: 1.5rem;
            background-color: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .dvm-titulo {
            color: var(--joga-secondary);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            text-align: center;
            font-weight: 700;
        }

        .dvm-descripcion {
            color: #7A7A7A;
            font-size: 1rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .dvm-niveles-grid {
            display: grid;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .dvm-nivel-item {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .dvm-nivel-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        .dvm-nivel-contenido { padding: 1.25rem; }

        .dvm-nivel-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .dvm-cantidad {
            font-weight: 600;
            color: var(--joga-primary);
            font-size: 1.1rem;
        }

        .dvm-descuento {
            background: var(--joga-secondary);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 1.2rem;
            min-width: 70px;
            text-align: center;
        }

        .dvm-nivel-visual { margin-top: 0.5rem; }

        .dvm-barra-progreso {
            height: 12px;
            background-color: #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
        }

        .dvm-progreso {
            height: 100%;
            background: linear-gradient(90deg, var(--joga-secondary), var(--joga-success));
            border-radius: 6px;
            transition: width 0.5s ease;
        }

        .dvm-leyenda {
            text-align: center;
            font-size: 0.85rem;
            color: #7A7A7A;
            margin-top: 1rem;
        }

        @media (prefers-color-scheme: dark) {
            .dvm-descuentos-container { background-color: #2c2c2c; color: #f0f0f0; }
            .dvm-nivel-item { background: #3a3a3a; }
            .dvm-cantidad { color: var(--joga-primary); }
            .dvm-descripcion, .dvm-leyenda { color: #ccc; }
        }

        /* Responsive design */
        @media (min-width: 768px) {
            .dvm-descuentos-container {
                padding: 2rem;
            }
            
            .dvm-niveles-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
        }

        @media (max-width: 767px) {
            .dvm-descuentos-container {
                padding: 1rem;
            }
            
            .dvm-nivel-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .dvm-descuento {
                align-self: flex-end;
            }
        }

        /* Accesibilidad */
        @media (prefers-reduced-motion: reduce) {
            .dvm-nivel-item,
            .dvm-progreso {
                transition: none;
            }
        }

        /* Modo oscuro */
        @media (prefers-color-scheme: dark) {
            .dvm-descuentos-container {
                background-color: #2c2c2c;
                color: #f0f0f0;
            }
            
            .dvm-nivel-item {
                background: #3a3a3a;
            }
            
            .dvm-cantidad {
                color: #f0f0f0;
            }
            
            .dvm-descripcion {
                color: #ccc;
            }
            
            .dvm-leyenda {
                color: #aaa;
            }
        }
    </style>
    <?php
    return ob_get_clean();
}