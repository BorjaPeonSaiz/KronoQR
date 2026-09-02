<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Workforce\Domain\Event\EmployeesImported;

/**
 * Sella en `audit_log` la carga masiva de plantilla (**RF-GP-05**, regla dura 6).
 *
 * ## Por que se audita el LOTE, y no solo cada alta
 *
 * Cada persona creada deja su rastro por el camino de siempre. Lo que este
 * asiento responde es otra pregunta: «¿quien cargo la plantilla, cuando y con
 * que fichero?» — la que se hace cuando aparecen doce altas que nadie recuerda
 * haber hecho. Sin el, habria que reconstruirla correlacionando doce asientos
 * por su marca de tiempo y esperando que no hubiera otra alta en medio.
 *
 * ## Que lleva el payload, y sobre todo que no
 *
 * Las cuatro cifras y la huella del fichero. **Ni un nombre, ni un correo, ni un
 * documento, ni el nombre del fichero** (regla dura 21): el nombre lo pone quien
 * sube y puede llevar dentro el de una persona —«plantilla_ana_revisada.xlsx»—,
 * y este asiento acaba en la exportacion de auditoria.
 *
 * La huella si, y es util de verdad: permite afirmar, meses despues, que el
 * fichero que el cliente conserva es exactamente el que se cargo.
 *
 * ## Sin `subject_id`
 *
 * El sujeto es «la plantilla», no una persona. Poner ahi el UUID de la primera
 * importada seria dar por sujeto a alguien elegido al azar.
 *
 * ## Sincrono, pero DESPUES de confirmar
 *
 * Al contrario que el asiento del centro y el del contrato. La razon es la
 * transaccion: la importacion escribe cuarenta altas en una sola, y su evento se
 * publica **al confirmarla**, cuando esas altas ya existen. Un asiento de una
 * carga que luego revierte dejaria en el trail una plantilla que no llego a
 * existir; y su fallo aqui no puede deshacer nada, porque lo unico que quedaria
 * sin escribir es el resumen de un hecho que si ocurrio y que ya tiene sus
 * asientos individuales.
 */
final readonly class RecordEmployeeImport
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(EmployeesImported $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::EmployeesImported,
            subject: AuditSubject::of('employee', null),
            payload: AuditPayload::of([
                'file_sha256' => $event->fileSha256,
                'created' => $event->created,
                'updated' => $event->updated,
                'unchanged' => $event->unchanged,
                'rejected' => $event->rejected,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }
}
