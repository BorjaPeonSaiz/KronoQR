<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\PersonalRecordHolderDirectory;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see PersonalRecordHolderDirectory} sobre PostgreSQL.
 *
 * **Dos columnas y una fila**, por `employees.uuid`, que tiene indice unico. No
 * se trae la ficha entera: imprimir un nombre no justifica tener en memoria el
 * hash del DNI ni el del PIN de nadie.
 *
 * **Lee la tabla, no el modelo de `Workforce`**, como el resto de este
 * directorio: `Reporting` es un modelo de lectura y su fuente es la base de
 * datos (doc 02 §1.6, Deptrac).
 *
 * **Sirve tambien a quien causo baja.** Su sesion de portal no existe ya, pero
 * el adaptador no lo presupone: el registro es de quien es aunque ya no trabaje
 * alli.
 */
final readonly class DatabasePersonalRecordHolderDirectory implements PersonalRecordHolderDirectory
{
    public function __construct(private ConnectionInterface $connection) {}

    public function fullNameOf(string $employeeUuid): ?string
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT e.first_name, e.last_name
              FROM employees e
             WHERE e.uuid = ?
             LIMIT 1
            SQL, [$employeeUuid]);

        if ($rows === []) {
            return null;
        }

        $row = Row::of($rows[0]);
        $name = trim($row->string('first_name').' '.$row->string('last_name'));

        return $name === '' ? null : $name;
    }
}
