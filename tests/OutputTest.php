<?php

declare(strict_types=1);

namespace Timber\Rings\Tests;

use function Mantle\Testing\html_string;
use Mantle\Testkit\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Timber\Rings\Collector;
use Timber\Rings\Output;
use Timber\Rings\Twig\NestedProfilerExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Profiler\Profile;
use Twig\TwigFunction;

/**
 * The Twig panel of Query Monitor.
 */
final class OutputTest extends TestCase
{
    private Collector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new Collector();
    }

    /**
     * @param array<string, string> $templates
     */
    private function createEnvironment(array $templates): Environment
    {
        $profile = new Profile();
        $twig = new Environment(new ArrayLoader($templates));
        $twig->addExtension(new NestedProfilerExtension(
            $profile,
            fn (Profile $profile, Profile $caller) => $this->collector->add_caller($profile, $caller)
        ));
        $this->collector->add_profile($twig, $profile);

        return $twig;
    }

    private function getPanel(): string
    {
        \ob_start();
        (new Output($this->collector))->output();

        return (string) \ob_get_clean();
    }

    /**
     * @return list<array{string, int}> Template (or block) and depth of each row.
     */
    private function getRows(string $panel): array
    {
        return html_string($panel)->get_by_selector('tr[data-qm-twig-template]')->each(
            static fn (Crawler $row): array => [
                $row->attr('data-qm-twig-template') ?? '',
                \intdiv(\mb_strlen($row->filter('td:first-of-type > span:first-of-type')->text('', false)), 4),
            ]
        );
    }

    /**
     * The inspector switches Query Monitor to the panel through its navigation, which only knows the panel by the id
     * and title of its menu entry.
     */
    public function testInspectorIsGivenThePanelMenuIdAndTitle(): void
    {
        $this->createEnvironment([
            'page.twig' => '<main></main>',
        ])->render('page.twig');

        // Tags are JSON-escaped, so the object ends at the first "</script>"
        $this->assertSame(1, \preg_match('#window\.QMTwigInspector=(.+?);</script>#', $this->getPanel(), $matches));
        $data = \json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        $menu = (new Output($this->collector))->admin_menu([])['qm-' . Collector::ID];
        $this->assertSame([
            'id' => Collector::ID,
            'title' => $menu['title'],
        ], $data['panel']);
    }

    public function testEnvironmentsRenderedFromATemplateAreNestedUnderIt(): void
    {
        $inner = $this->createEnvironment([
            'card.twig' => '<div>{% include "icon.twig" %}</div>',
            'icon.twig' => '<i></i>',
        ]);
        $outer = $this->createEnvironment([
            'page.twig' => '<main>{% block content %}{{ card() }}{{ card() }}{% endblock %}</main>',
        ]);
        $outer->addFunction(new TwigFunction('card', static fn (): string => $inner->render('card.twig'), [
            'is_safe' => ['html'],
        ]));

        $outer->render('page.twig');
        $panel = $this->getPanel();

        $this->assertSame(
            [
                ['page.twig', 0],
                ['page.twig::block(content)', 1],
                ['card.twig', 2],
                ['icon.twig', 3],
                ['card.twig', 2],
                ['icon.twig', 3],
            ],
            $this->getRows($panel)
        );

        // A single root environment: no environment rows
        html_string($panel)->assertElementCount('//tbody/tr[not(@data-qm-twig-template)]', 0);
        // The rows where another environment starts tell which one
        html_string($panel)->assertElementCount('//tr[@data-qm-twig-template="card.twig"][contains(., "Twig #1")]', 2);
        html_string($panel)->assertElementCount('//tr[contains(., "Twig #")]', 2);
    }
}
