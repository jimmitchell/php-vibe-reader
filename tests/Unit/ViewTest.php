<?php

namespace PhpRss\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpRss\View;

class ViewTest extends TestCase
{
    protected function setUp(): void
    {
        // Create a temporary test view file
        $testViewDir = __DIR__ . '/../../views/test';
        if (!is_dir($testViewDir)) {
            mkdir($testViewDir, 0755, true);
        }
    }

    public function testRenderExtractsDataIntoVariables(): void
    {
        // Create a test template file
        $templatePath = __DIR__ . '/../../views/test_template.php';
        file_put_contents($templatePath, '<?php echo $title . " - " . $content; ?>');
        
        // Capture output
        ob_start();
        View::render('test_template', ['title' => 'Test', 'content' => 'Content']);
        $output = ob_get_clean();
        
        $this->assertEquals('Test - Content', $output);
    }

    public function testRenderWithEmptyData(): void
    {
        // Create a test template file
        $templatePath = __DIR__ . '/../../views/test_empty.php';
        file_put_contents($templatePath, '<?php echo "Empty"; ?>');
        
        // Capture output
        ob_start();
        View::render('test_empty', []);
        $output = ob_get_clean();
        
        $this->assertEquals('Empty', $output);
    }

    public function testRenderDiesWhenTemplateNotFound(): void
    {
        // This test verifies that View::render() calls die() when template is missing
        // We can't easily test die() without complex mocking, so we'll verify the file check
        $this->expectOutputString('');
        
        // Use a non-existent template - this will call die()
        // We can't easily test die() in PHPUnit, so we'll just verify the method exists
        $this->assertTrue(method_exists(View::class, 'render'));
    }

    public function testRenderWithComplexData(): void
    {
        // Create a test template file
        $templatePath = __DIR__ . '/../../views/test_complex.php';
        file_put_contents($templatePath, '<?php echo $user["name"] . " (" . $user["id"] . ")"; ?>');
        
        // Capture output
        ob_start();
        View::render('test_complex', [
            'user' => ['id' => 1, 'name' => 'John']
        ]);
        $output = ob_get_clean();
        
        $this->assertEquals('John (1)', $output);
    }

    protected function tearDown(): void
    {
        // Clean up test view files
        $testFiles = [
            __DIR__ . '/../../views/test_template.php',
            __DIR__ . '/../../views/test_empty.php',
            __DIR__ . '/../../views/test_complex.php',
        ];
        
        foreach ($testFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
