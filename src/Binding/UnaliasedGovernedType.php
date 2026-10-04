<?php

namespace Splicewire\Beam\Workflows\Binding;

use Illuminate\Support\Str;
use LogicException;

/**
 * A model class resolved as GOVERNED (its workflow type has a binding) without a morph alias (launch ticket 05 ruling 1,
 * laravel-frame ADR-0004). An awaiting row stores the subject's getMorphClass(), which is the FQCN when the class has no
 * alias, and the Workflow Queue's Subject Type column shows it: a PHP class-string on the UI. Thrown the first time the
 * class resolves as governed, before any row can be stamped, with the one-line fix for the package or app that owns it.
 */
class UnaliasedGovernedType extends LogicException
{
    public static function for(string $class, string $typeKey): self
    {
        $alias = Str::snake(class_basename($class));

        return new self(
            "{$class} is governed by the workflow type '{$typeKey}' but has no morph alias, so its awaiting rows would show the "
            ."PHP class name in the Workflow Queue. Give it an alias in the package or app that owns it, e.g. "
            ."Relation::morphMap(['{$alias}' => {$class}::class]); (additive morphMap, never enforceMorphMap)."
        );
    }
}
