<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El ZIP con **todos** los datos de la instalacion existe en el disco
 * (**RF-PD-14**, RL-20, RS-05).
 *
 * ## El asiento se atribuye a quien la PIDIO, no al trabajador de cola
 *
 * Es la decision 7 de la ficha 5.10 y no es un detalle: la generacion la ejecuta
 * un proceso en segundo plano, donde `CurrentAuditContext` no ve ninguna sesion
 * y firmaria `system`. Con eso, el asiento que dice «se ha materializado una
 * copia completa de la plantilla» aparecerian sin autor, y responder «¿quien se
 * llevo los datos?» exigiria emparejarlo a mano con el `data_export.requested`
 * de unos segundos antes.
 *
 * Por eso `requestedByUserId` viaja dentro del evento y el listener construye
 * con el `AuditActor::user()`. Sin nadie detras —consola—, `system`, que es la
 * verdad.
 *
 * ## Lo que lleva el payload, y lo que no
 *
 * Van los **recuentos por fichero**, la huella y el tamaño: lo justo para
 * reconocer *esa* exportacion si vuelve a aparecer en una conversacion, y para
 * comprobar meses despues que el fichero que alguien tiene delante es el que
 * salio de aqui. **No va el contenido**, por lo mismo que en el paquete de
 * diagnostico: el asiento acaba en el trail, el trail se exporta, y nada de lo
 * que hay dentro del ZIP tiene por que difundirse otra vez ahi.
 */
final readonly class DataExportGenerated implements DomainEvent
{
    /**
     * @param  array<string, int>  $rowCounts  Filas de datos por fichero del ZIP.
     */
    public function __construct(
        public string $uuid,
        public DataExportOrigin $requestedVia,
        public ?int $requestedByUserId,
        public string $fileName,
        public string $sha256,
        public int $sizeBytes,
        public array $rowCounts,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.data_export_generated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
