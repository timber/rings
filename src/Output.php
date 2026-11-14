<?php

declare(strict_types=1);

namespace Timber\Rings;

use QM_Collector;
use QM_Output_Html;
use QM_Util;
use SplObjectStorage;
use Throwable;
use Timber\Rings\Twig\TemplateMarkerExtension;
use Twig\Environment;
use Twig\Profiler\Profile;

final class Output extends QM_Output_Html
{
    private ?Environment $twig = null;

    private float $rootDuration = 0;

    /** @var array<string, int> */
    private array $lineMap = [];

    /** @var array<string, string>|null */
    private ?array $pathMap = null;

    /** @var array<string, string> Template files per environment and template name, see get_template_path() */
    private array $templatePathCache = [];

    /**
     * Top-level profiles of environments rendered from each profile (e.g. Timber::compile() called from a template).
     *
     * @var SplObjectStorage<Profile, list<array{profile: Profile, twig: Environment, name: string}>>
     */
    private SplObjectStorage $nested;

    /**
     * Rows output so far per template, per marker extension (i.e. Twig environment).
     *
     * @var array<string, int>
     */
    private array $renderingIndexes = [];

    public function __construct(QM_Collector $collector)
    {
        parent::__construct($collector);
        \add_filter('qm/output/menus', [$this, 'admin_menu'], 105);
    }

    public function name(): string
    {
        return \__('Twig', 'timber');
    }

