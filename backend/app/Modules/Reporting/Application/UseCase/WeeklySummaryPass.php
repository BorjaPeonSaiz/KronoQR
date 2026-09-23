<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Support\WeeklySummaryReason;

/**
 * Como termino una pasada de {@see SendWeeklySummaries} (RF-PR-05, tarea 3.12).
 *
 * **Recuentos y un motivo, nunca nombres ni direcciones** (regla dura 21): esto
 * es lo que acaba escrito en `reporting.weekly_summary`, y ese log viaja al
 * fabricante dentro del paquete de diagnostico (ADR-020). Quien recibio el
 * correo consta en `audit_log` y en `weekly_summary_deliveries`, que se quedan
 * en el servidor del cliente.
 */
final readonly class WeeklySummaryPass
{
    public function __construct(
        public WeeklySummaryReason $reason,
        /** La semana resumida, `AAAA-Www`. Vacio cuando la pasada no llego a resolverla. */
        public string $week,
        /** Cuentas que entraban en la pasada. */
        public int $recipients,
        /** Correos entregados. */
        public int $sent,
        /**
         * Cuentas que no recibieron correo **sin que pasara nada malo**: ya
         * tenian el de esta semana o no alcanzan a ningun departamento.
         */
        public int $skipped,
        /**
         * Los envios que fallaron, uno por cuenta: su identificador y la
         * **clase** de la excepcion.
         *
         * Nunca el mensaje: puede llevar dentro el valor de una fila (regla dura
         * 21) y esto acaba en el log del planificador, que viaja al fabricante
         * en el paquete de diagnostico (ADR-020). Con la clase y la cuenta ya se
         * distingue «el SMTP rechazo el correo» de «el informe agoto su tiempo».
         *
         * La reclamacion de cada uno se retiro al fallar, asi que su semana
         * sigue pendiente y vuelve a intentarse en la pasada siguiente o con
         * `--week`.
         *
         * @var list<array{manager_user_id: int, exception: string}>
         */
        public array $failures = [],
    ) {}

    /** Cuantos envios fallaron. */
    public function failed(): int
    {
        return \count($this->failures);
    }

    /**
     * Si la pasada dejo algo sin entregar que deberia haberse entregado.
     *
     * Lo mira el comando para elegir su codigo de salida: es lo unico de esta
     * funcionalidad que merece `scheduler.command_failed`, porque es lo unico
     * que no se explica con la configuracion del cliente.
     */
    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
