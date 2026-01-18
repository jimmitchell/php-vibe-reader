<?php

namespace PhpRss;

/**
 * Standardized API response helper.
 * 
 * Provides consistent JSON response format for all API endpoints.
 * Ensures uniform response structure across the application.
 */
class Response
{
    /**
     * Send a successful JSON response.
     * 
     * @param mixed $data Response data
     * @param string|null $message Optional success message
     * @param int $statusCode HTTP status code (default: 200)
     * @return void
     */
    public static function success($data = null, ?string $message = null, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        
        $response = ['success' => true];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        if ($data !== null) {
            if (is_array($data) && isset($data[0])) {
                // If it's an array of items, return as array
                $response = array_merge($response, ['data' => $data]);
            } else {
                // Merge data into response
                $response = array_merge($response, is_array($data) ? $data : ['data' => $data]);
            }
        }
        
        echo json_encode($response);
    }

    /**
     * Send an error JSON response.
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default: 400)
     * @param array $errors Optional additional error details
     * @return void
     */
    public static function error(string $message, int $statusCode = 400, array $errors = []): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        
        $response = [
            'success' => false,
            'error' => $message
        ];
        
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        
        echo json_encode($response);
    }

    /**
     * Send a JSON response with custom structure.
     * 
     * @param array $data Response data array
     * @param int $statusCode HTTP status code (default: 200)
     * @return void
     */
    public static function json(array $data, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }
}
