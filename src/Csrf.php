<?php

namespace PhpRss;

/**
 * CSRF (Cross-Site Request Forgery) protection class.
 * 
 * Provides token generation, validation, and management for protecting
 * forms and API endpoints against CSRF attacks.
 */
class Csrf
{
    /**
     * Generate a CSRF token and store it in the session.
     * 
     * If a token already exists and hasn't expired, returns the existing token.
     * Otherwise, generates a new cryptographically secure random token.
     * 
     * @return string The CSRF token
     */
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_expires'])) {
            self::regenerate();
        } elseif ($_SESSION['csrf_token_expires'] < time()) {
            // Token expired, regenerate
            self::regenerate();
        }
        
        return $_SESSION['csrf_token'];
    }

    /**
     * Regenerate the CSRF token.
     * 
     * Creates a new cryptographically secure random token and stores it
     * in the session with an expiration time (default: 1 hour).
     * 
     * @param int $expiresIn Token expiration time in seconds (default: 3600 = 1 hour)
     * @return string The new CSRF token
     */
    public static function regenerate(int $expiresIn = 3600): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_expires'] = time() + $expiresIn;
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a CSRF token.
     * 
     * Checks if the provided token matches the token stored in the session
     * and if it hasn't expired. Returns false if validation fails.
     * 
     * @param string|null $token The token to validate (from POST/GET/header)
     * @return bool True if token is valid, false otherwise
     */
    public static function validate(?string $token): bool
    {
        if (empty($token) || !isset($_SESSION['csrf_token'])) {
            return false;
        }

        if (!isset($_SESSION['csrf_token_expires']) || $_SESSION['csrf_token_expires'] < time()) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Get the CSRF token field name for forms.
     * 
     * @return string The field name (default: '_token')
     */
    public static function fieldName(): string
    {
        return '_token';
    }

    /**
     * Generate a hidden input field with the CSRF token.
     * 
     * @return string HTML hidden input field
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . htmlspecialchars(self::fieldName()) . '" value="' . htmlspecialchars(self::token()) . '">';
    }

    /**
     * Require CSRF validation for the current request.
     * 
     * Validates the token from POST data, GET parameters, or X-CSRF-Token header.
     * Throws an exception or returns 403 if validation fails.
     * 
     * @param bool $throwException If true, throws exception on failure; otherwise returns 403
     * @return bool True if validation passed
     * @throws \Exception If validation fails and $throwException is true
     */
    public static function requireValid(bool $throwException = false): bool
    {
        // Try to get token from various sources
        // Priority: POST data (form submissions), GET params, then headers (for JSON/AJAX)
        // For JSON requests, the token should be in X-CSRF-Token header (set by addCsrfToken() in JS)
        // or in the JSON body itself (which we check via $_POST after the controller parses it)
        $token = $_POST[self::fieldName()] 
            ?? $_GET[self::fieldName()] 
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!self::validate($token)) {
            if ($throwException) {
                throw new \Exception('Invalid CSRF token');
            }
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        return true;
    }
}
