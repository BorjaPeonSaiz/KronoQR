<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Alguien ha pedido un informe en diferido (**RF-IN-06**, RS-05, regla dura 6).
 *
 * ## Se audita el acto de PEDIRLO, no solo el de generarlo
 *
 * Son dos hechos distintos que pueden no coincidir: entre uno y otro esta la
 * cola, y una generacion puede fallar o quedarse a medias. Con un solo asiento
 * al final, un intento de sacar las horas nominales de la plantilla entera que
 * revienta al minuto no dejaria ningun rastro — y la intencion es justo lo que
 * busca quien revisa accesos masivos a datos personales.
 *
 * ## Lo que lleva, y lo que no
 *
 * `kind`, `format`, los parametros del informe y el alcance con el que se
 * autorizo. **Ni un nombre, ni una hora, ni la ruta del fichero** (regla dura
 * 21): el alcance sale como `all` o `departments`, igual que en el asiento del
 * informe sincrono, porque ante una brecha (RL-15) lo que hay que distinguir es
 * «RRHH pidio el hotel entero» de «un responsable pidio su cocina».
 */
final readonly class ReportExportRequested implements DomainEvent
{
    /**
     * @param  array<string, bool|int|string|null>  $parameters  Lo que se pidio, con las claves
     *                                                           del contrato.
     */
    public function __construct(
        public string $uuid,
        public string $kind,
        public string $format,
        public array $parameters,
        /** `all` o `departments`. Nunca la lista de identificadores. */
        public string $scope,
        public int $requestedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'reporting.report_export_requested';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
