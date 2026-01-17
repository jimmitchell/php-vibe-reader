// Global state
let currentFeedId = null;
let currentItemId = null;
// hideReadItems is initialized from server-side script in dashboard.php
// If not set, default to true
if (typeof hideReadItems === 'undefined') {
    window.hideReadItems = true;
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    // Initialize theme based on default_theme_mode
    initializeTheme();
    
    loadFeeds().then(() => {
        // Fetch latest posts for all feeds on login/dashboard load
        refreshAllFeeds();
    });
    setupEventListeners();
    loadUserPreference();
});

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
                updateThemeButton();
            }
        });
    } else {
        document.documentElement.setAttribute('data-theme', defaultMode);
    }
    updateThemeButton();
}

function formatDate(dateString, options = {}) {
    if (!dateString) return '';
    const tz = window.userTimezone || (typeof userTimezone !== 'undefined' ? userTimezone : 'UTC');
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        ...options
    }).format(date);
}

function setupEventListeners() {
    // Add feed button
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
        await addFeed(url);
    });

    // Mark all as read button
    const markAllReadBtn = document.getElementById('mark-all-read-btn');
    markAllReadBtn.addEventListener('click', async () => {
        if (currentFeedId) {
            await markAllAsRead(currentFeedId);
        }
    });

    // Toggle hide read button
    const toggleHideReadBtn = document.getElementById('toggle-hide-read-btn');
    toggleHideReadBtn.addEventListener('click', async () => {
        await toggleHideRead();
    });

    // Refresh feed button
    const refreshFeedBtn = document.getElementById('refresh-feed-btn');
    refreshFeedBtn.addEventListener('click', async () => {
        if (currentFeedId) {
            await refreshFeed(currentFeedId);
        }
    });

    // Theme toggle
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', toggleTheme);
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
            await savePreferences();
        });
    }

    // Feed drag-and-drop reorder (delegation on list, set up once)
    setupFeedDragDrop(document.getElementById('feeds-list'));
}

async function loadFeeds() {
    try {
        const response = await fetch('/api/feeds');
        const feeds = await response.json();
        renderFeeds(feeds);
    } catch (error) {
        console.error('Error loading feeds:', error);
    }
}

async function refreshAllFeeds() {
    try {
        const response = await fetch('/api/feeds');
        const feeds = await response.json();
        if (feeds.length === 0) return;
        // Fetch each feed in the background (don't await, run in parallel)
        await Promise.all(feeds.map(feed => 
            fetch(`/feeds/${feed.id}/fetch`, { method: 'POST' }).catch(() => {})
        ));
        // Reload feeds list to show updated counts
        loadFeeds();
    } catch (error) {
        console.error('Error refreshing feeds:', error);
    }
}

async function refreshFeed(feedId) {
    const btn = document.getElementById('refresh-feed-btn');
    if (btn) btn.disabled = true;
    try {
        const response = await fetch(`/feeds/${feedId}/fetch`, { method: 'POST' });
        const result = await response.json();
        if (result.success && currentFeedId === feedId) {
            await loadFeedItems(feedId);
        }
        loadFeeds();
    } catch (error) {
        console.error('Error refreshing feed:', error);
    } finally {
        if (btn) btn.disabled = false;
    }
}

function renderFeeds(feeds) {
    const feedsList = document.getElementById('feeds-list');
    
    if (feeds.length === 0) {
        feedsList.innerHTML = '<div class="empty-state">No feeds yet. Add one to get started!</div>';
        return;
    }

    feedsList.innerHTML = feeds.map(feed => `
        <div class="feed-item ${feed.id === currentFeedId ? 'active' : ''} ${feed.unread_count > 0 ? 'unread' : ''}" 
             data-feed-id="${feed.id}" draggable="true">
            <div class="feed-item-content">
                <div class="feed-item-title">${escapeHtml(feed.title)}</div>
                <div class="feed-item-meta">
                    ${feed.item_count} items
                    ${feed.unread_count > 0 ? `• ${feed.unread_count} unread` : ''}
                </div>
            </div>
            <button class="feed-delete-btn" data-feed-id="${feed.id}" title="Delete feed">×</button>
        </div>
    `).join('');

    // Add click handlers
    feedsList.querySelectorAll('.feed-item').forEach(item => {
        const feedItemContent = item.querySelector('.feed-item-content');
        if (feedItemContent) {
            feedItemContent.addEventListener('click', () => {
                const feedId = parseInt(item.dataset.feedId);
                selectFeed(feedId);
            });
        }
    });

    // Add delete button handlers
    feedsList.querySelectorAll('.feed-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const feedId = parseInt(btn.dataset.feedId);
            if (confirm('Are you sure you want to delete this feed?')) {
                await deleteFeed(feedId);
            }
        });
    });
}

