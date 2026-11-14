<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

/**
 * Tells templates rendering HTML apart from templates dedicated to other formats, from their names.
 */
final class TemplateFormat
{
    /**
     * Whether the template is dedicated to a non-HTML format, e.g. "email.txt.twig" or "data.json.twig".
     */
    public static function isNonHtml(string $templateName): bool
    {
        return (bool) \preg_match('/\.(?:txt|text|json|js|css|xml|rss|atom|csv|ics)\.twig$/i', $templateName);
    }
}
