/**
 * Folder management module.
 * 
 * Handles folder creation, updating, deletion, and feed assignment.
 * 
 * Dependencies:
 * - utils/csrf.js (addCsrfToken)
 * - utils/toast.js (showError)
 * - modules/feeds.js (loadFeeds)
 */

/**
 * Create a new folder.
 * 
 * @param {string} name - Folder name
 */
async function createFolder(name) {
    try {
        const response = await fetch('/folders', addCsrfToken({
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        }));

        const result = await response.json();

        if (result.success) {
            loadFeeds();
        } else {
            showError('Error: ' + (result.error || 'Failed to create folder'));
        }
    } catch (error) {
        console.error('Error creating folder:', error);
        showError('Error creating folder. Please try again.');
    }
}

/**
 * Update a folder's name.
 * 
 * @param {number} folderId - Folder ID
 * @param {string} name - New folder name
 */
async function updateFolderName(folderId, name) {
    try {
        const response = await fetch(`/folders/${folderId}`, addCsrfToken({
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        }));

        const result = await response.json();

        if (result.success) {
            loadFeeds();
        } else {
            showError('Error: ' + (result.error || 'Failed to update folder'));
        }
    } catch (error) {
        console.error('Error updating folder:', error);
        showError('Error updating folder. Please try again.');
    }
}

/**
 * Delete a folder.
 * 
 * @param {number} folderId - Folder ID
 */
async function deleteFolder(folderId) {
    try {
        const response = await fetch(`/folders/${folderId}`, addCsrfToken({
            method: 'DELETE'
        }));

        const result = await response.json();

        if (result.success) {
            loadFeeds();
        } else {
            showError('Error: ' + (result.error || 'Failed to delete folder'));
        }
    } catch (error) {
        console.error('Error deleting folder:', error);
        showError('Error deleting folder. Please try again.');
    }
}

/**
 * Assign a feed to a folder (or remove from folder if folderId is null).
 * 
 * @param {number} feedId - Feed ID
 * @param {number|null} folderId - Folder ID, or null to remove from folder
 */
async function assignFeedToFolder(feedId, folderId) {
    try {
        const response = await fetch('/feeds/folder', addCsrfToken({
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ feed_id: feedId, folder_id: folderId })
        }));

        const result = await response.json();

        if (result.success) {
            if (typeof loadFeeds === 'function') {
                loadFeeds();
            }
        } else {
            showError('Error: ' + (result.error || 'Failed to assign feed to folder'));
        }
    } catch (error) {
        console.error('Error assigning feed to folder:', error);
        showError('Error assigning feed to folder. Please try again.');
    }
}
