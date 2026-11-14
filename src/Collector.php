<?php

declare(strict_types=1);

namespace Timber\Rings;

use QM_DataCollector;
use Twig\Environment;
use Twig\Profiler\Profile;

if (!\defined('QM_TWIG_WARN')) {
    \define('QM_TWIG_WARN', 50); // 50ms
}
if (!\defined('QM_TWIG_EXPENSIVE')) {
    \define('QM_TWIG_EXPENSIVE', 100); // 100ms
}

/**
 * @extends QM_DataCollector<TwigProfile>
 */
final class Collector extends QM_DataCollector
{
    public const string ID = 'twig_profiler';

    public $id = self::ID;

    private int $envCounter = 0;

    public function get_storage(): TwigProfile
    {
        return new TwigProfile();
    }

    /**
     * Register the root Profile of a Twig environment.
     * Each environment gets its own profile tree.
     */
    public function add_profile(Environment $twig, Profile $profile, ?string $name = null): void
    {
        $this->envCounter++;

        $this->data->profiles[] = [
            'profile' => $profile,
            'twig' => $twig,
            'name' => $name ?? 'Twig #' . $this->envCounter,
        ];
    }

    /**
     * Register the profile of another environment a top-level profile was rendered from.
     */
    public function add_caller(Profile $profile, Profile $caller): void
    {
        $this->data->callers[$profile] = $caller;
    }

    /**
     * Check if duration is in warning range (above warn, at or below expensive).
     */
    public static function is_warning(float $durationMs): bool
    {
        return $durationMs > QM_TWIG_WARN && $durationMs <= QM_TWIG_EXPENSIVE;
    }

    /**
     * Check if duration exceeds the expensive threshold.
     */
    public static function is_expensive(float $durationMs): bool
    {
        return $durationMs > QM_TWIG_EXPENSIVE;
    }
}
