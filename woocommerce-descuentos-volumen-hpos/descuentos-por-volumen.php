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
        
        $cart->add_fee(
            sprintf(esc_html__('Descuento por Docena (%s%%)', 'descuentos-volumen-medias'), $porcentaje),
            -1 * abs($descuento),
            false,
            ''
        );
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
    // Procesar formularios
    if (isset($_POST['dvm_action'])) {
        dvm_hpos_process_admin_actions();
    }
    
    $levels = dvm_hpos_get_discount_levels();
    $is_active = get_option('dvm_hpos_active', true);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Gestión de Niveles de Descuento por Docena', 'descuentos-volumen-medias'); ?></h1>
        
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
                                    <td><strong><?php echo esc_html($quantity); ?></strong> unidades</td>
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
    </div>
    
    <style>
        .dvm-admin-container {
            max-width: 1200px;
        }
        .dvm-form .form-table th {
            width: 150px;
        }
        .dvm-info ul {
            list-style-type: disc;
        }
        .dvm-levels-list table {
            margin-top: 10px;
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
    }
}

/**
 * Agregar scripts de administración
 */
add_action('admin_enqueue_scripts', 'dvm_hpos_admin_scripts');

function dvm_hpos_admin_scripts($hook) {
    // Solo cargar en nuestra página de administración
    if ($hook !== 'woocommerce_page_dvm-discount-levels') {
        return;
    }
    
    wp_enqueue_script('jquery');
    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            // Confirmación mejorada para eliminar
            $(".dvm-delete-level").on("click", function(e) {
                e.preventDefault();
                
                var quantity = $(this).data("quantity");
                var discount = $(this).data("discount");
                
                if (confirm("¿Estás seguro de eliminar el nivel de " + quantity + " unidades (" + discount + "% descuento)?")) {
                    $(this).closest("form").submit();
                }
            });
            
            // Validación en tiempo real del formulario
            $("#quantity, #discount").on("input", function() {
                var quantity = parseInt($("#quantity").val());
                var discount = parseFloat($("#discount").val());
                var submitBtn = $("#submit");
                var isValid = true;
                var errorMsg = "";
                
                // Limpiar mensajes anteriores
                $(".dvm-validation-error").remove();
                
                if (quantity <= 0) {
                    isValid = false;
                    errorMsg = "La cantidad debe ser mayor a 0";
                } else if (discount < 0 || discount > 100) {
                    isValid = false;
                    errorMsg = "El descuento debe estar entre 0 y 100%";
                }
                
                // Verificar si ya existe este nivel
                if (isValid && quantity > 0) {
                    var existingLevels = ' . json_encode(array_keys(dvm_hpos_get_discount_levels())) . ';
                    if (existingLevels.includes(quantity)) {
                        isValid = false;
                        errorMsg = "Ya existe un nivel para " + quantity + " docenas. Se actualizará el descuento.";
                        // En este caso, permitir el envío pero mostrar advertencia
                        isValid = true;
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
            echo '<td>' . esc_html($quantity) . ' unidades</td>';
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

    if ($is_max_level) {
        $motivational_message = __('¡Felicitaciones! Alcanzaste el máximo descuento disponible.', 'descuentos-volumen-medias');
    } elseif ($units_needed <= 5) {
        $motivational_message = sprintf(
            __('¡Casi! Solo %d unidad(es) más para el siguiente descuento.', 'descuentos-volumen-medias'),
            $units_needed
        );
    } else {
        $motivational_message = sprintf(
            __('Agrega %d unidades más para obtener %s%% de descuento.', 'descuentos-volumen-medias'),
            $units_needed,
            number_format($next_discount, 1)
        );
    }

    wp_send_json_success([
        'current_units'        => $current_units,
        'units_needed'         => max(0, $units_needed),
        'next_discount'        => $next_discount,
        'next_level'           => $next_level,
        'progress_percentage'  => max(0, min(100, $progress_percentage)),
        'is_max_level'         => $is_max_level,
        'current_discount'     => $current_discount,
        'levels'               => $levels,
        'motivational_message' => $motivational_message,
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
    // Atributos por defecto
    $atts = shortcode_atts([
        'titulo' => __('Niveles de Descuento por Docena', 'descuentos-volumen-medias'),
        'descripcion' => __('Compra más y ahorra más.', 'descuentos-volumen-medias'),
        'mostrar_descripcion' => 'si',
        'clase_contenedora' => 'dvm-descuentos-container',
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
                            <span class="dvm-cantidad" aria-label="<?php printf(esc_attr__('Desde %s unidades', 'descuentos-volumen-medias'), esc_attr($cantidad)); ?>">
                                <?php printf(esc_html__('Desde %s unidades', 'descuentos-volumen-medias'), esc_html($cantidad)); ?>
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
            color: #d1e9ff;
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

        .dvm-nivel-contenido {
            padding: 1.25rem;
        }

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
            color: #112236;
            font-size: 1.1rem;
        }

        .dvm-descuento {
            background: #007AFF;
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 1.2rem;
            min-width: 70px;
            text-align: center;
        }

        .dvm-nivel-visual {
            margin-top: 0.5rem;
        }

        .dvm-barra-progreso {
            height: 12px;
            background-color: #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
        }

        .dvm-progreso {
            height: 100%;
            background: linear-gradient(90deg, #007AFF, #4F9BFF);
            border-radius: 6px;
            transition: width 0.5s ease;
        }

        .dvm-leyenda {
            text-align: center;
            font-size: 0.85rem;
            color: #7A7A7A;
            margin-top: 1rem;
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