<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * Node that registers line numbers for templates/blocks at runtime.
 */
#[YieldReady]
final class RegisterLineNode extends Node
{
    public function __construct(
        private readonly string $extensionName,
        private readonly int $line,
        private readonly ?string $blockName = null,
    ) {
        parent::__construct();
    }

    public function compile(Compiler $compiler): void
    {
        // Use $this->extensions[...] directly like Twig's EnterProfileNode does
        $compiler
            ->write('$this->extensions[')
            ->repr($this->extensionName)
            ->raw(']->registerLine($this->getTemplateName(), ')
            ->repr($this->line)
            ->raw(', ')
            ->repr($this->blockName)
            ->raw(");\n");
    }
}
