<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Workforce\Application\Port\WorkRecordExtent;
use App\Modules\Workforce\Application\Port\WorkRecordPresence;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see WorkRecordPresence} sobre `shift_entries`.
 *
 * **Por nombre de tabla, sin el modelo de `Attendance`** (doc 02 §1.6,
 * Deptrac): `Workforce` no importa el nucleo. Es una lectura de la tabla, la
 * misma licencia que `DatabaseManagementAccountLookup` se toma con `users`.
 *
 * `EXISTS` y no `count(*)`: para decidir si la zona esta fija basta la primera
 * fila, y la tabla del registro legal crece todos los dias.
 *
 * **Sin candado.** El orden lo pone la fila del centro, que quien llama ya tiene
 * bloqueada: el primer fichaje la lee con `FOR KEY SHARE` antes de insertar su
 * tramo, asi que, si ese fichaje gano, su tramo ya esta confirmado cuando esta
 * consulta —una sentencia nueva en `READ COMMITTED`— lo busca.
 */
final readonly class DatabaseWorkRecordPresence implements WorkRecordPresence
{
    public function __construct(private ConnectionInterface $connection) {}

    public function exists(): bool
    {
        /** @var object{present: bool} $row */
        $row = $this->connection->selectOne('SELECT EXISTS (SELECT 1 FROM shift_entries) AS present');

        return (bool) $row->present;
    }

    public function count(): int
    {
        /** @var object{entries: int|string} $row */
        $row = $this->connection->selectOne('SELECT count(*) AS entries FROM shift_entries');

        return (int) $row->entries;
    }

    public function extent(): ?WorkRecordExtent
    {
        /** @var object{entries: int|string, first_work_date: string|null, last_work_date: string|null} $row */
        $row = $this->connection->selectOne(
            'SELECT count(*) AS entries, min(work_date)::text AS first_work_date, max(work_date)::text AS last_work_date FROM shift_entries',
        );

        $entries = (int) $row->entries;

        if ($entries === 0 || $row->first_work_date === null || $row->last_work_date === null) {
            return null;
        }

        return new WorkRecordExtent($entries, $row->first_work_date, $row->last_work_date);
    }
}
