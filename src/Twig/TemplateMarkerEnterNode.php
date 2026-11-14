<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

#[YieldReady]
final class TemplateMarkerEnterNode extends Node
{
    public function __construct(
        private readonly string $extensionName,
        private readonly string $templateName,
        private readonly int $line,
    ) {
        parent::__construct();
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->write('$this->extensions[')
            ->repr($this->extensionName)
            ->raw(']->enter(')
            ->repr($this->templateName)
            ->raw(', ')
            ->repr($this->line)
            ->raw(");\n");
    }
}
