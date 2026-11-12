<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/** The lifecycle's storage adapter; business payload never holds workflow bookkeeping. */
final class SubjectState
{
    public static function supportsPin(Model $model): bool
    {
        return $model instanceof BeamParticle || $model instanceof WorkflowManaged;
    }

    public static function version(Model $model): ?string
    {
        $value = self::stored($model)
            ? ($model->meta['workflow']['version'] ?? null)
            : ($model instanceof WorkflowManaged ? ($model->{$model->workflowVersionAttribute()} ?? null) : null);

        return $value === null ? null : (string) $value;
    }

    public static function marking(Model $model): mixed
    {
        if (self::stored($model)) {
            return $model->meta['workflow']['marking'] ?? null;
        }

        return $model->{$model instanceof WorkflowManaged ? $model->workflowStatusAttribute() : 'status'} ?? null;
    }

    public static function pin(Model $model, string $version): void
    {
        if (self::stored($model)) {
            self::merge($model, ['version' => $version]);
        } elseif ($model instanceof WorkflowManaged) {
            $model->{$model->workflowVersionAttribute()} = $version;
        }
    }

    public static function mark(Model $model, string $place): void
    {
        if (self::stored($model)) {
            self::merge($model, ['marking' => $place]);
        } else {
            $model->{$model instanceof WorkflowManaged ? $model->workflowStatusAttribute() : 'status'} = $place;
        }
    }

    private static function stored(Model $model): bool
    {
        return $model instanceof BeamParticle && ! $model instanceof WorkflowManaged;
    }

    /** @param array<string, string> $state */
    private static function merge(BeamParticle $model, array $state): void
    {
        $meta = $model->meta ?? [];
        $meta['workflow'] = array_replace($meta['workflow'] ?? [], $state);
        $model->meta = $meta;
    }
}
