/**
 * Indicador de Progreso de Descuentos por Volumen
 * Plugin: Descuentos por Volumen para Fábrica de Medias (HPOS)
 * Versión: 1.0.0
 * 
 * Funcionalidades:
 * - Actualización en tiempo real via AJAX
 * - Integración con eventos de WooCommerce
 * - Sistema de debouncing para optimización
 * - Animaciones y transiciones suaves
 * - Manejo de errores y estados de loading
 */

(function($) {
    'use strict';

    /**
     * Clase principal del indicador de progreso
     */
    class DVMProgressIndicator {
        constructor(options = {}) {
            // Configuración por defecto
            this.config = {
                debounceDelay: 500,
                retryAttempts: 3,
                retryDelay: 1000,
                animationDuration: 300,
                updateInterval: null,
                debug: false,
                ...options
            };

            // Estado interno
            this.state = {
                isLoading: false,
                lastRequestTime: 0,
                retryCount: 0,
                currentData: null,
                isInitialized: false
            };

            // Referencias DOM
            this.elements = {
                banner: null,
                summary: null,
                progressSteps: null,
                progressFill: null,
                progressLineFill: null,
                unitsNeeded: null,
                nextDiscount: null,
                motivationalMessage: null,
                currentUnits: null,
                targetUnits: null,
                progressInfo: null
            };

            // Timeouts y intervalos
            this.timeouts = {
                debounce: null,
                retry: null,
                animation: null
            };

            this.init();
        }

        /**
         * Inicialización principal
         */
        init() {
            this.log('Inicializando DVMProgressIndicator...');
            
            // Verificar dependencias
            if (!this.checkDependencies()) {
                this.log('Dependencias no disponibles', 'error');
                return;
            }

            // Cachear elementos DOM
            this.cacheElements();

            // Verificar si hay elementos para mostrar
            if (!this.elements.banner && !this.elements.summary) {
                this.log('No se encontraron elementos del indicador de progreso');
                return;
            }

            // Configurar eventos
            this.bindEvents();

            // Cargar datos iniciales
            this.loadProgressData();

            // Marcar como inicializado
            this.state.isInitialized = true;
            
            this.log('DVMProgressIndicator inicializado correctamente');
        }

        /**
         * Verificar dependencias necesarias
         */
        checkDependencies() {
            // Verificar jQuery
            if (typeof $ === 'undefined') {
                console.error('DVM Progress: jQuery no está disponible');
                return false;
            }

            // Verificar datos AJAX localizados
            if (typeof dvmProgressAjax === 'undefined') {
                console.error('DVM Progress: Datos AJAX no localizados');
                return false;
            }

            // Verificar propiedades requeridas
            const required = ['ajax_url', 'nonce'];
            for (const prop of required) {
                if (!dvmProgressAjax[prop]) {
                    console.error(`DVM Progress: Propiedad requerida '${prop}' no encontrada`);
                    return false;
                }
            }

            return true;
        }

        /**
         * Cachear referencias a elementos DOM
         */
        cacheElements() {
            this.elements = {
                banner: $('#dvm-progress-banner'),
                summary: $('#dvm-progress-summary'),
                progressSteps: $('.dvm-progress-steps'),
                progressFill: $('.dvm-progress-fill'),
                progressLineFill: $('.dvm-progress-line-fill'),
                unitsNeeded: $('#dvm-units-needed'),
                nextDiscount: $('#dvm-next-discount'),
                motivationalMessage: $('#dvm-motivational-message'),
                currentUnits: $('.dvm-current-units'),
                targetUnits: $('.dvm-target-units'),
                progressInfo: $('.dvm-progress-info')
            };
        }

        /**
         * Configurar eventos del carrito y DOM
         */
        bindEvents() {
            const $body = $(document.body);

            // Eventos principales de WooCommerce
            $body.on('updated_cart_totals', () => {
                this.log('Evento: updated_cart_totals');
                this.debouncedUpdate();
            });

            $body.on('cart_item_removed', () => {
                this.log('Evento: cart_item_removed');
                this.debouncedUpdate();
            });

            $body.on('cart_item_restored', () => {
                this.log('Evento: cart_item_restored');
                this.debouncedUpdate();
            });

            // Eventos de cambio de cantidad
            $(document).on('change', '.qty, input[name*="cart"][name*="qty"]', () => {
                this.log('Evento: quantity_changed');
                this.debouncedUpdate();
            });

            // Eventos de WooCommerce Blocks (si están disponibles)
            if (typeof wc !== 'undefined' && wc.blocksCheckout) {
                $body.on('wc-blocks-checkout-set-selected-shipping-rate', () => {
                    this.debouncedUpdate();
                });
            }

            // Evento personalizado para forzar actualización
            $body.on('dvm_force_update', () => {
                this.log('Evento: dvm_force_update');
                this.loadProgressData();
            });

            // Eventos de visibilidad de página (para reactivar cuando vuelve el usuario)
            $(document).on('visibilitychange', () => {
                if (!document.hidden && this.state.isInitialized) {
                    this.debouncedUpdate();
                }
            });
        }

        /**
         * Actualización con debouncing para evitar múltiples peticiones
         */
        debouncedUpdate() {
            // Limpiar timeout anterior
            if (this.timeouts.debounce) {
                clearTimeout(this.timeouts.debounce);
            }

            // Configurar nuevo timeout
            this.timeouts.debounce = setTimeout(() => {
                this.loadProgressData();
            }, this.config.debounceDelay);
        }

        /**
         * Cargar datos de progreso via AJAX
         */
        loadProgressData() {
            // Evitar múltiples peticiones simultáneas
            if (this.state.isLoading) {
                this.log('Petición ya en curso, ignorando...');
                return;
            }

            this.state.isLoading = true;
            this.state.lastRequestTime = Date.now();

            // Mostrar estado de carga
            this.showLoadingState();

            const ajaxData = {
                action: 'dvm_get_progress_data',
                nonce: dvmProgressAjax.nonce
            };

            // Agregar datos adicionales si están disponibles
            if (dvmProgressAjax.debug) {
                ajaxData.debug = true;
            }

            $.ajax({
                url: dvmProgressAjax.ajax_url,
                type: 'POST',
                data: ajaxData,
                timeout: 10000, // 10 segundos timeout
                success: (response) => {
                    this.handleAjaxSuccess(response);
                },
                error: (xhr, status, error) => {
                    this.handleAjaxError(xhr, status, error);
                },
                complete: () => {
                    this.state.isLoading = false;
                    this.hideLoadingState();
                }
            });
        }

        /**
         * Manejar respuesta exitosa de AJAX
         */
        handleAjaxSuccess(response) {
            this.log('Respuesta AJAX recibida:', response);

            if (response.success && response.data) {
                this.state.currentData = response.data;
                this.state.retryCount = 0; // Reset retry counter
                
                // Actualizar componentes visuales
                this.updateAllComponents(response.data);
                
                // Disparar evento personalizado
                $(document.body).trigger('dvm_progress_updated', [response.data]);
                
                this.log('Datos de progreso actualizados correctamente');
            } else {
                const errorMsg = response.data?.message || 'Error desconocido';
                this.log(`Error en respuesta: ${errorMsg}`, 'error');
                this.showError(errorMsg);
            }
        }

        /**
         * Manejar errores de AJAX
         */
        handleAjaxError(xhr, status, error) {
            this.log(`Error AJAX: ${status} - ${error}`, 'error');
            
            // Incrementar contador de reintentos
            this.state.retryCount++;

            // Intentar reintento automático
            if (this.state.retryCount <= this.config.retryAttempts) {
                this.log(`Reintentando... (${this.state.retryCount}/${this.config.retryAttempts})`);
                
                this.timeouts.retry = setTimeout(() => {
                    this.loadProgressData();
                }, this.config.retryDelay * this.state.retryCount);
            } else {
                // Mostrar error después de agotar reintentos
                const errorMessage = this.getErrorMessage(status, error);
                this.showError(errorMessage);
                this.state.retryCount = 0; // Reset para futuros intentos
            }
        }

        /**
         * Obtener mensaje de error amigable
         */
        getErrorMessage(status, error) {
            const messages = dvmProgressAjax.messages || {};
            
            switch (status) {
                case 'timeout':
                    return messages.timeout || 'Tiempo de espera agotado. Intenta recargar la página.';
                case 'error':
                    return messages.error || 'Error de conexión. Verifica tu conexión a internet.';
                case 'parsererror':
                    return messages.parse_error || 'Error al procesar la respuesta del servidor.';
                default:
                    return messages.generic_error || 'Error al cargar datos. Intenta recargar la página.';
            }
        }

        /**
         * Actualizar todos los componentes visuales
         */
        updateAllComponents(data) {
            // Actualizar banner step-by-step
            if (this.elements.banner.length) {
                this.updateBanner(data);
            }

            // Actualizar barra de progreso en resumen
            if (this.elements.summary.length) {
                this.updateProgressBar(data);
            }

            // Mostrar elementos si estaban ocultos
            this.showElements();
        }

        /**
         * Actualizar banner step-by-step
         */
        updateBanner(data) {
            this.log('Actualizando banner...');

            // Actualizar pasos
            if (this.elements.progressSteps.length) {
                const stepsHtml = this.generateStepsHtml(data);
                this.elements.progressSteps.html(stepsHtml);
            }

            // Actualizar mensaje de unidades necesarias
            if (this.elements.unitsNeeded.length) {
                const unitsText = data.units_needed > 0 
                    ? `Agrega ${data.units_needed} unidades más`
                    : '¡Objetivo alcanzado!';
                
                this.animateTextChange(this.elements.unitsNeeded, unitsText);
            }

            // Actualizar mensaje de próximo descuento
            if (this.elements.nextDiscount.length) {
                const discountText = data.units_needed > 0 
                    ? `para obtener ${this.formatDiscount(data.next_discount)}% de descuento`
                    : '';
                
                this.animateTextChange(this.elements.nextDiscount, discountText);
            }

            // Aplicar clase de estado
            this.updateBannerState(data);
        }

        /**
         * Generar HTML para los pasos del banner
         */
        generateStepsHtml(data) {
            let stepsHtml = '<div class="dvm-progress-line"><div class="dvm-progress-line-fill"></div></div>';
            let completedSteps = 0;
            const levels = Object.entries(data.levels).sort((a, b) => parseInt(a[0]) - parseInt(b[0]));
            const totalSteps = levels.length;

            levels.forEach(([units, discount], index) => {
                const unitsNum = parseInt(units);
                const isCompleted = data.current_units >= unitsNum;
                const isCurrent = !isCompleted && (
                    index === 0 || 
                    data.current_units >= parseInt(levels[index - 1][0])
                );

                if (isCompleted) completedSteps++;

                const stepClass = isCompleted ? 'completed' : (isCurrent ? 'current' : 'pending');
                const stepContent = isCompleted ? '✓' : unitsNum;

                stepsHtml += `
                    <div class="dvm-step" data-units="${unitsNum}" data-discount="${discount}">
                        <div class="dvm-step-circle ${stepClass}">
                            ${stepContent}
                        </div>
                        <div class="dvm-step-label">
                            <div class="dvm-step-units">${unitsNum} unidades</div>
                            <div class="dvm-step-discount">${this.formatDiscount(discount)}% descuento</div>
                        </div>
                    </div>
                `;
            });

            // Actualizar línea de progreso después de renderizar
            setTimeout(() => {
                const progressPercentage = totalSteps > 0 ? (completedSteps / totalSteps) * 100 : 0;
                this.elements.progressLineFill.css('width', `${progressPercentage}%`);
            }, 50);

            return stepsHtml;
        }

        /**
         * Actualizar barra de progreso en resumen
         */
        updateProgressBar(data) {
            this.log('Actualizando barra de progreso...');

            // Actualizar barra de progreso principal
            if (this.elements.progressFill.length) {
                this.animateProgressBar(data.progress_percentage);
            }

            // Actualizar etiquetas de unidades
            if (this.elements.currentUnits.length) {
                this.animateTextChange(
                    this.elements.currentUnits.first(), 
                    `${data.current_units} unidades`
                );
                this.animateTextChange(
                    this.elements.currentUnits.last(), 
                    data.current_units.toString()
                );
            }

            if (this.elements.targetUnits.length) {
                const targetText = data.next_level ? data.next_level.toString() : 'Max';
                this.animateTextChange(this.elements.targetUnits, targetText);
            }

            // Actualizar información de progreso
            if (this.elements.progressInfo.length) {
                const currentText = `${data.current_units} unidades`;
                const percentageText = `${data.progress_percentage}%`;
                
                this.elements.progressInfo.find('span:first-child').text(currentText);
                this.elements.progressInfo.find('span:last-child').text(percentageText);
            }

            // Actualizar mensaje motivacional
            if (this.elements.motivationalMessage.length) {
                this.animateTextChange(this.elements.motivationalMessage, data.motivational_message);
            }

            // Aplicar clase de estado
            this.updateSummaryState(data);
        }

        /**
         * Animar cambio de texto con transición suave
         */
        animateTextChange($element, newText) {
            if (!$element.length || $element.text() === newText) {
                return;
            }

            $element.addClass('dvm-progress-updating');
            
            setTimeout(() => {
                $element.text(newText);
                $element.removeClass('dvm-progress-updating');
            }, this.config.animationDuration / 2);
        }

        /**
         * Animar barra de progreso
         */
        animateProgressBar(percentage) {
            if (!this.elements.progressFill.length) {
                return;
            }

            // Agregar clase de animación
            this.elements.progressFill.addClass('dvm-progress-updating');

            // Animar el ancho
            this.elements.progressFill.css('width', `${percentage}%`);

            // Remover clase después de la animación
            setTimeout(() => {
                this.elements.progressFill.removeClass('dvm-progress-updating');
            }, this.config.animationDuration);
        }

        /**
         * Actualizar estado del banner
         */
        updateBannerState(data) {
            if (!this.elements.banner.length) return;

            // Remover clases de estado anteriores
            this.elements.banner.removeClass('dvm-progress-success dvm-progress-warning dvm-progress-info');

            // Aplicar nueva clase según el estado
            if (data.is_max_level) {
                this.elements.banner.addClass('dvm-progress-success');
            } else if (data.units_needed <= 5) {
                this.elements.banner.addClass('dvm-progress-warning');
            } else {
                this.elements.banner.addClass('dvm-progress-info');
            }
        }

        /**
         * Actualizar estado del resumen
         */
        updateSummaryState(data) {
            if (!this.elements.summary.length) return;

            // Remover clases de estado anteriores
            this.elements.summary.removeClass('dvm-progress-success dvm-progress-warning dvm-progress-info');

            // Aplicar nueva clase según el estado
            if (data.is_max_level) {
                this.elements.summary.addClass('dvm-progress-success');
            } else if (data.units_needed <= 5) {
                this.elements.summary.addClass('dvm-progress-warning');
            } else {
                this.elements.summary.addClass('dvm-progress-info');
            }
        }

        /**
         * Mostrar estado de carga
         */
        showLoadingState() {
            this.elements.banner.addClass('dvm-progress-loading');
            this.elements.summary.addClass('dvm-progress-loading');

            // Agregar indicador de carga si no existe
            if (!$('.dvm-loading-indicator').length) {
                const loadingHtml = `
                    <div class="dvm-loading-indicator" style="
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        z-index: 1000;
                        background: rgba(255,255,255,0.9);
                        padding: 10px;
                        border-radius: 4px;
                        font-size: 12px;
                        color: #666;
                    ">
                        ${dvmProgressAjax.messages?.loading || 'Actualizando...'}
                    </div>
                `;
                
                this.elements.banner.css('position', 'relative').append(loadingHtml);
                this.elements.summary.css('position', 'relative').append(loadingHtml);
            }
        }

        /**
         * Ocultar estado de carga
         */
        hideLoadingState() {
            this.elements.banner.removeClass('dvm-progress-loading');
            this.elements.summary.removeClass('dvm-progress-loading');
            $('.dvm-loading-indicator').remove();
        }

        /**
         * Mostrar mensaje de error
         */
        showError(message) {
            this.log(`Mostrando error: ${message}`, 'error');

            // Remover errores anteriores
            $('.dvm-error-message').remove();

            const errorHtml = `
                <div class="dvm-error-message" style="
                    background: #f8d7da;
                    color: #721c24;
                    padding: 10px;
                    border: 1px solid #f5c6cb;
                    border-radius: 4px;
                    margin: 10px 0;
                    font-size: 14px;
                    text-align: center;
                ">
                    <strong>Error:</strong> ${message}
                    <button type="button" class="dvm-retry-btn" style="
                        margin-left: 10px;
                        padding: 2px 8px;
                        background: #721c24;
                        color: white;
                        border: none;
                        border-radius: 3px;
                        cursor: pointer;
                        font-size: 12px;
                    ">Reintentar</button>
                </div>
            `;

            // Mostrar error en ambos componentes
            this.elements.banner.prepend(errorHtml);
            this.elements.summary.prepend(errorHtml);

            // Configurar botón de reintento
            $('.dvm-retry-btn').on('click', () => {
                $('.dvm-error-message').remove();
                this.state.retryCount = 0;
                this.loadProgressData();
            });

            // Auto-ocultar después de 10 segundos
            setTimeout(() => {
                $('.dvm-error-message').fadeOut();
            }, 10000);
        }

        /**
         * Mostrar elementos del indicador
         */
        showElements() {
            // Mostrar banner si está oculto
            if (this.elements.banner.length && this.elements.banner.is(':hidden')) {
                this.elements.banner.fadeIn(this.config.animationDuration);
            }

            // Mostrar resumen si está oculto
            if (this.elements.summary.length && this.elements.summary.is(':hidden')) {
                this.elements.summary.fadeIn(this.config.animationDuration);
            }
        }

        /**
         * Formatear descuento para mostrar
         */
        formatDiscount(discount) {
            return parseFloat(discount).toFixed(1);
        }

        /**
         * Función de logging con control de debug
         */
        log(message, type = 'info') {
            if (!this.config.debug && !dvmProgressAjax.debug) {
                return;
            }

            const prefix = '[DVM Progress]';
            const timestamp = new Date().toLocaleTimeString();
            
            switch (type) {
                case 'error':
                    console.error(`${prefix} ${timestamp}:`, message);
                    break;
                case 'warn':
                    console.warn(`${prefix} ${timestamp}:`, message);
                    break;
                default:
                    console.log(`${prefix} ${timestamp}:`, message);
            }
        }

        /**
         * Destruir instancia y limpiar recursos
         */
        destroy() {
            this.log('Destruyendo DVMProgressIndicator...');

            // Limpiar timeouts
            Object.values(this.timeouts).forEach(timeout => {
                if (timeout) clearTimeout(timeout);
            });

            // Remover event listeners
            $(document.body).off('.dvm-progress');
            $(document).off('.dvm-progress');

            // Limpiar elementos
            $('.dvm-loading-indicator, .dvm-error-message').remove();

            // Reset estado
            this.state.isInitialized = false;
            
            this.log('DVMProgressIndicator destruido');
        }

        /**
         * Obtener datos actuales
         */
        getCurrentData() {
            return this.state.currentData;
        }

        /**
         * Forzar actualización
         */
        forceUpdate() {
            this.state.retryCount = 0;
            this.loadProgressData();
        }

        /**
         * Verificar si está inicializado
         */
        isInitialized() {
            return this.state.isInitialized;
        }
    }

    /**
     * Plugin jQuery wrapper
     */
    $.fn.dvmProgressIndicator = function(options) {
        return this.each(function() {
            if (!$.data(this, 'dvmProgressIndicator')) {
                $.data(this, 'dvmProgressIndicator', new DVMProgressIndicator(options));
            }
        });
    };

    /**
     * Inicialización automática cuando el DOM esté listo
     */
    $(document).ready(function() {
        // Verificar si estamos en la página del carrito
        if (!$('body').hasClass('woocommerce-cart')) {
            return;
        }

        // Verificar si hay elementos del indicador
        if (!$('#dvm-progress-banner, #dvm-progress-summary').length) {
            return;
        }

        // Crear instancia global
        window.dvmProgressIndicator = new DVMProgressIndicator({
            debug: dvmProgressAjax.debug || false
        });

        // Exponer métodos útiles globalmente
        window.dvmProgress = {
            update: () => window.dvmProgressIndicator?.forceUpdate(),
            getData: () => window.dvmProgressIndicator?.getCurrentData(),
            isReady: () => window.dvmProgressIndicator?.isInitialized()
        };
    });

    /**
     * Compatibilidad con WooCommerce Blocks
     */
    if (typeof wc !== 'undefined' && wc.blocksCheckout) {
        const { registerCheckoutFilters } = wc.blocksCheckout;
        
        // Registrar filtro para actualizar cuando cambie el checkout
        registerCheckoutFilters('dvm-progress-indicator', {
            cartItemClass: (value, extensions, args) => {
                // Trigger update when cart items change
                setTimeout(() => {
                    if (window.dvmProgressIndicator) {
                        window.dvmProgressIndicator.debouncedUpdate();
                    }
                }, 100);
                return value;
            }
        });
    }

})(jQuery);