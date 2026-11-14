<?php

declare(strict_types=1);

namespace Timber\Rings\Tests;

use function Mantle\Testing\html_string;
use Mantle\Testkit\TestCase;
use Timber\Rings\Twig\SourceAnnotationExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Every HTML tag written in a template's source is annotated with the template name and the line of the tag.
 */
final class SourceAnnotationTest extends TestCase
{
    /**
     * @param array<string, string> $templates
     * @param array<string, mixed>  $context
     */
    private function render(array $templates, string $name, array $context = [], bool $useYield = false): string
    {
        $twig = new Environment(new ArrayLoader($templates), [
            'cache' => false,
            'use_yield' => $useYield,
        ]);
        $twig->addExtension(new SourceAnnotationExtension());

        return $twig->render($name, $context);
    }

    public function testStaticTagIsAnnotatedWithTemplateAndLine(): void
    {
        $html = html_string($this->render([
            'page.twig' => '<main>Hello</main>',
        ], 'page.twig'));

        $this->assertSame('page.twig:1', $html->first_by_tag('main')->get_attribute('data-twig'));
    }

    public function testAnnotationPointsToTheLineOfTheTag(): void
    {
        $template = "{% set title = 'T' %}\n<header>\n  <h1>{{ title }}</h1>\n\n  <p>Intro</p>\n</header>";

        $html = html_string($this->render([
            'page.twig' => $template,
        ], 'page.twig'));

        $this->assertSame('page.twig:2', $html->first_by_tag('header')->get_attribute('data-twig'));
        $this->assertSame('page.twig:3', $html->first_by_tag('h1')->get_attribute('data-twig'));
        $this->assertSame('page.twig:5', $html->first_by_tag('p')->get_attribute('data-twig'));
    }

    public function testTagSplitByAnExpressionIsAnnotated(): void
    {
        $template = '<div class="card {{ modifier }}" id="{{ id }}"><span>{{ label }}</span></div>';

        $html = html_string($this->render([
            'card.twig' => $template,
        ], 'card.twig', [
            'modifier' => 'card--big',
            'id' => 'c1',
            'label' => 'Label',
        ]));

        $div = $html->first_by_tag('div');
        $this->assertSame('card.twig:1', $div->get_attribute('data-twig'));
        $this->assertSame('card card--big', $div->get_attribute('class'));
        $this->assertSame('c1', $div->get_attribute('id'));
        $this->assertSame('card.twig:1', $html->first_by_tag('span')->get_attribute('data-twig'));
    }

    public function testTagLookalikesInRawTextCommentsAndAttributesAreLeftAlone(): void
    {
        $template = '<script>var s = "<div>" + {{ n }};</script>'
            . '<style>a::before { content: "<p>"; }</style>'
            . '<textarea><em>{{ n }}</em></textarea>'
            . '<!-- <section> -->'
            . '<input value="<b>">';

        $output = $this->render([
            'page.twig' => $template,
        ], 'page.twig', [
            'n' => 1,
        ]);

        $this->assertSame(
            '<script data-twig="page.twig:1">var s = "<div>" + 1;</script>'
            . '<style data-twig="page.twig:1">a::before { content: "<p>"; }</style>'
            . '<textarea data-twig="page.twig:1"><em>1</em></textarea>'
            . '<!-- <section> -->'
            . '<input data-twig="page.twig:1" value="<b>">',
            $output
        );
    }

    public function testHtmlFromVariablesIsLeftAlone(): void
    {
        $output = $this->render([
            'page.twig' => '<article>{{ content|raw }}</article>',
        ], 'page.twig', [
            'content' => '<p>From the editor</p>',
        ]);

        $this->assertSame(
            '<article data-twig="page.twig:1"><p>From the editor</p></article>',
            $output
        );
    }

