<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Lectura de `audit_log` para el verificador de RS-07.
 *
 * **Por lotes y sin `OFFSET`.** Se pagina por `id > ultimo`, que usa la clave
 * primaria y no degrada: con `OFFSET`, recorrer cuatro años de historico
 * significaria releer la tabla entera una vez por lote. Con un generador, la
 * memoria del proceso no crece con el historico, que es lo que permite que el
 * comando diario corra en el contenedor del scheduler sin reservas especiales.
 *
 * **La traduccion es explicita.** De cada fila se reconstruye el borrador de
 * dominio, no un modelo Eloquent: el verificador recalcula el hash a partir de
 * los mismos objetos de valor con los que se escribio, y cualquier diferencia de
 * interpretacion —una zona horaria, un JSON con las claves en otro orden—
 * aparece aqui como una rotura. Es deseable: es exactamente lo que se quiere
 * detectar.
 */
final readonly class DatabaseAuditChainReader implements AuditChainReader
{
    public function __construct(private ConnectionInterface $connection) {}

    public function inChainOrder(int $chunkSize = 1000): iterable
    {
        $lastId = 0;

        while (true) {
            /** @var list<object{id: int, occurred_at: string, actor_type: string, actor_id: int|null, action: string, subject_type: string|null, subject_id: int|null, payload: string, prev_hash: string|null, hash: string, ip: string|null, user_agent: string|null}> $rows */
            $rows = $this->connection->table(AuditLogSchema::TABLE)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(max(1, $chunkSize))
                ->get()
                ->all();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = $row->id;

                yield $this->toEntry($row);
            }
        }
    }

    public function anchorSealedWith(string $hash): ?AuditChainAnchor
    {
        /** @var object{partition_year: int, first_hash: string, last_hash: string, row_count: int, sealed_at: string, sealed_by: string}|null $row */
        $row = $this->connection->table(AuditLogSchema::ANCHORS_TABLE)
            ->where('last_hash', $hash)
            ->first();

        if ($row === null) {
            return null;
        }

        return new AuditChainAnchor(
            partitionYear: (int) $row->partition_year,
            firstHash: $row->first_hash,
            lastHash: $row->last_hash,
            rowCount: (int) $row->row_count,
            sealedAt: $this->toUtc($row->sealed_at),
            sealedBy: $row->sealed_by,
        );
    }

    /**
     * @param  object{id: int, occurred_at: string, actor_type: string, actor_id: int|null, action: string, subject_type: string|null, subject_id: int|null, payload: string, prev_hash: string|null, hash: string, ip: string|null, user_agent: string|null}  $row
     */
    private function toEntry(object $row): AuditEntry
    {
        /** @var array<array-key, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        $draft = new AuditEntryDraft(
            occurredAt: $this->toUtc($row->occurred_at),
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
     * PostgreSQL devuelve `TIMESTAMPTZ` en la zona de la sesion. La sesion es
     * UTC (`APP_TIMEZONE=UTC` y el cluster arranca con `timezone=UTC`), pero se
     * fuerza igualmente: si algun dia una conexion llegara con otra zona, el
     * instante seria el mismo y su representacion distinta, y la representacion
     * es lo que entra en el hash.
     */
    private function toUtc(string $raw): DateTimeImmutable
    {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
    }
}
