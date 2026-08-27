<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Port\LegalExportAudit;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;

/**
 * Deja en `audit_log` que alguien genero la exportacion legal: **quien, que
 * periodo, que alcance y cuanto se llevo** (regla dura 6, RS-05, RL-04,
 * `/revision-cumplimiento` bloque D).
 *
 * ## Que se apunta
 *
 * El periodo, el alcance y las cifras. Con eso, meses despues, se distingue la
 * consulta de una jornada concreta de una descarga de la plantilla entera, que
 * es la pregunta que hay que poder contestar ante una brecha (RL-15).
 *
 * ## Que NO se apunta, y es deliberado
 *
 * **Ni un nombre.** Ni de trabajador, ni de quien exporto: el actor va por su
 * identificador, igual que en el resto de la auditoria (regla dura 21). Y
 * tampoco la **lista** de personas exportadas: `audit_log` se conserva cuatro
 * años (RL-02) y una lista nominal repetida en cada exportacion lo convertiria
 * en una segunda copia de la plantilla. Cuando el alcance es una sola persona,
 * su `employee_uuid` si consta —es el alcance, no una lista— y es lo que permite
 * responder «¿quien pidio el registro de esta persona?».
 *
 * ## El actor sale de la peticion, no de quien llama
 *
 * Mismo motivo que en {@see AuditedPersonalDataAccessLog}: quien exporta no
 * puede declarar quien es. Desde el comando de consola no hay sesion y
 * {@see CurrentAuditContext} devuelve `system()`, que es la verdad —lo lanzo
 * alguien con acceso al servidor— y no «usuario desconocido».
 *
 * ## Corre dentro de la transaccion de la exportacion
 *
 * `GenerateLegalExport` abre la transaccion y esta escritura entra en ella: si
 * el asiento falla, la exportacion no se da por hecha y quien llamo recibe el
 * error en vez del fichero. Una descarga del registro horario de la plantilla
 * sin traza es exactamente lo que RS-05 prohibe.
 */
final readonly class AuditedLegalExportGeneration implements LegalExportAudit
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function recordGeneration(LegalExportManifest $manifest, LegalExportTally $tally): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::LegalExportGenerated,
            // El sujeto es la exportacion en si. No lleva identificador porque
            // el fichero no es una fila de ninguna tabla: lo que lo describe es
            // el periodo y el alcance, y eso va en el payload.
            subject: AuditSubject::of('legal_export'),
            payload: AuditPayload::of([
                'period_from' => $manifest->period->from,
                'period_to' => $manifest->period->to,
                'scope' => $manifest->scope->metricLabel(),
                'employee_uuid' => $manifest->scope->employeeUuid,
                'shift_entry_rows' => $tally->shiftEntries,
                'correction_rows' => $tally->corrections,
                'employees_exported' => $tally->employees,
            ]),
            // El momento del hecho es ahora: exportar no viene de ninguna cola
            // con retraso. Lo pide el caso de uso al puerto `Clock`.
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
