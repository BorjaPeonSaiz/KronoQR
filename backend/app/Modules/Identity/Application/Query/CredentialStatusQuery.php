<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

/**
 * Lo que pide el panel de RF-QR-08 o el comando de consola.
 *
 * Sin centro (ADR-040): el alcance es la plantilla de la instalacion, acotada
 * como mucho a una persona. `unattended` distingue la ejecucion del
 * planificador —solo metricas, sin nadie que vea un nombre— de una lectura que
 * si divulga y deja asiento (ADR-037).
 */
final readonly class CredentialStatusQuery
{
    public function __construct(
        public bool $pendingOnly = false,
        public ?string $employeeUuid = null,
        public bool $unattended = false,
        /**
         * Solo quien sigue fichando con una tarjeta firmada por esa clave
         * (RF-QR-07, §5.3). Es lo que responde «a quien le falta reimprimir»
         * durante una rotacion, y lo que permite retirar la clave anterior
         * cuando ya no devuelve a nadie.
         */
        public ?string $keyId = null,
    ) {}
}
