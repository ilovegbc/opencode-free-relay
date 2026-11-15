<?php

declare(strict_types=1);

namespace Relay\Support;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::capture($template, $data);
        if ($layout === null || $layout === '') {
            return $content;
        }
        return self::capture($layout, $data + ['content' => $content, 'view' => $template]);
    }

    public static function capture(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
