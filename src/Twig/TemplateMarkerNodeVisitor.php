<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\BodyNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\NodeVisitor\NodeVisitorInterface;

final readonly class TemplateMarkerNodeVisitor implements NodeVisitorInterface
{
    public function __construct(
        private string $extensionName,
    ) {
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $templateName = $node->getTemplateName() ?? '';
            $line = $node->getTemplateLine();

            $node->setNode('display_start', new Nodes([
                new RegisterLineNode($this->extensionName, $line),
                new TemplateMarkerEnterNode($this->extensionName, $templateName, $line),
                $node->getNode('display_start'),
            ]));

            $node->setNode('display_end', new Nodes([
                new TemplateMarkerLeaveNode($this->extensionName),
                $node->getNode('display_end'),
            ]));
        } elseif ($node instanceof BlockNode && \is_string($blockName = $node->getAttribute('name'))) {
            $line = $node->getTemplateLine();

            // Wrap block body with enter/leave markers so block content gets its own markers
            // BlockMarkerEnterNode resolves the template name at runtime
            $node->setNode('body', new BodyNode([
                new RegisterLineNode($this->extensionName, $line, $blockName),
                new BlockMarkerEnterNode($this->extensionName, $blockName, $line),
                $node->getNode('body'),
                new TemplateMarkerLeaveNode($this->extensionName),
            ]));
        }

        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
