<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * El lote de impresion: todas las credenciales pendientes de la instalacion
 * (ADR-040). No hay seleccion posible mas alla de «las pendientes».
 */
final readonly class PrintCredentialBatchCommand
{
    public function __construct(
        public ?int $actorUserId = null,
    ) {}
}
