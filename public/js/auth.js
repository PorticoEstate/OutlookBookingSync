/**
 * Shared Authentication Module for OutlookBookingSync Admin Interface
 * 
 * Provides authentication helpers for API key management and request headers.
 * Used across multiple admin pages to maintain consistent auth handling.
 */

class AdminAuth {
    constructor() {
        this.apiKeyStorageKey = 'dashboard_api_key';
    }

    /**
     * Get the stored API key from localStorage
     * @returns {string} The API key or empty string if not found
     */
    getApiKey() {
        try {
            return localStorage.getItem(this.apiKeyStorageKey) || '';
        } catch (_) {
            return '';
        }
    }

    /**
     * Store the API key in localStorage
     * @param {string} key - The API key to store
     */
    setApiKey(key) {
        try {
            if (key) {
                localStorage.setItem(this.apiKeyStorageKey, key);
            }
        } catch (_) {
            // ignore
        }
    }

    /**
     * Generate authentication headers for API requests
     * @param {string} tenantId - Optional tenant ID to include in headers
     * @returns {Object} Headers object with authentication and tenant info
     */
    getAuthHeaders(tenantId = null) {
        const key = this.getApiKey();
        const headers = { 'Accept': 'application/json' };
        
        if (key) {
            headers['api_key'] = key;
            headers['X-API-Key'] = key;
        }
        
        // Add tenant ID if provided or try to get from current form (but only if populated)
        const finalTenantId = tenantId || this.getCurrentTenantId();
        if (finalTenantId) {
            headers['X-Tenant-ID'] = finalTenantId;
        }
        
        return headers;
    }

    /**
     * Get current tenant ID from the tenantSelect dropdown (safe for initial loading)
     * @returns {string|null} The current tenant ID or null
     */
    getCurrentTenantId() {
        try {
            const tenantSelect = document.getElementById('tenantSelect');
            // Only return a value if the select has been populated (more than just default option)
            if (tenantSelect && tenantSelect.options.length > 1 && tenantSelect.value) {
                return tenantSelect.value;
            }
        } catch (error) {
            // Ignore errors during DOM access
        }
        return null;
    }

    /**
     * Prompt user for API key with optional custom message
     * @param {string} message - Custom prompt message
     * @returns {string} The entered API key or empty string
     */
    promptForApiKey(message = 'Admin access required. Enter API key:') {
        const key = window.prompt(message, '');
        if (key && key.trim()) {
            this.setApiKey(key.trim());
            this.showMessage('API key saved for this session.', 'success');
            return key.trim();
        }
        this.showMessage('API key not set. Some actions may fail with 401 Unauthorized.', 'error');
        return '';
    }

    /**
     * Handle 401 Unauthorized responses by prompting for new API key
     * @param {Response} response - The fetch response object
     * @returns {Promise<boolean>} True if retry should be attempted with new key
     */
    async handle401Response(response) {
        if (response.status === 401) {
            const key = this.promptForApiKey('Authentication failed. Please enter a valid API key:');
            return !!key; // Return true if user provided a key
        }
        return false;
    }

    /**
     * Display a message to the user (requires messageArea element in DOM)
     * @param {string} message - The message to display
     * @param {string} type - Message type: 'success', 'error', 'info', 'warning'
     */
    showMessage(message, type) {
        const messageArea = document.getElementById('messageArea');
        if (messageArea) {
            messageArea.innerHTML = `<div class="message ${type}">${this.escapeHtml(message)}</div>`;
            setTimeout(() => {
                messageArea.innerHTML = '';
            }, 5000);
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} HTML-escaped text
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Make an authenticated API request with automatic retry on 401 and JSON parsing
     * @param {string} url - The API endpoint URL
     * @param {Object} options - Fetch options (method, body, etc.)
     * @param {string} tenantId - Optional tenant ID
     * @returns {Promise<object>} The parsed JSON response
     */
    async authenticatedFetch(url, options = {}, tenantId = null) {
        // Merge auth headers with any existing headers
        const headers = {
            ...this.getAuthHeaders(tenantId),
            ...(options.headers || {})
        };

        // Add Content-Type for POST/PUT requests if not already set
        if ((options.method === 'POST' || options.method === 'PUT') && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        const requestOptions = {
            ...options,
            headers
        };

        let response = await fetch(url, requestOptions);

        // If unauthorized, prompt for new key and retry once
        if (response.status === 401) {
            const shouldRetry = await this.handle401Response(response);
            if (shouldRetry) {
                // Update headers with new API key
                const newHeaders = {
                    ...this.getAuthHeaders(tenantId),
                    ...(options.headers || {})
                };

                // Add Content-Type for POST/PUT requests if not already set
                if ((options.method === 'POST' || options.method === 'PUT') && !newHeaders['Content-Type']) {
                    newHeaders['Content-Type'] = 'application/json';
                }

                const retryOptions = {
                    ...options,
                    headers: newHeaders
                };
                response = await fetch(url, retryOptions);
            }
        }

        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        // Parse and return JSON
        return await response.json();
    }
}

// Create a singleton instance for global use
const adminAuth = new AdminAuth();

// Export for module systems (if needed)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminAuth;
}

// Add keyboard shortcut for API key prompt
document.addEventListener('keydown', (e) => {
    // Ctrl+K to set API key
    if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        adminAuth.promptForApiKey('Update API key:');
    }
});