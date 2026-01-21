/**
 * Feed management module.
 * 
 * Handles feed loading, rendering, refreshing, adding, deleting, and drag-and-drop reordering.
 * 
 * Dependencies:
 * - utils/csrf.js (addCsrfToken)
 * - utils/toast.js (showError)
 * - utils/ui.js (escapeHtml)
 * - modules/items.js (selectFeed, loadFeedItems)
 * - modules/folders.js (updateFolderName, deleteFolder, assignFeedToFolder)
 */

// Global state (shared with app.js)
// currentFeedId, collapsedFolders are defined in app.js

/**
 * Load all feeds and folders from the API.
 */
async function loadFeeds() {
    try {
        // Fetch both feeds and folders
        const [feedsResponse, foldersResponse] = await Promise.all([
            fetch('/api/feeds'),
            fetch('/folders')
        ]);
        
        if (!feedsResponse.ok || !foldersResponse.ok) {
            throw new Error('Failed to fetch feeds or folders');
        }
        
        const feeds = await feedsResponse.json();
        const foldersResult = await foldersResponse.json();
        const folders = foldersResult.success ? (foldersResult.folders || []) : [];
        
        // Merge feeds with folders to show empty folders too
        renderFeeds(feeds, folders);
        
        // Re-setup drag and drop after rendering
        if (typeof setupFeedDragDrop === 'function') {
            setupFeedDragDrop(document.getElementById('feeds-list'));
        }
    } catch (error) {
        console.error('Error loading feeds:', error);
    }
}

/**
 * Refresh all feeds by fetching latest content.
 */
