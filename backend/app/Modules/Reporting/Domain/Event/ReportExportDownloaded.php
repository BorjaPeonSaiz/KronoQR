<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Alguien se ha llevado el fichero del informe en diferido (**RF-IN-06**,
 * RS-05, ADR-041).
 *
 * ## Es el mas importante de los tres
 *
 * Generar el fichero lo deja en un directorio del servidor, fuera de `public/`.
 * Descargarlo lo saca de ahi, y el fichero lleva las horas de personas
 * identificadas. Ante una brecha (RL-15) el cliente tiene que poder responder
 * quien se lo llevo y cuando.
 *
 * ## Y aqui el asiento es lo unico que ata la descarga a una persona
 *
 * La ruta de descarga **no lleva sesion** (ADR-041): la autoriza un token de un
 * solo uso, no una cabecera `Authorization`. Asi que el actor del asiento no
 * puede salir del `request` —ahi no hay nadie— y sale de la fila: quien pidio el
 * informe es quien recibio el enlace. Por eso `requestedByUserId` viaja dentro
 * del evento, igual que en la generacion.
 *
 * Eso tiene una consecuencia que conviene tener escrita: el asiento dice **a
 * quien se le entrego el enlace**, no quien tecleo la URL. Un enlace reenviado a
 * un tercero se registra a nombre de quien lo pidio, que es exactamente de quien
 * responde.
 */
final readonly class ReportExportDownloaded implements DomainEvent
{
    public function __construct(
        public string $uuid,
        public string $kind,
        public string $format,
        public string $fileName,
        public string $sha256,
        public int $sizeBytes,
        public int $downloadCount,
        public int $requestedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'reporting.report_export_downloaded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
