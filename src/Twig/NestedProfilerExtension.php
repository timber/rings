<?php

declare(strict_types=1);

namespace Timber\Rings\Twig;

use Closure;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

/**
 * Profiler telling which profile of another environment a rendering was called from.
 *
 * Timber creates a Twig environment per Timber::compile() call, so templates calling PHP code that compiles other
 * templates end up spread across environments. Rendering profiles are tracked across all of them, so the top-level
 * profiles of an environment can be linked to the profile being rendered in another one when they started.
 */
final class NestedProfilerExtension extends ProfilerExtension
{
    /**
     * Profiles being rendered, across environments, innermost last.
     *
     * @var list<array{extension: self, profile: Profile}>
     */
    private static array $rendering = [];

    /**
     * @param Closure(Profile $profile, Profile $caller): void $recordCaller Called with a top-level profile of this
     *                                                                     environment, and the profile of another
     *                                                                     environment it was rendered from.
     */
    public function __construct(
        Profile $profile,
        private readonly Closure $recordCaller
    ) {
        parent::__construct($profile);
    }

    public function enter(Profile $profile): void
    {
        $caller = \end(self::$rendering);
        if ($caller !== false && $caller['extension'] !== $this && !$this->isRendering()) {
            ($this->recordCaller)($profile, $caller['profile']);
        }

        parent::enter($profile);
        self::$rendering[] = [
            'extension' => $this,
            'profile' => $profile,
        ];
    }

    public function leave(Profile $profile): void
    {
        parent::leave($profile);

        // Up to this profile: renderings that threw never left
        do {
            $rendering = \array_pop(self::$rendering);
        } while ($rendering !== null && $rendering['profile'] !== $profile);
    }

    /**
     * Whether a profile of this environment is being rendered.
     */
    private function isRendering(): bool
    {
        foreach (self::$rendering as $rendering) {
            if ($rendering['extension'] === $this) {
                return true;
            }
        }

        return false;
    }
}
