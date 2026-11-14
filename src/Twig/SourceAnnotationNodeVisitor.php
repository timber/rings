<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\MacroNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Annotates the HTML tags written in templates with `data-twig="template:line"`, at compile time.
 *
 * A tag can be spread over several text nodes, e.g. `<div class="{{ x }}">` is `<div class="`, an expression, then
 * `">`. So the text nodes of a template (or block, or macro) are joined into a skeleton, expressions being replaced
 * with a space, and the skeleton is parsed to find the actual tags. The attribute is then inserted right after the
 * tag name, which is valid HTML whatever comes next.
 */
final class SourceAnnotationNodeVisitor implements NodeVisitorInterface
{
    private const string ATTRIBUTE = 'data-twig';

    /**
     * Placeholder for the output of anything that isn't static text. A space can't start nor continue a tag name.
     */
    private const string PLACEHOLDER = ' ';

    /**
     * Elements that can be annotated (lowercase), along with custom elements. Text that isn't HTML may look like a tag
     * when its context can't be known at compile time, e.g. `if (i <max && j > 0)` in a template included in a
     * <script>: only known elements are annotated to limit the risk.
     */
    private const array ELEMENTS = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'base', 'bdi', 'bdo', 'blockquote', 'body',
        'br', 'button', 'canvas', 'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del',
        'details', 'dfn', 'dialog', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset', 'figcaption', 'figure', 'footer',
        'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'i', 'iframe', 'img',
        'input', 'ins', 'kbd', 'label', 'legend', 'li', 'link', 'main', 'map', 'mark', 'menu', 'meta', 'meter',
        'nav', 'noscript', 'object', 'ol', 'optgroup', 'option', 'output', 'p', 'picture', 'pre', 'progress', 'q',
        'rp', 'rt', 'ruby', 's', 'samp', 'script', 'search', 'section', 'select', 'slot', 'small', 'source', 'span',
        'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th',
        'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr', 'svg', 'animate', 'animatemotion',
        'animatetransform', 'circle', 'clippath', 'defs', 'desc', 'ellipse', 'feblend', 'fecolormatrix',
        'fecomponenttransfer', 'fecomposite', 'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap',
        'fedistantlight', 'fedropshadow', 'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur',
        'feimage', 'femerge', 'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting',
        'fespotlight', 'fetile', 'feturbulence', 'filter', 'foreignobject', 'g', 'image', 'line', 'lineargradient',
        'marker', 'mask', 'metadata', 'mpath', 'path', 'pattern', 'polygon', 'polyline', 'radialgradient', 'rect',
        'set', 'stop', 'switch', 'symbol', 'text', 'textpath', 'tspan', 'use', 'view', 'math',
    ];

    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if (
            ($node instanceof ModuleNode || $node instanceof BlockNode || $node instanceof MacroNode)
            && $node->hasNode('body')
            && !TemplateFormat::isNonHtml($node->getTemplateName() ?? '')
        ) {
            $this->annotate($node);
        }

        return $node;
    }

    private function annotate(Node $owner): void
    {
        $body = $owner->getNode('body');
        $texts = [];
        if ($body instanceof TextNode) {
            // A body with a single child is that child, e.g. a block with only static content
            $texts[] = [
                'node' => $body,
                'parent' => $owner,
                'key' => 'body',
            ];
        } else {
            $this->collectTexts($body, $texts);
        }
        if ($texts === []) {
            return;
        }

        [$skeleton, $starts] = $this->buildSkeleton($texts);

        foreach ($this->findInsertions($skeleton, $starts, $texts) as $index => $positions) {
            $this->insertAttributes($texts[$index], $positions);
        }
    }

    /**
     * Join the text nodes, with a placeholder in between: text nodes are never contiguous, there's always something
     * in between, which may output something.
     *
     * @param list<array{node: TextNode, parent: Node, key: string}> $texts
     *
     * @return array{string, list<int>} The skeleton, and where each text node starts in it.
     */
    private function buildSkeleton(array $texts): array
    {
        $skeleton = '';
        $starts = [];
        foreach ($texts as $index => $text) {
            if ($index > 0) {
                $skeleton .= self::PLACEHOLDER;
            }
            $starts[] = \strlen($skeleton);
            $skeleton .= self::getText($text['node']);
        }

        return [$skeleton, $starts];
    }

    /**
     * Find the tags of the skeleton, and where to annotate them in the text nodes: right after the tag name. Tags whose
     * name isn't entirely in a single text node (e.g. `<{{ tag }}>`) are skipped.
     *
     * @param list<int>                                              $starts
     * @param list<array{node: TextNode, parent: Node, key: string}> $texts
     *
     * @return array<int, list<array{int, int}>> Per text node index, the offsets of the tags and of their name ends.
     */
    private function findInsertions(string $skeleton, array $starts, array $texts): array
    {
        $insertions = [];
        $ends = \array_map(
            static fn (int $start, array $text): int => $start + \strlen(self::getText($text['node'])),
            $starts,
            $texts
        );
        $index = 0;

        $scanner = new HtmlTagScanner($skeleton);
        while ($scanner->next_tag([
            'tag_closers' => 'skip',
        ])) {
            $tagName = \strtolower((string) $scanner->get_tag());
            $tagStart = $scanner->get_current_tag_offset();
            if ($tagStart === null || !$this->isElement($tagName)) {
                continue;
            }
            $nameEnd = $tagStart + 1 + \strlen($tagName);

            // Tags come in order: the text node of this one is the same as the previous one's, or a following one
            while (isset($starts[$index + 1]) && $starts[$index + 1] <= $tagStart) {
                $index++;
            }
            // The tag starts in the placeholder before the text node, or its name doesn't fit in the text node
            if ($tagStart < $starts[$index] || $nameEnd > $ends[$index]) {
                continue;
            }

            // A name ending with the text node may go on with what's output next, e.g. `<my-{{ kind }}>`: only known
            // elements are safe, e.g. `<input{{ attributes }}>`
            if ($nameEnd < $ends[$index] || \in_array($tagName, self::ELEMENTS, true)) {
                $insertions[$index][] = [$tagStart - $starts[$index], $nameEnd - $starts[$index]];
            }
        }

        return $insertions;
    }

    /**
     * Replace a text node with a copy where its tags are annotated.
     *
     * @param array{node: TextNode, parent: Node, key: string} $text
     * @param list<array{int, int}>                            $positions Offsets of the tags and of their name ends.
     */
    private function insertAttributes(array $text, array $positions): void
    {
        $node = $text['node'];
        $data = self::getText($node);

        // From the end, so earlier offsets stay valid
        foreach (\array_reverse($positions) as [$tagOffset, $insertAt]) {
            $line = $node->getTemplateLine() + \substr_count($data, "\n", 0, $tagOffset);
            $attribute = \sprintf(
                ' %s="%s"',
                self::ATTRIBUTE,
                \htmlspecialchars($node->getTemplateName() . ':' . $line, ENT_QUOTES)
            );
            $data = \substr_replace($data, $attribute, $insertAt, 0);
        }

        $text['parent']->setNode($text['key'], new TextNode($data, $node->getTemplateLine()));
    }

    /**
     * Text of a text node, which its constructor stores in the `data` attribute.
     */
    private static function getText(TextNode $node): string
    {
        $data = $node->getAttribute('data');

        return \is_string($data) ? $data : '';
    }

    private function isElement(string $tagName): bool
    {
        // Custom elements: a lowercase ASCII letter, then letters, digits, dots, underscores or hyphens, with a hyphen
        if (\preg_match('/^[a-z][a-z0-9._]*-[a-z0-9._-]*$/', $tagName)) {
            return true;
        }

        return \in_array($tagName, self::ELEMENTS, true);
    }

    /**
     * Collect the text nodes under a node, in output order.
     *
     * @param list<array{node: TextNode, parent: Node, key: string}> $texts
     */
    private function collectTexts(Node $node, array &$texts): void
    {
        foreach ($node as $key => $child) {
            if ($child instanceof TextNode) {
                $texts[] = [
                    'node' => $child,
                    'parent' => $node,
                    'key' => (string) $key,
                ];
            } else {
                $this->collectTexts($child, $texts);
            }
        }
    }

    public function getPriority(): int
    {
        return 0;
    }
}