    public function testNonHtmlTemplatesAreLeftAlone(): void
    {
        $templates = [
            'data.json.twig' => '{"html": "<div>{{ n }}</div>"}',
            'mail.txt.twig' => 'Hello <{{ email }}>, see <b>this</b>',
            'app.js.twig' => 'if (a <b && c) { el.innerHTML = "<span>"; }',
        ];

        foreach ($templates as $name => $template) {
            $this->assertSame(
                \str_replace(['{{ n }}', '{{ email }}'], ['1', 'me@example.com'], $template),
                $this->render($templates, $name, [
                    'n' => 1,
                    'email' => 'me@example.com',
                ]),
                $name
            );
        }
    }

    public function testVoidAndSelfClosingTagsAreAnnotated(): void
    {
        $template = '<img src="{{ src }}"><br/><input{{ attrs|raw }}>';

        $output = $this->render([
            'page.twig' => $template,
        ], 'page.twig', [
            'src' => 'a.png',
            'attrs' => ' type="email"',
        ]);

        $this->assertSame(
            '<img data-twig="page.twig:1" src="a.png"><br data-twig="page.twig:1"/><input data-twig="page.twig:1" type="email">',
            $output
        );
    }

    public function testTagWithDynamicNameIsLeftAlone(): void
    {
        $output = $this->render([
            'page.twig' => '<{{ tag }} class="title">Title</{{ tag }}>',
        ], 'page.twig', [
            'tag' => 'h2',
        ]);

        $this->assertSame('<h2 class="title">Title</h2>', $output);
    }

    public function testTagsPointToTheTemplateDefiningThem(): void
    {
        $templates = [
            'base.twig' => "<main>\n{% block content %}<p>Default</p>{% endblock %}\n{% include 'footer.twig' %}\n</main>",
            'page.twig' => "{% extends 'base.twig' %}\n\n{% block content %}\n  <div class=\"page\">{{ title }}</div>\n{% endblock %}",
            'footer.twig' => "\n<footer>Footer</footer>",
        ];

        $html = html_string($this->render($templates, 'page.twig', [
            'title' => 'Title',
        ]));

        $this->assertSame('base.twig:1', $html->first_by_tag('main')->get_attribute('data-twig'));
        $this->assertSame('page.twig:4', $html->first_by_tag('div')->get_attribute('data-twig'));
        $this->assertSame('footer.twig:2', $html->first_by_tag('footer')->get_attribute('data-twig'));
    }

    public function testOnlyActualElementsAreAnnotated(): void
    {
        $templates = [
            'page.twig' => "<script>{% include 'loop.twig' %}</script><my-card><svg><path d=\"M0\"/></svg></my-card>",
            // Included in a <script>, which can't be known when compiling this template
            'loop.twig' => 'if (i <max && j > 0) { run(); }',
        ];

        $output = $this->render($templates, 'page.twig');

        $this->assertSame(
            '<script data-twig="page.twig:1">if (i <max && j > 0) { run(); }</script>'
            . '<my-card data-twig="page.twig:1"><svg data-twig="page.twig:1"><path data-twig="page.twig:1" d="M0"/></svg></my-card>',
            $output
        );
    }

    public function testTagsAreAnnotatedWhenTwigYieldsOutput(): void
    {
        $output = $this->render([
            'page.twig' => '<main>{{ title }}</main>',
        ], 'page.twig', [
            'title' => 'Title',
        ], true);

        $this->assertSame('<main data-twig="page.twig:1">Title</main>', $output);
    }

    public function testBlockWithOnlyStaticContentIsAnnotated(): void
    {
        $output = $this->render([
            'page.twig' => '<main>{% block footer %}<footer>Footer</footer>{% endblock %}</main>',
        ], 'page.twig');

        $this->assertSame('<main data-twig="page.twig:1"><footer data-twig="page.twig:1">Footer</footer></main>', $output);
    }

    public function testTagWithPartlyDynamicNameIsLeftAlone(): void
    {
        $output = $this->render([
            'page.twig' => '<my-{{ kind }} class="x">Card</my-{{ kind }}>',
        ], 'page.twig', [
            'kind' => 'card',
        ]);

        $this->assertSame('<my-card class="x">Card</my-card>', $output);
    }
}
