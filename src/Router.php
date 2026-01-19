<?php

namespace PhpRss;

/**
 * Simple HTTP router for handling application routes.
 *
 * Handles routing of HTTP requests to appropriate controller methods.
 * Supports parameterized routes (e.g., /feeds/:id), static asset serving,
 * and both GET and POST request methods.
 */
class Router
{
    /**
     * Dispatch the current HTTP request to the appropriate route handler.
     *
     * Parses the request URI, handles static asset serving, matches routes
     * (exact and parameterized), and invokes the corresponding controller method.
     * Returns 404 if no matching route is found.
     *
     * @return void
     */
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

        // Serve favicon files
        if ($path === '/favicon.svg' || $path === '/favicon.ico') {
            $filePath = __DIR__ . '/..' . $path;
            if (file_exists($filePath) && is_file($filePath)) {
                if ($path === '/favicon.svg') {
                    header('Content-Type: image/svg+xml');
                } else {
                    header('Content-Type: image/x-icon');
                }
                readfile($filePath);

                return;
            }
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
            'POST /preferences/toggle-hide-feeds-no-unread' => 'FeedController@toggleHideFeedsWithNoUnread',
            'POST /preferences/toggle-item-sort-order' => 'FeedController@toggleItemSortOrder',
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
            'GET /api/version' => 'ApiController@getVersion',
            'GET /api/jobs/stats' => 'ApiController@getJobStats',
            'POST /api/jobs/cleanup' => 'ApiController@queueCleanup',
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
        $this->showErrorPage(404);
    }

    /**
     * Check if a route pattern matches the given path and method.
     *
     * Converts route patterns with parameters (e.g., /feeds/:id) to regex
     * and tests if they match the provided path. Also checks HTTP method.
     *
     * @param string $pattern The route pattern (e.g., "GET /feeds/:id")
     * @param string $path The request path to match
     * @param string $method The HTTP method to match
     * @return bool True if the route matches, false otherwise
     */
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

    /**
     * Extract route parameters from a path that matches a parameterized pattern.
     *
     * Extracts named parameters (e.g., :id) from route patterns and returns
     * them as an associative array with parameter names as keys.
     *
     * @param string $pattern The route pattern with parameters (e.g., "/feeds/:id")
     * @param string $path The actual path containing parameter values
     * @return array Associative array of parameter names to values
     */
    private function extractParams(string $pattern, string $path): array
    {
        $parts = explode(' ', $pattern, 2);
        if (count($parts) < 2) {
            return [];
        }
        $patternPath = $parts[1];

        // Extract parameter names
        preg_match_all('#/:([^/]+)#', $patternPath, $paramNames);
        // preg_match_all always returns array with numeric keys, so [1] always exists
        // @phpstan-ignore-next-line - preg_match_all always populates [1] when pattern matches
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

    /**
     * Handle a matched route by instantiating the controller and calling the method.
     *
     * Parses the handler string (format: "Controller@method"), instantiates
     * the controller class, and invokes the method with the provided parameters.
     * Returns 500 error if controller or method is not found.
     *
     * @param string $handler The route handler string (e.g., "FeedController@add")
     * @param array $params Route parameters to pass to the controller method
     * @return void
     */
    private function handleRoute(string $handler, array $params = []): void
    {
        [$controller, $method] = explode('@', $handler);
        $controllerClass = "PhpRss\\Controllers\\$controller";

        if (! class_exists($controllerClass)) {
            $this->showErrorPage(500);

            return;
        }

        $controllerInstance = new $controllerClass();
        if (! method_exists($controllerInstance, $method)) {
            $this->showErrorPage(500);

            return;
        }

        $controllerInstance->$method($params);
    }

    /**
     * Show an error page based on the HTTP status code.
     *
     * Renders the appropriate error page template (404, 500, 403) and
     * sets the corresponding HTTP response code.
     *
     * @param int $statusCode The HTTP status code (404, 500, or 403)
     * @return void
     */
    private function showErrorPage(int $statusCode): void
    {
        http_response_code($statusCode);

        $templateMap = [
            404 => 'error_404',
            500 => 'error_500',
            403 => 'error_403',
        ];

        $template = $templateMap[$statusCode] ?? 'error_500';

        View::render($template);
    }
}
