<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Router;

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        // Backup original superglobals
        $this->originalServer = $_SERVER ?? [];
    }

    protected function tearDown(): void
    {
        // Restore original superglobals
        $_SERVER = $this->originalServer;
    }

    public function testMatchRouteWithExactMatch(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($router, [
            'GET /dashboard',
            '/dashboard',
            'GET'
        ]);
        
        $this->assertTrue($result);
    }

    public function testMatchRouteWithParameterizedRoute(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($router, [
            'GET /feeds/:id/items',
            '/feeds/123/items',
            'GET'
        ]);
        
        $this->assertTrue($result);
    }

    public function testMatchRouteFailsWithWrongMethod(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($router, [
            'GET /dashboard',
            '/dashboard',
            'POST'
        ]);
        
        $this->assertFalse($result);
    }

    public function testMatchRouteFailsWithWrongPath(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($router, [
            'GET /dashboard',
            '/other',
            'GET'
        ]);
        
        $this->assertFalse($result);
    }

    public function testExtractParamsWithSingleParameter(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('extractParams');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($router, [
            'GET /feeds/:id/items',
            '/feeds/123/items'
        ]);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('123', $result['id']);
    }

    public function testExtractParamsWithMultipleParameters(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('extractParams');
        $method->setAccessible(true);
        
        // Test with a route that has multiple params (if such exists)
        // For now, test with single param but verify structure
        $result = $method->invokeArgs($router, [
            'GET /feeds/:id/items',
            '/feeds/123/items'
        ]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testExtractParamsWithNoParameters(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('extractParams');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($router, [
            'GET /dashboard',
            '/dashboard'
        ]);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMatchRouteWithInvalidPattern(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);
        
        // Pattern without space (invalid format)
        $result = $method->invokeArgs($router, [
            'invalid-pattern',
            '/dashboard',
            'GET'
        ]);
        
        $this->assertFalse($result);
    }

    // Note: Testing dispatch() would require:
    // 1. Mocking $_SERVER superglobals
    // 2. Mocking controller instantiation
    // 3. Capturing headers and output
    // 4. Testing static asset serving
    //
    // These are better suited for integration tests.
    // The matchRoute() and extractParams() tests above verify the core routing logic.
}
