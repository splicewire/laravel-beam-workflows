<?php

namespace Splicewire\Beam\Workflows\Tests\Config;

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Workflows\Config\BeamWorkflowsSection;

/**
 * The `beam.workflows` Config Section (ticket 13, ADR-0245): BeamWorkflowsServiceProvider registers it, the
 * declaration covers every key of the shipped config/beam/workflows.php, that default validates through the
 * data-schemas AcceptanceGate against the generated section schema, and every key is declared Host-tier /
 * Deploy-effect with a clean declarations walk (no secret keys in this section).
 */

it('registers beam.workflows from the package service provider', function () {
    $entry = app(ConfigSectionRegistry::class)->get('beam.workflows');

    expect($entry)->toBeInstanceOf(RegisteredConfigSection::class);
    expect($entry->section)->toBe(BeamWorkflowsSection::class);
    expect($entry->owner)->toBe('splicewire/laravel-beam-workflows');

    // The registered package-tier defaults file must resolve to this package's own shipped config, so a
    // wrong packageFile cannot pass unnoticed behind an acceptance gate run against runtime config().
    expect($entry->packageFile)->not->toBeNull();
    expect($entry->packageFile)->toEndWith('config/beam/workflows.php');
    expect(is_file($entry->packageFile))->toBeTrue();
});

it('covers every key of the package default config', function () {
    $uncovered = ConfigSectionCoverage::uncovered(BeamWorkflowsSection::class, config('beam.workflows'));

    expect($uncovered)->toBe([]);
});

it('validates the package default config through the acceptance gate', function () {
    $schema = app(ConfigSectionSchema::class)->for(BeamWorkflowsSection::class);

    expect(app(AcceptanceGate::class)->accepts(config('beam.workflows'), $schema))->toBeTrue();
});

it('declares every key Host-tier / Deploy-effect with a clean declarations walk', function () {
    // Assert the RESOLVED declaration of every shipped key explicitly, as key => enum maps so a
    // mismatch names the offending key. findings() === [] alone would still pass if a non-secret key
    // silently changed ceiling or effect (that raises no misdeclaration finding), so this locks the
    // Host / Deploy contract per key rather than inferring it from a clean walk.
    $keys = array_keys(config('beam.workflows'));
    expect($keys)->toHaveCount(8);

    $ceilings = array_map(
        fn (string $key): ConfigTier => BeamWorkflowsSection::keyDeclaration([$key])->ceiling,
        array_combine($keys, $keys),
    );
    $effects = array_map(
        fn (string $key): ConfigEffect => BeamWorkflowsSection::keyDeclaration([$key])->effect,
        array_combine($keys, $keys),
    );

    expect($ceilings)->toBe(array_fill_keys($keys, ConfigTier::Host));
    expect($effects)->toBe(array_fill_keys($keys, ConfigEffect::Deploy));

    // The declarations walk is also clean: Host ceiling never admits the operator tier and this section
    // carries no secret keys, so no misdeclaration finding (secret-open-to-operator / secret-in-list / …).
    expect(ConfigSectionDeclarations::findings(BeamWorkflowsSection::class))->toBe([]);
});

it('covers a host-populated activity_models class-string map and still passes the acceptance gate', function () {
    // The shipped default is activity_models => [], which coverage short-circuits on an empty array, so the
    // "covers every key" test above passes VACUOUSLY — it never exercises the populated-map path. A real host
    // populates this key with a class-string => class-string map (e.g. Tenant::class => CentralActivityLog::class).
    // `public array $activity_models` is an untyped array; without an element-shape attribute coverage descends
    // into a non-empty, non-list map and reports /activity_models uncovered. This locks the #[MapValues('string')]
    // element shape: a populated map must be COVERED and must still validate through the acceptance gate, and the
    // shipped [] (asserted above) must keep validating too.
    $config = config('beam.workflows');
    $config['activity_models'] = [
        'App\\Workflows\\FakeSubjectOne' => 'App\\Workflows\\FakeActivityOne',
        'App\\Workflows\\FakeSubjectTwo' => 'App\\Workflows\\FakeActivityTwo',
    ];

    expect(ConfigSectionCoverage::uncovered(BeamWorkflowsSection::class, $config))->toBe([]);

    $schema = app(ConfigSectionSchema::class)->for(BeamWorkflowsSection::class);
    expect(app(AcceptanceGate::class)->accepts($config, $schema))->toBeTrue();
});
