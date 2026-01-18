/**
 * Date formatting utility functions.
 */

/**
 * Format a date string according to user's timezone preference.
 * 
 * @param {string} dateString - ISO 8601 date string
 * @param {Object} options - Intl.DateTimeFormat options (optional)
 * @returns {string} Formatted date string
 */
function formatDate(dateString, options = {}) {
    if (!dateString) return '';
    const tz = window.userTimezone || (typeof userTimezone !== 'undefined' ? userTimezone : 'UTC');
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        ...options
    }).format(date);
}
