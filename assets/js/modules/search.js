/**
 * Search functionality module.
 * 
 * Handles searching across all feeds and displaying search results.
 * 
 * Dependencies:
 * - utils/dateFormat.js (formatDate)
 * - utils/ui.js (escapeHtml)
 * - modules/items.js (selectItem, getItemDisplayTitle, loadFeedItems)
 */

// Search state (global)
window.isSearchMode = false;
window.searchResults = [];

/**
 * Perform a search query.
 * 
 * @param {string} query - Search query string
 */
async function performSearch(query) {
    if (!query || query.trim().length === 0) {
        clearSearch();
        return;
    }

    window.isSearchMode = true;
    try {
        const response = await fetch(`/api/search?q=${encodeURIComponent(query)}`);
        const results = await response.json();
        window.searchResults = results;
        displaySearchResults(results);
    } catch (error) {
        console.error('Error performing search:', error);
        document.getElementById('items-list').innerHTML = '<div class="empty-state">Error performing search</div>';
    }
}

/**
 * Clear search and restore normal view.
 */
function clearSearch() {
    window.isSearchMode = false;
    window.searchResults = [];
    
    // Restore normal view - show current feed items or empty state
    if (window.currentFeedId && typeof loadFeedItems === 'function') {
        loadFeedItems(window.currentFeedId);
    } else {
        document.getElementById('items-list').innerHTML = '<div class="empty-state">Select a feed from the left to view items</div>';
        document.getElementById('items-title').textContent = 'Select a feed';
    }
    
    // Clear item content if no item selected
    if (!window.currentItemId) {
        document.getElementById('item-content').innerHTML = '<div class="empty-state">Select an item to read</div>';
        document.getElementById('content-title').textContent = 'Item';
    }
    
    // Show feed action buttons again
    const paneHeaderActions = document.querySelector('.pane-header-actions');
    if (paneHeaderActions) {
        paneHeaderActions.style.display = '';
    }
}

/**
 * Display search results in the items list.
 * 
 * @param {Array} results - Array of search result items
 */
function displaySearchResults(results) {
    const itemsList = document.getElementById('items-list');
    const itemsTitle = document.getElementById('items-title');
    
    itemsTitle.textContent = `Search Results (${results.length})`;

    if (results.length === 0) {
        itemsList.innerHTML = '<div class="empty-state">No results found</div>';
        document.getElementById('item-content').innerHTML = '<div class="empty-state">No results found</div>';
        document.getElementById('content-title').textContent = 'Search Results';
        return;
    }

    itemsList.innerHTML = results.map(item => {
        const displayTitle = getItemDisplayTitle(item);
        return `
        <div class="item-entry ${item.is_read ? '' : 'unread'}" 
             data-item-id="${item.id}">
            <div class="item-entry-title">${escapeHtml(displayTitle)}</div>
            <div class="item-entry-meta">
                <span class="item-feed-name">${escapeHtml(item.feed_title || 'Unknown Feed')}</span>
                ${item.published_at ? `• ${formatDate(item.published_at, { year: 'numeric', month: 'short', day: 'numeric' })}` : ''}
                ${item.author ? `• ${escapeHtml(item.author)}` : ''}
            </div>
        </div>
        `;
    }).join('');

    // Add click handlers for search results
    itemsList.querySelectorAll('.item-entry').forEach(item => {
        item.addEventListener('click', () => {
            const itemId = parseInt(item.dataset.itemId);
            if (typeof selectItem === 'function') {
                selectItem(itemId);
            }
        });
    });

    // Hide feed action buttons when in search mode
    const paneHeaderActions = document.querySelector('.pane-header-actions');
    if (paneHeaderActions) {
        paneHeaderActions.style.display = 'none';
    }
}
