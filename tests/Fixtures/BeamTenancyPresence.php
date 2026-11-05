<?php

namespace Splicewire\Beam\Workflows\Tests\Fixtures;

/**
 * Test-only stand-in for "beam-tenancy is installed". A test aliases it to
 * `Splicewire\Beam\Tenancy\BeamTenancyServiceProvider` ONLY inside an isolated process
 * (`#[RunInSeparateProcess]`), so the alias can never reach another test. Nothing calls into it: the
 * central workflow declaration only asks whether that class exists.
 */
final class BeamTenancyPresence
{
    public const PROVIDER = 'Splicewire\\Beam\\Tenancy\\BeamTenancyServiceProvider';

    public static function alias(): void
    {
        class_alias(self::class, self::PROVIDER);
    }
}
