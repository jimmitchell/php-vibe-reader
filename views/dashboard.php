<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php use PhpRss\Csrf; echo htmlspecialchars(Csrf::token()); ?>">
    <meta name="application-name" content="<?php use PhpRss\Version; echo htmlspecialchars(Version::getAppName()); ?>">
    <meta name="version" content="<?php echo htmlspecialchars(Version::getVersion()); ?>">
    <title>Dashboard - VibeReader</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/favicon.svg">
    <?php
    // Get font family from session first (most up-to-date), then user data, then default
    $fontFamily = $_SESSION['font_family'] ?? $user['font_family'] ?? 'system';
    $googleFontsMap = [
        'Lato' => 'Lato:ital,wght@0,400;0,700;1,400;1,700',
        'Roboto' => 'Roboto:ital,wght@0,400;0,700;1,400;1,700',
        'Noto Sans' => 'Noto+Sans:ital,wght@0,400;0,700;1,400;1,700',
        'Nunito' => 'Nunito:ital,wght@0,400;0,700;1,400;1,700',
        'Mulish' => 'Mulish:ital,wght@0,400;0,700;1,400;1,700'
    ];
    if ($fontFamily !== 'system' && isset($googleFontsMap[$fontFamily])) {
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . PHP_EOL;
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . PHP_EOL;
        echo '<link href="https://fonts.googleapis.com/css2?family=' . htmlspecialchars($googleFontsMap[$fontFamily]) . '&display=swap" rel="stylesheet">' . PHP_EOL;
    }
    ?>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root {
            --font-family: <?php
                if ($fontFamily === 'system') {
                    echo '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif';
                } else {
                    echo "'" . htmlspecialchars($fontFamily) . "', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif";
                }
            ?>;
        }
        body {
            font-family: var(--font-family);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1>VibeReader</h1>
            <div class="header-search">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <input type="search" id="search-input" class="search-input" placeholder="Search articles..." autocomplete="off">
            </div>
            <div class="header-actions">
                <span class="username"><?= htmlspecialchars($user['username']) ?></span>
                <button id="theme-toggle-btn" class="btn btn-icon btn-sm" aria-label="<?= !empty($user['dark_mode']) ? 'Switch to light mode' : 'Switch to dark mode' ?>" title="<?= !empty($user['dark_mode']) ? 'Switch to light mode' : 'Switch to dark mode' ?>">
                    <?php if (!empty($user['dark_mode'])): ?>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    <?php else: ?>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <?php endif; ?>
                </button>
                <button id="preferences-btn" class="btn btn-icon btn-sm" aria-label="Preferences" title="Preferences">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/>
                    </svg>
                </button>
                <a href="/logout" class="btn btn-icon btn-sm" aria-label="Logout" title="Logout">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </a>
            </div>
        </header>

        <div class="main-content">
            <!-- Left Pane: Feeds List -->
            <aside class="feeds-pane">
                <div class="pane-header pane-header-compact" style="flex-direction: row; gap: 8px;">
                    <button id="add-feed-btn" class="btn btn-primary btn-sm" style="flex: 1;">+ Add Feed</button>
                    <button id="refresh-all-btn" class="btn btn-icon btn-sm" aria-label="Refresh all feeds" title="Refresh all feeds">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                    </button>
                    <button id="toggle-hide-feeds-no-unread-btn" class="btn btn-icon btn-sm" aria-label="Hide feeds with no unread items" title="Hide feeds with no unread items">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                    <button id="create-folder-btn" class="btn btn-icon btn-sm" aria-label="Create Folder" title="Create Folder">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>
                        </svg>
                    </button>
                </div>
                <div id="feeds-list" class="feeds-list">
                    <!-- Feeds will be loaded here -->
                </div>
            </aside>

            <!-- Resizer between feeds and items -->
            <div class="pane-resizer" id="feeds-items-resizer" role="separator" aria-label="Resize feeds and items panes" aria-orientation="vertical"></div>

            <!-- Middle Pane: Feed Items -->
            <section class="items-pane">
                <div class="pane-header">
                    <h2 id="items-title">Select a feed</h2>
                    <div class="pane-header-actions" style="display: none;">
                        <button id="refresh-feed-btn" class="btn btn-icon btn-sm" aria-label="Refresh feed" title="Refresh feed">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                            </svg>
                        </button>
                        <button id="toggle-item-sort-btn" class="btn btn-icon btn-sm" aria-label="Sort by oldest first" title="Sort by oldest first">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 19V5M5 12l7-7 7 7"/>
                            </svg>
                        </button>
                        <button id="toggle-hide-read-btn" class="btn btn-icon btn-sm" aria-label="Hide all read" title="Hide all read">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                        <button id="mark-all-read-btn" class="btn btn-icon btn-sm" aria-label="Mark all as read" title="Mark all as read">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div id="items-list" class="items-list">
                    <div class="empty-state">Select a feed from the left to view items</div>
                </div>
            </section>

            <!-- Resizer between items and content -->
            <div class="pane-resizer" id="items-content-resizer" role="separator" aria-label="Resize items and content panes" aria-orientation="vertical"></div>

            <!-- Right Pane: Item Content -->
            <section class="content-pane">
                <div class="pane-header">
                    <h2 id="content-title">Item</h2>
                </div>
                <div id="item-content" class="item-content">
                    <div class="empty-state">Select an item to read</div>
                </div>
            </section>
        </div>
    </div>

    <!-- Add Feed Modal -->
    <div id="add-feed-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Add Feed</h2>
            <form id="add-feed-form">
                <div class="form-group">
                    <label for="feed-url">Feed URL or Website URL</label>
                    <input type="url" id="feed-url" name="url" placeholder="https://example.com/feed or https://example.com" required>
                    <small style="color: var(--text-light); font-size: 0.9em;">Enter a feed URL or a website URL - we'll discover the feed automatically</small>
                </div>
                <button type="submit" class="btn btn-primary">Add Feed</button>
            </form>
        </div>
    </div>

    <!-- Preferences Modal -->
    <div id="preferences-modal" class="modal">
        <div class="modal-content">
            <span class="close" id="preferences-close">&times;</span>
            <h2>Preferences</h2>
            <form id="preferences-form">
                <div class="form-group">
                    <label for="timezone">Timezone</label>
                    <select id="timezone" name="timezone">
                        <option value="UTC">UTC</option>
                        <option value="America/New_York">Eastern Time (ET)</option>
                        <option value="America/Chicago">Central Time (CT)</option>
                        <option value="America/Denver">Mountain Time (MT)</option>
                        <option value="America/Los_Angeles">Pacific Time (PT)</option>
                        <option value="Europe/London">London (GMT)</option>
                        <option value="Europe/Paris">Paris (CET)</option>
                        <option value="Europe/Berlin">Berlin (CET)</option>
                        <option value="Asia/Tokyo">Tokyo (JST)</option>
                        <option value="Asia/Shanghai">Shanghai (CST)</option>
                        <option value="Australia/Sydney">Sydney (AEST)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="default-theme-mode">Default Theme</label>
                    <select id="default-theme-mode" name="default_theme_mode">
                        <option value="system">System</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                    <small style="color: var(--text-light); font-size: 0.9em;">System will match your OS preference</small>
                </div>
                <div class="form-group">
                    <label for="font-family">Font Family</label>
                    <select id="font-family" name="font_family">
                        <option value="system" <?= ($user['font_family'] ?? 'system') === 'system' ? 'selected' : '' ?>>System Font</option>
                        <option value="Lato" <?= ($user['font_family'] ?? 'system') === 'Lato' ? 'selected' : '' ?>>Lato</option>
                        <option value="Roboto" <?= ($user['font_family'] ?? 'system') === 'Roboto' ? 'selected' : '' ?>>Roboto</option>
                        <option value="Noto Sans" <?= ($user['font_family'] ?? 'system') === 'Noto Sans' ? 'selected' : '' ?>>Noto Sans</option>
                        <option value="Nunito" <?= ($user['font_family'] ?? 'system') === 'Nunito' ? 'selected' : '' ?>>Nunito</option>
                        <option value="Mulish" <?= ($user['font_family'] ?? 'system') === 'Mulish' ? 'selected' : '' ?>>Mulish</option>
                    </select>
                </div>
                <hr style="border: none; border-top: 1px solid var(--border); margin: 20px 0;">
                <div class="form-group">
                    <label>Feed Management</label>
                    <div style="display: flex; gap: 8px; margin-top: 8px;">
                        <button type="button" id="export-opml-btn" class="btn btn-secondary" style="flex: 1; padding: 12px 24px;" title="Export OPML">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width: 14px; height: 14px; margin-right: 4px; vertical-align: middle;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Export OPML
                        </button>
                        <label for="import-opml-input" class="btn btn-secondary" style="flex: 1; cursor: pointer; margin: 0; color: white; text-align: center; padding: 12px 24px;" title="Import OPML">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width: 14px; height: 14px; margin-right: 4px; vertical-align: middle;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Import OPML
                            <input type="file" id="import-opml-input" accept=".opml,.xml" style="display: none;">
                        </label>
                    </div>
                    <small style="color: var(--text-light); font-size: 0.9em; display: block; margin-top: 8px;">Export your feeds or import feeds from another RSS reader</small>
                </div>
                <button type="submit" class="btn btn-primary">Save Preferences</button>
            </form>
        </div>
    </div>

    <script>
        // Initialize user preferences from server (must be before app.js)
        var hideReadItems = <?= json_encode((bool)($user['hide_read_items'] ?? true)) ?>;
        var hideFeedsWithNoUnread = <?= json_encode((bool)($user['hide_feeds_with_no_unread'] ?? false)) ?>;
        var itemSortOrder = <?= json_encode($user['item_sort_order'] ?? 'newest') ?>;
        var userTimezone = <?= json_encode($user['timezone'] ?? 'UTC') ?>;
        var defaultThemeMode = <?= json_encode($user['default_theme_mode'] ?? 'system') ?>;
        var fontFamily = <?= json_encode($user['font_family'] ?? 'system') ?>;
    </script>
    <!-- Utility modules -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.3.1/dist/purify.min.js"></script>
    <script src="/assets/js/utils/csrf.js"></script>
    <script src="/assets/js/utils/toast.js"></script>
    <script src="/assets/js/utils/dateFormat.js"></script>
    <script src="/assets/js/utils/ui.js"></script>
    <script src="/assets/js/utils/resizer.js"></script>
    
    <!-- Feature modules -->
    <script src="/assets/js/modules/feeds.js"></script>
    <script src="/assets/js/modules/items.js"></script>
    <script src="/assets/js/modules/folders.js"></script>
    <script src="/assets/js/modules/search.js"></script>
    <script src="/assets/js/modules/preferences.js"></script>
    
    <!-- Main application -->
    <script src="/assets/js/app.js"></script>
</body>
</html>
