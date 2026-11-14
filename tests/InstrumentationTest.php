<?php

declare(strict_types=1);

namespace Timber\Rings\Tests;

use Mantle\Testkit\TestCase;
use Timber\Rings\Twig\NestedProfilerExtension;
use Timber\Rings\Twig\SourceAnnotationExtension;
use Timber\Rings\Twig\TemplateMarkerExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Twig is instrumented (profiled, annotated, marked) only for users who can see Query Monitor's HTML output.
 */
final class InstrumentationTest extends TestCase
{
    private function getTimberTwig(): Environment
    {
        $twig = \apply_filters('timber/twig', new Environment(new ArrayLoader([]), [
            'cache' => false,
        ]));
        $this->assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    public function testTwigIsInstrumentedForUsersWhoCanViewQueryMonitor(): void
    {
        $this->acting_as('administrator');

        $twig = $this->getTimberTwig();

        $this->assertTrue($twig->hasExtension(NestedProfilerExtension::class));
        $this->assertTrue($twig->hasExtension(TemplateMarkerExtension::class));
        $this->assertTrue($twig->hasExtension(SourceAnnotationExtension::class));
    }

    public function testTwigIsNotInstrumentedForVisitors(): void
    {
        $twig = $this->getTimberTwig();

        $this->assertFalse($twig->hasExtension(NestedProfilerExtension::class));
        $this->assertFalse($twig->hasExtension(TemplateMarkerExtension::class));
        $this->assertFalse($twig->hasExtension(SourceAnnotationExtension::class));
    }

    /**
     * Their output doesn't end up in a page showing Query Monitor's panel.
     */
    public function testTwigIsNotInstrumentedInAjaxCronAndFeedRequests(): void
    {
        $this->acting_as('administrator');

        \add_filter('wp_doing_ajax', '__return_true');
        $this->assertFalse($this->getTimberTwig()->hasExtension(TemplateMarkerExtension::class), 'AJAX');
        \remove_filter('wp_doing_ajax', '__return_true');

        \add_filter('wp_doing_cron', '__return_true');
        $this->assertFalse($this->getTimberTwig()->hasExtension(TemplateMarkerExtension::class), 'Cron');
        \remove_filter('wp_doing_cron', '__return_true');

        $this->get(\get_feed_link());
        $this->assertFalse($this->getTimberTwig()->hasExtension(TemplateMarkerExtension::class), 'Feed');
    }

    /**
     * Instrumented output must not be cached, it would be served to everyone.
     */
    public function testTimberCacheIsBypassedWhenInstrumented(): void
    {
        $this->assertSame('transient', \apply_filters('timber/cache/mode', 'transient'));
        $this->assertSame('timber_key', \apply_filters('timber/cache/transient_key', 'timber_key', 'key', 'timber', 'transient'));

        $this->acting_as('administrator');

        $this->assertSame('none', \apply_filters('timber/cache/mode', 'transient'));
        // When a cache mode is explicitly given to Timber::render() or Timber::compile()
        $this->assertNotSame('timber_key', \apply_filters('timber/cache/transient_key', 'timber_key', 'key', 'timber', 'transient'));
    }
}