    public function output(): void
    {
        /** @var TwigProfile $data */
        $data = $this->collector->get_data();

        if ($data->profiles === []) {
            $this->before_non_tabular_output();
            echo '<div class="qm-none"><p>' . \esc_html__('No Twig profile data collected.', 'timber') . '</p></div>';
            $this->after_non_tabular_output();
            return;
        }

        // Get line map from extension (static, shared across all instances)
        $firstTwig = $data->profiles[0]['twig'] ?? null;
        if ($firstTwig !== null && $firstTwig->hasExtension(TemplateMarkerExtension::class)) {
            /** @var TemplateMarkerExtension $ext */
            $ext = $firstTwig->getExtension(TemplateMarkerExtension::class);
            $this->lineMap = $ext->getLineMap();
        }

        $roots = $this->get_roots($data);

        /**
         * Whether to show separate roots for each Twig environment.
         *
         * @param bool $separate True for separate roots, false for merged single root.
         */
        $separateRoots = \apply_filters('timber/rings/separate_twig_roots', \count($roots) > 1);

        // Totals: environments rendered from other ones are already part of their callers' duration and memory
        $totalDuration = 0;
        $totalMemory = 0;
        foreach ($roots as $root) {
            $totalDuration += $root['profile']->getDuration() * 1000;
            $totalMemory += $this->get_memory($root['profile']);
        }
        $this->rootDuration = $totalDuration;

        $totalTemplates = 0;
        $totalBlocks = 0;
        $totalMacros = 0;
        foreach ($data->profiles as $entry) {
            $stats = $this->compute_stats($entry['profile']);
            $totalTemplates += $stats['templates'];
            $totalBlocks += $stats['blocks'];
            $totalMacros += $stats['macros'];
        }

        $this->before_tabular_output();

        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col">' . \esc_html__('Template', 'query-monitor') . '</th>';
        echo '<th scope="col" class="qm-num qm-sortable-column qm-sorted-desc" role="columnheader" aria-sort="descending">';
        echo $this->build_sorter(\__('Time', 'query-monitor'));
        echo '</th>';
        echo '<th scope="col" class="qm-num qm-sortable-column" role="columnheader">';
        echo $this->build_sorter(\__('%', 'query-monitor'));
        echo '</th>';
        echo '<th scope="col" class="qm-num qm-sortable-column" role="columnheader">';
        echo $this->build_sorter(\__('Memory', 'query-monitor'));
        echo '</th>';
        echo '</tr>';
        echo '</thead>';

        echo '<tbody>';

        if ($separateRoots) {
            $this->render_separate_roots($roots, $totalDuration);
        } else {
            $this->render_merged_root($roots);
        }

        echo '</tbody>';

        echo '<tfoot>';
        echo '<tr>';
        echo '<td>';
        $parts = [];
        $parts[] = \sprintf(
            /* translators: %s: number of templates */
            \esc_html__('%s templates', 'timber'),
            '<span class="qm-items-number">' . \esc_html(\number_format_i18n($totalTemplates)) . '</span>'
        );
        if ($totalBlocks > 0) {
            $parts[] = \sprintf(
                /* translators: %s: number of blocks */
                \esc_html__('%s blocks', 'timber'),
                '<span class="qm-items-number">' . \esc_html(\number_format_i18n($totalBlocks)) . '</span>'
            );
        }
        if ($totalMacros > 0) {
            $parts[] = \sprintf(
                /* translators: %s: number of macros */
                \esc_html__('%s macros', 'timber'),
                '<span class="qm-items-number">' . \esc_html(\number_format_i18n($totalMacros)) . '</span>'
            );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo \implode(', ', $parts);
        echo '</td>';
        echo '<td class="qm-num qm-items-time">' . \esc_html(\number_format_i18n($totalDuration, 2)) . ' ms</td>';
        echo '<td class="qm-num">100%</td>';
        echo '<td class="qm-num">' . \esc_html($this->format_memory($totalMemory)) . '</td>';
        echo '</tr>';
        echo '</tfoot>';

        $this->after_tabular_output();

        // Output the inspect button and the inspector's assets
        $this->output_inspector();
    }

    /**
     * Environments not rendered from other ones (usually a single one), with their top-level profiles that weren't
     * either. The others are attached to the profiles they were rendered from.
     *
     * @return list<array{profile: Profile, twig: Environment, name: string, children: list<Profile>}>
     */
    private function get_roots(TwigProfile $data): array
    {
        $this->nested = new SplObjectStorage();
        $roots = [];

        foreach ($data->profiles as $entry) {
            $children = [];
            foreach ($entry['profile']->getProfiles() as $child) {
                if (!isset($data->callers[$child])) {
                    $children[] = $child;
                    continue;
                }

                $caller = $data->callers[$child];
                $this->nested[$caller] = [
                    ...($this->nested[$caller] ?? []),
                    [
                        'profile' => $child,
                        'twig' => $entry['twig'],
                        'name' => $entry['name'],
                    ],
                ];
            }

            if ($children !== []) {
                $roots[] = $entry + [
                    'children' => $children,
                ];
            }
        }

        return $roots;
    }

    /**
     * Render root environments as separate trees.
     *
     * @param list<array{profile: Profile, twig: Environment, name: string, children: list<Profile>}> $roots
     */
    private function render_separate_roots(array $roots, float $totalDuration): void
    {
        foreach ($roots as $root) {
            $profile = $root['profile'];
            $this->twig = $root['twig'];

            // Render root row for this environment
            $duration = $profile->getDuration() * 1000;
            $percentage = $totalDuration > 0 ? ($duration / $totalDuration) * 100 : 0;
            $memory = $this->get_memory($profile);

            echo '<tr style="background: var(--qm-panel-menu-bg);">';
            echo '<td class="qm-ltr"><strong>' . \esc_html($root['name']) . '</strong></td>';
            // Not highlighted: it's the total of the environment, which would almost always be
            echo '<td class="qm-num" data-qm-sort-weight="' . \esc_attr((string) $duration) . '">';
            echo \esc_html(\number_format($duration, 2)) . ' ms</td>';
            echo '<td class="qm-num" data-qm-sort-weight="' . \esc_attr((string) $percentage) . '">';
            echo \esc_html(\number_format($percentage, 1)) . '%</td>';
            echo '<td class="qm-num" data-qm-sort-weight="' . \esc_attr((string) $memory) . '">';
            echo \esc_html($this->format_memory($memory)) . '</td>';
            echo '</tr>';

            $this->render_children($this->get_own_children($root['children']), 1, '');
        }
    }

    /**
     * Render root environments merged in a single tree.
     *
     * @param list<array{profile: Profile, twig: Environment, name: string, children: list<Profile>}> $roots
     */
    private function render_merged_root(array $roots): void
    {
        foreach ($roots as $root) {
            $this->twig = $root['twig'];
            $this->render_children($this->get_own_children($root['children']), 0, '');
        }
    }

    /**
     * @param array<Profile> $profiles
     *
     * @return list<array{profile: Profile, twig: Environment|null, name: string|null}>
     */
    private function get_own_children(array $profiles): array
    {
        return \array_map(
            static fn (Profile $profile): array => [
                'profile' => $profile,
                'twig' => null,
                'name' => null,
            ],
            \array_values($profiles)
        );
    }

    /**
     * Children of a profile: its own, and the top-level profiles of environments rendered from it, in render order.
     *
     * @return list<array{profile: Profile, twig: Environment|null, name: string|null}> With the environment and its
     *                                                                                 name, for other environments.
     */
    private function get_children(Profile $profile): array
    {
        $children = $this->get_own_children($profile->getProfiles());
        if (!isset($this->nested[$profile])) {
            return $children;
        }

        $children = [...$children, ...$this->nested[$profile]];
        \usort(
            $children,
            static fn (array $a, array $b): int => $a['profile']->getStartTime() <=> $b['profile']->getStartTime()
        );

        return $children;
    }

    /**
     * @param list<array{profile: Profile, twig: Environment|null, name: string|null}> $children
     */
    private function render_children(array $children, int $depth, string $treePrefix): void
    {
        $lastIndex = \count($children) - 1;
        foreach ($children as $index => ['profile' => $child, 'twig' => $twig, 'name' => $environment]) {
            // Paths and renderings are resolved with the environment that rendered the profile
            $previousTwig = $this->twig;
            $this->twig = $twig ?? $previousTwig;
            $this->render_table_rows($child, $depth, $treePrefix, $index === $lastIndex, $environment);
            $this->twig = $previousTwig;
        }
    }

    private function output_inspector(): void
    {
        // Inspect button (will be moved to QM title bar by JS)
        // Icon: cursor pointing at element (Chrome DevTools style)
        echo '<button type="button" class="qm-twig-inspect-btn" id="qm-twig-inspect-btn" aria-label="' . \esc_attr__('Inspect Twig Templates', 'timber') . '">';
        echo '<svg class="qm-twig-inspect-icon" width="20" height="20" viewBox="0 0 20 20" fill="currentColor">';
        echo '<path d="M4.5 17C4.08333 17 3.72933 16.854 3.438 16.562C3.146 16.2707 3 15.9167 3 15.5H4.5V17ZM3 13.875V12.375H4.5V13.875H3ZM3 10.75V9.25H4.5V10.75H3ZM3 7.625V6.125H4.5V7.625H3ZM3 4.5C3 4.08333 3.146 3.72933 3.438 3.438C3.72933 3.146 4.08333 3 4.5 3V4.5H3ZM6.125 17V15.5H7.625V17H6.125ZM6.125 4.5V3H7.625V4.5H6.125ZM9 17V9H17V10.5H11.562L17 15.938L15.938 17L10.5 11.562V17H9ZM9.25 4.5V3H10.75V4.5H9.25ZM12.375 4.5V3H13.875V4.5H12.375ZM15.5 7.5V6H17V7.5H15.5ZM15.5 4.5V3C15.9167 3 16.2707 3.146 16.562 3.438C16.854 3.72933 17 4.08333 17 4.5H15.5Z"/>';
        echo '</svg>';
        echo '</button>';

        // Assets are inlined: the package may live anywhere (plugin, theme vendor dir, outside the web root...)
        $assetsDir = \dirname(__DIR__) . '/assets';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $cssContent = \file_get_contents($assetsDir . '/inspector.css');

        // Styles for the inspector, which adopts them in the page and in QM's shadow root, and the panel to switch to
        $inspectorData = [
            'css' => $cssContent !== false ? $cssContent : '',
            'panel' => [
                'id' => Collector::ID,
                'title' => $this->name(),
            ],
        ];
        $json = \wp_json_encode($inspectorData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        echo '<script>window.QMTwigInspector=' . ($json !== false ? $json : 'null') . ';</script>';

        // Built with its dependencies (see CONTRIBUTING.md)
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $jsContent = \file_get_contents($assetsDir . '/dist/inspector.js');
        if ($jsContent !== false) {
            echo '<script type="module">' . $jsContent . '</script>';
        }
    }

    /**
     * Apply Query Monitor's file path mapping (e.g. container path to host path), like QM does for its own links.
     */
    private function map_path(string $path): string
    {
        $this->pathMap ??= self::get_file_path_map();

        foreach ($this->pathMap as $from => $to) {
            if (\str_starts_with($path, $from)) {
                return $to . \substr($path, \strlen($from));
            }
        }

        return $path;
    }

    /**
     * Compute statistics for a profile tree.
     *
     * @return array{templates: int, blocks: int, macros: int}
     */
    private function compute_stats(Profile $profile): array
    {
        $stats = [
            'templates' => 0,
            'blocks' => 0,
            'macros' => 0,
        ];

        $this->collect_stats($profile, $stats);

        return $stats;
    }

    /**
     * Recursively collect statistics from profile tree.
     *
     * @param array{templates: int, blocks: int, macros: int} $stats
     */
    private function collect_stats(Profile $profile, array &$stats): void
    {
        if ($profile->isTemplate()) {
            $stats['templates']++;
        } elseif ($profile->isBlock()) {
            $stats['blocks']++;
        } elseif ($profile->isMacro()) {
            $stats['macros']++;
        }

        foreach ($profile->getProfiles() as $child) {
            $this->collect_stats($child, $stats);
        }
    }

    /**
     * @param string|null $environment Name of the Twig environment, when the row starts another one than its parent's.
     */
    private function render_table_rows(
        Profile $profile,
        int $depth = 0,
        string $treePrefix = '',
        bool $isLast = true,
        ?string $environment = null
    ): void {
        $name = $this->get_profile_name($profile);
        $file = $this->get_template_path($profile->getTemplate());
        $line = $this->get_profile_line($profile);
        $duration = $profile->getDuration() * 1000;
        $percentage = $this->rootDuration > 0 ? ($duration / $this->rootDuration) * 100 : 0;
        $memory = $this->get_memory($profile);

        $children = $this->get_children($profile);

        // Tree visualization - use consistent-width characters
        $connector = $depth === 0 ? '' : ($isLast ? '└── ' : '├── ');
        $childTreePrefix = $treePrefix . ($depth === 0 ? '' : ($isLast ? '    ' : '│   '));
        $treeStyle = 'font-family: monospace; white-space: pre; user-select: none;';

        // Row class based on time threshold
        [$rowClass, $timeClass] = $this->get_duration_classes($duration);

        // Templates and blocks have markers in the page, which the inspector links to the rows
        $markerAttributes = '';
        if (!$profile->isMacro()) {
            $markerAttributes = ' data-qm-twig-template="' . \esc_attr($name) . '"';
            $renderingId = $this->get_rendering_id($name);
            if ($renderingId !== null) {
                $markerAttributes .= ' data-qm-twig-rendering="' . \esc_attr($renderingId) . '"';
            }
        }
        echo '<tr class="' . \esc_attr($rowClass) . '"' . $markerAttributes . '>';

        // Template column with tree prefix
        echo '<td class="qm-ltr">';
        if ($depth > 0) {
            echo '<span class="qm-info" style="' . \esc_attr($treeStyle) . '">' . \esc_html($treePrefix . $connector) . '</span>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->output_template_name($name, $file, $line);
        if ($environment !== null) {
            echo ' <span class="qm-info qm-twig-environment">' . \esc_html($environment) . '</span>';
        }

        // File path below the name, the tree lines continuing alongside (down to the children, if any)
        if ($file !== '') {
            $pathPrefix = $childTreePrefix . ($children !== [] ? '│ ' : '  ');
            echo '<div style="display: flex;">';
            echo '<span class="qm-info" style="' . \esc_attr($treeStyle) . '">' . \esc_html($pathPrefix) . '</span>';
            echo '<span class="qm-info qm-supplemental">' . \esc_html(QM_Util::standard_dir($file, '') . ':' . $line) . '</span>';
            echo '</div>';
        }
        echo '</td>';

        // Time column (sortable)
        echo '<td class="qm-num ' . \esc_attr($timeClass) . '" data-qm-sort-weight="' . \esc_attr((string) $duration) . '">';
        echo \esc_html(\number_format($duration, 2)) . ' ms';
        echo '</td>';

        // Percentage column (sortable)
        echo '<td class="qm-num" data-qm-sort-weight="' . \esc_attr((string) $percentage) . '">';
        echo \esc_html(\number_format($percentage, 1)) . '%';
        echo '</td>';

        // Memory column (sortable)
        echo '<td class="qm-num" data-qm-sort-weight="' . \esc_attr((string) $memory) . '">';
        echo \esc_html($this->format_memory($memory));
        echo '</td>';

        echo '</tr>';

        $this->render_children($children, $depth + 1, $childTreePrefix);
    }

    /**
     * Template name, linked to the file in the editor.
     *
     * QM 4 builds its edit links client side (from the editor chosen in its settings, or the file link format), and
     * QM_Output_Html::output_filename() no longer does. The link gets its default href from the file link format here,
     * and the inspector script updates it from QM's settings.
     */
    private function output_template_name(string $name, string $file, int $line): string
    {
        if ($file === '') {
            return \esc_html($name);
        }

        $mappedFile = $this->map_path($file);
        $format = self::get_file_link_format();

        return \sprintf(
            '<a class="qm-edit-link" data-qm-twig-file="%s" data-qm-twig-line="%d"%s><code>%s</code></a>',
            \esc_attr($mappedFile),
            $line,
            // Not esc_url(): it strips editor protocols such as phpstorm://
            $format !== false ? ' href="' . \esc_attr(\sprintf($format, \rawurlencode($mappedFile), $line)) . '"' : '',
            \esc_html($name)
        );
    }

    /**
     * Marker id of the next rendering of a template or block. Rows are output in render order, as markers are.
     */
    private function get_rendering_id(string $name): ?string
    {
        if ($this->twig === null || !$this->twig->hasExtension(TemplateMarkerExtension::class)) {
            return null;
        }

        /** @var TemplateMarkerExtension $extension */
        $extension = $this->twig->getExtension(TemplateMarkerExtension::class);
        $key = \spl_object_id($extension) . '|' . $name;
        $this->renderingIndexes[$key] = ($this->renderingIndexes[$key] ?? -1) + 1;

        return $extension->getRenderings()[$name][$this->renderingIndexes[$key]] ?? null;
    }

    private function get_profile_name(Profile $profile): string
    {
        if ($profile->isRoot()) {
            return 'main';
        }

        if ($profile->isTemplate()) {
            return $profile->getTemplate();
        }

        if ($profile->isMacro()) {
            return $profile->getTemplate() . '::macro(' . $profile->getName() . ')';
        }

        // Block
        return $profile->getTemplate() . '::block(' . $profile->getName() . ')';
    }

    /**
     * File of a template, from the loader of the current environment. Cached, as the loader may read the file every
     * time (Twig's filesystem loader does), and a template rendered in a loop has a row per rendering.
     */
    private function get_template_path(string $templateName): string
    {
        if ($this->twig === null || $templateName === 'main') {
            return '';
        }

        $key = \spl_object_id($this->twig) . ' ' . $templateName;
        if (!isset($this->templatePathCache[$key])) {
            try {
                $this->templatePathCache[$key] = $this->twig->getLoader()->getSourceContext($templateName)->getPath();
            } catch (Throwable) {
                $this->templatePathCache[$key] = '';
            }
        }

        return $this->templatePathCache[$key];
    }

    /**
     * Classes of a row and of its time cell, depending on the duration: the whole row is highlighted when expensive,
     * only the time cell when in the warning range.
     *
     * In QM 4, "qm-warn" is the class highlighting table rows and cells ("qm-error" is for notices, with a border and
     * padding), and a "qm-warn" cell in a "qm-warn" row is made transparent.
     *
     * @return array{string, string}
     */
    private function get_duration_classes(float $durationMs): array
    {
        if (Collector::is_expensive($durationMs)) {
            return ['qm-warn', ''];
        }
        if (Collector::is_warning($durationMs)) {
            return ['', 'qm-warn'];
        }

        return ['', ''];
    }

    /**
     * Memory used by a profile entry, in bytes.
     *
     * Root profiles are never "left" by Twig, so their memory is the sum of their children's. Deltas can be negative
     * when memory gets freed during rendering: they're reported as 0.
     */
    private function get_memory(Profile $profile): int
    {
        if (!$profile->isRoot()) {
            return \max(0, $profile->getMemoryUsage());
        }

        $memory = 0;
        foreach ($profile->getProfiles() as $child) {
            $memory += $this->get_memory($child);
        }

        return $memory;
    }

    /**
     * Human readable memory size. size_format() only fails below 1 byte (0 excepted), i.e. for negative values.
     */
    private function format_memory(int $bytes): string
    {
        return \size_format($bytes) ?: '0 B';
    }

    /**
     * Get line number for a profile entry from the line map.
     */
    private function get_profile_line(Profile $profile): int
    {
        $key = $this->get_profile_name($profile);
        return $this->lineMap[$key] ?? 1;
    }
}
