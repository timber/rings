<?php

declare(strict_types=1);

namespace Timber\Rings\Tests;

use Mantle\Testkit\TestCase;
use SplObjectStorage;
use Timber\Rings\Twig\NestedProfilerExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Profiler\Profile;
use Twig\TwigFunction;

/**
 * Twig environments rendered while another one is rendering (e.g. Timber::compile() called from a template) are
 * linked to the profile that called them.
 */
final class NestedProfilerTest extends TestCase
{
    public function testRenderingFromAnotherEnvironmentIsLinkedToItsCaller(): void
    {
        $callers = new SplObjectStorage();
        $recordCaller = static function (Profile $profile, Profile $caller) use ($callers): void {
            $callers[$profile] = $caller;
        };

        $innerProfile = new Profile();
        $inner = new Environment(new ArrayLoader([
            'card.twig' => '<div>{% include "icon.twig" %}</div>',
            'icon.twig' => '<i></i>',
        ]));
        $inner->addExtension(new NestedProfilerExtension($innerProfile, $recordCaller));

        $outerProfile = new Profile();
        $outer = new Environment(new ArrayLoader([
            'page.twig' => '<main>{% block content %}{{ card() }}{{ card() }}{% endblock %}</main>',
        ]));
        $outer->addExtension(new NestedProfilerExtension($outerProfile, $recordCaller));
        $outer->addFunction(new TwigFunction('card', static fn (): string => $inner->render('card.twig'), [
            'is_safe' => ['html'],
        ]));

        $outer->render('page.twig');

        // Both renderings of the inner environment are linked to the block they were called from
        $block = $outerProfile->getProfiles()[0]->getProfiles()[0];
        $this->assertSame('page.twig::content', $block->getTemplate() . '::' . $block->getName());
        $cards = $innerProfile->getProfiles();
        $this->assertCount(2, $cards);
        $this->assertSame($block, $callers[$cards[0]]);
        $this->assertSame($block, $callers[$cards[1]]);

        // Profiles within an environment aren't linked
        $this->assertCount(2, $callers);
    }
}