async function refreshAllFeeds() {
    const btn = document.getElementById('refresh-all-btn');
    if (btn) btn.disabled = true;
    try {
        const response = await fetch('/api/feeds');
        const feeds = await response.json();
        if (feeds.length === 0) return;
        // Fetch each feed in the background (don't await, run in parallel)
        // Use immediate=true to force synchronous refresh even if background jobs are enabled
        await Promise.all(feeds.map(feed => {
            // Create FormData with immediate flag first
            const formData = new FormData();
            formData.append('immediate', 'true');
            
            // Then add CSRF token
            const options = addCsrfToken({ method: 'POST', body: formData });
            
            return fetch(`/feeds/${feed.id}/fetch`, options).catch(() => {});
        }));
        // Reload feeds list to show updated counts
        if (typeof loadFeeds === 'function') {
            loadFeeds();
        }
        // Reload current feed items if a feed is selected
        if (window.currentFeedId && typeof loadFeedItems === 'function') {
            await loadFeedItems(window.currentFeedId);
        }
    } catch (error) {
        console.error('Error refreshing feeds:', error);
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * Refresh a single feed.
 * 
 * @param {number} feedId - The feed ID to refresh
 */
async function refreshFeed(feedId) {
    const btn = document.getElementById('refresh-feed-btn');
    if (btn) btn.disabled = true;
    try {
        // Use immediate=true to force synchronous refresh even if background jobs are enabled
        // Create FormData with immediate flag first
        const formData = new FormData();
        formData.append('immediate', 'true');
        
        // Then add CSRF token
        const options = addCsrfToken({ method: 'POST', body: formData });
        
        const response = await fetch(`/feeds/${feedId}/fetch`, options);
        const result = await response.json();
        if (result.success && window.currentFeedId === feedId && typeof loadFeedItems === 'function') {
            await loadFeedItems(feedId);
        }
        if (typeof loadFeeds === 'function') {
            loadFeeds();
        }
    } catch (error) {
        console.error('Error refreshing feed:', error);
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * Render feeds and folders in the feeds list.
 * 
 * @param {Array} feeds - Array of feed objects
 * @param {Array} allFolders - Array of folder objects
 */
function renderFeeds(feeds, allFolders = []) {
    const feedsList = document.getElementById('feeds-list');
    
    if (feeds.length === 0 && allFolders.length === 0) {
        feedsList.innerHTML = '<div class="empty-state">No feeds yet. Add one to get started!</div>';
        return;
    }

    // Group feeds by folder
    const foldersMap = new Map();
    const feedsWithoutFolder = [];

    // First, initialize all folders (including empty ones)
    allFolders.forEach(folder => {
        foldersMap.set(folder.id, {
            id: folder.id,
            name: folder.name,
            sort_order: folder.sort_order || 0,
            feeds: []
        });
    });

    // Then, assign feeds to folders
    feeds.forEach(feed => {
        if (feed.folder_id && feed.folder_name) {
            if (!foldersMap.has(feed.folder_id)) {
                foldersMap.set(feed.folder_id, {
                    id: feed.folder_id,
                    name: feed.folder_name,
                    sort_order: feed.folder_sort_order || 0,
                    feeds: []
                });
            }
            foldersMap.get(feed.folder_id).feeds.push(feed);
        } else {
            feedsWithoutFolder.push(feed);
        }
    });

    // Sort folders by sort_order, then name
    const folders = Array.from(foldersMap.values()).sort((a, b) => {
        if (a.sort_order !== b.sort_order) return a.sort_order - b.sort_order;
        return a.name.localeCompare(b.name);
    });

    // Build HTML
    let html = '';

    // Render feeds without folder first
    feedsWithoutFolder.forEach(feed => {
        html += renderFeedItem(feed);
    });

    // Render folders with their feeds
    folders.forEach(folder => {
        const folderId = typeof folder.id === 'string' ? parseInt(folder.id, 10) : Number(folder.id);
        const isCollapsed = window.collapsedFolders.has(folderId) || window.collapsedFolders.has(String(folderId));
        html += `
            <div class="folder-item" data-folder-id="${folderId}">
                <div class="folder-header" data-folder-id="${folderId}">
                    <span class="folder-toggle">${isCollapsed ? '▶' : '▼'}</span>
                    <span class="folder-name">${escapeHtml(folder.name)}</span>
                    <button class="folder-edit-btn" data-folder-id="${folderId}" title="Edit folder">✎</button>
                    <button class="folder-delete-btn" data-folder-id="${folderId}" title="Delete folder">×</button>
                </div>
                <div class="folder-feeds ${isCollapsed ? 'collapsed' : ''}">
                    ${folder.feeds.map(feed => renderFeedItem(feed)).join('')}
                </div>
            </div>
        `;
    });

    feedsList.innerHTML = html;

    // Add click handlers for feeds
    feedsList.querySelectorAll('.feed-item').forEach(item => {
        const feedItemContent = item.querySelector('.feed-item-content');
        if (feedItemContent) {
            feedItemContent.addEventListener('click', () => {
                const feedId = parseInt(item.dataset.feedId);
                if (typeof selectFeed === 'function') {
                    selectFeed(feedId);
                }
            });
        }
    });

    // Add delete button handlers for feeds
    feedsList.querySelectorAll('.feed-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const feedId = parseInt(btn.dataset.feedId);
            if (confirm('Are you sure you want to delete this feed?')) {
                await deleteFeed(feedId);
            }
        });
    });

    // Add folder toggle handlers
    feedsList.querySelectorAll('.folder-header').forEach(header => {
        const toggle = header.querySelector('.folder-toggle');
        const folderName = header.querySelector('.folder-name');
        const folderFeeds = header.parentElement.querySelector('.folder-feeds');
        const folderId = parseInt(header.dataset.folderId);
        if (toggle && folderFeeds && folderId) {
            // Toggle function to be used by both toggle arrow and folder name
            const toggleFolder = (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isCollapsed = folderFeeds.classList.toggle('collapsed');
                toggle.textContent = isCollapsed ? '▶' : '▼';
                if (isCollapsed) {
                    window.collapsedFolders.add(folderId);
                } else {
                    window.collapsedFolders.delete(folderId);
                }
                // Save collapsed state to localStorage
                if (typeof window.saveCollapsedFolders === 'function') {
                    window.saveCollapsedFolders();
                }
            };
            
            // Make toggle arrow clickable
            toggle.style.cursor = 'pointer';
            toggle.addEventListener('click', toggleFolder);
            
            // Make folder name clickable to toggle
            if (folderName) {
                folderName.style.cursor = 'pointer';
                folderName.addEventListener('click', toggleFolder);
            }

            // Prevent edit/delete buttons from triggering toggle
            header.querySelectorAll('.folder-edit-btn, .folder-delete-btn').forEach(btn => {
                btn.addEventListener('click', (e) => e.stopPropagation());
            });
        }
    });

    // Add folder edit handlers
    feedsList.querySelectorAll('.folder-edit-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const folderId = parseInt(btn.dataset.folderId);
            const folderHeader = btn.closest('.folder-item');
            const folderName = folderHeader.querySelector('.folder-name').textContent;
            const newName = prompt('Enter new folder name:', folderName);
            if (newName && newName.trim() && newName.trim() !== folderName && typeof updateFolderName === 'function') {
                await updateFolderName(folderId, newName.trim());
            }
        });
    });

    // Add folder delete handlers
    feedsList.querySelectorAll('.folder-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const folderId = parseInt(btn.dataset.folderId);
            if (confirm('Are you sure you want to delete this folder? Feeds in this folder will be moved to the root.') && typeof deleteFolder === 'function') {
                await deleteFolder(folderId);
            }
        });
    });
}

