<?php

namespace PhpRss;

class Router
{
    public function dispatch(): void
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($path, PHP_URL_PATH);
        $path = rtrim($path, '/') ?: '/';

        // Remove base path if needed
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }

        // Serve static assets
        if (strpos($path, '/assets/') === 0) {
            $filePath = __DIR__ . '/..' . $path;
            if (file_exists($filePath) && is_file($filePath)) {
                $mimeTypes = [
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                ];
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
                header('Content-Type: ' . $mimeType);
                readfile($filePath);
                return;
            }
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Route mapping
        $routes = [
            'GET /' => 'AuthController@loginPage',
            'POST /login' => 'AuthController@login',
            'GET /logout' => 'AuthController@logout',
            'GET /register' => 'AuthController@registerPage',
            'POST /register' => 'AuthController@register',
            'GET /dashboard' => 'DashboardController@index',
            'POST /feeds/add' => 'FeedController@add',
            'GET /feeds/list' => 'FeedController@list',
            'GET /feeds/:id/items' => 'FeedController@getItems',
            'GET /items/:id' => 'FeedController@getItem',
            'POST /items/:id/read' => 'FeedController@markAsRead',
            'POST /items/:id/unread' => 'FeedController@markAsUnread',
            'POST /feeds/:id/fetch' => 'FeedController@fetch',
            'POST /feeds/:id/delete' => 'FeedController@delete',
            'POST /feeds/:id/mark-all-read' => 'FeedController@markAllAsRead',
            'POST /feeds/reorder' => 'FeedController@reorderFeeds',
            'GET /preferences' => 'FeedController@getPreferences',
            'POST /preferences' => 'FeedController@updatePreferences',
            'POST /preferences/toggle-hide-read' => 'FeedController@toggleHideRead',
            'POST /preferences/toggle-theme' => 'FeedController@toggleTheme',
            'GET /folders' => 'FeedController@getFolders',
            'POST /folders' => 'FeedController@createFolder',
            'PUT /folders/:id' => 'FeedController@updateFolder',
            'DELETE /folders/:id' => 'FeedController@deleteFolder',
            'POST /feeds/folder' => 'FeedController@updateFeedFolder',
            'DELETE /feeds/:id' => 'FeedController@delete',
            'GET /api/feeds' => 'ApiController@getFeeds',
            'GET /api/feeds/:id/items' => 'ApiController@getFeedItems',
            'GET /api/items/:id' => 'ApiController@getItem',
            'GET /api/search' => 'ApiController@searchItems',
            'POST /api/items/:id/read' => 'ApiController@markAsRead',
            'GET /opml/export' => 'FeedController@exportOpml',
            'POST /opml/import' => 'FeedController@importOpml',
        ];

        $routeKey = "$method $path";
        
        // Try exact match first
        if (isset($routes[$routeKey])) {
            $this->handleRoute($routes[$routeKey]);
            return;
        }

        // Try parameterized routes
        foreach ($routes as $pattern => $handler) {
            if ($this->matchRoute($pattern, $path, $method)) {
                $this->handleRoute($handler, $this->extractParams($pattern, $path));
                return;
            }
        }

        // 404
        http_response_code(404);
        echo "Page not found";
    }

    private function matchRoute(string $pattern, string $path, string $method): bool
    {
        $parts = explode(' ', $pattern, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $patternMethod = $parts[0];
        $patternPath = $parts[1];

        if ($patternMethod !== $method) {
            return false;
        }

        // Convert route pattern to regex
        $regex = '#^' . preg_replace('#/:([^/]+)#', '/([^/]+)', $patternPath) . '$#';
        return preg_match($regex, $path);
    }

    private function extractParams(string $pattern, string $path): array
    {
        $parts = explode(' ', $pattern, 2);
        $patternPath = $parts[1];
        
        // Extract parameter names
        preg_match_all('#/:([^/]+)#', $patternPath, $paramNames);
        $paramNames = $paramNames[1] ?? [];

        // Extract parameter values
        $regex = '#^' . preg_replace('#/:([^/]+)#', '/([^/]+)', $patternPath) . '$#';
        preg_match($regex, $path, $matches);
        array_shift($matches); // Remove full match

        $params = [];
        foreach ($paramNames as $index => $name) {
            $params[$name] = $matches[$index] ?? null;
        }

        return $params;
    }

    private function handleRoute(string $handler, array $params = []): void
    {
        [$controller, $method] = explode('@', $handler);
        $controllerClass = "PhpRss\\Controllers\\$controller";
        
        if (!class_exists($controllerClass)) {
            http_response_code(500);
            echo "Controller not found";
            return;
        }

        $controllerInstance = new $controllerClass();
        if (!method_exists($controllerInstance, $method)) {
            http_response_code(500);
            echo "Method not found";
            return;
        }

        $controllerInstance->$method($params);
    }
}
