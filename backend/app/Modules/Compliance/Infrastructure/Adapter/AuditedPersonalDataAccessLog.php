<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;

/**
 * Escribe en `audit_log` cada divulgacion de datos personales de terceros
 * (**RS-05**, regla dura 6, ADR-010).
 *
 * **Es la arista de ADR-025 de este modulo**: el puerto lo declara quien lo
 * necesita —hoy `Kiosk`, mañana el panel de la tarea 1.16 y la exportacion legal
 * de la 1.17— en `Shared/Application/Port`, y lo implementa `Compliance`, que es
 * quien tiene la cadena de hash. Nadie importa `Compliance` para dejar traza.
 *
 * **La accion la decide este adaptador y no quien llama.** Siempre
 * `personal_data.accessed`, que es la familia `PersonalDataAccess` del bloque D.
 * Si el puerto aceptara una accion cualquiera, el catalogo cerrado de
 * {@see AuditAction} dejaria de estar cerrado y cualquier modulo podria escribir
 * lo que quisiera en la tabla que hay que enseñar en una inspeccion.
 *
 * **El actor tampoco lo declara quien llama**: sale de {@see CurrentAuditContext},
 * igual que en el resto de la auditoria. Para el padron del quiosco eso significa
 * `AuditActor::device(...)`, con la IP y el `User-Agent` de la peticion. Quien
 * accede no puede decir quien es.
 *
 * **`occurredAt` va a nulo a proposito**: aqui el hecho es la lectura, y la
 * lectura ocurre ahora. No es como un fichaje, que puede venir de la cola offline
 * con horas de retraso (regla dura 9). El caso de uso lo pide al puerto `Clock`.
 */
final readonly class AuditedPersonalDataAccessLog implements PersonalDataAccessLog
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::PersonalDataAccessed,
            // Sin identificador: lo divulgado es un conjunto, no una fila. Poner
            // aqui el centro seria confundir el sujeto del acceso con su alcance,
            // que ya viaja en el payload.
            subject: AuditSubject::of('personal_data'),
            payload: AuditPayload::of([
                'dataset' => $dataset,
                'record_count' => $recordCount,
                ...$context,
            ]),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
