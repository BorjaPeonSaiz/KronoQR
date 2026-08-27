<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * Un escaneo que **ya estaba registrado**, tal y como lo devuelve el puerto
 * {@see ScanLog} cuando el mismo `scan_id` vuelve a llegar.
 *
 * Es la materia prima de la idempotencia de RF-AT-07 (regla dura 8): con estos
 * hechos, el caso de uso reconstruye la respuesta original —la misma `action`,
 * el mismo `work_date`, las mismas dos marcas de tiempo— en lugar de crear un
 * segundo tramo o de devolver un error.
 *
 * **Lleva hechos, no una respuesta.** Quien compone la respuesta es el caso de
 * uso, que es tambien quien la compuso la primera vez: si el adaptador
 * devolviera un cuerpo ya formado, habria dos sitios donde se decide que ve el
 * quiosco y el dia que uno cambiara el reenvio dejaria de ser identico.
 */
final readonly class RecordedScan
{
    public function __construct(
        public string $scanId,
        /** Nulo si la credencial no resolvio: el escaneo se registro sin empleado. */
        public ?string $employeeUuid,
        /** Momento real del escaneo (regla dura 9). Es el que usa el registro legal. */
        public DateTimeImmutable $occurredAt,
        /** Recepcion en servidor de la peticion **original**, no la del reenvio. */
        public DateTimeImmutable $recordedAt,
        public ScanResult $result,
        /**
         * Jornada del tramo que este escaneo abrio o cerro, en forma `YYYY-MM-DD`.
         * Nula en todo rechazo, y tambien en el anti-rebote, que no creo tramo.
         */
        public ?string $workDate = null,
        /**
         * El acumulado tal y como este escaneo lo dejo la primera vez. Nulo solo
         * en un rechazo real; presente en el anti-rebote (ADR-031).
         */
        public ?int $workedMinutes = null,
    ) {}
}
