<?php

declare(strict_types=1);

namespace Timber\Rings;

use QM_Data;
use SplObjectStorage;
use Twig\Environment;
use Twig\Profiler\Profile;

final class TwigProfile extends QM_Data
{
    /**
     * Array of profiles, one per Twig environment.
     * Each entry contains the profile and its associated twig environment.
     *
     * @var array<int, array{profile: Profile, twig: Environment, name: string}>
     */
    public array $profiles = [];

    /**
     * Profile of another environment each top-level profile was rendered from, if any.
     *
     * @var SplObjectStorage<Profile, Profile>
     */
    public SplObjectStorage $callers;

    public function __construct()
    {
        $this->callers = new SplObjectStorage();
    }
}
