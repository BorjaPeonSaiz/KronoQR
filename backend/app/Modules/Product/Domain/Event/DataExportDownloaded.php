<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Alguien se ha llevado el ZIP con todos los datos de la instalacion
 * (**RF-PD-14**, RL-20, RS-05).
 *
 * ## Es el asiento que mas falta hace de los tres
 *
 * Generar el fichero lo deja en un directorio del servidor con permisos `0600`;
 * **descargarlo lo saca de ahi**. El fichero contiene la plantilla entera, sus
 * fichajes de cuatro años y las cuentas de gestion, y el cliente —que es el
 * responsable del tratamiento (RL-16)— tiene que poder responder quien se lo
 * llevo y cuando, sobre todo ante una brecha (RL-15).
 *
 * ## Se escribe ANTES de entregar el fichero
 *
 * Si se escribiera despues, una descarga que se corta a mitad —o un proceso que
 * muere sirviendo dos gigabytes— dejaria el fichero fuera y el asiento sin
 * escribir. El orden inverso puede dejar un asiento de una descarga que no se
 * completo, y eso es preferible: sobra informacion en el trail en lugar de
 * faltar. Mismo criterio que el resto de asientos con relevancia legal (regla
 * dura 6).
 *
 * ## Cada descarga, un asiento
 *
 * Sin agrupar por ventana, al contrario que el uso de una concesion de soporte
 * (5.9). Ahi se agrupaba porque una sesion de soporte son cientos de peticiones
 * de lectura; aqui cada descarga es un acto deliberado sobre un fichero de
 * gigabytes, y no hay ningun caso real en el que alguien lo pulse cien veces por
 * minuto — el limitador `throttle:data-export` se encarga de que no lo haya.
 */
final readonly class DataExportDownloaded implements DomainEvent
{
    public function __construct(
        public string $uuid,
        public string $fileName,
        public string $sha256,
        public int $sizeBytes,
        public int $downloadCount,
        public ?int $downloadedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.data_export_downloaded';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
