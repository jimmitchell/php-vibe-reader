<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\Csrf;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // Start session for CSRF token storage
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Clear session data
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Clean up session
        $_SESSION = [];
    }

    public function testTokenGeneration(): void
    {
        $token = Csrf::token();
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex characters
        $this->assertTrue(ctype_xdigit($token));
    }

    public function testTokenPersistsInSession(): void
    {
        $token1 = Csrf::token();
        $token2 = Csrf::token();
        $this->assertEquals($token1, $token2, 'Token should persist within session');
    }

    public function testRegenerateToken(): void
    {
        $token1 = Csrf::token();
        $token2 = Csrf::regenerate();
        $this->assertNotEquals($token1, $token2, 'Regenerated token should be different');
        
        $token3 = Csrf::token();
        $this->assertEquals($token2, $token3, 'Token should persist after regeneration');
    }

    public function testValidateValidToken(): void
    {
        $token = Csrf::token();
        $this->assertTrue(Csrf::validate($token), 'Valid token should pass validation');
    }

    public function testValidateInvalidToken(): void
    {
        $token = Csrf::token();
        $this->assertFalse(Csrf::validate('invalid_token'), 'Invalid token should fail validation');
        $this->assertFalse(Csrf::validate(null), 'Null token should fail validation');
        $this->assertFalse(Csrf::validate(''), 'Empty token should fail validation');
    }

    public function testValidateExpiredToken(): void
    {
        // Generate token with short expiration (1 second)
        Csrf::regenerate(1);
        $token = Csrf::token();
        
        // Token should be valid initially
        $this->assertTrue(Csrf::validate($token));
        
        // Wait for expiration
        sleep(2);
        
        // Token should now be invalid
        $this->assertFalse(Csrf::validate($token), 'Expired token should fail validation');
    }

    public function testFieldName(): void
    {
        $this->assertEquals('_token', Csrf::fieldName());
    }

    public function testField(): void
    {
        $token = Csrf::token();
        $field = Csrf::field();
        
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="_token"', $field);
        $this->assertStringContainsString($token, $field);
        $this->assertStringContainsString('value="', $field);
    }

    public function testRequireValidWithValidToken(): void
    {
        $token = Csrf::token();
        $_POST['_token'] = $token;
        
        $result = Csrf::requireValid();
        $this->assertTrue($result, 'requireValid should return true for valid token');
    }

    public function testRequireValidWithInvalidToken(): void
    {
        $_POST['_token'] = 'invalid_token';
        
        // This should exit with 403, but in test environment we catch it
        $this->expectException(\Exception::class);
        Csrf::requireValid(true);
    }
}
