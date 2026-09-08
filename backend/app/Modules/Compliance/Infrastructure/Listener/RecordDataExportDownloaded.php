<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Product\Domain\Event\DataExportDownloaded;

/**
 * Sella en `audit_log` que alguien se ha llevado el ZIP (**RF-PD-14**, RL-20,
 * RS-05, regla dura 6).
 *
 * ## Es el asiento que mas falta hace de los tres
 *
 * Generar el fichero lo deja en un directorio del servidor con permisos `0600`;
 * **descargarlo lo saca de ahi**. El fichero lleva la plantilla entera, sus
 * fichajes y las cuentas de gestion, y el cliente —responsable del tratamiento
 * (RL-16)— tiene que poder responder quien se lo llevo y cuando, sobre todo ante
 * una brecha (RL-15).
 *
 * ## Se escribe ANTES de entregar el fichero
 *
 * Lo garantiza el caso de uso, que publica el evento dentro de la transaccion y
 * solo despues devuelve la ruta al controlador. Al reves, una descarga cortada a
 * la mitad sacaria el fichero sin dejar rastro.
 *
 * ## `download_count` en el payload
 *
 * Para que se pueda ver de un vistazo si alguien lo descargo una vez o siete —lo
 * segundo, en un fichero de estas caracteristicas, es una pregunta que merece
 * respuesta— sin tener que contar asientos.
 */
final readonly class RecordDataExportDownloaded
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(DataExportDownloaded $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $event->downloadedByUserId === null
                ? AuditActor::system()
                : AuditActor::user($event->downloadedByUserId),
            action: AuditAction::DataExportDownloaded,
            subject: AuditSubject::of('data_export'),
            payload: AuditPayload::of([
                'data_export_uuid' => $event->uuid,
                'file_name' => $event->fileName,
                'sha256' => $event->sha256,
                'size_bytes' => $event->sizeBytes,
                'download_count' => $event->downloadCount,
            ]),
        ));
    }
}
