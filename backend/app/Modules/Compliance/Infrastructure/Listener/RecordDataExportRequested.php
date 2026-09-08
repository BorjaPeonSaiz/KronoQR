<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Product\Domain\Event\DataExportRequested;

/**
 * Sella en `audit_log` que alguien ha pedido la exportacion **integra** de todos
 * los datos de la instalacion (**RF-PD-14**, RL-20, RS-05, regla dura 6).
 *
 * ## Que pregunta responde este asiento
 *
 * «¿Quien quiso llevarse una copia completa de todo, y cuando?». Es un acceso
 * masivo a datos personales —toda la plantilla, cuatro años de fichajes, las
 * cuentas de gestion— y RS-05 obliga a registrarlo.
 *
 * ## Por que se audita PEDIRLA y no solo generarla
 *
 * Porque son dos hechos distintos que pueden no coincidir: entre uno y otro esta
 * la cola, y una generacion puede fallar. Con un solo asiento al final, un
 * intento que revienta al minuto no dejaria ningun rastro — y la intencion es
 * justo lo que busca quien revisa accesos masivos.
 *
 * ## El actor sale del evento, no de la sesion
 *
 * Aqui **si** hay sesion cuando la peticion viene del panel, asi que
 * `CurrentAuditContext` resolveria lo mismo. Se usa el del evento igualmente
 * para que los tres asientos de la familia se atribuyan por el mismo camino: el
 * hermano `data_export.generated` corre en la cola, donde no hay sesion, y dos
 * mecanismos distintos para la misma pregunta es como acaban divergiendo.
 * Sin nadie detras —consola— es `system`, que es la verdad.
 *
 * ## Sincrono y dentro de la transaccion
 *
 * Sin `ShouldQueue` y sin `afterCommit`: si el asiento no se puede escribir, la
 * fila no se crea (ADR-027). Una copia completa de la plantilla no se empieza a
 * fabricar sin dejar rastro.
 *
 * **Por un listener y no por una llamada desde `Product`**: el §1.6 no concede la
 * arista `Product -> Compliance`.
 */
final readonly class RecordDataExportRequested
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(DataExportRequested $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $event->requestedByUserId === null
                ? AuditActor::system()
                : AuditActor::user($event->requestedByUserId),
            action: AuditAction::DataExportRequested,
            // Sin identificador: el sujeto es «la exportacion integra de esta
            // instalacion» y su clave interna no sale de la base de datos. Lo
            // que la identifica es su `uuid`, que va en el payload.
            subject: AuditSubject::of('data_export'),
            payload: AuditPayload::of([
                'data_export_uuid' => $event->uuid,
                'requested_via' => $event->requestedVia->value,
            ]),
        ));
    }
}
