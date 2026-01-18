<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="application-name" content="<?php use PhpRss\Version; echo htmlspecialchars(Version::getAppName()); ?>">
    <meta name="version" content="<?php echo htmlspecialchars(Version::getVersion()); ?>">
    <title>Login - VibeReader</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <h1>VibeReader</h1>
        <div class="auth-box">
            <h2>Login</h2>
            <?php if (isset($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST" action="/login">
                <?php use PhpRss\Csrf; echo Csrf::field(); ?>
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            <p class="auth-link">Don't have an account? <a href="/register">Register here</a></p>
        </div>
    </div>
</body>
</html>
