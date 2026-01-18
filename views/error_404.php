<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="application-name" content="<?php use PhpRss\Version; echo htmlspecialchars(Version::getAppName()); ?>">
    <meta name="version" content="<?php echo htmlspecialchars(Version::getVersion()); ?>">
    <title>404 - Page Not Found - VibeReader</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .error-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: var(--background);
            padding: 20px;
        }
        .error-container {
            text-align: center;
            max-width: 600px;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            color: var(--primary-color);
            line-height: 1;
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 32px;
            color: var(--text);
            margin-bottom: 16px;
        }
        .error-message {
            font-size: 18px;
            color: var(--text-light);
            margin-bottom: 32px;
            line-height: 1.6;
        }
        .error-actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 16px;
            transition: background-color 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background-color: var(--primary-hover);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
        }
        .btn-secondary:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body class="error-page">
    <div class="error-container">
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-message">
            The page you're looking for doesn't exist or has been moved.
            Please check the URL and try again.
        </p>
        <div class="error-actions">
            <a href="/dashboard" class="btn">Go to Dashboard</a>
            <a href="/" class="btn btn-secondary">Go to Login</a>
        </div>
    </div>
</body>
</html>
