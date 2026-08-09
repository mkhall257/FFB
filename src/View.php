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
     * @param string $active  Nav key to mark active in the layout (e.g. 'home'); '' for none.
     * @param string $pageCss Page stylesheet basename under public/assets/css/pages/; '' for none.
     * @param string $layout  Layout template to wrap the content in (default 'layout').
     */
    public function page(
        string $template,
        string $title,
        array $data = [],
        string $active = '',
        string $pageCss = '',
        string $layout = 'layout',
    ): string {
        $content = $this->render($template, $data);

        return $this->render($layout, [
            'title' => $title,
            'content' => $content,
            'active' => $active,
            'pageCss' => $pageCss,
        ]);
    }
}
