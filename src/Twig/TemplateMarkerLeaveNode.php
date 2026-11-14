<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

#[YieldReady]
final class TemplateMarkerLeaveNode extends Node
{
    public function __construct(
        private readonly string $extensionName,
    ) {
        parent::__construct();
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->write('$this->extensions[')
            ->repr($this->extensionName)
            ->raw("]->leave();\n");
    }
}
