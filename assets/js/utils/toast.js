/**
 * Toast notification utility for displaying non-blocking messages.
 * 
 * Provides success, error, info, and warning toast notifications
 * that automatically dismiss after a configurable duration.
 */

/**
 * Show a toast notification.
 * 
 * @param {string} message - The message to display
 * @param {string} type - Toast type: 'success', 'error', 'info', 'warning' (default: 'info')
 * @param {number} duration - Duration in milliseconds before auto-dismiss (default: 4000)
 */
function showToast(message, type = 'info', duration = 4000) {
    // Create toast container if it doesn't exist
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    
    // Create icon based on type
    const icon = getToastIcon(type);
    
    // Create message element
    const messageEl = document.createElement('span');
    messageEl.className = 'toast-message';
    messageEl.textContent = message;
    
    // Create close button
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.innerHTML = '×';
    closeBtn.onclick = () => dismissToast(toast);
    
    // Assemble toast
    toast.appendChild(icon);
    toast.appendChild(messageEl);
    toast.appendChild(closeBtn);
    
    // Add to container
    container.appendChild(toast);
    
    // Trigger animation
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });
    
    // Auto-dismiss after duration
    if (duration > 0) {
        setTimeout(() => {
            dismissToast(toast);
        }, duration);
    }
    
    return toast;
}

/**
 * Get icon element for toast type.
 * 
 * @param {string} type - Toast type
 * @returns {HTMLElement} Icon element
 */
function getToastIcon(type) {
    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    
    const icons = {
        success: '✓',
        error: '✕',
        info: 'ℹ',
        warning: '⚠'
    };
    
    icon.textContent = icons[type] || icons.info;
    return icon;
}

/**
 * Dismiss a toast notification.
 * 
 * @param {HTMLElement} toast - The toast element to dismiss
 */
function dismissToast(toast) {
    if (!toast || !toast.parentElement) return;
    
    toast.classList.remove('show');
    toast.classList.add('dismissing');
    
    // Remove from DOM after animation
    setTimeout(() => {
        if (toast.parentElement) {
            toast.parentElement.removeChild(toast);
        }
    }, 300);
}

/**
 * Show a success toast.
 * 
 * @param {string} message - Success message
 * @param {number} duration - Duration in milliseconds (default: 4000)
 */
function showSuccess(message, duration = 4000) {
    return showToast(message, 'success', duration);
}

/**
 * Show an error toast.
 * 
 * @param {string} message - Error message
 * @param {number} duration - Duration in milliseconds (default: 6000)
 */
function showError(message, duration = 6000) {
    return showToast(message, 'error', duration);
}

/**
 * Show an info toast.
 * 
 * @param {string} message - Info message
 * @param {number} duration - Duration in milliseconds (default: 4000)
 */
function showInfo(message, duration = 4000) {
    return showToast(message, 'info', duration);
}

/**
 * Show a warning toast.
 * 
 * @param {string} message - Warning message
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showWarning(message, duration = 5000) {
    return showToast(message, 'warning', duration);
}
