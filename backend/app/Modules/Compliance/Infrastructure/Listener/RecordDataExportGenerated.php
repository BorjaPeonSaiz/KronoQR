<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Product\Domain\Event\DataExportGenerated;

/**
 * Sella en `audit_log` que el ZIP con todos los datos existe (**RF-PD-14**,
 * RL-20, RS-05, regla dura 6).
 *
 * ## El actor es quien la PIDIO, aunque escriba el trabajador de cola
 *
 * Es la decision 7 de la ficha 5.10, y sin ella este asiento saldria firmado como
 * `system` —«no hay nadie detras»— porque en la cola no hay sesion que
 * `CurrentAuditContext` pueda leer. Responder «¿quien se llevo los datos?»
 * exigiria entonces emparejar a mano este asiento con el
 * `data_export.requested` de unos segundos antes, y eso es exactamente lo que
 * un trail existe para evitar.
 *
 * El identificador viaja dentro del evento; sin nadie detras —consola—,
 * `system`, que es la verdad.
 *
 * ## Lo que lleva el payload
 *
 * Los recuentos por fichero, la huella y el tamaño: lo justo para reconocer
 * **esa** exportacion si vuelve a aparecer en una conversacion, y para comprobar
 * meses despues que el fichero que alguien tiene delante es el que salio de
 * aqui.
 *
 * **No lleva el contenido**, por lo mismo que el paquete de diagnostico: el
 * asiento acaba en el trail, el trail se exporta —de hecho, dentro de esta misma
 * exportacion—, y nada de lo que hay en el ZIP tiene por que difundirse otra vez
 * ahi. Un recuento no identifica a nadie (regla dura 21).
 */
final readonly class RecordDataExportGenerated
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(DataExportGenerated $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $event->requestedByUserId === null
                ? AuditActor::system()
                : AuditActor::user($event->requestedByUserId),
            action: AuditAction::DataExportGenerated,
            subject: AuditSubject::of('data_export'),
            payload: AuditPayload::of([
                'data_export_uuid' => $event->uuid,
                'requested_via' => $event->requestedVia->value,
                'file_name' => $event->fileName,
                // La huella es lo que identifica al fichero: con ella se puede
                // confirmar que el ZIP que alguien tiene delante es el que salio
                // de aqui.
                'sha256' => $event->sha256,
                'size_bytes' => $event->sizeBytes,
                'row_counts' => $event->rowCounts,
            ]),
        ));
    }
}
