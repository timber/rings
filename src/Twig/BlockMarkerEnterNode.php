<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * Enter node for blocks - gets template name at runtime via $this->getTemplateName()
 */
#[YieldReady]
final class BlockMarkerEnterNode extends Node
{
    public function __construct(
        private readonly string $extensionName,
        private readonly string $blockName,
        private readonly int $line,
    ) {
        parent::__construct();
    }

    public function compile(Compiler $compiler): void
    {
        // Use $this->getTemplateName() to get the actual template name at runtime
        // This correctly identifies which template defines this block override
        $compiler
            ->write('$this->extensions[')
            ->repr($this->extensionName)
            ->raw(']->enter($this->getTemplateName() . ')
            ->repr('::block(' . $this->blockName . ')')
            ->raw(', ')
            ->repr($this->line)
            ->raw(");\n");
    }
}
