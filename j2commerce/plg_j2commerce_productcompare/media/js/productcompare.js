/**
 * J2Commerce Product Compare
 *
 * Configuration (maxProducts, ajaxUrl, token) and texts are passed by the
 * plugin through Joomla's script options and read with Joomla.getOptions() and
 * Joomla.Text._() (asset dependency "core").
 */
(function() {
    'use strict';

    const hasJoomla = typeof Joomla !== 'undefined';
    const options = (hasJoomla && Joomla.getOptions)
        ? (Joomla.getOptions('plg_j2commerce_productcompare') || {})
        : {};

    // Translated text registered by the plugin with Text::script(), or an empty
    // string. The script carries no text of its own, so a site never shows a
    // message in a language other than its own.
    const text = (key) => {
        if (hasJoomla && Joomla.Text && typeof Joomla.Text._ === 'function') {
            const value = Joomla.Text._(key, '');

            return value && value !== key ? value : '';
        }

        return '';
    };

    const format = (template, value) => String(template).replace('%s', String(value)).replace('%d', String(value));

    const ProductCompare = {
        storageKey: 'j2store_compare_products',
        maxProducts: options.maxProducts || 4,
        ajaxUrl: options.ajaxUrl || '',
        token: options.token || '',
        products: [],

        init() {
            this.loadFromStorage();
            this.bindEvents();
            this.updateUI();
        },

        loadFromStorage() {
            try {
                const stored = localStorage.getItem(this.storageKey);
                const parsed = stored ? JSON.parse(stored) : [];
                this.products = Array.isArray(parsed)
                    ? parsed.map((id) => parseInt(id, 10)).filter((id) => id > 0)
                    : [];
            } catch (e) {
                this.products = [];
            }
        },

        saveToStorage() {
            try {
                localStorage.setItem(this.storageKey, JSON.stringify(this.products));
            } catch (e) {
                // Storage unavailable (private mode): keep the selection for this page only.
            }
        },

        bindEvents() {
            document.addEventListener('click', (e) => {
                const button = e.target.closest ? e.target.closest('.j2store-compare-btn') : null;

                if (button) {
                    e.preventDefault();
                    this.toggleProduct(parseInt(button.dataset.productId, 10), button);
                }

                if (e.target.classList.contains('modal-close') || e.target.classList.contains('modal-overlay')) {
                    const modal = document.getElementById('j2store-compare-modal');
                    if (modal) modal.style.display = 'none';
                }
            });

            const viewBtn = document.getElementById('compare-bar-view');
            if (viewBtn) {
                viewBtn.addEventListener('click', () => this.viewComparison());
            }

            const clearBtn = document.getElementById('compare-bar-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => this.clearAll());
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('j2store-compare-modal');
                    if (modal && modal.style.display === 'block') {
                        modal.style.display = 'none';
                    }
                }
            });
        },

        toggleProduct(productId, button) {
            if (!(productId > 0)) return;

            const index = this.products.indexOf(productId);

            if (index > -1) {
                this.products.splice(index, 1);
            } else {
                if (this.products.length >= this.maxProducts) {
                    const message = text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_MAX_PRODUCTS');
                    if (message) alert(format(message, this.maxProducts));
                    return;
                }
                this.products.push(productId);
            }

            this.saveToStorage();
            this.updateUI();
        },

        updateButton(button) {
            const productId = parseInt(button.dataset.productId, 10);

            if (button.dataset.originalText === undefined) {
                button.dataset.originalText = button.textContent;
            }

            if (this.products.includes(productId)) {
                button.classList.add('active');
                button.textContent = text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_REMOVE') || button.dataset.originalText;
            } else {
                button.classList.remove('active');
                button.textContent = button.dataset.originalText || text('PLG_J2COMMERCE_PRODUCTCOMPARE_DEFAULT_BUTTON_TEXT');
            }
        },

        updateUI() {
            document.querySelectorAll('.j2store-compare-btn').forEach((btn) => this.updateButton(btn));

            const compareBar = document.getElementById('j2store-compare-bar');
            const productsContainer = document.getElementById('compare-bar-products');

            if (!compareBar || !productsContainer) return;

            if (this.products.length === 0) {
                compareBar.style.display = 'none';
                return;
            }

            compareBar.style.display = 'block';
            productsContainer.textContent = '';

            this.products.forEach((productId) => {
                const productDiv = document.createElement('div');
                productDiv.className = 'compare-product-item';

                const label = document.createElement('span');
                label.textContent = format(text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_PRODUCT') || '%s', productId);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove-compare';
                remove.dataset.productId = String(productId);
                remove.textContent = '×';
                const removeLabel = text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_REMOVE');
                if (removeLabel) remove.setAttribute('aria-label', removeLabel);
                remove.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.removeProduct(productId);
                });

                productDiv.appendChild(label);
                productDiv.appendChild(remove);
                productsContainer.appendChild(productDiv);
            });
        },

        removeProduct(productId) {
            const index = this.products.indexOf(productId);
            if (index > -1) {
                this.products.splice(index, 1);
                this.saveToStorage();
                this.updateUI();
            }
        },

        clearAll() {
            const question = text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_CLEAR_CONFIRM');
            if (question && !confirm(question)) return;

            this.products = [];
            this.saveToStorage();
            this.updateUI();
        },

        /**
         * Request body for the com_ajax endpoint: form-encoded product IDs and the
         * form token, as the plugin's onAjaxProductcompare() reads them.
         */
        buildRequestBody() {
            const body = new URLSearchParams();

            this.products.forEach((id) => body.append('products[]', String(id)));

            if (this.token) {
                body.append(this.token, '1');
            }

            return body;
        },

        showMessage(container, message) {
            const box = document.createElement('div');
            box.className = 'error';
            box.textContent = message;
            container.textContent = '';
            container.appendChild(box);
        },

        viewComparison() {
            if (this.products.length < 2) {
                const message = text('PLG_J2COMMERCE_PRODUCTCOMPARE_ERROR_MIN_PRODUCTS');
                if (message) alert(message);
                return;
            }

            const modal = document.getElementById('j2store-compare-modal');
            const modalBody = document.getElementById('compare-modal-body');

            if (!modal || !modalBody) return;

            modal.style.display = 'block';
            modalBody.textContent = '';
            const loading = document.createElement('div');
            loading.className = 'loading';
            loading.textContent = text('PLG_J2COMMERCE_PRODUCTCOMPARE_LOADING');
            modalBody.appendChild(loading);

            fetch(this.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: this.buildRequestBody(),
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.success && data.data && typeof data.data.html === 'string') {
                        // The table HTML is rendered and escaped by the plugin's layout.
                        modalBody.innerHTML = data.data.html;
                        return;
                    }

                    this.showMessage(modalBody, (data && data.message) || text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_LOAD_FAILED'));
                })
                .catch(() => {
                    this.showMessage(modalBody, text('PLG_J2COMMERCE_PRODUCTCOMPARE_JS_LOAD_FAILED'));
                });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ProductCompare.init());
    } else {
        ProductCompare.init();
    }

    window.J2StoreProductCompare = ProductCompare;
})();
