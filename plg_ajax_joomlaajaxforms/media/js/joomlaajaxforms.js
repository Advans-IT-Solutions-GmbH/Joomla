/**
 * @package     Joomla.Plugin
 * @subpackage  Ajax.JoomlaAjaxForms
 *
 * @copyright   Copyright (C) 2025 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary License
 */

'use strict';

/**
 * Get language string from Joomla script options
 */
function getFormsLang(key, fallback) {
    try {
        var opts = Joomla.getOptions('plg_ajax_joomlaajaxforms') || {};
        return opts[key] || fallback;
    } catch (e) {
        return fallback;
    }
}

/**
 * Joomla AJAX Forms Handler
 * Converts standard Joomla forms to AJAX-powered forms
 */
const JoomlaAjaxForms = {
    /**
     * Configuration
     */
    config: {
        // option, plugin, format go in URL query to prevent Joomla SEF 301 redirect
        baseUrl: (function() {
            try {
                var paths = Joomla.getOptions('system.paths') || {};
                return (paths.root || '') + '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
            } catch (e) {
                return '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
            }
        })(),
        errorClass: 'alert alert-danger',
        successClass: 'alert alert-success',
        loadingClass: 'is-loading'
    },

    /**
     * Build URLSearchParams with POST body parameters.
     * Only task and token go in POST body; option/plugin/format are in the URL
     * query string to prevent Joomla's SEF router from issuing a 301 redirect.
     */
    buildBaseParams: function(task, tokenName) {
        var params = new URLSearchParams();
        params.append('task', task);
        if (tokenName) {
            params.append(tokenName, '1');
        }
        return params;
    },

    /**
     * Unwrap com_ajax response to get plugin data
     * com_ajax wraps plugin results in data[] as JSON strings
     *
     * @param {object} response - The com_ajax response
     * @returns {object} - The unwrapped plugin response
     */
    unwrapResponse: function(response) {
        if (response.data && Array.isArray(response.data) && response.data.length > 0) {
            var item = response.data[0];
            // data[0] can be a JSON string or already an object
            if (typeof item === 'string') {
                try {
                    return JSON.parse(item);
                } catch (e) {
                    return { success: response.success, message: item };
                }
            }
            if (typeof item === 'object' && item !== null) {
                return item;
            }
        }
        return response;
    },

    /**
     * Initialize the handler
     */
    init: function() {
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[JoomlaAjaxForms] Initializing...');
            JoomlaAjaxForms.initLoginForm();
            JoomlaAjaxForms.initRegistrationForm();
            JoomlaAjaxForms.initResetForm();
            JoomlaAjaxForms.initRemindForm();
            JoomlaAjaxForms.initProfileForm();
            JoomlaAjaxForms.relocateGuestFormMessages();
        });
    },

    /**
     * Move system messages into the guest order form's error container.
     * On the login page the regular login form uses AJAX (no system messages),
     * so any Joomla system messages must originate from the guest order form.
     * Uses a MutationObserver to catch alerts rendered after DOMContentLoaded.
     */
    relocateGuestFormMessages: function() {
        var guestError = document.getElementById('guest-login-error');
        if (!guestError) return;

        var smc = document.getElementById('system-message-container');
        if (!smc) return;

        function moveAlerts() {
            var alerts = smc.querySelectorAll('joomla-alert, .alert');
            if (!alerts.length) return false;

            var text = '';
            alerts.forEach(function(el) {
                if (text) { el.remove(); return; }
                // Joomla 5 <joomla-alert> renders:
                //   <div class="alert-heading">info</div>
                //   <div class="alert-wrapper">actual message</div>
                var wrapper = el.querySelector('.alert-wrapper, .alert-message');
                var msg = wrapper
                    ? wrapper.textContent.trim()
                    : el.textContent.trim().replace(/^(danger|error|warning|message|notice|info)\s*/i, '');
                if (msg) text = msg;
                el.remove();
            });

            if (text) {
                var errorSpan = guestError.querySelector('.error-message');
                if (errorSpan) {
                    errorSpan.textContent = text;
                }
                guestError.style.display = 'block';
            }
            return !!text;
        }

        // Try immediately (server-rendered alerts)
        if (moveAlerts()) return;

        // Watch for late-arriving alerts (Joomla may insert them asynchronously)
        var observer = new MutationObserver(function(mutations) {
            if (moveAlerts()) observer.disconnect();
        });
        observer.observe(smc, { childList: true, subtree: true });
        // Stop watching after 3 seconds
        setTimeout(function() { observer.disconnect(); }, 3000);
    },

    /**
     * Initialize login form
     */
    initLoginForm: function() {
        // Standard Joomla login form selectors
        const selectors = [
            'form[action*="com_users"][action*="login"]',
            '.login form.form-validate',
            '#login-form',
            'form.mod-login'
        ];
        
        selectors.forEach(function(selector) {
            const forms = document.querySelectorAll(selector);
            forms.forEach(function(form) {
                // Skip logout forms that happen to match login selectors
                var taskInput = form.querySelector('input[name="task"]');
                if (taskInput && taskInput.value && taskInput.value.indexOf('logout') !== -1) {
                    return;
                }
                if (form.action && form.action.indexOf('logout') !== -1) {
                    return;
                }
                if (!form.dataset.ajaxInitialized) {
                    JoomlaAjaxForms.convertLoginForm(form);
                    form.dataset.ajaxInitialized = 'true';
                }
            });
        });
    },

    /**
     * Initialize registration form
     */
    initRegistrationForm: function() {
        const selectors = [
            'form[action*="com_users"][action*="registration"]',
            '.registration form.form-validate',
            '#member-registration'
        ];
        
        selectors.forEach(function(selector) {
            const forms = document.querySelectorAll(selector);
            forms.forEach(function(form) {
                if (!form.dataset.ajaxInitialized) {
                    JoomlaAjaxForms.convertRegistrationForm(form);
                    form.dataset.ajaxInitialized = 'true';
                }
            });
        });
    },

    /**
     * Initialize password reset form
     */
    initResetForm: function() {
        const form = document.querySelector('.reset form.form-validate, .reset #user-registration, form[action*="reset.request"]');
        console.log('[JoomlaAjaxForms] Reset form search:', form ? 'FOUND' : 'NOT FOUND');
        if (form && !form.dataset.ajaxInitialized) {
            console.log('[JoomlaAjaxForms] Converting reset form to AJAX');
            JoomlaAjaxForms.convertForm(form, 'reset', ['email']);
            form.dataset.ajaxInitialized = 'true';
        }
    },

    /**
     * Initialize username reminder form
     */
    initRemindForm: function() {
        const form = document.querySelector('.remind form.form-validate, .remind #user-registration, form[action*="remind.remind"]');
        console.log('[JoomlaAjaxForms] Remind form search:', form ? 'FOUND' : 'NOT FOUND');
        if (form && !form.dataset.ajaxInitialized) {
            console.log('[JoomlaAjaxForms] Converting remind form to AJAX');
            JoomlaAjaxForms.convertForm(form, 'remind', ['email']);
            form.dataset.ajaxInitialized = 'true';
        }
    },

    /**
     * Convert login form to AJAX
     *
     * @param {HTMLFormElement} form - The form element
     */
    convertLoginForm: function(form) {
        const messageContainer = JoomlaAjaxForms.createMessageContainer(form);

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            
            // Get credentials - try different field name patterns
            const username = formData.get('username') || formData.get('jform[username]') || '';
            const password = formData.get('password') || formData.get('jform[password]') || '';
            const remember = formData.get('remember') || formData.get('jform[remember]') || '';
            const returnUrl = formData.get('return') || '';

            const tokenName = JoomlaAjaxForms.getTokenName(form);
            const body = JoomlaAjaxForms.buildBaseParams('login', tokenName);
            body.append('username', username);
            body.append('password', password);
            if (remember) body.append('remember', '1');
            if (returnUrl) body.append('return', returnUrl);

            const submitBtn = JoomlaAjaxForms.disableSubmit(form);
            messageContainer.style.display = 'none';

            fetch(JoomlaAjaxForms.config.baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(response => response.json())
            .then(function(rawData) {
                JoomlaAjaxForms.enableSubmit(submitBtn);
                var serverErrorMsg = JoomlaAjaxForms.extractAndClearMessages(rawData);
                JoomlaAjaxForms.clearSystemMessages();
                const data = JoomlaAjaxForms.unwrapResponse(rawData);

                if (data.success) {
                    JoomlaAjaxForms.showMessage(messageContainer, data.message, 'success');

                    // Redirect after successful login (or to MFA captive page)
                    var redirect = (data.data && data.data.redirect) || data.redirect;
                    if (redirect) {
                        setTimeout(function() {
                            window.location.href = redirect;
                        }, 1000);
                    } else {
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    var errorMsg = serverErrorMsg || JoomlaAjaxForms.getErrorMessage(data);
                    JoomlaAjaxForms.showMessage(messageContainer, errorMsg, 'error');
                }
            })
            .catch(function(error) {
                JoomlaAjaxForms.enableSubmit(submitBtn);
                JoomlaAjaxForms.showMessage(messageContainer, getFormsLang('ERROR_GENERIC', 'An error occurred. Please try again.'), 'error');
                console.error('JoomlaAjaxForms Error:', error);
            });
        });
    },

    /**
     * Convert registration form to AJAX
     *
     * @param {HTMLFormElement} form - The form element
     */
    convertRegistrationForm: function(form) {
        const messageContainer = JoomlaAjaxForms.createMessageContainer(form);

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            
            // Get registration fields
            const name = formData.get('jform[name]') || formData.get('name') || '';
            const username = formData.get('jform[username]') || formData.get('username') || '';
            const email = formData.get('jform[email1]') || formData.get('email') || '';
            const email2 = formData.get('jform[email2]') || formData.get('email2') || email;
            const password = formData.get('jform[password1]') || formData.get('password') || '';
            const password2 = formData.get('jform[password2]') || formData.get('password2') || '';

            const tokenName = JoomlaAjaxForms.getTokenName(form);
            const body = JoomlaAjaxForms.buildBaseParams('register', tokenName);
            body.append('name', name);
            body.append('username', username);
            body.append('email', email);
            body.append('email2', email2);
            body.append('password', password);
            body.append('password2', password2);

            const submitBtn = JoomlaAjaxForms.disableSubmit(form);
            messageContainer.style.display = 'none';

            fetch(JoomlaAjaxForms.config.baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(response => response.json())
            .then(function(rawData) {
                JoomlaAjaxForms.enableSubmit(submitBtn);
                var serverErrorMsg = JoomlaAjaxForms.extractAndClearMessages(rawData);
                JoomlaAjaxForms.clearSystemMessages();
                const data = JoomlaAjaxForms.unwrapResponse(rawData);

                if (data.success) {
                    JoomlaAjaxForms.showMessage(messageContainer, data.message, 'success');
                    form.reset();
                } else {
                    var errorMsg = serverErrorMsg || JoomlaAjaxForms.getErrorMessage(data);
                    JoomlaAjaxForms.showMessage(messageContainer, errorMsg, 'error');
                }
            })
            .catch(function(error) {
                JoomlaAjaxForms.enableSubmit(submitBtn);
                JoomlaAjaxForms.showMessage(messageContainer, getFormsLang('ERROR_GENERIC', 'An error occurred. Please try again.'), 'error');
                console.error('JoomlaAjaxForms Error:', error);
            });
        });
    },

    /**
     * Convert a standard form to AJAX (for reset/remind)
     *
     * @param {HTMLFormElement} form - The form element
     * @param {string} task - The task name
     * @param {array} fields - Field names to send
     */
    convertForm: function(form, task, fields) {
        const messageContainer = JoomlaAjaxForms.createMessageContainer(form);

        // Remove form-validate class to prevent Joomla's validator from submitting
        form.classList.remove('form-validate');
        
        // Disable HTML5 validation
        form.setAttribute('novalidate', 'novalidate');
        
        // Use capture phase to intercept before other handlers
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            const formData = new FormData(form);
            const tokenName = JoomlaAjaxForms.getTokenName(form);
            const body = JoomlaAjaxForms.buildBaseParams(task, tokenName);
            fields.forEach(function(field) {
                const value = formData.get('jform[' + field + ']') || formData.get(field) || '';
                body.append(field, value);
            });

            const submitBtn = JoomlaAjaxForms.disableSubmit(form);
            messageContainer.style.display = 'none';

            fetch(JoomlaAjaxForms.config.baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(response => response.json())
            .then(function(rawData) {
                JoomlaAjaxForms.enableSubmit(submitBtn);
                var serverErrorMsg = JoomlaAjaxForms.extractAndClearMessages(rawData);
                JoomlaAjaxForms.clearSystemMessages();
                const data = JoomlaAjaxForms.unwrapResponse(rawData);

                // Empty data[] from com_ajax means plugin was not reached (token/session issue)
                if (rawData.data && Array.isArray(rawData.data) && rawData.data.length === 0) {
                    JoomlaAjaxForms.showMessage(messageContainer, getFormsLang('ERROR_GENERIC', 'An error occurred. Please try again.'), 'error');
                } else if (data.success) {
                    var msg = data.message || (data.data && data.data.message) || '';
                    JoomlaAjaxForms.showMessage(messageContainer, msg, 'success');
                    form.reset();
                } else {
                    var errorMsg = serverErrorMsg || JoomlaAjaxForms.getErrorMessage(data);
                    JoomlaAjaxForms.showMessage(messageContainer, errorMsg, 'error');
                }
            })
            .catch(function(error) {
                JoomlaAjaxForms.enableSubmit(submitBtn);
                JoomlaAjaxForms.showMessage(messageContainer, getFormsLang('ERROR_GENERIC', 'An error occurred. Please try again.'), 'error');
                console.error('JoomlaAjaxForms Error:', error);
            });

            return false;
        }, true);
    },

    /**
     * Create message container for form
     *
     * @param {HTMLFormElement} form
     * @returns {HTMLElement}
     */
    createMessageContainer: function(form) {
        let container = form.querySelector('.ajax-message');
        if (!container) {
            container = document.createElement('div');
            container.className = 'ajax-message';
            container.style.display = 'none';
            form.insertBefore(container, form.firstChild);
        }
        return container;
    },

    /**
     * Get CSRF token name from form
     *
     * @param {HTMLFormElement} form
     * @returns {string}
     */
    getTokenName: function(form) {
        // Try standard token input
        const tokenInput = form.querySelector('input[name^="csrf.token"], input[name*="token"]');
        if (tokenInput) {
            return tokenInput.name;
        }

        // Find token from hidden inputs (32 char name with value 1)
        const hiddenInputs = form.querySelectorAll('input[type="hidden"]');
        for (let i = 0; i < hiddenInputs.length; i++) {
            const input = hiddenInputs[i];
            if (input.value === '1' && input.name.length === 32) {
                return input.name;
            }
        }

        return '';
    },

    /**
     * Disable submit button and return reference
     *
     * @param {HTMLFormElement} form
     * @returns {object}
     */
    disableSubmit: function(form) {
        const btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!btn) return null;

        const originalText = btn.tagName === 'INPUT' ? btn.value : btn.textContent;
        btn.disabled = true;
        btn.classList.add(JoomlaAjaxForms.config.loadingClass);
        
        if (btn.tagName === 'INPUT') {
            btn.value = '...';
        } else {
            btn.textContent = '...';
        }

        return { element: btn, originalText: originalText };
    },

    /**
     * Re-enable submit button
     *
     * @param {object} btnData
     */
    enableSubmit: function(btnData) {
        if (!btnData || !btnData.element) return;

        const btn = btnData.element;
        btn.disabled = false;
        btn.classList.remove(JoomlaAjaxForms.config.loadingClass);
        
        if (btn.tagName === 'INPUT') {
            btn.value = btnData.originalText;
        } else {
            btn.textContent = btnData.originalText;
        }
    },

    /**
     * Extract error message from response
     *
     * @param {object} data
     * @returns {string}
     */
    getErrorMessage: function(data) {
        if (data.error && data.error.warning) {
            return data.error.warning;
        }
        if (data.message) {
            return data.message;
        }
        return getFormsLang('ERROR_GENERIC', 'An error occurred');
    },

    /**
     * Show message in container
     *
     * @param {HTMLElement} container
     * @param {string} message
     * @param {string} type - 'success' or 'error'
     */
    showMessage: function(container, message, type) {
        container.className = 'ajax-message ' + (type === 'success' 
            ? JoomlaAjaxForms.config.successClass 
            : JoomlaAjaxForms.config.errorClass);
        container.innerHTML = '';
        var textNode = document.createElement('span');
        textNode.textContent = message || '';
        container.appendChild(textNode);
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'close';
        closeBtn.setAttribute('aria-label', 'Schliessen');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', function() { container.remove(); });
        container.appendChild(closeBtn);
        container.style.display = 'block';
        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    /**
     * Clear Joomla system messages from #system-message-container.
     * Prevents stale joomla-alert elements from showing alongside our AJAX messages.
     */
    clearSystemMessages: function() {
        var smc = document.getElementById('system-message-container');
        if (smc) {
            smc.querySelectorAll('joomla-alert, .alert').forEach(function(el) { el.remove(); });
        }
    },

    /**
     * Extract error messages from Joomla's messages object and delete it
     * from the response to prevent Joomla core from rendering them as
     * joomla-alert elements (which can show the type as text prefix).
     *
     * @param {object} rawData - Raw com_ajax response
     * @returns {string} First error message found, or empty string
     */
    extractAndClearMessages: function(rawData) {
        var msg = '';
        if (rawData && rawData.messages) {
            ['danger', 'error', 'warning'].forEach(function(type) {
                var arr = rawData.messages[type];
                if (!msg && arr && Array.isArray(arr) && arr.length) {
                    msg = arr[0];
                }
            });
            delete rawData.messages;
        }
        return msg;
    },

    /**
     * Logout via AJAX
     *
     * @param {string} returnUrl - Optional return URL (base64 encoded)
     * @returns {Promise}
     */
    logout: function(returnUrl) {
        // Get token from page
        let tokenName = '';
        const tokenInput = document.querySelector('input[type="hidden"][value="1"]');
        if (tokenInput && tokenInput.name.length === 32) {
            tokenName = tokenInput.name;
        }

        const body = JoomlaAjaxForms.buildBaseParams('logout', tokenName);
        if (returnUrl) {
            body.append('return', returnUrl);
        }

        return fetch(JoomlaAjaxForms.config.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(response => response.json())
        .then(function(rawData) {
            const data = JoomlaAjaxForms.unwrapResponse(rawData);
            if (data.success && data.data && data.data.redirect) {
                window.location.href = data.data.redirect;
            } else if (data.success) {
                window.location.reload();
            }
            return data;
        });
    },
    /**
     * Remove item from J2Store cart via AJAX
     *
     * @param {number} cartitemId - Cart item ID to remove
     * @param {Element} clickedElement - The clicked element (for visual feedback)
     * @param {Function} callback - Callback(error, data)
     */
    removeCartItem: function(cartitemId, clickedElement, callback) {
        var tokenName = '';
        var tokenInput = document.querySelector('input[type="hidden"][value="1"]');
        if (tokenInput && tokenInput.name.length === 32) {
            tokenName = tokenInput.name;
        }

        var body = JoomlaAjaxForms.buildBaseParams('removeCartItem', tokenName);
        body.append('cartitem_id', cartitemId);

        // Visual feedback: fade out the item
        var cartItem = clickedElement ? clickedElement.closest('.cartitems') : null;
        if (cartItem) {
            cartItem.style.opacity = '0.5';
        }

        fetch(JoomlaAjaxForms.config.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(response) { return response.json(); })
        .then(function(rawData) {
            var data = JoomlaAjaxForms.unwrapResponse(rawData);
            if (data.success) {
                // Remove the item from DOM
                if (cartItem) {
                    cartItem.remove();
                }
                // Update cart count badge
                var countBadges = document.querySelectorAll('.cart-item-count');
                countBadges.forEach(function(badge) {
                    if (data.data && typeof data.data.cartCount !== 'undefined') {
                        badge.textContent = data.data.cartCount;
                        if (data.data.cartCount === 0) {
                            badge.style.display = 'none';
                        }
                    }
                });
                // Update subtotal — hide when total is unavailable (cartTotal === '0.00')
                if (data.data && typeof data.data.cartTotal !== 'undefined') {
                    var subtotal = document.querySelector('.top-subtotal span');
                    if (subtotal) {
                        if (data.data.cartTotal === '0.00') {
                            subtotal.closest('.top-subtotal') && (subtotal.closest('.top-subtotal').style.display = 'none');
                        } else {
                            subtotal.innerHTML = data.data.cartTotal;
                        }
                    }
                }
                if (callback) callback(null, data);
            } else {
                if (cartItem) cartItem.style.opacity = '1';
                if (callback) callback(data.message || 'Error', null);
            }
        })
        .catch(function(error) {
            if (cartItem) cartItem.style.opacity = '1';
            if (callback) callback(error, null);
        });
    },

    /**
     * Initialize profile edit form
     */
    initProfileForm: function() {
        var selectors = [
            '#member-profile',
            '.profile-edit form.form-validate',
            'form[action*="profile.save"]'
        ];

        selectors.forEach(function(selector) {
            var forms = document.querySelectorAll(selector);
            forms.forEach(function(form) {
                if (!form.dataset.ajaxInitialized) {
                    console.log('[JoomlaAjaxForms] Converting profile form to AJAX');
                    // Prevent Joomla's form validator from submitting normally
                    form.classList.remove('form-validate');
                    form.setAttribute('novalidate', 'novalidate');
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        JoomlaAjaxForms.saveProfile(form);
                    });
                    form.dataset.ajaxInitialized = 'true';
                }
            });
        });
    },

    /**
     * Save user profile via AJAX
     *
     * @param {HTMLFormElement} form - The profile form
     */
    saveProfile: function(form) {
        var tokenName = JoomlaAjaxForms.getTokenName(form);
        var body = JoomlaAjaxForms.buildBaseParams('saveProfile', tokenName);

        // Collect all form fields
        var formData = new FormData(form);
        formData.forEach(function(value, key) {
            if (key !== 'option' && key !== 'plugin' && key !== 'format' && key !== 'task') {
                body.append(key, value);
            }
        });

        var messageContainer = form.querySelector('.ajax-message') || JoomlaAjaxForms.createMessageContainer(form);
        var submitBtn = JoomlaAjaxForms.disableSubmit(form);
        messageContainer.style.display = 'none';

        fetch(JoomlaAjaxForms.config.baseUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(response) { return response.json(); })
        .then(function(rawData) {
            JoomlaAjaxForms.enableSubmit(submitBtn);
                var serverErrorMsg = JoomlaAjaxForms.extractAndClearMessages(rawData);
                JoomlaAjaxForms.clearSystemMessages();
            var data = JoomlaAjaxForms.unwrapResponse(rawData);
            if (data.success) {
                var msg = data.message || getFormsLang('PROFILE_SAVED', 'Profile saved.');
                JoomlaAjaxForms.showMessage(messageContainer, msg, 'success');
            } else {
                JoomlaAjaxForms.showMessage(messageContainer, JoomlaAjaxForms.getErrorMessage(data), 'error');
            }
        })
        .catch(function(error) {
            console.error('[JoomlaAjaxForms] Profile save error:', error);
            JoomlaAjaxForms.enableSubmit(submitBtn);
            JoomlaAjaxForms.showMessage(messageContainer, getFormsLang('ERROR_GENERIC', 'An error occurred.'), 'error');
        });
    }
};

// Initialize
JoomlaAjaxForms.init();
