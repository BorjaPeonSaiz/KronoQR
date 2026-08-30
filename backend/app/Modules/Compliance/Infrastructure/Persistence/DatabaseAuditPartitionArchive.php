<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Exception\AuditPartitionNotPurgeable;
use App\Modules\Compliance\Application\Port\AuditPartitionArchive;
use App\Modules\Compliance\Application\Port\AuditPartitionInventory;
use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use Illuminate\Database\ConnectionInterface;

/**
 * Las particiones de `audit_log`: leerlas, sellarlas y soltarlas (ADR-027,
 * ADR-033, regla dura 6).
 *
 * ## Se registra dos veces, con dos conexiones
 *
 * Como {@see AuditPartitionInventory}
 * corre con el rol de la **aplicacion** -contar y ver rangos para el informe- y
 * como `AuditPartitionArchive` con el de **mantenimiento**, que es el unico que
 * puede soltar una particion. Es la misma clase porque las consultas son las
 * mismas; lo que cambia es quien las ejecuta y que se le permite hacer despues.
 *
 * ## Soltar la particion es una funcion del motor, no un `ALTER TABLE` de aqui
 *
 * `ALTER TABLE … DETACH PARTITION` exige ser **propietario** de la tabla, y el
 * propietario es el rol de migracion. Darle esa propiedad al rol de
 * mantenimiento le daria de paso poder retirar los `REVOKE` que sostienen la
 * regla dura 6 -un propietario puede volver a otorgarse lo que se le revoque-,
 * y con eso el registro probatorio dejaria de estar protegido de quien hace la
 * purga.
 *
 * En su lugar hay una funcion `SECURITY DEFINER` que crea la migracion, que
 * pertenece al propietario y que **solo** el rol de mantenimiento puede
 * ejecutar. Comprueba dentro que el ano existe como particion y que su ancla ya
 * esta escrita: sin sello no suelta nada, ni siquiera si quien la llama se
 * equivoca de orden. Es el mecanismo estandar de PostgreSQL para delegar un
 * privilegio estrecho sin repartir el ancho.
 *
 * ## La revalidacion dentro de la transaccion
 *
 * Antes de sellar se comprueba, **con la particion ya bloqueada**, que sigue
 * teniendo exactamente las filas y los hashes extremos que se verificaron. Entre
 * la verificacion y el `DROP` cabria una entrada retrodatada -la cola offline
 * del quiosco puede traerlas, regla dura 9-, y esa entrada se iria sin haber
 * sido verificada nunca y sin que su hash entrara en el ancla.
 */
final class DatabaseAuditPartitionArchive implements AuditPartitionArchive
{
    private readonly DatabaseAuditLogPartitions $catalog;

    public function __construct(private readonly ConnectionInterface $connection)
    {
        // Reutiliza la lectura del catalogo de PostgreSQL en lugar de repetir la
        // consulta a `pg_inherits`: dos listas de particiones que se separaran
        // dejarian al informe hablando de particiones que no existen.
        $this->catalog = new DatabaseAuditLogPartitions($connection);
    }

    public function attachedYears(): array
    {
        return $this->catalog->years();
    }

    public function summarize(int $year): RetentionTally
    {
        /** @var object{row_count: int|string, oldest: string|null, newest: string|null}|null $row */
        $row = $this->connection->selectOne(
            'SELECT count(*) AS row_count, min(occurred_at)::date::text AS oldest, '
            .'max(occurred_at)::date::text AS newest FROM '.$this->partition($year)
        );

        return new RetentionTally(
            scope: RetentionScope::AuditLog,
            dataset: AuditLogSchema::partitionName($year),
            rows: (int) ($row->row_count ?? 0),
            oldest: $row->oldest ?? null,
            newest: $row->newest ?? null,
        );
    }

    public function entriesOf(int $year, int $chunkSize = 1000): iterable
    {
        $lastId = 0;
        $partition = $this->partition($year);
        $limit = max(1, $chunkSize);

        while (true) {
            /** @var list<object{id: int, occurred_at: string, actor_type: string, actor_id: int|null, action: string, subject_type: string|null, subject_id: int|null, payload: string, prev_hash: string|null, hash: string, ip: string|null, user_agent: string|null}> $rows */
            $rows = $this->connection->select(
                'SELECT * FROM '.$partition.' WHERE id > ? ORDER BY id LIMIT '.$limit,
                [$lastId],
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                yield AuditLogRow::toEntry($row);
            }
        }
    }

