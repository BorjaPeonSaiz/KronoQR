<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use DateTimeImmutable;
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
        // La traduccion vive en AuditLogRow porque la comparte con la purga por
        // retencion (ADR-027): dos copias que se separaran producirian hashes
        // distintos para la misma fila.
        return AuditLogRow::toEntry($row);
    }

    private function toUtc(string $raw): DateTimeImmutable
    {
        return AuditLogRow::toUtc($raw);
    }
}
