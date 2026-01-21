/**
 * Pane resizer utility.
 * 
 * Handles horizontal resizing of panes by dragging dividers.
 */

/**
 * Initialize pane resizers.
 * 
 * Sets up drag handlers for resizable panes and loads saved widths from localStorage.
 */
function initPaneResizers() {
    // Load saved widths from localStorage
    const savedFeedsWidth = localStorage.getItem('vibereader_feeds_pane_width');
    const savedItemsWidth = localStorage.getItem('vibereader_items_pane_width');
    
    if (savedFeedsWidth) {
        document.documentElement.style.setProperty('--feeds-pane-width', savedFeedsWidth + 'px');
    }
    
    if (savedItemsWidth) {
        document.documentElement.style.setProperty('--items-pane-width', savedItemsWidth + 'px');
    }
    
    // Set up feeds-items resizer
    const feedsItemsResizer = document.getElementById('feeds-items-resizer');
    if (feedsItemsResizer) {
        setupResizer(feedsItemsResizer, 'feeds', 'items');
    }
    
    // Set up items-content resizer
    const itemsContentResizer = document.getElementById('items-content-resizer');
    if (itemsContentResizer) {
        setupResizer(itemsContentResizer, 'items', 'content');
    }
}

/**
 * Set up a resizer between two panes.
 * 
 * @param {HTMLElement} resizer - The resizer element
 * @param {string} leftPaneClass - CSS class of the left pane
 * @param {string} rightPaneClass - CSS class of the right pane
 */
function setupResizer(resizer, leftPaneClass, rightPaneClass) {
    let isResizing = false;
    let startX = 0;
    let startLeftWidth = 0;
    let startRightWidth = 0;
    
    const leftPane = document.querySelector(`.${leftPaneClass}-pane`);
    const rightPane = document.querySelector(`.${rightPaneClass}-pane`);
    
    if (!leftPane || !rightPane) {
        return;
    }
    
    const getCurrentWidth = (element) => {
        return parseInt(window.getComputedStyle(element).width, 10);
    };
    
    const startResize = (e) => {
        isResizing = true;
        startX = e.clientX || e.touches[0].clientX;
        startLeftWidth = getCurrentWidth(leftPane);
        
        // For content pane (which uses flex: 1), we don't need its width
        // It will automatically adjust when items pane width changes
        if (rightPaneClass !== 'content') {
            startRightWidth = getCurrentWidth(rightPane);
        }
        
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        resizer.style.backgroundColor = 'var(--primary-color)';
        
        e.preventDefault();
    };
    
    const doResize = (e) => {
        if (!isResizing) return;
        
        const currentX = e.clientX || e.touches[0].clientX;
        const diffX = currentX - startX;
        
        // Determine min/max widths based on which panes we're resizing
        let leftMinWidth, leftMaxWidth;
        
        if (leftPaneClass === 'feeds') {
            // Feeds pane: 200-600px
            leftMinWidth = 200;
            leftMaxWidth = 600;
        } else if (leftPaneClass === 'items') {
            // Items pane: 300-800px
            leftMinWidth = 300;
            leftMaxWidth = 800;
        }
        
        // Calculate new left pane width
        const newLeftWidth = Math.max(leftMinWidth, Math.min(leftMaxWidth, startLeftWidth + diffX));
        
        // Apply new width to left pane
        document.documentElement.style.setProperty(`--${leftPaneClass}-pane-width`, newLeftWidth + 'px');
        
        // For items pane (right pane when resizing feeds-items), adjust its width
        if (rightPaneClass === 'items') {
            const actualDiff = newLeftWidth - startLeftWidth;
            const rightMinWidth = 300;
            const rightMaxWidth = 800;
            const newRightWidth = Math.max(rightMinWidth, Math.min(rightMaxWidth, startRightWidth - actualDiff));
            document.documentElement.style.setProperty(`--${rightPaneClass}-pane-width`, newRightWidth + 'px');
            localStorage.setItem(`vibereader_${rightPaneClass}_pane_width`, newRightWidth.toString());
        }
        // For content pane, it uses flex: 1 so it automatically adjusts
        
        // Save to localStorage
        localStorage.setItem(`vibereader_${leftPaneClass}_pane_width`, newLeftWidth.toString());
        
        e.preventDefault();
    };
    
    const stopResize = (e) => {
        if (!isResizing) return;
        
        isResizing = false;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        resizer.style.backgroundColor = '';
        
        e.preventDefault();
    };
    
    // Mouse events
    resizer.addEventListener('mousedown', startResize);
    document.addEventListener('mousemove', doResize);
    document.addEventListener('mouseup', stopResize);
    
    // Touch events for mobile
    resizer.addEventListener('touchstart', startResize, { passive: false });
    document.addEventListener('touchmove', doResize, { passive: false });
    document.addEventListener('touchend', stopResize);
}
