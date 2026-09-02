<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Ya existe el centro de trabajo de la instalacion y no cabe un segundo
 * (ADR-040). Es un conflicto de estado, no un error de formulario: `409`.
 *
 * **El mensaje dice a donde ir**, porque quien lo recibe esta poniendo en marcha
 * el sistema y no tiene la consola del servidor delante: sin la segunda frase se
 * queda mirando un «ya existe» sin saber que el centro se consulta y se modifica
 * en `/api/v1/site`.
 */
final class SiteAlreadyConfigured extends WorkforceConflict
{
    public static function make(): self
    {
        return new self(
            'Esta instalacion ya tiene su centro de trabajo: una licencia es un centro (ADR-040). '
            .'Consultalo en GET /api/v1/site y modificalo con PATCH /api/v1/site; el cambio queda registrado.',
        );
    }
}
