<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La traduccion de una fila de `audit_log` al borrador de dominio con el que se
 * escribio (doc 02 §7.4).
 *
 * **Vive en un solo sitio a proposito.** La usan el verificador diario
 * ({@see DatabaseAuditChainReader}) y la purga por retencion
 * ({@see DatabaseAuditPartitionArchive}), y los dos **recalculan el hash** a
 * partir de lo que aqui se reconstruye. Dos traducciones que se separaran un
 * dia -una zona horaria distinta, un `json_decode` con otras banderas- darian
 * hashes distintos para la misma fila: uno de los dos denunciaria una rotura
 * que no existe, o peor, el de la purga sellaria un ancla con un `last_hash`
 * que el verificador no reconoceria despues.
 */
final class AuditLogRow
{
    /**
     * @param  object{id: int, occurred_at: string, actor_type: string, actor_id: int|null, action: string, subject_type: string|null, subject_id: int|null, payload: string, prev_hash: string|null, hash: string, ip: string|null, user_agent: string|null}  $row
     */
    public static function toEntry(object $row): AuditEntry
    {
        /** @var array<array-key, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        $draft = new AuditEntryDraft(
            occurredAt: self::toUtc($row->occurred_at),
            actor: AuditActor::fromStorage($row->actor_type, $row->actor_id === null ? null : (int) $row->actor_id),
            action: AuditAction::from($row->action),
            subject: AuditSubject::fromStorage(
                $row->subject_type,
                $row->subject_id === null ? null : (int) $row->subject_id,
            ),
            payload: AuditPayload::fromStorage($payload),
            ip: $row->ip,
            userAgent: $row->user_agent,
        );

        return new AuditEntry($draft, $row->prev_hash ?? '', $row->hash, (int) $row->id);
    }

    /**
     * PostgreSQL devuelve `TIMESTAMPTZ` en la zona de la sesion. La sesion es UTC
     * (`APP_TIMEZONE=UTC` y el cluster arranca con `timezone=UTC`), pero se
     * fuerza igualmente: si algun dia una conexion llegara con otra zona, el
     * instante seria el mismo y su representacion distinta, y la representacion
     * es lo que entra en el hash.
     */
    public static function toUtc(string $raw): DateTimeImmutable
    {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
    }
}
