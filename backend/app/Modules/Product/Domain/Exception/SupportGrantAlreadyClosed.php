<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * Se ha intentado revocar una concesion que ya estaba revocada (RF-PD-11).
 *
 * **No es un error del usuario y no llega al cliente como tal.** El contrato es
 * explicito: revocar dos veces devuelve `204` y no vuelve a auditar, porque *la
 * segunda pulsacion de un boton no es un hecho nuevo*. Esta excepcion existe
 * para que el caso de uso pueda distinguir «ya estaba» de «acabo de revocarla» y
 * decidir si publica el evento, sin que ese estado se resuelva con un `SELECT`
 * previo que tendria condicion de carrera con otra pestaña abierta.
 *
 * Una concesion **caducada** si se puede revocar, y no lanza: caducar no es
 * cerrar, y revocarla deja constancia de que alguien la retiro a proposito
 * ademas de que expiro.
 */
final class SupportGrantAlreadyClosed extends ProductDomainException
{
    public function __construct(public readonly string $uuid)
    {
        parent::__construct(\sprintf('The support grant "%s" was already revoked.', $uuid));
    }
}