function setupFeedDragDrop(feedsList) {
    if (!feedsList) return;

    feedsList.addEventListener('dragstart', (e) => {
        const item = e.target.closest('.feed-item');
        if (!item) return;
        e.dataTransfer.setData('text/plain', item.dataset.feedId);
        e.dataTransfer.effectAllowed = 'move';
        item.classList.add('dragging');
    });

    feedsList.addEventListener('dragend', (e) => {
        const item = e.target.closest('.feed-item');
        if (item) item.classList.remove('dragging');
    });

    feedsList.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });

    feedsList.addEventListener('drop', async (e) => {
        e.preventDefault();
        const feedId = e.dataTransfer.getData('text/plain');
        if (!feedId) return;
        const droppedOn = e.target.closest('.feed-item');
        const draggedEl = feedsList.querySelector(`[data-feed-id="${feedId}"]`);
        if (!draggedEl) return;
        if (droppedOn && droppedOn.dataset.feedId === feedId) return;

        if (droppedOn) {
            feedsList.insertBefore(draggedEl, droppedOn);
        } else if (feedsList.contains(e.target)) {
            feedsList.appendChild(draggedEl);
        } else {
            return;
        }

        const order = [...feedsList.querySelectorAll('.feed-item')].map(el => el.dataset.feedId);
        await saveFeedOrder(order);
    });
}

async function saveFeedOrder(order) {
    try {
        const response = await fetch('/feeds/reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order })
        });
        const result = await response.json();
        if (!result.success) {
            alert('Error: ' + (result.error || 'Failed to save order'));
            loadFeeds(); // revert by reloading
        }
    } catch (error) {
        console.error('Error saving feed order:', error);
        loadFeeds(); // revert
    }
}

async function selectFeed(feedId) {
    currentFeedId = feedId;
    currentItemId = null;
    
    // Update active state
    document.querySelectorAll('.feed-item').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector(`[data-feed-id="${feedId}"]`).classList.add('active');

    // Update items title
    const feedTitle = document.querySelector(`[data-feed-id="${feedId}"] .feed-item-title`).textContent;
    document.getElementById('items-title').textContent = feedTitle;

    // Show action buttons
    document.querySelector('.pane-header-actions').style.display = 'flex';
    updateHideReadButton();

    // Load items
    await loadFeedItems(feedId);
    
    // Clear content
    document.getElementById('item-content').innerHTML = '<div class="empty-state">Select an item to read</div>';
    document.getElementById('content-title').textContent = 'Item';
}

async function loadFeedItems(feedId) {
    const itemsList = document.getElementById('items-list');
    itemsList.innerHTML = '<div class="loading">Loading items...</div>';

    try {
        const response = await fetch(`/feeds/${feedId}/items`);
        const items = await response.json();
        renderItems(items);
    } catch (error) {
        console.error('Error loading items:', error);
        itemsList.innerHTML = '<div class="empty-state">Error loading items</div>';
    }
}

function renderItems(items) {
    const itemsList = document.getElementById('items-list');
    
    if (items.length === 0) {
        itemsList.innerHTML = '<div class="empty-state">No items in this feed</div>';
        return;
    }

    itemsList.innerHTML = items.map(item => {
        const displayTitle = getItemDisplayTitle(item);
        return `
        <div class="item-entry ${item.is_read ? '' : 'unread'} ${item.id === currentItemId ? 'active' : ''}" 
             data-item-id="${item.id}">
            <div class="item-entry-title">${escapeHtml(displayTitle)}</div>
            <div class="item-entry-meta">
                ${item.published_at ? formatDate(item.published_at, { year: 'numeric', month: 'short', day: 'numeric' }) : ''}
                ${item.author ? `• ${escapeHtml(item.author)}` : ''}
            </div>
        </div>
        `;
    }).join('');

    // Add click handlers
    itemsList.querySelectorAll('.item-entry').forEach(item => {
        item.addEventListener('click', () => {
            const itemId = parseInt(item.dataset.itemId);
            selectItem(itemId);
        });
    });
}

async function selectItem(itemId) {
    currentItemId = itemId;
    
    // Update active state
    document.querySelectorAll('.item-entry').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector(`[data-item-id="${itemId}"]`).classList.add('active');

    // Mark as read
    await markAsRead(itemId);

    // Load item content
    await loadItemContent(itemId);
}

