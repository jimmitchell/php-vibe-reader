<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - VibeReader</title>
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
                <div class="pane-header pane-header-compact">
                    <button id="add-feed-btn" class="btn btn-primary btn-sm">+ Add Feed</button>
                </div>
                <div id="feeds-list" class="feeds-list">
                    <!-- Feeds will be loaded here -->
                </div>
            </aside>

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
                <button type="submit" class="btn btn-primary">Save Preferences</button>
            </form>
        </div>
    </div>

    <script>
        // Initialize user preferences from server (must be before app.js)
        var hideReadItems = <?= json_encode((bool)($user['hide_read_items'] ?? true)) ?>;
        var userTimezone = <?= json_encode($user['timezone'] ?? 'UTC') ?>;
        var defaultThemeMode = <?= json_encode($user['default_theme_mode'] ?? 'system') ?>;
        var fontFamily = <?= json_encode($user['font_family'] ?? 'system') ?>;
    </script>
    <script src="/assets/js/app.js"></script>
</body>
</html>
