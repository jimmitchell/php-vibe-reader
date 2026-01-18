/**
 * CSRF token utility functions.
 * 
 * Handles CSRF token retrieval and adding tokens to fetch requests.
 */

/**
 * Get CSRF token from meta tag or form input.
 * @returns {string|null} CSRF token or null if not found
 */
function getCsrfToken() {
    // Try to get from meta tag first
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        return metaTag.getAttribute('content');
    }
    // Fallback: try to get from hidden input in any form
    const input = document.querySelector('input[name="_token"]');
    if (input) {
        return input.value;
    }
    return null;
}

/**
 * Add CSRF token to fetch options for POST/PUT/DELETE requests.
 * @param {Object} options - Fetch options object
 * @returns {Object} Modified options with CSRF token
 */
function addCsrfToken(options = {}) {
    const token = getCsrfToken();
    if (!token) return options;
    
    if (!options.headers) {
        options.headers = {};
    }
    
    // Add to headers
    options.headers['X-CSRF-Token'] = token;
    
    // Also add to body if it's a form data or JSON
    if (options.body) {
        if (options.body instanceof FormData) {
            options.body.append('_token', token);
        } else if (typeof options.body === 'string') {
            try {
                const json = JSON.parse(options.body);
                json._token = token;
                options.body = JSON.stringify(json);
            } catch (e) {
                // Not JSON, add as form data
                if (options.body.includes('&')) {
                    options.body += '&_token=' + encodeURIComponent(token);
                }
            }
        }
    } else if (options.method && ['POST', 'PUT', 'DELETE'].includes(options.method.toUpperCase())) {
        // If no body, create form data
        const formData = new FormData();
        formData.append('_token', token);
        options.body = formData;
    }
    
    return options;
}
