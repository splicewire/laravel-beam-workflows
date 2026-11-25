<?php

namespace Splicewire\Beam\Workflows\Tests\Config;

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\RegisteredConfigSection;
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
    // Host ceiling never admits the operator tier and this section carries no secret keys, so the
    // declarations walk raises no misdeclaration finding (secret-open-to-operator / secret-in-list / …).
    expect(ConfigSectionDeclarations::findings(BeamWorkflowsSection::class))->toBe([]);
});
