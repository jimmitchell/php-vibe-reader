<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Response;

class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset headers
        header_remove();
    }

    protected function tearDown(): void
    {
        // Clean output buffer if any
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Reset headers
        header_remove();
    }

    public function testSuccessResponse(): void
    {
        ob_start();
        Response::success(['test' => 'data'], 'Operation successful');
        $output = ob_get_clean();
        
        $this->assertStringContainsString('"success":true', $output);
        $this->assertStringContainsString('"test":"data"', $output);
        $this->assertStringContainsString('"message":"Operation successful"', $output);
        
        // Check status code
        $this->assertEquals(200, http_response_code());
    }

    public function testErrorResponse(): void
    {
        ob_start();
        Response::error('Something went wrong', 400);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('"success":false', $output);
        $this->assertStringContainsString('"error":"Something went wrong"', $output);
        
        // Check status code
        $this->assertEquals(400, http_response_code());
    }

    public function testJsonResponse(): void
    {
        $data = ['key' => 'value', 'number' => 123];
        ob_start();
        Response::json($data, 201);
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertEquals($data, $decoded);
        $this->assertEquals(201, http_response_code());
    }

    public function testSuccessWithArrayData(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        ob_start();
        Response::success($items);
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        
        $this->assertTrue($decoded['success']);
        $this->assertIsArray($decoded['data']);
        $this->assertCount(2, $decoded['data']);
    }
}
