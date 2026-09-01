<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

/**
 * Activar una clave de licencia (RF-PD-04, Anexo B y Anexo C del doc 01).
 *
 * `actorUserId` va **nulo** cuando la activacion llega por consola o por el
 * instalador (5.4): ahi no hay sesion detras y no se inventa una. El asiento de
 * `audit_log` lo refleja tal cual, que es la unica forma honesta de distinguir
 * «lo activo Marta desde el panel» de «lo activo el instalador».
 */
final readonly class ActivateLicenseCommand
{
    public function __construct(
        public string $signedKey,
        public ?int $actorUserId = null,
    ) {}
}
