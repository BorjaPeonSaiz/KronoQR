<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use App\Modules\Workforce\Application\Port\PinMaterial;

/**
 * Sin centro: el alta queda adscrita al de la instalacion (ADR-040).
 */
final readonly class RegisterEmployeeCommand
{
    public function __construct(
        public ?int $departmentId,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $nationalId,
        public string $hiredAt,
        public string $locale,
        /**
         * PIN y hash **ya calculados**, o `null` para que los genere el alta.
         *
         * Solo lo usa la importacion masiva (RF-GP-05), y por rendimiento con
         * consecuencias de disponibilidad: bcrypt cuesta unos 160 ms por PIN, y
         * 500 altas dentro de una sola transaccion tenian el candado global de
         * `audit_log` tomado 80 segundos —bloqueando cada fichaje del hotel— y se
         * pasaban del `max_execution_time` de 60 s.
         *
         * El alta individual no lo pasa y se comporta como siempre.
         */
        public ?PinMaterial $pinMaterial = null,
    ) {}
}
