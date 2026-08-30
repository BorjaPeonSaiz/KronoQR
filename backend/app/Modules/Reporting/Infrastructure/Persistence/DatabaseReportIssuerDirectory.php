<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see ReportIssuerDirectory} sobre PostgreSQL.
 *
 * **Una columna y una fila.** Se lee por `users.uuid`, que tiene indice unico, y
 * no se trae la cuenta entera: sellar un pie de pagina no justifica poner el
 * correo ni el hash de la contraseña de nadie en memoria.
 *
 * **Lee la tabla, no el modelo de otro modulo.** Es lo mismo que ya hacen las
 * consultas de este directorio con `employees`, `daily_totals` y
 * `shift_corrections`: `Reporting` es un modelo de lectura y su fuente es la
 * base de datos, no el agregado del modulo que la escribe (doc 02 §1.6). Un
 * `use` del modelo Eloquent de `Identity` seria la frontera que Deptrac rechaza.
 *
 * **Sirve tambien a la cuenta desactivada**, y tiene que hacerlo: quien firmo un
 * informe el mes pasado puede haber causado baja este, y el documento archivado
 * sigue teniendo que decir quien lo emitio.
 */
final readonly class DatabaseReportIssuerDirectory implements ReportIssuerDirectory
{
    public function __construct(private ConnectionInterface $connection) {}

    public function displayNameOf(string $actorUuid): ?string
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT u.name
              FROM users u
             WHERE u.uuid = ?
             LIMIT 1
            SQL, [$actorUuid]);

        if ($rows === []) {
            return null;
        }

        $name = Row::of($rows[0])->string('name');

        return $name === '' ? null : $name;
    }
}
