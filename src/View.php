<?php

declare(strict_types=1);

namespace FFB;

/**
 * Renders a PHP template from a views directory to a string. Templates receive
 * the supplied data as local variables and use the global e() helper to escape
 * output.
 */
final class View
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    /**
     * Render an inner template and wrap it in the site layout.
     *
     * @param array<string,mixed> $data
     */
    public function page(string $template, string $title, array $data = []): string
    {
        $content = $this->render($template, $data);

        return $this->render('layout', ['title' => $title, 'content' => $content]);
    }
}
