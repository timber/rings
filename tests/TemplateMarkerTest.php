<?php

declare(strict_types=1);

namespace Timber\Rings\Tests;

use function Mantle\Testing\html_string;
use Mantle\Testkit\TestCase;
use RuntimeException;
use Throwable;
use Timber\Rings\Twig\TemplateMarkerExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * The output of each template and block rendering is surrounded with marker comments, identified per rendering.
 */
final class TemplateMarkerTest extends TestCase
{
    /**
     * @param array<string, string> $templates
     */
    private function render(array $templates, string $name): string
    {
        $twig = new Environment(new ArrayLoader($templates), [
            'cache' => false,
        ]);
        $twig->addExtension(new TemplateMarkerExtension());

        return $twig->render($name);
    }

    public function testRenderingsAreListedPerTemplateInRenderOrder(): void
    {
        $twig = new Environment(new ArrayLoader([
            'page.twig' => "{% for i in 1..2 %}{% include 'card.twig' %}{% endfor %}{% block footer %}<footer></footer>{% endblock %}",
            'card.twig' => '<div class="card">{{ i }}</div>',
        ]), [
            'cache' => false,
        ]);
        $extension = new TemplateMarkerExtension();
        $twig->addExtension($extension);

        $output = $twig->render('page.twig');

        $renderings = $extension->getRenderings();
        $this->assertSame(['page.twig', 'card.twig', 'page.twig::block(footer)'], \array_keys($renderings));
        $this->assertCount(2, $renderings['card.twig']);

        // The ids are the ones of the markers, in the same order as in the output
        $this->assertSame(
            [
                $renderings['card.twig'][0],
                $renderings['card.twig'][1],
                $renderings['page.twig::block(footer)'][0],
            ],
            \array_map(
                static fn (array $match): string => $match[1],
                \preg_match_all('/<!--twig:start [^>]*&quot;id&quot;:&quot;([^&]+)&quot;\}-->/', $output, $matches, PREG_SET_ORDER) ? \array_slice($matches, 1) : []
            )
        );
    }

    public function testMarkersDoNotBreakOutOfAttributes(): void
    {
        $output = $this->render([
            'page.twig' => '<meta content="{% block description %}<b>Hi</b> there{% endblock %}">',
        ], 'page.twig');

        $html = html_string($output);
        $content = $html->first_by_tag('meta')->get_attribute('content');
        $this->assertNotNull($content);
        $this->assertStringStartsWith('<!--twig:start ', $content);
        $this->assertMatchesRegularExpression('#<b>Hi</b> there<!--twig:end twig-\d+-->$#', $content);
        $html->assertElementMissing('//b');
    }

    public function testOutputNotStartingWithATagIsNotMarked(): void
    {
        $output = $this->render([
            'page.twig' => '<script>{% block js %}if (a <b && c > d) { go(); }{% endblock %}</script>'
                . '<title>{% block title %}Title with <b>tags</b>{% endblock %}</title>',
        ], 'page.twig');

        $this->assertStringContainsString('<script>if (a <b && c > d) { go(); }</script>', $output);
        $this->assertStringContainsString('<title>Title with <b>tags</b></title>', $output);
    }

    public function testMarkersStayPairedWhenARenderingFails(): void
    {
        $twig = new Environment(new ArrayLoader([
            'page.twig' => '<main>{{ render_or_nothing("broken.twig") }}<p>After</p></main>',
            'broken.twig' => '<div>{{ fail() }}</div>',
        ]), [
            'cache' => false,
        ]);
        $twig->addExtension(new TemplateMarkerExtension());
        $twig->addFunction(new TwigFunction('fail', static function (): never {
            throw new RuntimeException('Broken');
        }));
        $twig->addFunction(new TwigFunction('render_or_nothing', static function (string $name) use ($twig): string {
            try {
                return $twig->render($name);
            } catch (Throwable) {
                return '';
            }
        }, [
            'is_safe' => ['html'],
        ]));

        $output = $twig->render('page.twig');

        $this->assertMatchesRegularExpression(
            '#^<!--twig:start \{&quot;template&quot;:&quot;page\.twig&quot;.*&quot;id&quot;:&quot;(twig-\d+)&quot;\}--><main><p>After</p></main><!--twig:end \1-->$#',
            $output
        );
    }
}