    public function precedesEveryLiveEntry(int $year): bool
    {
        /** @var object{last_old: int|string|null, first_new: int|string|null}|null $row */
        $row = $this->connection->selectOne(
            'SELECT (SELECT max(id) FROM '.AuditLogSchema::TABLE.' WHERE occurred_at < ?) AS last_old, '
            .'(SELECT min(id) FROM '.AuditLogSchema::TABLE.' WHERE occurred_at >= ?) AS first_new',
            [$this->startOfYear($year + 1), $this->startOfYear($year + 1)],
        );

        $lastOld = $row?->last_old;
        $firstNew = $row?->first_new;

        // Sin filas nuevas, o todas las nuevas escritas despues: la particion es
        // el principio de la cadena viva y su hueco lo explica el ancla.
        return $lastOld === null || $firstNew === null || (int) $firstNew > (int) $lastOld;
    }

    public function sealAndDrop(AuditChainAnchor $anchor): void
    {
        $this->connection->transaction(function () use ($anchor): void {
            $this->assertStillMatches($anchor);

            $this->connection->table(AuditLogSchema::ANCHORS_TABLE)->insert([
                'partition_year' => $anchor->partitionYear,
                'first_hash' => $anchor->firstHash,
                'last_hash' => $anchor->lastHash,
                'row_count' => $anchor->rowCount,
                'sealed_at' => $anchor->sealedAt->format('Y-m-d H:i:s.uP'),
                'sealed_by' => $anchor->sealedBy,
            ]);

            // La funcion vuelve a exigir el ancla desde dentro: aunque alguien
            // la invocara a mano, no suelta una particion sin sellar.
            $this->connection->statement(
                'SELECT '.AuditLogSchema::DROP_FUNCTION.'(?)',
                [$anchor->partitionYear],
            );
        });
    }

    public function role(): string
    {
        /** @var object{role: string}|null $row */
        $row = $this->connection->selectOne('SELECT current_user AS role');

        return $row === null ? 'desconocido' : $row->role;
    }

    /**
     * Que la particion sigue siendo la que se verifico, con la fila bloqueada
     * por la transaccion en curso.
     */
    private function assertStillMatches(AuditChainAnchor $anchor): void
    {
        $partition = $this->partition($anchor->partitionYear);

        /** @var object{row_count: int|string, first_hash: string|null, last_hash: string|null}|null $row */
        $row = $this->connection->selectOne(
            'SELECT count(*) AS row_count, '
            .'(SELECT hash FROM '.$partition.' ORDER BY id ASC LIMIT 1) AS first_hash, '
            .'(SELECT hash FROM '.$partition.' ORDER BY id DESC LIMIT 1) AS last_hash '
            .'FROM '.$partition
        );

        $matches = $row !== null
            && (int) $row->row_count === $anchor->rowCount
            && $row->first_hash === $anchor->firstHash
            && $row->last_hash === $anchor->lastHash;

        if (! $matches) {
            throw AuditPartitionNotPurgeable::chainIsBroken(
                $anchor->partitionYear,
                'la particion ha cambiado entre la verificacion y el sellado',
            );
        }
    }

    /**
     * Nombre de la particion, entrecomillado. El ano es un `int`, asi que no hay
     * entrada de usuario en el identificador; se valida igualmente contra el
     * primer ano del producto para que un `0` o un negativo no construyan un
     * nombre absurdo.
     */
    private function partition(int $year): string
    {
        if ($year < AuditLogSchema::FIRST_YEAR || $year > 9999) {
            throw AuditPartitionNotPurgeable::chainIsBroken($year, 'no es un ano de particion posible');
        }

        return '"'.AuditLogSchema::partitionName($year).'"';
    }

    private function startOfYear(int $year): string
    {
        return $year.'-01-01T00:00:00Z';
    }
}
