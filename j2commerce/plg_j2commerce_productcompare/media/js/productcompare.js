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

    const text = (key, fallback) => {
        if (hasJoomla && Joomla.Text && typeof Joomla.Text._ === 'function') {
            const value = Joomla.Text._('PLG_J2COMMERCE_PRODUCTCOMPARE_' + key, fallback);

            return value || fallback;
        }

        return fallback;
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
                    alert(format(text('JS_MAX_PRODUCTS', 'You can compare up to %s products.'), this.maxProducts));
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
                button.textContent = text('JS_REMOVE', 'Remove from comparison');
            } else {
                button.classList.remove('active');
                button.textContent = button.dataset.originalText || text('DEFAULT_BUTTON_TEXT', 'Compare');
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
                label.textContent = format(text('JS_PRODUCT', 'Product #%s'), productId);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove-compare';
                remove.dataset.productId = String(productId);
                remove.textContent = '×';
                remove.setAttribute('aria-label', text('JS_REMOVE', 'Remove from comparison'));
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
            if (!confirm(text('JS_CLEAR_CONFIRM', 'Remove all products from the comparison?'))) return;

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
                alert(text('ERROR_MIN_PRODUCTS', 'Please select at least 2 products to compare'));
                return;
            }

            const modal = document.getElementById('j2store-compare-modal');
            const modalBody = document.getElementById('compare-modal-body');

            if (!modal || !modalBody) return;

            modal.style.display = 'block';
            modalBody.textContent = '';
            const loading = document.createElement('div');
            loading.className = 'loading';
            loading.textContent = text('LOADING', 'Loading comparison...');
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

                    this.showMessage(modalBody, (data && data.message) || text('JS_LOAD_FAILED', 'The comparison could not be loaded.'));
                })
                .catch(() => {
                    this.showMessage(modalBody, text('JS_LOAD_FAILED', 'The comparison could not be loaded.'));
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