async function loadItemContent(itemId) {
    const itemContent = document.getElementById('item-content');
    itemContent.innerHTML = '<div class="loading">Loading content...</div>';

    try {
        // Get item details
        const response = await fetch(`/items/${itemId}`);
        const item = await response.json();
        
        // Check if item is read by checking the items list or fetching from feed
        let isRead = true; // Default to read
        const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
        if (itemElement) {
            // Use the visual state from the list
            isRead = !itemElement.classList.contains('unread');
        } else if (currentFeedId) {
            // If not in list (maybe hidden), check the feed items API
            try {
                const itemsResponse = await fetch(`/feeds/${currentFeedId}/items`);
                const items = await itemsResponse.json();
                const foundItem = items.find(i => i.id == itemId);
                if (foundItem) {
                    isRead = foundItem.is_read === 1 || foundItem.is_read === true;
                }
            } catch (e) {
                // If we can't determine, assume it's read (since it was just viewed)
                isRead = true;
            }
        }
        item.is_read = isRead;
        
        renderItemContent(item);
    } catch (error) {
        console.error('Error loading item:', error);
        itemContent.innerHTML = '<div class="empty-state">Error loading content</div>';
    }
}

function getReaderTitle(item) {
    const t = item.title || '';
    if (t.trim() === '' || t.trim().toLowerCase() === 'untitled') {
        return item.feed_title || 'Untitled';
    }
    return t;
}

function renderItemContent(item) {
    const itemContent = document.getElementById('item-content');
    const contentTitle = document.getElementById('content-title');
    const displayTitle = getReaderTitle(item);

    contentTitle.textContent = displayTitle;

    const content = item.content || item.summary || 'No content available';
    const link = item.link ? `<a href="${escapeHtml(item.link)}" target="_blank">Read original</a>` : '';
    const isRead = item.is_read !== undefined ? item.is_read : true; // Default to read if viewing
    
    const markUnreadButton = isRead ? 
        `<button id="mark-unread-btn" class="btn btn-icon btn-sm" aria-label="Mark as unread" title="Mark as unread">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
            </svg>
        </button>` : 
        '';

    itemContent.innerHTML = `
        <div class="item-content-header">
            <div class="item-content-title-row">
                <h1 class="item-content-title">${escapeHtml(displayTitle)}</h1>
                ${markUnreadButton}
            </div>
            <div class="item-content-meta">
                ${item.published_at ? formatDate(item.published_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''}
                ${item.author ? ` • ${escapeHtml(item.author)}` : ''}
                ${link ? ` • ${link}` : ''}
            </div>
        </div>
        <div class="item-content-body">${content}</div>
    `;

    // Add event listener for mark as unread button
    const markUnreadBtn = document.getElementById('mark-unread-btn');
    if (markUnreadBtn) {
        markUnreadBtn.addEventListener('click', async () => {
            await markAsUnread(item.id);
        });
    }

    // Update unread status in items list
    const itemElement = document.querySelector(`[data-item-id="${item.id}"]`);
    if (itemElement) {
        itemElement.classList.remove('unread');
    }

    // Reload feeds to update unread counts
    loadFeeds();
}

async function addFeed(url) {
    const modal = document.getElementById('add-feed-modal');
    const form = document.getElementById('add-feed-form');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding...';

    try {
        const formData = new FormData();
        formData.append('url', url);

        const response = await fetch('/feeds/add', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            modal.classList.remove('show');
            form.reset();
            loadFeeds();
            // Select the new feed
            setTimeout(() => {
                selectFeed(result.feed_id);
            }, 100);
        } else {
            alert('Error: ' + (result.error || 'Failed to add feed'));
        }
    } catch (error) {
        console.error('Error adding feed:', error);
        alert('Error adding feed. Please try again.');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Feed';
    }
}

async function markAsRead(itemId) {
    try {
        await fetch(`/items/${itemId}/read`, {
            method: 'POST'
        });
    } catch (error) {
        console.error('Error marking as read:', error);
    }
}

