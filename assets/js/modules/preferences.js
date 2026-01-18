/**
 * User preferences management module.
 * 
 * Handles user preferences including theme, timezone, font, and display options.
 * 
 * Dependencies:
 * - utils/csrf.js (addCsrfToken)
 * - utils/toast.js (showError)
 * - utils/ui.js (initializeTheme)
 * - modules/items.js (loadFeedItems)
 * - modules/feeds.js (loadFeeds)
 */

// SVG icons for buttons
const EYE_OFF_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
const EYE_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
const ARROW_UP_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
const ARROW_DOWN_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>';
const SUN_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
const MOON_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

/**
 * Load user preferences from server.
 */
async function loadUserPreference() {
    // Preference is initialized from server-side script in dashboard.php
    // Update button text based on current preference
    updateHideReadButton();
}

/**
 * Update the item sort order button appearance based on current state.
 */
function updateItemSortButton() {
    const btn = document.getElementById('toggle-item-sort-btn');
    if (!btn) return;

    const sortOrder = window.itemSortOrder || 'newest';
    
    if (sortOrder === 'oldest') {
        btn.setAttribute('aria-label', 'Sort by newest first');
        btn.setAttribute('title', 'Sort by newest first');
        btn.innerHTML = ARROW_UP_SVG;
    } else {
        btn.setAttribute('aria-label', 'Sort by oldest first');
        btn.setAttribute('title', 'Sort by oldest first');
        btn.innerHTML = ARROW_DOWN_SVG;
    }
}

/**
 * Update the hide read items button appearance.
 */
function updateHideReadButton() {
    const btn = document.getElementById('toggle-hide-read-btn');
    if (!btn) return;

    if (window.hideReadItems) {
        btn.setAttribute('aria-label', 'Show all posts');
        btn.setAttribute('title', 'Show all posts');
        btn.innerHTML = EYE_SVG;
    } else {
        btn.setAttribute('aria-label', 'Hide all read');
        btn.setAttribute('title', 'Hide all read');
        btn.innerHTML = EYE_OFF_SVG;
    }
}

/**
 * Update the hide feeds with no unread button appearance.
 */
function updateHideFeedsNoUnreadButton() {
    const btn = document.getElementById('toggle-hide-feeds-no-unread-btn');
    if (!btn) return;
    const isActive = window.hideFeedsWithNoUnread || false;
    
    if (isActive) {
        btn.setAttribute('aria-label', 'Show all feeds');
        btn.setAttribute('title', 'Show all feeds');
        btn.innerHTML = EYE_SVG;
    } else {
        btn.setAttribute('aria-label', 'Hide feeds with no unread items');
        btn.setAttribute('title', 'Hide feeds with no unread items');
        btn.innerHTML = EYE_OFF_SVG;
    }
}

/**
 * Update the theme toggle button appearance.
 */
function updateThemeButton() {
    const btn = document.getElementById('theme-toggle-btn');
    if (!btn) return;
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
        btn.setAttribute('aria-label', 'Switch to light mode');
        btn.setAttribute('title', 'Switch to light mode');
        btn.innerHTML = SUN_SVG;
    } else {
        btn.setAttribute('aria-label', 'Switch to dark mode');
        btn.setAttribute('title', 'Switch to dark mode');
        btn.innerHTML = MOON_SVG;
    }
}

/**
 * Toggle the item sort order preference.
 */
async function toggleItemSortOrder() {
    try {
        const response = await fetch('/preferences/toggle-item-sort-order', addCsrfToken({
            method: 'POST'
        }));

        const result = await response.json();

        if (result.success) {
            window.itemSortOrder = result.item_sort_order;
            updateItemSortButton();
            
            // Reload items with new sort order
            if (window.currentFeedId && typeof loadFeedItems === 'function') {
                await loadFeedItems(window.currentFeedId);
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to toggle sort order'));
        }
    } catch (error) {
        console.error('Error toggling item sort order:', error);
        showError('Error toggling sort order. Please try again.');
    }
}

/**
 * Toggle the hide read items preference.
 */
async function toggleHideRead() {
    try {
        const response = await fetch('/preferences/toggle-hide-read', addCsrfToken({
            method: 'POST'
        }));

        const result = await response.json();

        if (result.success) {
            window.hideReadItems = result.hide_read_items;
            updateHideReadButton();
            
            // Reload items with new filter
            if (window.currentFeedId && typeof loadFeedItems === 'function') {
                await loadFeedItems(window.currentFeedId);
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to toggle preference'));
        }
    } catch (error) {
        console.error('Error toggling hide read:', error);
        showError('Error toggling preference. Please try again.');
    }
}

/**
 * Toggle the hide feeds with no unread preference.
 */
async function toggleHideFeedsWithNoUnread() {
    try {
        const response = await fetch('/preferences/toggle-hide-feeds-no-unread', addCsrfToken({
            method: 'POST'
        }));

        const result = await response.json();

        if (result.success) {
            window.hideFeedsWithNoUnread = result.hide_feeds_with_no_unread;
            updateHideFeedsNoUnreadButton();
            
            // Reload feeds list with new filter
            if (typeof loadFeeds === 'function') {
                await loadFeeds();
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to toggle preference'));
        }
    } catch (error) {
        console.error('Error toggling hide feeds with no unread:', error);
        showError('Error toggling preference. Please try again.');
    }
}

/**
 * Toggle the theme (light/dark mode).
 */
async function toggleTheme() {
    try {
        const response = await fetch('/preferences/toggle-theme', addCsrfToken({ method: 'POST' }));
        const result = await response.json();
        if (result.success) {
            document.documentElement.setAttribute('data-theme', result.dark_mode ? 'dark' : 'light');
            updateThemeButton();
        } else {
            showError('Error: ' + (result.error || 'Failed to toggle theme'));
        }
    } catch (error) {
        console.error('Error toggling theme:', error);
        showError('Error toggling theme. Please try again.');
    }
}

/**
 * Load preferences into the preferences modal.
 */
async function loadPreferences() {
    try {
        const response = await fetch('/preferences');
        const result = await response.json();
        if (result.success) {
            document.getElementById('timezone').value = result.timezone || 'UTC';
            document.getElementById('default-theme-mode').value = result.default_theme_mode || 'system';
            if (document.getElementById('font-family')) {
                document.getElementById('font-family').value = result.font_family || 'system';
            }
        }
    } catch (error) {
        console.error('Error loading preferences:', error);
    }
}

/**
 * Save preferences from the preferences modal.
 */
async function savePreferences() {
    const timezone = document.getElementById('timezone').value;
    const defaultThemeMode = document.getElementById('default-theme-mode').value;
    const fontFamily = document.getElementById('font-family') ? document.getElementById('font-family').value : null;

    try {
        const response = await fetch('/preferences', addCsrfToken({
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                timezone,
                default_theme_mode: defaultThemeMode,
                font_family: fontFamily
            })
        }));

        const result = await response.json();

        if (result.success) {
            // Update global variables
            if (timezone) window.userTimezone = timezone;
            if (defaultThemeMode) window.defaultThemeMode = defaultThemeMode;
            if (fontFamily) window.fontFamily = fontFamily;
            
            // Reinitialize theme if it changed
            if (defaultThemeMode && typeof initializeTheme === 'function') {
                initializeTheme();
            }
            
            // Reload page to apply font family change
            if (fontFamily) {
                window.location.reload();
            } else {
                document.getElementById('preferences-modal').classList.remove('show');
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to save preferences'));
        }
    } catch (error) {
        console.error('Error saving preferences:', error);
        showError('Error saving preferences. Please try again.');
    }
}
