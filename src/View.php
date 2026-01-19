<?php

namespace PhpRss;

/**
 * View rendering class for handling PHP templates.
 *
 * Provides a simple templating system that extracts data arrays into
 * variables and includes PHP template files from the views directory.
 */
class View
{
    /**
     * Render a PHP template with provided data.
     *
     * Extracts the data array into variables, making them available to
     * the template. The template file is included from the views directory.
     *
     * @param string $template The template filename (without .php extension)
     * @param array $data Associative array of data to make available to the template
     * @return void
     * @throws void Terminates execution if template file is not found
     */
    public static function render(string $template, array $data = []): void
    {
        extract($data);
        $templatePath = __DIR__ . "/../views/$template.php";

        if (! file_exists($templatePath)) {
            die("Template not found: $template");
        }

        include $templatePath;
    }
}
