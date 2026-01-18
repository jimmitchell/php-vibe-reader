/**
 * Main JavaScript application for VibeReader RSS feed reader.
 * 
 * This is the main entry point that coordinates all modules and initializes the application.
 * 
 * Module structure:
 * - utils/csrf.js - CSRF token utilities
 * - utils/toast.js - Toast notification system
 * - utils/dateFormat.js - Date formatting utilities
 * - utils/ui.js - UI utilities (loading overlay, theme, HTML escaping)
 * - modules/feeds.js - Feed management
 * - modules/items.js - Item management
 * - modules/folders.js - Folder management
 * - modules/search.js - Search functionality
 * - modules/preferences.js - User preferences
 */

// Global state
/** @type {number|null} Current selected feed ID */
let currentFeedId = null;

/** @type {number|null} Current selected item ID */
let currentItemId = null;

/** @type {Set<number>} Track which folders are collapsed */
const collapsedFolders = new Set();

// Make state available globally for modules
window.currentFeedId = currentFeedId;
window.currentItemId = currentItemId;
window.collapsedFolders = collapsedFolders;

// Initialize user preferences from server (set in dashboard.php)
if (typeof hideReadItems === 'undefined') {
    window.hideReadItems = true;
}
if (typeof hideFeedsWithNoUnread === 'undefined') {
    window.hideFeedsWithNoUnread = false;
}
if (typeof itemSortOrder === 'undefined') {
    window.itemSortOrder = 'newest';
}

/**
 * Set up all event listeners for user interactions.
 */
