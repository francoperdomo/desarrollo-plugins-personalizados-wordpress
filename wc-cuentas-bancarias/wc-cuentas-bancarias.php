<?php
/*
Plugin Name: Cuentas Bancarias - Segun monto
Plugin URI: http://francoperdomo.com.ar
Description: Plugin para mostrar información bancaria en la descripción de transferencia según el monto del pedido.
Version: 1.4
Author: Franco Perdomo
Author URI: https://francoperdomo.com.ar    
License: GPLv2 o posterior
Text Domain:
*/

/**
 * Sistema de gestión de cuentas bancarias por rangos de monto para WooCommerce
 * 
 * @package WooCommerce/BankTransfer
 * @version 1.4
 */

if (!defined('ABSPATH')) {
    exit; // Salida directa no permitida
}

class WC_Bank_Transfer_Manager {
    
    /**
     * Instancia única de la clase
     * 
     * @var WC_Bank_Transfer_Manager
     */
    private static $instance = null;
    
    /**
     * Opciones almacenadas en caché
     * 
     * @var array|null
     */
    private static $cached_options = null;
    
    /**
     * Constructor privado para patrón Singleton
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Obtener instancia única
     * 
     * @return WC_Bank_Transfer_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar hooks de WordPress
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wc_bank_transfer_save_account', array($this, 'save_account_callback'));
        add_action('wp_ajax_wc_bank_transfer_delete_account', array($this, 'delete_account_callback'));
        add_action('wp_ajax_wc_bank_transfer_save_range', array($this, 'save_range_callback'));
        add_action('wp_ajax_wc_bank_transfer_delete_range', array($this, 'delete_range_callback'));
        add_action('woocommerce_settings_saved', array($this, 'clear_cache'));
        add_filter('woocommerce_available_payment_gateways', array($this, 'modify_bacs_description'));
    }
    
    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Transferencias Bancarias', 'woocommerce'),
            __('Transferencias Bancarias', 'woocommerce'),
            'manage_woocommerce',
            'wc-bank-transfer',
            array($this, 'admin_page_callback')
        );
    }
    
    /**
     * Página de administración
     */
    public function admin_page_callback() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos suficientes para acceder a esta página.', 'woocommerce'));
        }
        
        $this->handle_form_submissions();
        
        $accounts = get_option('wc_bank_transfer_accounts', array());
        $ranges = get_option('wc_bank_transfer_ranges', array());
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Transferencias Bancarias', 'woocommerce'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    
                    <!-- Columna principal -->
                    <div id="post-body-content">
                        
                        <!-- Sección de Cuentas Bancarias -->
                        <div class="postbox">
                            <h2 class="hndle"><?php echo esc_html__('Cuentas Bancarias', 'woocommerce'); ?></h2>
                            <div class="inside">
                                <div id="bank-accounts-list">
                                    <?php if (!empty($accounts)): ?>
                                        <table class="wp-list-table widefat fixed striped">
                                            <thead>
                                                <tr>
                                                    <th><?php echo esc_html__('Beneficiario', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Banco', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('CBU', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Alias', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Acciones', 'woocommerce'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($accounts as $account_id => $account): ?>
                                                    <tr data-account-id="<?php echo esc_attr($account_id); ?>">
                                                        <td><?php echo esc_html($account['beneficiary'] ?? ''); ?></td>
                                                        <td><?php echo esc_html($account['bank'] ?? ''); ?></td>
                                                        <td><?php echo esc_html($account['cbu'] ?? ''); ?></td>
                                                        <td><?php echo esc_html($account['alias'] ?? ''); ?></td>
                                                        <td>
                                                            <button type="button" class="button edit-account" 
                                                                    data-account="<?php echo esc_attr(json_encode($account)); ?>" 
                                                                    data-id="<?php echo esc_attr($account_id); ?>">
                                                                <?php echo esc_html__('Editar', 'woocommerce'); ?>
                                                            </button>
                                                            <button type="button" class="button delete-account" 
                                                                    data-id="<?php echo esc_attr($account_id); ?>">
                                                                <?php echo esc_html__('Eliminar', 'woocommerce'); ?>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p><?php echo esc_html__('No hay cuentas bancarias configuradas.', 'woocommerce'); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <button type="button" class="button button-primary" id="add-new-account">
                                    <?php echo esc_html__('Agregar Nueva Cuenta', 'woocommerce'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Sección de Rangos de Montos -->
                        <div class="postbox">
                            <h2 class="hndle"><?php echo esc_html__('Rangos de Montos', 'woocommerce'); ?></h2>
                            <div class="inside">
                                <div id="amount-ranges-list">
                                    <?php if (!empty($ranges)): ?>
                                        <table class="wp-list-table widefat fixed striped">
                                            <thead>
                                                <tr>
                                                    <th><?php echo esc_html__('Monto Mínimo', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Monto Máximo', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Cuentas Asociadas', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Habilitado', 'woocommerce'); ?></th>
                                                    <th><?php echo esc_html__('Acciones', 'woocommerce'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($ranges as $index => $range): ?>
                                                    <tr data-range-index="<?php echo esc_attr($index); ?>">
                                                        <td><?php echo wc_price(floatval($range['min_amount'] ?? 0)); ?></td>
                                                        <td><?php echo wc_price(floatval($range['max_amount'] ?? 0)); ?></td>
                                                        <td>
                                                            <?php 
                                                            $account_ids = isset($range['account_ids']) ? $range['account_ids'] : array();
                                                            $accounts_list = get_option('wc_bank_transfer_accounts', array());
                                                            $display_accounts = array();
                                                            
                                                            foreach ($account_ids as $account_id) {
                                                                if (isset($accounts_list[$account_id])) {
                                                                    $display_accounts[] = esc_html($accounts_list[$account_id]['beneficiary']);
                                                                }
                                                            }
                                                            echo !empty($display_accounts) ? implode(', ', $display_accounts) : esc_html__('Ninguna', 'woocommerce');
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php echo ($range['enabled'] ?? 'no') === 'yes' ? 
                                                                esc_html__('Sí', 'woocommerce') : 
                                                                esc_html__('No', 'woocommerce'); ?>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="button edit-range" 
                                                                    data-range="<?php echo esc_attr(json_encode($range)); ?>" 
                                                                    data-index="<?php echo esc_attr($index); ?>">
                                                                <?php echo esc_html__('Editar', 'woocommerce'); ?>
                                                            </button>
                                                            <button type="button" class="button delete-range" 
                                                                    data-index="<?php echo esc_attr($index); ?>">
                                                                <?php echo esc_html__('Eliminar', 'woocommerce'); ?>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p><?php echo esc_html__('No hay rangos de montos configurados.', 'woocommerce'); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <button type="button" class="button button-primary" id="add-new-range">
                                    <?php echo esc_html__('Agregar Nuevo Rango', 'woocommerce'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Columna lateral -->
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><?php echo esc_html__('Instrucciones', 'woocommerce'); ?></h2>
                            <div class="inside">
                                <p><?php echo esc_html__('Configure las cuentas bancarias y los rangos de montos para mostrar información diferente según el total del pedido.', 'woocommerce'); ?></p>
                                <ol>
                                    <li><?php echo esc_html__('Agregue cuentas bancarias con todos los datos requeridos.', 'woocommerce'); ?></li>
                                    <li><?php echo esc_html__('Configure rangos de montos asociando una o más cuentas.', 'woocommerce'); ?></li>
                                    <li><?php echo esc_html__('Los rangos se evalúan en orden ascendente por monto mínimo.', 'woocommerce'); ?></li>
                                </ol>
                            </div>
                        </div>
                        
                        <div class="postbox">
                            <h2 class="hndle"><?php echo esc_html__('Información Técnica', 'woocommerce'); ?></h2>
                            <div class="inside">
                                <p><strong><?php echo esc_html__('Hook utilizado:', 'woocommerce'); ?></strong></p>
                                <code>woocommerce_available_payment_gateways</code>
                                <p><strong><?php echo esc_html__('Método afectado:', 'woocommerce'); ?></strong></p>
                                <code>BACS (Transferencia Bancaria Directa)</code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Modal para Cuentas Bancarias -->
        <div id="account-modal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2><?php echo esc_html__('Cuenta Bancaria', 'woocommerce'); ?></h2>
                <form id="account-form">
                    <input type="hidden" id="account-id" name="account_id">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Beneficiario', 'woocommerce'); ?></th>
                            <td><input type="text" id="beneficiary" name="beneficiary" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('CUIT', 'woocommerce'); ?></th>
                            <td><input type="text" id="cuit" name="cuit" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('CBU', 'woocommerce'); ?></th>
                            <td><input type="text" id="cbu" name="cbu" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Alias', 'woocommerce'); ?></th>
                            <td><input type="text" id="alias" name="alias" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Banco', 'woocommerce'); ?></th>
                            <td><input type="text" id="bank" name="bank" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Tipo de Cuenta', 'woocommerce'); ?></th>
                            <td><input type="text" id="account_type" name="account_type" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Número de Cuenta', 'woocommerce'); ?></th>
                            <td><input type="text" id="account_number" name="account_number" class="regular-text" required></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Guardar Cuenta', 'woocommerce'); ?></button>
                        <button type="button" class="button modal-cancel"><?php echo esc_html__('Cancelar', 'woocommerce'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        
        <!-- Modal para Rangos de Montos -->
        <div id="range-modal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2><?php echo esc_html__('Rango de Montos', 'woocommerce'); ?></h2>
                <form id="range-form">
                    <input type="hidden" id="range-index" name="range_index">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Monto Mínimo', 'woocommerce'); ?></th>
                            <td><input type="number" id="min_amount" name="min_amount" step="0.01" min="0" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Monto Máximo', 'woocommerce'); ?></th>
                            <td><input type="number" id="max_amount" name="max_amount" step="0.01" min="0" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Cuentas Asociadas', 'woocommerce'); ?></th>
                            <td>
                                <select id="account_ids" name="account_ids[]" multiple class="regular-text" required>
                                    <?php foreach (get_option('wc_bank_transfer_accounts', array()) as $account_id => $account): ?>
                                        <option value="<?php echo esc_attr($account_id); ?>">
                                            <?php echo esc_html($account['beneficiary']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php echo esc_html__('Seleccione una o más cuentas para este rango.', 'woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Habilitado', 'woocommerce'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="enabled" name="enabled" value="yes"> 
                                    <?php echo esc_html__('Habilitar este rango', 'woocommerce'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Guardar Rango', 'woocommerce'); ?></button>
                        <button type="button" class="button modal-cancel"><?php echo esc_html__('Cancelar', 'woocommerce'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        
        <style>
            .modal {
                position: fixed;
                z-index: 10000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.4);
            }
            .modal-content {
                background-color: #fefefe;
                margin: 5% auto;
                padding: 20px;
                border: 1px solid #888;
                width: 60%;
                max-width: 600px;
                position: relative;
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
            .modal-cancel {
                margin-left: 10px !important;
            }
            .widefat th, .widefat td {
                padding: 10px;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                // Variables globales
                var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                var nonce = '<?php echo wp_create_nonce('wc_bank_transfer_nonce'); ?>';
                
                // Abrir modal de cuenta
                $('#add-new-account, .edit-account').on('click', function() {
                    var account = $(this).data('account') || {};
                    var accountId = $(this).data('id') || '';
                    
                    $('#account-id').val(accountId);
                    $('#beneficiary').val(account.beneficiary || '');
                    $('#cuit').val(account.cuit || '');
                    $('#cbu').val(account.cbu || '');
                    $('#alias').val(account.alias || '');
                    $('#bank').val(account.bank || '');
                    $('#account_type').val(account.account_type || '');
                    $('#account_number').val(account.account_number || '');
                    
                    $('#account-modal').show();
                });
                
                // Abrir modal de rango
                $('#add-new-range, .edit-range').on('click', function() {
                    var range = $(this).data('range') || {};
                    var rangeIndex = $(this).data('index') || '';
                    
                    $('#range-index').val(rangeIndex);
                    $('#min_amount').val(range.min_amount || '');
                    $('#max_amount').val(range.max_amount || '');
                    
                    // Manejar múltiples cuentas
                    var accountIds = range.account_ids || [];
                    $('#account_ids').val(accountIds);
                    $('#enabled').prop('checked', (range.enabled || 'no') === 'yes');
                    
                    $('#range-modal').show();
                });
                
                // Cerrar modales
                $('.close, .modal-cancel').on('click', function() {
                    $('.modal').hide();
                });
                
                // Guardar cuenta
                $('#account-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var formData = {
                        action: 'wc_bank_transfer_save_account',
                        nonce: nonce,
                        account_id: $('#account-id').val(),
                        account_data: {
                            beneficiary: $('#beneficiary').val(),
                            cuit: $('#cuit').val(),
                            cbu: $('#cbu').val(),
                            alias: $('#alias').val(),
                            bank: $('#bank').val(),
                            account_type: $('#account_type').val(),
                            account_number: $('#account_number').val()
                        }
                    };
                    
                    $.post(ajaxurl, formData, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error al guardar la cuenta');
                        }
                    }).fail(function() {
                        alert('Error de conexión. Por favor, inténtelo nuevamente.');
                    });
                });
                
                // Guardar rango
                $('#range-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var formData = {
                        action: 'wc_bank_transfer_save_range',
                        nonce: nonce,
                        range_index: $('#range-index').val(),
                        range_data: {
                            min_amount: $('#min_amount').val(),
                            max_amount: $('#max_amount').val(),
                            account_ids: $('#account_ids').val() || [],
                            enabled: $('#enabled').is(':checked') ? 'yes' : 'no'
                        }
                    };
                    
                    $.post(ajaxurl, formData, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error al guardar el rango');
                        }
                    }).fail(function() {
                        alert('Error de conexión. Por favor, inténtelo nuevamente.');
                    });
                });
                
                // Eliminar cuenta
                $('.delete-account').on('click', function() {
                    if (!confirm('<?php echo esc_js(__('¿Está seguro de eliminar esta cuenta?', 'woocommerce')); ?>')) {
                        return;
                    }
                    
                    var accountId = $(this).data('id');
                    
                    $.post(ajaxurl, {
                        action: 'wc_bank_transfer_delete_account',
                        nonce: nonce,
                        account_id: accountId
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error al eliminar la cuenta');
                        }
                    }).fail(function() {
                        alert('Error de conexión. Por favor, inténtelo nuevamente.');
                    });
                });
                
                // Eliminar rango
                $('.delete-range').on('click', function() {
                    if (!confirm('<?php echo esc_js(__('¿Está seguro de eliminar este rango?', 'woocommerce')); ?>')) {
                        return;
                    }
                    
                    var rangeIndex = $(this).data('index');
                    
                    $.post(ajaxurl, {
                        action: 'wc_bank_transfer_delete_range',
                        nonce: nonce,
                        range_index: rangeIndex
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || 'Error al eliminar el rango');
                        }
                    }).fail(function() {
                        alert('Error de conexión. Por favor, inténtelo nuevamente.');
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Inicializar configuración
     */
    public function init_settings() {
        // Registrar configuración si no existe
        if (false === get_option('wc_bank_transfer_accounts')) {
            update_option('wc_bank_transfer_accounts', array());
        }
        
        if (false === get_option('wc_bank_transfer_ranges')) {
            update_option('wc_bank_transfer_ranges', array(
                array(
                    'min_amount' => 0,
                    'max_amount' => 80000,
                    'account_ids' => array(),
                    'enabled' => 'yes'
                ),
                array(
                    'min_amount' => 80000,
                    'max_amount' => 999999999,
                    'account_ids' => array(),
                    'enabled' => 'yes'
                )
            ));
        }
    }
    
    /**
     * Enqueue scripts y styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_wc-bank-transfer') {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    /**
     * Limpiar caché cuando se guardan opciones
     */
    public function clear_cache() {
        self::$cached_options = null;
    }
    
    /**
     * Obtener opciones con caché
     * 
     * @return array
     */
    private function get_cached_options() {
        if (self::$cached_options === null) {
            self::$cached_options = array(
                'accounts' => get_option('wc_bank_transfer_accounts', array()),
                'ranges' => get_option('wc_bank_transfer_ranges', array())
            );
        }
        return self::$cached_options;
    }
    
    /**
     * Obtener cuentas bancarias para un monto específico
     * 
     * @param float $amount
     * @return array
     */
    public function get_accounts_for_amount($amount) {
        // Validación de entrada
        if (!is_numeric($amount) || $amount < 0) {
            return array();
        }
        
        $options = $this->get_cached_options();
        $ranges = $options['ranges'];
        $accounts = $options['accounts'];
        
        // Ordenar rangos por monto mínimo
        usort($ranges, function($a, $b) {
            return floatval($a['min_amount']) <=> floatval($b['min_amount']);
        });
        
        // Buscar rango correspondiente
        foreach ($ranges as $range) {
            if (
                isset($range['enabled']) && $range['enabled'] === 'yes' &&
                $amount >= floatval($range['min_amount']) && 
                $amount <= floatval($range['max_amount']) &&
                !empty($range['account_ids'])
            ) {
                // Devolver todas las cuentas disponibles en este rango
                $valid_accounts = array();
                foreach ($range['account_ids'] as $account_id) {
                    if (isset($accounts[$account_id])) {
                        $valid_accounts[] = $accounts[$account_id];
                    }
                }
                return $valid_accounts;
            }
        }
        
        return array();
    }
    
    /**
     * Formatear información de cuentas para mostrar
     * 
     * @param array $accounts
     * @return string
     */
    public function format_accounts_info($accounts) {
        if (empty($accounts) || !is_array($accounts)) {
            return '';
        }
        
        $formatted_accounts = array();
        
        foreach ($accounts as $account) {
            $required_fields = array('beneficiary', 'cuit', 'cbu', 'alias', 'bank', 'account_type', 'account_number');
            $valid = true;
            
            foreach ($required_fields as $field) {
                if (empty($account[$field])) {
                    $valid = false;
                    break;
                }
            }
            
            if ($valid) {
                $formatted_accounts[] = sprintf(
                    "%s\nCUIT: %s\nCBU: %s\nAlias: %s\nBanco: %s\n– %s : %s\n– N° de cuenta: %s",
                    sanitize_text_field($account['beneficiary']),
                    sanitize_text_field($account['cuit']),
                    sanitize_text_field($account['cbu']),
                    sanitize_text_field($account['alias']),
                    sanitize_text_field($account['bank']),
                    sanitize_text_field($account['bank']),
                    sanitize_text_field($account['account_type']),
                    sanitize_text_field($account['account_number'])
                );
            }
        }
        
        return implode("\n\n" . str_repeat("-", 50) . "\n\n", $formatted_accounts);
    }
    
    /**
     * Modificar descripción del método de pago BACS
     * 
     * @param array $gateways
     * @return array
     */
    public function modify_bacs_description($gateways) {
        if (
            isset($gateways['bacs']) && 
            is_checkout() && 
            WC()->cart instanceof WC_Cart
        ) {
            $cart_total = WC()->cart->get_total('edit');
            $accounts = $this->get_accounts_for_amount($cart_total);
            
            if (!empty($accounts)) {
                $formatted_info = $this->format_accounts_info($accounts);
                if (!empty($formatted_info)) {
                    $gateways['bacs']->description .= '<div class="bank-transfer-info">' . 
                                                     nl2br(esc_html($formatted_info)) . 
                                                     '</div>';
                }
            }
        }
        
        return $gateways;
    }
    
    /**
     * Validar estructura de cuenta bancaria
     * 
     * @param array $account
     * @return bool
     */
    public function validate_account($account) {
        if (!is_array($account)) {
            return false;
        }
        
        $required_fields = array(
            'beneficiary' => 'string',
            'cuit' => 'string',
            'cbu' => 'string',
            'alias' => 'string',
            'bank' => 'string',
            'account_type' => 'string',
            'account_number' => 'string'
        );
        
        foreach ($required_fields as $field => $type) {
            if (!isset($account[$field]) || empty(trim($account[$field]))) {
                return false;
            }
            
            if ($type === 'string' && !is_string($account[$field])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validar rango de montos
     * 
     * @param array $range
     * @return bool
     */
    public function validate_range($range) {
        if (!is_array($range)) {
            return false;
        }
        
        $required_fields = array('min_amount', 'max_amount', 'account_ids', 'enabled');
        
        foreach ($required_fields as $field) {
            if (!isset($range[$field])) {
                return false;
            }
        }
        
        // Validar montos numéricos
        if (!is_numeric($range['min_amount']) || !is_numeric($range['max_amount'])) {
            return false;
        }
        
        // Validar que min_amount <= max_amount
        if (floatval($range['min_amount']) > floatval($range['max_amount'])) {
            return false;
        }
        
        // Validar que hay al menos una cuenta seleccionada
        if (empty($range['account_ids']) || !is_array($range['account_ids'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Manejar envíos de formularios
     */
    private function handle_form_submissions() {
        // Este método puede manejar formularios POST si se necesita
    }
    
    /**
     * Callback para guardar cuenta
     */
    public function save_account_callback() {
        check_ajax_referer('wc_bank_transfer_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(-1);
        }
        
        $account_data = isset($_POST['account_data']) ? wp_unslash($_POST['account_data']) : array();
        $account_id = isset($_POST['account_id']) ? sanitize_text_field($_POST['account_id']) : '';
        
        if (!$this->validate_account($account_data)) {
            wp_send_json_error(__('Datos de cuenta inválidos', 'woocommerce'));
        }
        
        $accounts = get_option('wc_bank_transfer_accounts', array());
        
        if (empty($account_id)) {
            $account_id = uniqid('account_');
        }
        
        $accounts[$account_id] = array_map('sanitize_text_field', $account_data);
        update_option('wc_bank_transfer_accounts', $accounts);
        
        wp_send_json_success(array(
            'account_id' => $account_id,
            'message' => __('Cuenta guardada correctamente', 'woocommerce')
        ));
    }
    
    /**
     * Callback para eliminar cuenta
     */
    public function delete_account_callback() {
        check_ajax_referer('wc_bank_transfer_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(-1);
        }
        
        $account_id = isset($_POST['account_id']) ? sanitize_text_field($_POST['account_id']) : '';
        
        if (empty($account_id)) {
            wp_send_json_error(__('ID de cuenta no válido', 'woocommerce'));
        }
        
        $accounts = get_option('wc_bank_transfer_accounts', array());
        unset($accounts[$account_id]);
        update_option('wc_bank_transfer_accounts', $accounts);
        
        // También eliminar referencias en rangos
        $ranges = get_option('wc_bank_transfer_ranges', array());
        foreach ($ranges as &$range) {
            if (isset($range['account_ids']) && is_array($range['account_ids'])) {
                $range['account_ids'] = array_diff($range['account_ids'], array($account_id));
                // Reindexar array
                $range['account_ids'] = array_values($range['account_ids']);
            }
        }
        update_option('wc_bank_transfer_ranges', $ranges);
        $this->clear_cache();
        
        wp_send_json_success(__('Cuenta eliminada correctamente', 'woocommerce'));
    }
    
    /**
     * Callback para guardar rango
     */
    public function save_range_callback() {
        check_ajax_referer('wc_bank_transfer_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(-1);
        }
        
        $range_data = isset($_POST['range_data']) ? wp_unslash($_POST['range_data']) : array();
        $range_index = isset($_POST['range_index']) ? intval($_POST['range_index']) : -1;
        
        if (!$this->validate_range($range_data)) {
            wp_send_json_error(__('Datos de rango inválidos', 'woocommerce'));
        }
        
        $ranges = get_option('wc_bank_transfer_ranges', array());
        
        if ($range_index >= 0 && isset($ranges[$range_index])) {
            $ranges[$range_index] = $range_data;
        } else {
            $ranges[] = $range_data;
        }
        
        update_option('wc_bank_transfer_ranges', $ranges);
        $this->clear_cache();
        
        wp_send_json_success(__('Rango guardado correctamente', 'woocommerce'));
    }
    
    /**
     * Callback para eliminar rango
     */
    public function delete_range_callback() {
        check_ajax_referer('wc_bank_transfer_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(-1);
        }
        
        $range_index = isset($_POST['range_index']) ? intval($_POST['range_index']) : -1;
        
        if ($range_index < 0) {
            wp_send_json_error(__('Índice de rango no válido', 'woocommerce'));
        }
        
        $ranges = get_option('wc_bank_transfer_ranges', array());
        
        if (!isset($ranges[$range_index])) {
            wp_send_json_error(__('Rango no encontrado', 'woocommerce'));
        }
        
        unset($ranges[$range_index]);
        $ranges = array_values($ranges); // Reindexar array
        
        update_option('wc_bank_transfer_ranges', $ranges);
        $this->clear_cache();
        
        wp_send_json_success(__('Rango eliminado correctamente', 'woocommerce'));
    }
}

/**
 * Función helper para obtener la instancia
 * 
 * @return WC_Bank_Transfer_Manager
 */
function wc_bank_transfer_manager() {
    return WC_Bank_Transfer_Manager::get_instance();
}

// Inicializar el sistema
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        wc_bank_transfer_manager();
    }
});

/**
 * Funciones auxiliares para uso externo
 */

/**
 * Obtener información bancaria para un monto
 * 
 * @param float $amount
 * @return string Formatted bank information
 */
function wc_get_bank_transfer_info($amount) {
    $manager = wc_bank_transfer_manager();
    $accounts = $manager->get_accounts_for_amount($amount);
    return $manager->format_accounts_info($accounts);
}

/**
 * Mostrar información bancaria para un monto
 * 
 * @param float $amount
 */
function wc_display_bank_transfer_info($amount) {
    $info = wc_get_bank_transfer_info($amount);
    if (!empty($info)) {
        echo '<div class="bank-transfer-info">' . nl2br(esc_html($info)) . '</div>';
    }
}
?>