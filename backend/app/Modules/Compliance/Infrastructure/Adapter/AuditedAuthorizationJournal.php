<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Shared\Application\Port\AuthorizationJournal;

/**
 * Escribe en `audit_log` el intento de salirse del alcance por departamento
 * (**RF-ID-03**, RS-05, escenario «Aislamiento por departamento» del doc 01 §11).
 *
 * **Es la arista de ADR-025**, igual que {@see AuditedPersonalDataAccessLog}: el
 * puerto lo declara `Shared`, lo llaman `Workforce`, `Reporting` y `Attendance`, y
 * lo implementa este modulo, que es el unico que tiene la cadena de hash. Nadie
 * importa `Compliance` para dejar traza.
 *
 * **La accion la decide este adaptador**: siempre `access.denied`. Si el puerto
 * aceptara una accion cualquiera, el catalogo cerrado de {@see AuditAction}
 * dejaria de estarlo.
 *
 * **El actor sale de {@see CurrentAuditContext}** y no de quien llama: quien
 * intenta acceder no puede declarar quien es.
 *
 * **El sujeto es el empleado, no la cuenta que lo intento.** Quien lo intento ya
 * viaja como actor, y el sujeto tiene que ser el dato protegido para que la
 * consulta que responde «quien ha ido a por el expediente de esta persona» sea
 * una sola consulta por `subject`.
 */
final readonly class AuditedAuthorizationJournal implements AuthorizationJournal
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function recordScopeDenial(string $dataset, ?string $employeeUuid, array $context = []): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::AccessDenied,
            // Sin identificador interno: el UUID publico va en el payload y la
            // clave `BIGINT` no sale de la base de datos ni siquiera aqui. El
            // tipo, en cambio, si importa: distingue «fue a por una ficha» de
            // «fue a por un registro horario».
            subject: AuditSubject::of($dataset),
            payload: AuditPayload::of([
                'dataset' => $dataset,
                // Regla dura 21: el UUID identifica a la persona afectada; su
                // nombre no aparece por ningun camino.
                'employee_uuid' => $employeeUuid,
                'reason' => 'out_of_scope',
                ...$context,
            ]),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
