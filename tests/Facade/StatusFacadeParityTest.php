<?php

use Splicewire\Beam\Workflows\Display\StatusManager;
use Splicewire\Beam\Workflows\Facades\Status;

/**
 * The guard on the Status facade's hand-written `@method` block (beam-facade ticket 32), modelled on
 * core's `FacadeMethodParityTest` — and a test, never an audit (beam-facade ticket 10: audits run in
 * hosts against host code, and a host can neither violate nor repair a claim about this package's own
 * docblock).
 *
 * Three claims: the tags match {@see StatusManager}'s public methods exactly (no tag without a method,
 * no method without a tag); each tag matches that method's REFLECTED signature, so a parameter added
 * to the manager cannot silently leave the block stale; and the static class the facade replaces is
 * gone, so a stale `Display\Status` import fatals rather than quietly meaning something else.
 *
 * Pure reflection over two class files — no container, no `uses()` binding in `Pest.php`.
 */
function statusTaggedMethods(): array
{
    $doc = (new ReflectionClass(Status::class))->getDocComment();

    expect($doc)->toBeString();

    preg_match_all('/@method\s+static\s+(\S+)\s+(\w+)\((.*)\)/', $doc, $matches, PREG_SET_ORDER);

    $tags = [];
    foreach ($matches as [, $return, $name, $params]) {
        $tags[$name] = $return.' '.$name.'('.$params.')';
    }

    return $tags;
}

function statusManagerPublicMethods(): array
{
    $names = array_values(array_filter(
        array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(StatusManager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        ),
        fn (string $name) => $name !== '__construct',
    ));
    sort($names);

    return $names;
}

function statusRenderType(?ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        $name = $type->isBuiltin() ? $type->getName() : '\\'.$type->getName();

        return ($type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed' ? '?' : '').$name;
    }

    return (string) $type;
}

function statusReflectedSignature(ReflectionMethod $method): string
{
    $params = array_map(function (ReflectionParameter $p): string {
        $type = $p->getType();
        $rendered = $type ? statusRenderType($type).' ' : '';
        $default = '';
        if ($p->isDefaultValueAvailable()) {
            $default = ' = '.str_replace(["\n", ' '], '', var_export($p->getDefaultValue(), true));
        }

        return $rendered.'$'.$p->getName().$default;
    }, $method->getParameters());

    return statusRenderType($method->getReturnType()).' '.$method->getName().'('.implode(', ', $params).')';
}

/** Whitespace-, case- and leading-slash-insensitive: formatting is not what fails this test. */
function statusNormalize(string $signature): string
{
    return strtolower(str_replace('\\', '', preg_replace('/\s+/', '', $signature)));
}

it('tags exactly the manager\'s public surface', function () {
    $tagged = array_keys(statusTaggedMethods());
    sort($tagged);

    expect($tagged)->toBe(statusManagerPublicMethods());
});

it('carries the emit seam and its five state helpers', function () {
    expect(statusManagerPublicMethods())
        ->toBe(['complete', 'emit', 'failed', 'queued', 'running', 'skipped']);
});

it('keeps every tag matching its reflected signature', function () {
    foreach (statusTaggedMethods() as $name => $tag) {
        expect(statusNormalize($tag))->toBe(
            statusNormalize(statusReflectedSignature(new ReflectionMethod(StatusManager::class, $name))),
            "the @method {$name}(...) tag has drifted from StatusManager::{$name}()",
        );
    }
});

it('leaves no static class behind for a stale import to resolve', function () {
    expect(class_exists('Splicewire\Beam\Workflows\Display\Status'))->toBeFalse();
});
