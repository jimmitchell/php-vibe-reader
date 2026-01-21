<?php

namespace PhpRss;

class Utils
{
    /**
     * Format a date string for JSON response (ISO 8601 with UTC timezone)
     * Ensures dates are interpreted as UTC by JavaScript
     */
    public static function formatDateForJson(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            // Parse the date string - assume it's stored as UTC
            // First try to parse as-is, then explicitly set timezone to UTC
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString, new \DateTimeZone('UTC'));

            // If that format doesn't work, try generic parsing
            if ($dt === false) {
                $dt = new \DateTime($dateString);
                $dt->setTimezone(new \DateTimeZone('UTC'));
            }

            // Format as ISO 8601 with 'Z' suffix (UTC indicator)
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            // If parsing fails, return null
            return null;
        }
    }

    /**
     * Format date fields in an array for JSON response
     */
    public static function formatDatesForJson(array $data, array $dateFields = ['published_at', 'created_at', 'last_fetched', 'read_at']): array
    {
        foreach ($dateFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = self::formatDateForJson($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Safely decode JSON string with error handling.
     * 
     * Decodes a JSON string and checks for errors. Returns the decoded value
     * or the default value if decoding fails. Logs errors for debugging.
     * 
     * @param string $json JSON string to decode
     * @param mixed $default Default value to return if decoding fails (default: null)
     * @param bool $assoc Whether to decode as associative array (default: true)
     * @return mixed Decoded JSON value or default value
     */
    public static function safeJsonDecode(string $json, $default = null, bool $assoc = true)
    {
        if (empty($json)) {
            return $default;
        }

        // Reset any previous JSON errors
        json_last_error();
        
        $decoded = json_decode($json, $assoc);
        
        // Check for JSON errors (must check after json_decode)
        // Note: json_decode returns null for both errors AND the JSON value "null"
        // So we must check json_last_error() to distinguish
        $error = json_last_error();
        if ($error !== JSON_ERROR_NONE) {
            $errorMessage = match ($error) {
                JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
                JSON_ERROR_STATE_MISMATCH => 'Underflow or the modes mismatch',
                JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
                JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
                JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded',
                JSON_ERROR_RECURSION => 'One or more recursive references in the value to be encoded',
                JSON_ERROR_INF_OR_NAN => 'One or more NAN or INF values in the value to be encoded',
                JSON_ERROR_UNSUPPORTED_TYPE => 'A value of a type that cannot be encoded was given',
                default => 'Unknown JSON error',
            };
            
            \PhpRss\Logger::warning("JSON decode failed: {$errorMessage}", [
                'error_code' => $error,
                'json_length' => strlen($json),
                'json_preview' => substr($json, 0, 100),
                'context' => 'json_decode',
            ]);
            
            return $default;
        }

        // Return decoded value (can be null if JSON is literally "null", which is valid)
        return $decoded;
    }
}
