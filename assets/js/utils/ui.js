/**
 * UI utility functions for loading overlays and theme management.
 */

/**
 * Show a loading overlay with optional message.
 * 
 * @param {string} message - Loading message to display
 */
/**
 * Escape HTML to prevent XSS attacks.
 * 
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Strip HTML tags from a string.
 * 
 * @param {string} html - HTML string
 * @returns {string} Plain text
 */
function stripHtml(html) {
    if (!html) return '';
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent || div.innerText || '';
}

/**
 * Show a loading overlay with optional message.
 * 
 * @param {string} message - Loading message to display
 */
function showLoadingOverlay(message = 'Loading...') {
    // Remove existing overlay if any
    hideLoadingOverlay();
    
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.id = 'loading-overlay';
    
    overlay.innerHTML = `
        <div class="loading-spinner-container">
            <div class="loading-spinner"></div>
            <p class="loading-message">${escapeHtml(message)}</p>
        </div>
    `;
    document.body.appendChild(overlay);
}

/**
 * Hide the loading overlay.
 */
function hideLoadingOverlay() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Initialize the theme based on user's default_theme_mode preference.
 * 
 * If set to 'system', detects OS preference and listens for system theme changes.
 * Otherwise, applies the specified theme (light or dark).
 */
function initializeTheme() {
    const defaultMode = window.defaultThemeMode || (typeof defaultThemeMode !== 'undefined' ? defaultThemeMode : 'system');
    if (defaultMode === 'system') {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            // Only apply if we're still in system mode
            const currentMode = window.defaultThemeMode || (typeof defaultThemeMode !== 'undefined' ? defaultThemeMode : 'system');
            if (currentMode === 'system') {
                document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
                if (typeof updateThemeButton === 'function') {
                    updateThemeButton();
                }
            }
        });
    } else {
        document.documentElement.setAttribute('data-theme', defaultMode);
    }
    if (typeof updateThemeButton === 'function') {
        updateThemeButton();
    }
}