/**
 * Render a single feed item.
 * 
 * @param {Object} feed - Feed object
 * @returns {string} HTML string for the feed item
 */
function renderFeedItem(feed) {
    return `
        <div class="feed-item ${feed.id === window.currentFeedId ? 'active' : ''} ${feed.unread_count > 0 ? 'unread' : ''}" 
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
    `;
}

/**
 * Set up drag-and-drop functionality for feed reordering and folder assignment.
 * 
 * @param {HTMLElement} feedsList - The feeds list container element
 */
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
        feedsList.querySelectorAll('.folder-header').forEach(header => {
            header.classList.remove('drag-over');
        });
    });

    feedsList.addEventListener('dragover', (e) => {
        e.preventDefault();
        const feedId = e.dataTransfer.getData('text/plain');
        if (!feedId) return;
        
        const folderHeader = e.target.closest('.folder-header');
        if (folderHeader) {
            e.dataTransfer.dropEffect = 'move';
            feedsList.querySelectorAll('.folder-header').forEach(header => {
                header.classList.remove('drag-over');
            });
            folderHeader.classList.add('drag-over');
        }
    });

    feedsList.addEventListener('drop', async (e) => {
        e.preventDefault();
        const feedId = parseInt(e.dataTransfer.getData('text/plain'));
        if (!feedId) return;
        
        const folderHeader = e.target.closest('.folder-header');
        if (folderHeader && typeof assignFeedToFolder === 'function') {
            const folderId = parseInt(folderHeader.dataset.folderId);
            await assignFeedToFolder(feedId, folderId);
        }
        
        feedsList.querySelectorAll('.folder-header').forEach(header => {
            header.classList.remove('drag-over');
        });
    });
}

/**
 * Save the new feed order after drag-and-drop reordering.
 * 
 * @param {Array<number>} order - Array of feed IDs in the new order
 */
async function saveFeedOrder(order) {
    try {
        const response = await fetch('/feeds/reorder', addCsrfToken({
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order })
        }));

        const result = await response.json();
        if (!result.success) {
            showError('Error: ' + (result.error || 'Failed to save order'));
            loadFeeds(); // revert by reloading
        }
    } catch (error) {
        console.error('Error saving feed order:', error);
        showError('Error saving feed order. Please try again.');
    }
}

/**
 * Add a new feed.
 * 
 * @param {string} url - The feed URL to add
 */
async function addFeed(url) {
    const modal = document.getElementById('add-feed-modal');
    const form = document.getElementById('add-feed-form');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding...';

    try {
        const formData = new FormData();
        formData.append('url', url);

        const response = await fetch('/feeds/add', addCsrfToken({
            method: 'POST',
            body: formData
        }));

        const result = await response.json();

        if (result.success) {
            modal.classList.remove('show');
            form.reset();
            if (typeof loadFeeds === 'function') {
                loadFeeds();
            }
            // Select the new feed
            setTimeout(() => {
                if (typeof selectFeed === 'function') {
                    selectFeed(result.feed_id);
                }
            }, 100);
        } else {
            showError('Error: ' + (result.error || 'Failed to add feed'));
        }
    } catch (error) {
        console.error('Error adding feed:', error);
        showError('Error adding feed. Please try again.');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Feed';
    }
}

/**
 * Delete a feed.
 * 
 * @param {number} feedId - The feed ID to delete
 */
async function deleteFeed(feedId) {
    try {
        const response = await fetch(`/feeds/${feedId}/delete`, addCsrfToken({
            method: 'POST'
        }));

        const result = await response.json();

        if (result.success) {
            // If deleted feed was selected, clear the view
            if (window.currentFeedId === feedId) {
                window.currentFeedId = null;
                window.currentItemId = null;
                document.getElementById('items-list').innerHTML = '<div class="empty-state">Select a feed from the left to view items</div>';
                document.getElementById('items-title').textContent = 'Select a feed';
                document.getElementById('item-content').innerHTML = '<div class="empty-state">Select an item to read</div>';
                document.getElementById('content-title').textContent = 'Item';
            }
            if (typeof loadFeeds === 'function') {
                loadFeeds();
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to delete feed'));
        }
    } catch (error) {
        console.error('Error deleting feed:', error);
        showError('Error deleting feed. Please try again.');
    }
}
