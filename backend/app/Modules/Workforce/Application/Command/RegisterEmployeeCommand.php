<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use InvalidArgumentException;

/**
 * Sin centro: el alta queda adscrita al de la instalacion (ADR-040).
 */
final readonly class RegisterEmployeeCommand
{
    /**
     * @throws InvalidArgumentException si se difiere el PIN fuera de una importacion masiva
     */
    public function __construct(
        public ?int $departmentId,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $nationalId,
        public string $hiredAt,
        public string $locale,
        /**
         * El alta emite el PIN ahora o lo deja pendiente (RF-ID-09, RF-GP-05).
         *
         * De serie se emite, que es el lado seguro: un camino nuevo que se
         * olvide de declararlo da un PIN, nunca una persona sin el. Diferirlo
         * solo lo puede pedir la importacion masiva —el constructor rechaza el
         * resto—, porque es la unica en la que nadie tiene delante a la persona
         * para entregarle el PIN que se mostraria una vez.
         *
         * El hash ya no viaja por aqui: el alta individual lo calcula antes de
         * abrir su transaccion (ADR-046, A-3), y la importacion no calcula
         * ninguno.
         */
        public PinProvisioning $pin = PinProvisioning::IssueNow,
        /**
         * El alta forma parte de una carga masiva (RF-GP-05).
         *
         * Viaja hasta `EmployeeHired` y decide quien cuenta el uso del plan: con
         * el lote, la cuenta la hace una sola vez el evento de la importacion
         * (ADR-028, H-04 de la 3.8), en vez de una vez por fila bajo el candado
         * global de `audit_log` (ADR-010). La persona entra igual, con su codigo
         * y su asiento; lo que pasa con su PIN lo dice {@see self::$pin}, no
         * esta marca.
         *
         * Por defecto `false`, que es el seguro: un camino nuevo que se olvide
         * de declararlo cuenta de mas, nunca de menos.
         */
        public bool $viaImport = false,
        /**
         * Teletrabaja (RF-GP-01). Informativo. `false` de serie: la importacion
         * masiva no lo lee del fichero y no lo pasa.
         */
        public bool $teleworking = false,
    ) {
        // Un estado imposible que no se puede construir: un PIN diferido fuera
        // de la importacion seria un alta individual que deja a alguien sin
        // poder fichar por respaldo (RF-AT-11) ni entrar al portal (RL-05).
        if ($pin === PinProvisioning::DeferredToCardHandover && ! $viaImport) {
            throw new InvalidArgumentException('Solo la importacion masiva puede dejar pendiente el PIN de un alta.');
        }
    }
}
