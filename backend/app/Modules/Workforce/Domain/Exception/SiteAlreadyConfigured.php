<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Ya existe el centro de trabajo de la instalacion y no cabe un segundo
 * (ADR-040). Es un conflicto de estado, no un error de formulario: `409`.
 */
final class SiteAlreadyConfigured extends WorkforceConflict
{
    public static function make(): self
    {
        return new self('La instalacion ya tiene su centro de trabajo: una licencia es un centro.');
    }
}
