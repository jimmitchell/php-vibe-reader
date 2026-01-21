/**
 * Feed items management module.
 * 
 * Handles loading, rendering, and managing feed items (mark as read/unread, display content).
 * 
 * Dependencies:
 * - utils/csrf.js (addCsrfToken)
 * - utils/toast.js (showError, showSuccess)
 * - utils/dateFormat.js (formatDate)
 * - utils/ui.js (escapeHtml, stripHtml)
 * - modules/feeds.js (loadFeeds)
 */

/**
 * Get display title for an item (used in both items list and search results).
 * 
 * @param {Object} item - Item object
 * @returns {string} Display title
 */
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

/**
 * Select a feed and load its items.
 * 
 * @param {number} feedId - The feed ID to select
 */
async function selectFeed(feedId) {
    window.currentFeedId = feedId;
    window.currentItemId = null;
    
    // Update active state in feeds list
    document.querySelectorAll('.feed-item').forEach(item => {
        item.classList.toggle('active', parseInt(item.dataset.feedId) === feedId);
    });
    
    // Update items title
    const feedItem = document.querySelector(`[data-feed-id="${feedId}"]`);
    const feedTitle = feedItem ? feedItem.querySelector('.feed-item-title').textContent : 'Feed';
    document.getElementById('items-title').textContent = feedTitle;
    
    // Show action buttons
    const paneHeaderActions = document.querySelector('.pane-header-actions');
    if (paneHeaderActions) {
        paneHeaderActions.style.display = 'flex';
    }
    
    // Update button states
    if (typeof updateHideReadButton === 'function') {
        updateHideReadButton();
    }
    if (typeof updateItemSortButton === 'function') {
        updateItemSortButton();
    }
    
    // Load items for this feed (this will apply hideReadItems filter if enabled)
    await loadFeedItems(feedId);
    
    // Clear item content
    document.getElementById('item-content').innerHTML = '<div class="empty-state">Select an item to read</div>';
    document.getElementById('content-title').textContent = 'Item';
}

/**
 * Load feed items for a specific feed.
 * 
 * @param {number} feedId - The feed ID
 */
async function loadFeedItems(feedId) {
    const itemsList = document.getElementById('items-list');
    itemsList.innerHTML = '<div class="loading">Loading items...</div>';
    
    try {
        const response = await fetch(`/feeds/${feedId}/items`);
        const items = await response.json();
        renderItems(items);
    } catch (error) {
        console.error('Error loading feed items:', error);
        itemsList.innerHTML = '<div class="empty-state">Error loading items</div>';
    }
}

/**
 * Render feed items in the items list.
 * 
 * @param {Array} items - Array of item objects
 */
