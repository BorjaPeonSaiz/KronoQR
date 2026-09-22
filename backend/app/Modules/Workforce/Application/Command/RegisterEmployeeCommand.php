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
        /**
         * El alta forma parte de una carga masiva (RF-GP-05).
         *
         * Viaja hasta `EmployeeHired` y **no cambia nada del alta**: la persona
         * entra igual, con su PIN y su asiento. Lo unico que decide es quien
         * cuenta el uso del plan: con el lote, la cuenta la hace una sola vez el
         * evento de la importacion (ADR-028, H-04 de la 3.8), en vez de una vez
         * por fila bajo el candado global de `audit_log` (ADR-010).
         *
         * Por defecto `false`, que es el seguro: un camino nuevo que se olvide
         * de declararlo cuenta de mas, nunca de menos.
         */
        public bool $viaImport = false,
    ) {}
}
