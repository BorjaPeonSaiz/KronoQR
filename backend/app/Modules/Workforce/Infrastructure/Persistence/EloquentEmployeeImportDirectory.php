<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Domain\ValueObject\ImportColumnMap;
use App\Modules\Workforce\Domain\ValueObject\ImportedEmployee;
use Illuminate\Database\ConnectionInterface;

/**
 * A quien reconoce ya la instalacion, para la importacion masiva (**RF-GP-05**).
 *
 * ## El documento se compara HASHEADO en la propia sentencia
 *
 * `national_id_hash = digest(?, 'sha256')`, con parametro enlazado y nunca
 * interpolado (RL-08, doc 02 §3.2). Es exactamente la misma expresion con la que
 * el alta lo escribe —{@see EloquentEmployeeRepository}— y tiene que serlo: si
 * una de las dos usara otro algoritmo, la busqueda no encontraria nunca a nadie
 * y **cada reimportacion crearia la plantilla de nuevo**, que es justo lo que la
 * regla dura 5 prohibe.
 *
 * Con parametro enlazado y no interpolado para que el documento en claro no
 * aparezca en el texto de la consulta, que puede acabar en el registro de
 * consultas lentas de PostgreSQL.
 *
 * ## Y NORMALIZADO con la funcion del dominio, no con una copia
 *
 * `digest` es sensible a mayusculas. Antes de la revision de la 5.5, un
 * `12345678z` guardado y un `12345678Z` en el fichero eran dos huellas distintas:
 * la busqueda no encontraba a nadie y la reimportacion **creaba una segunda
 * ficha de la misma persona**, con su registro horario partido en dos. La forma
 * canonica la decide {@see ImportedEmployee::normaliseNationalId()} y aqui se
 * invoca; dos implementaciones serian dos oportunidades de que se separen.
 *
 * ## Los departamentos se traen de una vez
 *
 * Una consulta para todo el fichero en lugar de una por linea. Son unidades de
 * filas y se usan en cada una de las mil posibles; el `N+1` aqui seria mil
 * consultas para resolver cinco nombres.
 *
 * ## La normalizacion del nombre se hace en PHP y no en SQL
 *
 * Con la **misma** funcion que usa el mapa de columnas
 * ({@see ImportColumnMap::normalise()}): quien escribe «Recepción» en el Excel y
 * quien creo «Recepcion» en el panel se refieren al mismo departamento, y
 * rechazar la linea por una tilde seria un rechazo que nadie entiende. En SQL
 * haria falta `unaccent()` sobre una columna sin indice util para esto, y ademas
 * habria dos definiciones de «el mismo nombre» que podrian separarse.
 */
final readonly class EloquentEmployeeImportDirectory implements EmployeeImportDirectory
{
    public function __construct(private ConnectionInterface $connection) {}

    public function uuidByNationalId(string $nationalId): ?string
    {
        $normalised = ImportedEmployee::normaliseNationalId($nationalId);

        if ($normalised === null) {
            return null;
        }

        $uuid = $this->connection->table('employees')
            ->whereRaw('national_id_hash = digest(?, ?)', [$normalised, 'sha256'])
            ->value('uuid');

        return \is_string($uuid) ? $uuid : null;
    }

    public function uuidByEmail(string $email): ?string
    {
        // Sin bajar a minusculas: la columna es `citext` y la comparacion ya es
        // insensible a mayusculas. Hacerlo aqui ademas seria una segunda regla
        // que podria separarse de la del esquema.
        $uuid = $this->connection->table('employees')
            ->where('email', $email)
            ->value('uuid');

        return \is_string($uuid) ? $uuid : null;
    }

    public function departmentsByNormalisedName(): array
    {
        $departments = [];

        foreach ($this->connection->table('departments')->get(['id', 'name']) as $row) {
            $name = ImportColumnMap::normalise((string) $row->name);

            if ($name !== '') {
                // El PRIMERO gana si dos nombres normalizan igual —«Recepción» y
                // «Recepcion» conviviendo—: es un caso que el UNIQUE por centro
                // no impide y que aqui se resuelve de forma estable en lugar de
                // depender del orden de las filas.
                $departments[$name] ??= (int) $row->id;
            }
        }

        return $departments;
    }
}