function renderItems(items) {
    const itemsList = document.getElementById('items-list');
    
    if (items.length === 0) {
        itemsList.innerHTML = '<div class="empty-state">No items in this feed</div>';
        return;
    }

    itemsList.innerHTML = items.map(item => {
        const displayTitle = getItemDisplayTitle(item);
        return `
        <div class="item-entry ${item.is_read ? '' : 'unread'} ${item.id === window.currentItemId ? 'active' : ''}" 
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

/**
 * Select an item and load its content.
 * 
 * @param {number} itemId - The item ID to select
 */
async function selectItem(itemId) {
    window.currentItemId = itemId;
    
    // Update active state in items list
    document.querySelectorAll('.item-entry').forEach(item => {
        item.classList.toggle('active', parseInt(item.dataset.itemId) === itemId);
    });
    
    // Mark as read first
    await markAsRead(itemId);
    
    // Load item content
    await loadItemContent(itemId);
}

/**
 * Load and display the full content of an item.
 * 
 * @param {number} itemId - The item ID
 */
async function loadItemContent(itemId) {
    const itemContent = document.getElementById('item-content');
    itemContent.innerHTML = '<div class="loading">Loading content...</div>';
    
    try {
        const response = await fetch(`/items/${itemId}`);
        const item = await response.json();
        
        // Check if item is read
        let isRead = true;
        const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
        if (itemElement) {
            isRead = !itemElement.classList.contains('unread');
        }
        item.is_read = isRead;
        
        renderItemContent(item);
        
        // Update content title
        const displayTitle = getReaderTitle(item);
        document.getElementById('content-title').textContent = displayTitle;
    } catch (error) {
        console.error('Error loading item content:', error);
        itemContent.innerHTML = '<div class="empty-state">Error loading content</div>';
    }
}

/**
 * Get the reader title for an item (used in content view).
 * 
 * @param {Object} item - Item object
 * @returns {string} Display title
 */
function getReaderTitle(item) {
    const t = item.title || '';
    if (t.trim() === '' || t.trim().toLowerCase() === 'untitled') {
        return item.feed_title || 'Untitled';
    }
    return t;
}

/**
 * Render item content in the content pane.
 * 
 * @param {Object} item - Item object
 */
function renderItemContent(item) {
    const itemContent = document.getElementById('item-content');
    const contentTitle = document.getElementById('content-title');
    const displayTitle = getReaderTitle(item);

    contentTitle.textContent = displayTitle;

    const content = item.content || item.summary || 'No content available';
    const link = item.link ? `<a href="${escapeHtml(item.link)}" target="_blank">Read original</a>` : '';
    const isRead = item.is_read !== undefined ? item.is_read : true;
    
    const markUnreadButton = isRead ? 
        `<button id="mark-unread-btn" class="btn btn-icon btn-sm" aria-label="Mark as unread" title="Mark as unread">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
            </svg>
        </button>` : '';

    // Build title row with mark unread button on the right
    const titleRow = markUnreadButton ? `
        <div class="item-content-title-row">
            <h1 class="item-content-title">${escapeHtml(displayTitle)}</h1>
            ${markUnreadButton}
        </div>
    ` : `<h1 class="item-content-title">${escapeHtml(displayTitle)}</h1>`;

    // Build meta row with "read original" link on the right
    const metaContent = `
        ${item.published_at ? formatDate(item.published_at, { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''}
        ${item.author ? ` • ${escapeHtml(item.author)}` : ''}
    `;
    
    const metaRow = link ? `
        <div class="item-content-meta-row">
            <div class="item-content-meta">${metaContent}</div>
            <div class="item-content-link">${link}</div>
        </div>
    ` : `<div class="item-content-meta">${metaContent}</div>`;

    // Sanitize HTML content with DOMPurify if available (defense in depth)
    const sanitizedContent = (typeof DOMPurify !== 'undefined') 
        ? DOMPurify.sanitize(content, { 
            ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'table', 'thead', 'tbody', 'tr', 'td', 'th'],
            ALLOWED_ATTR: ['href', 'title', 'target', 'src', 'alt', 'width', 'height', 'style', 'rel'],
            ALLOW_DATA_ATTR: false
        })
        : content; // Fallback if DOMPurify not loaded
    
    itemContent.innerHTML = `
        <div class="item-content-body">
            ${titleRow}
            ${metaRow}
            <div class="item-content-text">
                ${sanitizedContent}
            </div>
        </div>
    `;
    
    // Add click handler for mark unread button
    const markUnreadBtn = document.getElementById('mark-unread-btn');
    if (markUnreadBtn) {
        markUnreadBtn.addEventListener('click', () => {
            markAsUnread(item.id);
        });
    }
}

/**
 * Mark an item as read.
 * 
 * @param {number} itemId - The item ID
 */
async function markAsRead(itemId) {
    try {
        await fetch(`/items/${itemId}/read`, addCsrfToken({
            method: 'POST'
        }));
        
        // Update UI - remove unread class but keep item visible
        const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
        if (itemElement) {
            itemElement.classList.remove('unread');
        }
        
        // Reload feeds to update unread counts
        if (typeof loadFeeds === 'function') {
            loadFeeds();
        }
        
        // Note: We don't reload items here - items will remain visible
        // until the user switches to a different feed, at which point
        // selectFeed() will reload items with the hideReadItems filter applied
    } catch (error) {
        console.error('Error marking as read:', error);
    }
}

/**
 * Mark an item as unread.
 * 
 * @param {number} itemId - The item ID
 */
async function markAsUnread(itemId) {
    try {
        const response = await fetch(`/items/${itemId}/unread`, addCsrfToken({
            method: 'POST'
        }));

        const result = await response.json();

        if (result.success) {
            // Update item in list to show as unread
            const itemElement = document.querySelector(`[data-item-id="${itemId}"]`);
            if (itemElement) {
                itemElement.classList.add('unread');
            }

            // Reload feeds to update unread counts
            if (typeof loadFeeds === 'function') {
                loadFeeds();
            }

            // If hide read is enabled, reload items (item will reappear in list)
            if (window.currentFeedId && window.hideReadItems) {
                await loadFeedItems(window.currentFeedId);
            } else if (window.currentFeedId) {
                // Just update the current item in the list
                await loadFeedItems(window.currentFeedId);
            }

            // Update the content view to remove the button
            if (window.currentItemId === itemId) {
                await loadItemContent(itemId);
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to mark as unread'));
        }
    } catch (error) {
        console.error('Error marking as unread:', error);
        showError('Error marking as unread. Please try again.');
    }
}

/**
 * Mark all items in a feed as read.
 * 
 * @param {number} feedId - The feed ID
 */
async function markAllAsRead(feedId) {
    try {
        const response = await fetch(`/feeds/${feedId}/mark-all-read`, addCsrfToken({
            method: 'POST'
        }));

        const result = await response.json();

        if (result.success) {
            // Reload feeds to update unread counts
            if (typeof loadFeeds === 'function') {
                loadFeeds();
            }
            // Reload items to update read status
            if (window.currentFeedId === feedId) {
                await loadFeedItems(feedId);
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to mark all as read'));
        }
    } catch (error) {
        console.error('Error marking all as read:', error);
        showError('Error marking all as read. Please try again.');
    }
}
