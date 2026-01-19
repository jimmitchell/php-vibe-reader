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
}
