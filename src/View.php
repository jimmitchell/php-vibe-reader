<?php

namespace PhpRss;

class View
{
    public static function render(string $template, array $data = []): void
    {
        extract($data);
        $templatePath = __DIR__ . "/../views/$template.php";
        
        if (!file_exists($templatePath)) {
            die("Template not found: $template");
        }

        include $templatePath;
    }
}
