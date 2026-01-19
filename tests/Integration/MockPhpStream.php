<?php

namespace PhpRss\Tests\Integration;

/**
 * Mock stream wrapper for php://input in tests.
 * 
 * Allows mocking php://input which is used by controllers to read JSON request bodies.
 */
class MockPhpStream
{
    public static $input = '';
    private $position = 0;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        if (strpos($path, 'php://input') !== false) {
            return true;
        }
        return false;
    }

    public function stream_read($count)
    {
        $result = substr(self::$input, $this->position, $count);
        $this->position += strlen($result);
        return $result;
    }

    public function stream_eof()
    {
        return $this->position >= strlen(self::$input);
    }

    public function stream_stat()
    {
        return [];
    }
}
