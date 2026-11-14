<?php

declare(strict_types=1);

namespace Timber\Rings;

use LogicException;
use n5s\WpHookKit\Hook;
use QM_Collector;
use QM_Collectors;
use QM_Dispatcher;
use QM_Output;
use Timber\Rings\Twig\NestedProfilerExtension;
use Timber\Rings\Twig\SourceAnnotationExtension;
use Timber\Rings\Twig\TemplateMarkerExtension;
use Twig\Environment;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

/**
 * Hook everything up, provided Query Monitor is active.
 *
 * @param bool $late Whether Query Monitor has already registered its collectors.
 */
function boot(bool $late = false): void
{
    if (!\defined('QM_VERSION') || !\class_exists(QM_Collectors::class)) {
        return;
    }

    if ($late) {
        QM_Collectors::add(new Collector());
    } else {
        Hook::addFilter('qm/collectors', __NAMESPACE__ . '\\register_collector');
    }

    Hook::addFilter('qm/outputter/html', __NAMESPACE__ . '\\render');
    Hook::addFilter('timber/twig', __NAMESPACE__ . '\\add_twig_profiler');
    Hook::addFilter('timber/cache/mode', __NAMESPACE__ . '\\filter_timber_cache_mode');
    Hook::addFilter('timber/cache/transient_key', __NAMESPACE__ . '\\filter_timber_cache_key');
}

if (\function_exists('did_action') && \did_action('plugins_loaded') && !\doing_action('plugins_loaded')) {
    // Loaded after plugins_loaded, e.g. through a Composer autoloader required from the theme's functions.php
    boot(true);
} else {
    // Before Query Monitor's 'plugins_loaded' action (priority 10), which applies the 'qm/collectors' filter
    Hook::addAction('plugins_loaded', static fn () => boot(), 9);
}

/**
 * Register the collector.
 *
 * @param array<string, QM_Collector>  $collectors
 *
 * @return array<string, QM_Collector>
 */
function register_collector(array $collectors): array
{
    $collectors[Collector::ID] = new Collector();
    return $collectors;
}

/**
 * Render the output for the Twig Profiler collector.
 *
 * @param array<string, QM_Output> $output
 *
 * @return array<string, QM_Output>
 */
function render(array $output): array
{
    $collector = QM_Collectors::get(Collector::ID);
    if (!$collector instanceof Collector) {
        return $output;
    }

    $output[Collector::ID] = new Output($collector);
    return $output;
}

/**
 * Whether Twig output is instrumented (profiled, annotated, marked) for the current request.
 *
 * Only for users who can see Query Monitor, so regular visitors, emails and fragments rendered for them are neither
 * slowed down nor polluted. And only for requests whose output is a page showing Query Monitor's panel.
 */
function is_instrumented(): bool
{
    if (\wp_doing_ajax() || \wp_doing_cron() || \wp_is_serving_rest_request() || \is_feed()) {
        return false;
    }

    return QM_Dispatcher::user_can_view();
}

/**
 * Don't use Timber's cache (Timber::render() / Timber::compile() expiration, {% cache %} tag) when instrumenting:
 * instrumented output would be served to everyone, and the profiler should see the actual rendering.
 */
function filter_timber_cache_mode(string $mode): string
{
    return is_instrumented() ? 'none' : $mode;
}

/**
 * Keep instrumented output apart when a transient cache mode is explicitly given to Timber::render() or
 * Timber::compile(), which bypasses the default cache mode.
 */
function filter_timber_cache_key(string $key): string
{
    return is_instrumented() ? $key . '_qm-twig' : $key;
}

/**
 * Add the Twig Profiler and template marker extensions to Twig and store the profiling data.
 *
 * Only when the request is instrumented, see is_instrumented().
 *
 * @param Environment $twig The Twig environment.
 *
 * @return Environment The modified Twig environment.
 */
function add_twig_profiler(Environment $twig): Environment
{
    $collector = QM_Collectors::get(Collector::ID);
    if (!$collector instanceof Collector || !is_instrumented()) {
        return $twig;
    }

    try {
        // Skip profiling if this environment is already profiled (e.g. by the theme)
        if (!$twig->hasExtension(ProfilerExtension::class) && !$twig->hasExtension(NestedProfilerExtension::class)) {
            $profile = new Profile();
            $twig->addExtension(new NestedProfilerExtension(
                $profile,
                static fn (Profile $profile, Profile $caller) => $collector->add_caller($profile, $caller)
            ));
            $collector->add_profile($twig, $profile);
        }

        // Markers give the inspector the templates and blocks each element was output by. They rely on output
        // buffering, so they can't work when Twig yields output (`use_yield` option).
        if (!$twig->useYield() && !$twig->hasExtension(TemplateMarkerExtension::class)) {
            $twig->addExtension(new TemplateMarkerExtension());
        }

        // Annotations give the inspector the template line of each element written in a template
        if (!$twig->hasExtension(SourceAnnotationExtension::class)) {
            $twig->addExtension(new SourceAnnotationExtension());
        }
    } catch (LogicException) {
        // Extensions are already initialized (the environment was used before this filter ran): leave it as is
    }

    return $twig;
}
