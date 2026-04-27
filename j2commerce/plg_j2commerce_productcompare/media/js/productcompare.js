/**
 * J2Store Product Compare
 */
(function() {
    'use strict';

    // Configuration is injected by the plugin via Joomla.addScriptOptions()
    // and read here via Joomla.getOptions(). This avoids inline <script> blocks
    // and works correctly with Joomla's asset pipeline.
    const options = (typeof Joomla !== 'undefined' && Joomla.getOptions)
        ? (Joomla.getOptions('plg_j2store_productcompare') || {})
        : {};

    const ProductCompare = {
        storageKey: 'j2store_compare_products',
        maxProducts: options.maxProducts || 4,
        ajaxUrl: options.ajaxUrl || '',
        products: [],

        init() {
            this.loadFromStorage();
            this.bindEvents();
            this.updateUI();
        },

        loadFromStorage() {
            const stored = localStorage.getItem(this.storageKey);
            this.products = stored ? JSON.parse(stored) : [];
        },

        saveToStorage() {
            localStorage.setItem(this.storageKey, JSON.stringify(this.products));
        },

        bindEvents() {
            // Compare button clicks
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('j2store-compare-btn')) {
                    e.preventDefault();
                    const productId = parseInt(e.target.dataset.productId);
                    this.toggleProduct(productId, e.target);
                }
                
                // Modal close button
                if (e.target.classList.contains('modal-close') || e.target.classList.contains('modal-overlay')) {
                    const modal = document.getElementById('j2store-compare-modal');
                    if (modal) modal.style.display = 'none';
                }
            });

            // View comparison button
            const viewBtn = document.getElementById('compare-bar-view');
            if (viewBtn) {
                viewBtn.addEventListener('click', () => this.viewComparison());
            }

            // Clear all button
            const clearBtn = document.getElementById('compare-bar-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => this.clearAll());
            }
            
            // Close modal on ESC key
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
            const index = this.products.indexOf(productId);

            if (index > -1) {
                // Remove product
                this.products.splice(index, 1);
                button.classList.remove('active');
                button.textContent = button.dataset.originalText || 'Compare';
            } else {
                // Add product
                if (this.products.length >= this.maxProducts) {
                    alert(`You can only compare up to ${this.maxProducts} products`);
                    return;
                }
                this.products.push(productId);
                button.classList.add('active');
                button.dataset.originalText = button.textContent;
                button.textContent = 'Remove from Compare';
            }

            this.saveToStorage();
            this.updateUI();
        },

        updateUI() {
            const compareBar = document.getElementById('j2store-compare-bar');
            const productsContainer = document.getElementById('compare-bar-products');

            if (!compareBar || !productsContainer) return;

            if (this.products.length === 0) {
                compareBar.style.display = 'none';
                return;
            }

            compareBar.style.display = 'block';
            productsContainer.innerHTML = '';

            this.products.forEach(productId => {
                const productDiv = document.createElement('div');
                productDiv.className = 'compare-product-item';
                productDiv.innerHTML = `
                    <span>Product #${productId}</span>
                    <button type="button" class="remove-compare" data-product-id="${productId}">×</button>
                `;

                productDiv.querySelector('.remove-compare').addEventListener('click', (e) => {
                    e.preventDefault();
                    this.removeProduct(productId);
                });

                productsContainer.appendChild(productDiv);
            });

            // Update all compare buttons
            document.querySelectorAll('.j2store-compare-btn').forEach(btn => {
                const productId = parseInt(btn.dataset.productId);
                if (this.products.includes(productId)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
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
            if (!confirm('Clear all products from comparison?')) return;
            
            this.products = [];
            this.saveToStorage();
            this.updateUI();
        },

        viewComparison() {
            if (this.products.length < 2) {
                alert('Please select at least 2 products to compare');
                return;
            }

            // Show modal
            const modal = document.getElementById('j2store-compare-modal');
            const modalBody = document.getElementById('compare-modal-body');
            
            if (!modal || !modalBody) return;
            
            modal.style.display = 'block';
            modalBody.innerHTML = '<div class="loading">Loading...</div>';
            
            // Fetch comparison data via AJAX
            const ajaxUrl = this.ajaxUrl;
            
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    products: this.products
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data && data.data.html) {
                    modalBody.innerHTML = data.data.html;
                } else {
                    throw new Error(data.message || 'Failed to load comparison');
                }
            })
            .catch(error => {
                modalBody.innerHTML = `<div class="error">Error: ${error.message}</div>`;
            });
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ProductCompare.init());
    } else {
        ProductCompare.init();
    }

    // Expose to window for external access
    window.J2StoreProductCompare = ProductCompare;
})();