function setupEventListeners() {
    // Refresh all button
    const refreshAllBtn = document.getElementById('refresh-all-btn');
    if (refreshAllBtn) {
        refreshAllBtn.addEventListener('click', async () => {
            await refreshAllFeeds();
        });
    }

    // Toggle hide feeds with no unread button
    const toggleHideFeedsNoUnreadBtn = document.getElementById('toggle-hide-feeds-no-unread-btn');
    if (toggleHideFeedsNoUnreadBtn) {
        toggleHideFeedsNoUnreadBtn.addEventListener('click', async () => {
            await toggleHideFeedsWithNoUnread();
        });
    }

    // Create folder button
    const createFolderBtn = document.getElementById('create-folder-btn');
    if (createFolderBtn) {
        createFolderBtn.addEventListener('click', () => {
            const name = prompt('Enter folder name:');
            if (name && name.trim()) {
                createFolder(name.trim());
            }
        });
    }

    // Add feed button and modal
    const addFeedBtn = document.getElementById('add-feed-btn');
    const modal = document.getElementById('add-feed-modal');
    const closeBtn = modal.querySelector('.close');
    const addFeedForm = document.getElementById('add-feed-form');

    addFeedBtn.addEventListener('click', () => {
        modal.classList.add('show');
    });

    closeBtn.addEventListener('click', () => {
        modal.classList.remove('show');
    });

    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('show');
        }
    });

    addFeedForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = document.getElementById('feed-url').value;
        if (typeof addFeed === 'function') {
            await addFeed(url);
        }
    });

    // Mark all as read button
    const markAllReadBtn = document.getElementById('mark-all-read-btn');
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', async () => {
            if (window.currentFeedId && typeof markAllAsRead === 'function') {
                await markAllAsRead(window.currentFeedId);
            }
        });
    }

    // Toggle hide read button
    const toggleHideReadBtn = document.getElementById('toggle-hide-read-btn');
    if (toggleHideReadBtn) {
        toggleHideReadBtn.addEventListener('click', async () => {
            if (typeof toggleHideRead === 'function') {
                await toggleHideRead();
            }
        });
    }

    // Refresh feed button
    const refreshFeedBtn = document.getElementById('refresh-feed-btn');
    if (refreshFeedBtn) {
        refreshFeedBtn.addEventListener('click', async () => {
            if (window.currentFeedId && typeof refreshFeed === 'function') {
                await refreshFeed(window.currentFeedId);
            }
        });
    }

    // Toggle item sort order button
    const toggleItemSortBtn = document.getElementById('toggle-item-sort-btn');
    if (toggleItemSortBtn) {
        toggleItemSortBtn.addEventListener('click', async () => {
            if (typeof toggleItemSortOrder === 'function') {
                await toggleItemSortOrder();
            }
        });
    }

    // Theme toggle
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            if (typeof toggleTheme === 'function') {
                toggleTheme();
            }
        });
    }

    // Preferences modal
    const preferencesBtn = document.getElementById('preferences-btn');
    const preferencesModal = document.getElementById('preferences-modal');
    const preferencesClose = document.getElementById('preferences-close');
    const preferencesForm = document.getElementById('preferences-form');

    if (preferencesBtn && preferencesModal) {
        preferencesBtn.addEventListener('click', () => {
            loadPreferences();
            preferencesModal.classList.add('show');
        });

        preferencesClose.addEventListener('click', () => {
            preferencesModal.classList.remove('show');
        });

        window.addEventListener('click', (e) => {
            if (e.target === preferencesModal) {
                preferencesModal.classList.remove('show');
            }
        });

        preferencesForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (typeof savePreferences === 'function') {
                await savePreferences();
            }
        });
    }

    // Feed drag-and-drop reorder (delegation on list, set up once)
    if (typeof setupFeedDragDrop === 'function') {
        setupFeedDragDrop(document.getElementById('feeds-list'));
    }

    // Search functionality
    const searchInput = document.getElementById('search-input');
    let searchTimeout = null;

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            if (query.length === 0) {
                clearSearch();
                return;
            }

            // Debounce search
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (typeof performSearch === 'function') {
                    performSearch(query);
                }
            }, 300);
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                searchInput.value = '';
                if (typeof clearSearch === 'function') {
                    clearSearch();
                }
                searchInput.blur();
            }
        });
    }

    // OPML Export
    const exportOpmlBtn = document.getElementById('export-opml-btn');
    if (exportOpmlBtn) {
        exportOpmlBtn.addEventListener('click', () => {
            window.location.href = '/opml/export';
        });
    }

    // OPML Import
    const importOpmlInput = document.getElementById('import-opml-input');
    if (importOpmlInput) {
        importOpmlInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;

            // Show loading overlay
            showLoadingOverlay('Importing OPML file...');

            const formData = new FormData();
            formData.append('opml_file', file);

            try {
                const response = await fetch('/opml/import', addCsrfToken({
                    method: 'POST',
                    body: formData
                }));

                const result = await response.json();

                if (result.success) {
                    let message = `Successfully imported ${result.added} feed(s).`;
                    if (result.skipped > 0) {
                        message += ` ${result.skipped} feed(s) were skipped (already exist).`;
                    }
                    if (result.errors && result.errors.length > 0) {
                        message += '\n\nErrors:\n' + result.errors.join('\n');
                    }
                    hideLoadingOverlay();
                    showSuccess(message);
                    if (typeof loadFeeds === 'function') {
                        loadFeeds();
                    }
                } else {
                    hideLoadingOverlay();
                    showError('Error: ' + (result.error || 'Failed to import OPML file'));
                }
            } catch (error) {
                console.error('Error importing OPML:', error);
                hideLoadingOverlay();
                showError('Error importing OPML file. Please try again.');
            } finally {
                // Reset file input
                e.target.value = '';
            }
        });
    }
}

// Note: selectFeed and selectItem are defined in modules/items.js
// They update window.currentFeedId and window.currentItemId directly

/**
 * Initialize the application when DOM is loaded.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Initialize theme based on default_theme_mode
    initializeTheme();
    
    // Initialize button states
    if (typeof updateHideFeedsNoUnreadButton === 'function') {
        updateHideFeedsNoUnreadButton();
    }
    if (typeof updateItemSortButton === 'function') {
        updateItemSortButton();
    }
    
    if (typeof loadFeeds === 'function') {
        loadFeeds().then(() => {
            // Fetch latest posts for all feeds on login/dashboard load
            if (typeof refreshAllFeeds === 'function') {
                refreshAllFeeds();
            }
        });
    }
    setupEventListeners();
    if (typeof loadUserPreference === 'function') {
        loadUserPreference();
    }
});
