<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * Escape a value for safe output in HTML. Used throughout the view
     * templates.
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
