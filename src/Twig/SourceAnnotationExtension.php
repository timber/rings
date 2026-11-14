<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Extension\AbstractExtension;

/**
 * Annotates the HTML tags written in templates with the template name and line, for the inspector.
 *
 * Unlike the template markers, this happens at compile time, so it works whether Twig echoes or yields output.
 */
final class SourceAnnotationExtension extends AbstractExtension
{
    public function getNodeVisitors(): array
    {
        return [new SourceAnnotationNodeVisitor()];
    }
}
