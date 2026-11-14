<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Twig\Extension\AbstractExtension;

/**
 * Surrounds the HTML output of each template and block with comment markers:
 *
 *     <!--twig:start {&quot;template&quot;:&quot;page.twig&quot;,&quot;line&quot;:1,&quot;id&quot;:&quot;twig-3&quot;}-->
 *     ...
 *     <!--twig:end twig-3-->
 *
 * Comments are used rather than attributes so nested templates and blocks sharing the same first element each keep
 * their own marker, and so the DOM structure is left untouched (comments are valid in tables, lists, before the
 * doctype, etc.). The inspector script pairs the markers up and maps them onto the elements between them.
 */
final class TemplateMarkerExtension extends AbstractExtension
{
    private const string START = 'twig:start ';

    private const string END = 'twig:end ';

    private static int $idCounter = 0;

    /** @var array<array{template: string, line: int, id: string, level: int}> */
    private array $stack = [];

    /**
     * Marker ids of each template ("template" or "template::block(name)") renderings, in render order.
     *
     * @var array<string, list<string>>
     */
    private array $renderings = [];

    /**
     * Maps "template::block(name)" or "template" to line numbers.
     * Static so it's shared across all Twig environment instances.
     *
     * @var array<string, int>
     */
    private static array $lineMap = [];

    public function enter(string $templateName, int $line): void
    {
        $id = $this->generateId();
        $this->renderings[$templateName][] = $id;
        \ob_start();
        $this->stack[] = [
            'template' => $templateName,
            'line' => $line,
            'id' => $id,
            'level' => \ob_get_level(),
        ];
    }

    /**
     * Marker ids of each template ("template" or "template::block(name)") renderings, in render order.
     *
     * Renderings whose output isn't marked (empty, not HTML...) are listed too, so they match the profiler's.
     *
     * @return array<string, list<string>>
     */
    public function getRenderings(): array
    {
        return $this->renderings;
    }

    public function leave(): void
    {
        // Renderings that threw never left: Twig discarded their output buffers, forget them too
        while ($this->stack !== [] && \end($this->stack)['level'] > \ob_get_level()) {
            \array_pop($this->stack);
        }

        $output = \ob_get_clean();
        $metadata = \array_pop($this->stack);
        unset($metadata['level']);

        if ($output === false || $metadata === null) {
            return;
        }

        if (!$this->shouldMark($metadata['template'], $output)) {
            echo $output;
            return;
        }

        // No "<", ">", "&", quotes, so the payload can't close the comment early, nor break out of an attribute value
        // when the output ends up in one (e.g. a block rendered in a <meta content="">): in strings, they're escaped as
        // \u00XX, and JSON's own double quotes are encoded as &quot;.
        $json = \wp_json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $json = \str_replace('"', '&quot;', (string) $json);

        echo '<!--' . self::START . $json . '-->' . $output . '<!--' . self::END . $metadata['id'] . '-->';
    }

    private function shouldMark(string $templateName, string $output): bool
    {
        // HTML output starts with a tag. Anything else (text, JavaScript in a <script>, JSON...) may contain things
        // looking like tags, but a comment there would show up as text or break the script.
        $trimmed = \ltrim($output);
        if (!\str_starts_with($trimmed, '<') || \str_starts_with($trimmed, '<?xml')) {
            return false;
        }

        return !TemplateFormat::isNonHtml($templateName);
    }

    private function generateId(): string
    {
        return 'twig-' . ++self::$idCounter;
    }

    public function getNodeVisitors(): array
    {
        return [new TemplateMarkerNodeVisitor(self::class)];
    }

    /**
     * Register a line number for a template or block.
     * Called from compiled template code.
     */
    public function registerLine(string $template, int $line, ?string $blockName = null): void
    {
        $key = $blockName !== null
            ? $template . '::block(' . $blockName . ')'
            : $template;

        // Only store first occurrence (definition line)
        self::$lineMap[$key] ??= $line;
    }

    /**
     * Get all registered line mappings.
     *
     * @return array<string, int>
     */
    public function getLineMap(): array
    {
        return self::$lineMap;
    }
}