async function markAsUnread(itemId) {
    try {
        const response = await fetch(`/items/${itemId}/unread`, {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            // Update item in list to show as unread
            const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
            if (itemElement) {
                itemElement.classList.add('unread');
            }

            // Reload feeds to update unread counts
            loadFeeds();

            // If hide read is enabled, reload items (item will reappear in list)
            if (currentFeedId && window.hideReadItems) {
                await loadFeedItems(currentFeedId);
            } else if (currentFeedId) {
                // Just update the current item in the list
                await loadFeedItems(currentFeedId);
            }

            // Update the content view to remove the button
            if (currentItemId === itemId) {
                await loadItemContent(itemId);
            }
        } else {
            alert('Error: ' + (result.error || 'Failed to mark as unread'));
        }
    } catch (error) {
        console.error('Error marking as unread:', error);
        alert('Error marking as unread. Please try again.');
    }
}

async function deleteFeed(feedId) {
    try {
        const response = await fetch(`/feeds/${feedId}/delete`, {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            // If deleted feed was selected, clear the view
            if (currentFeedId === feedId) {
                currentFeedId = null;
                currentItemId = null;
                document.getElementById('items-list').innerHTML = '<div class="empty-state">Select a feed from the left to view items</div>';
                document.getElementById('item-content').innerHTML = '<div class="empty-state">Select an item to read</div>';
                document.getElementById('items-title').textContent = 'Select a feed';
                document.getElementById('content-title').textContent = 'Item';
                document.querySelector('.pane-header-actions').style.display = 'none';
            }
            // Reload feeds list
            loadFeeds();
        } else {
            alert('Error: ' + (result.error || 'Failed to delete feed'));
        }
    } catch (error) {
        console.error('Error deleting feed:', error);
        alert('Error deleting feed. Please try again.');
    }
}

async function markAllAsRead(feedId) {
    try {
        const response = await fetch(`/feeds/${feedId}/mark-all-read`, {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            // Reload items to update read status
            await loadFeedItems(feedId);
            // Reload feeds to update unread counts
            loadFeeds();
        } else {
            alert('Error: ' + (result.error || 'Failed to mark all as read'));
        }
    } catch (error) {
        console.error('Error marking all as read:', error);
        alert('Error marking all as read. Please try again.');
    }
}

async function loadUserPreference() {
    // Preference is initialized from server-side script in dashboard.php
    // Update button text based on current preference
    updateHideReadButton();
}

const EYE_OFF_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
const EYE_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
const SUN_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
const MOON_SVG = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

function updateHideReadButton() {
    const btn = document.getElementById('toggle-hide-read-btn');
    if (!btn) return;

    // hideReadItems true = only unread shown → button shows "Show all posts" + eye (no slash)
    // hideReadItems false = all shown → button shows "Hide all read" + eye-slash
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

async function toggleHideRead() {
    try {
        const response = await fetch('/preferences/toggle-hide-read', {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            window.hideReadItems = result.hide_read_items;
            updateHideReadButton();
            
            // Reload items with new filter
            if (currentFeedId) {
                await loadFeedItems(currentFeedId);
            }
        } else {
            alert('Error: ' + (result.error || 'Failed to toggle preference'));
        }
    } catch (error) {
        console.error('Error toggling hide read:', error);
        alert('Error toggling preference. Please try again.');
    }
}

async function toggleTheme() {
    try {
        const response = await fetch('/preferences/toggle-theme', { method: 'POST' });
        const result = await response.json();
        if (result.success) {
            document.documentElement.setAttribute('data-theme', result.dark_mode ? 'dark' : 'light');
            updateThemeButton();
        } else {
            alert('Error: ' + (result.error || 'Failed to toggle theme'));
        }
    } catch (error) {
        console.error('Error toggling theme:', error);
        alert('Error toggling theme. Please try again.');
    }
}

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

async function savePreferences() {
    const timezone = document.getElementById('timezone').value;
    const defaultThemeMode = document.getElementById('default-theme-mode').value;
    const newFontFamily = document.getElementById('font-family').value;
    
    try {
        const response = await fetch('/preferences', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ timezone, default_theme_mode: defaultThemeMode, font_family: newFontFamily })
        });
        const result = await response.json();
        if (result.success) {
            // Get the original font family from page load (set by PHP)
            const originalFontFamily = typeof fontFamily !== 'undefined' ? fontFamily : 'system';
            
            // Always reload page when font changes (needs new Google Fonts link in head)
            if (newFontFamily !== originalFontFamily) {
                window.location.reload();
                return;
            }
            
            // Update global variables
            window.userTimezone = timezone;
            window.defaultThemeMode = defaultThemeMode;
            // Reinitialize theme if default mode changed
            if (defaultThemeMode === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
            } else {
                document.documentElement.setAttribute('data-theme', defaultThemeMode);
            }
            updateThemeButton();
            // Close modal
            document.getElementById('preferences-modal').classList.remove('show');
        } else {
            alert('Error: ' + (result.error || 'Failed to save preferences'));
        }
    } catch (error) {
        console.error('Error saving preferences:', error);
        alert('Error saving preferences. Please try again.');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function stripHtml(html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent || div.innerText || '';
}

function getItemDisplayTitle(item) {
    const title = item.title || '';
    
    // If title is empty, null, or "Untitled", use content preview
    if (!title || title.trim() === '' || title.trim().toLowerCase() === 'untitled') {
        const content = item.content || item.summary || '';
        const textContent = stripHtml(content);
        const preview = textContent.trim();
        
        if (preview.length > 100) {
            return preview.substring(0, 100) + '...';
        }
        return preview || 'Untitled';
    }
    
    return title;
}
