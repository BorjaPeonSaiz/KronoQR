<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\LegalExportEmployeeDirectory;
use App\Modules\Compliance\Application\Port\LegalExportEmployeeMatch;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\NationalId;
use App\Modules\Shared\Infrastructure\Persistence\NationalIdDigest;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * La busqueda de la persona de la exportacion legal sobre PostgreSQL (RF-IN-05).
 *
 * ## Por texto: subcadena sin acentos en el nombre, exacto en el codigo
 *
 * `unaccent(...) ILIKE unaccent(?)` sobre el nombre, los apellidos y el nombre
 * completo concatenado —quien busca escribe «Youssef Amrani» de corrido, y esa
 * cadena no esta en ninguna de las dos columnas por separado—. Es el mismo
 * tratamiento que `GET /employees` y la presencia en vivo.
 *
 * **`%`, `_` y `\` se escapan**: sin eso, un `%%%` casaria con la plantilla
 * entera y la busqueda dejaria de tener el minimo de tres caracteres que
 * RF-IN-05 le impone. `unaccent()` no toca ninguno de los tres, asi que el
 * escape sigue en pie despues de normalizar.
 *
 * El **codigo de empleado, exacto** y no por subcadena: es un identificador
 * opaco impreso en la tarjeta, y por prefijo serviria para recorrer la
 * plantilla de diez en diez. La columna es `citext`, asi que la igualdad ya no
 * distingue mayusculas.
 *
 * **Sin indice para el texto, y a proposito**: `ILIKE '%…%'` no usa un btree y
 * `unaccent()` no es `IMMUTABLE`. La plantilla de una instalacion son cientos
 * de filas (doc 02, Anexo A). La misma decision, y la misma palanca si algun
 * dia hiciera falta (`pg_trgm`), que en el directorio de plantilla.
 *
 * ## Por documento: exacto contra el hash, con el indice
 *
 * `national_id_hash = digest(?, ?)` con el documento normalizado por
 * {@see NationalId} y la expresion de {@see NationalIdDigest}, las mismas con
 * las que el alta lo escribe. La igualdad usa el indice unico parcial
 * `employees_national_id_hash_unique` (`NationalIdSearchIndexUsageTest`).
 *
 * ## Incluye bajas y suspendidos
 *
 * Sin filtro por `status`: la Inspeccion pregunta tambien por quien ya se fue, y
 * su registro se conserva cuatro años (RL-02).
 */
final readonly class DatabaseLegalExportEmployeeDirectory implements LegalExportEmployeeDirectory
{
    private const string SELECT = <<<'SQL'
        SELECT e.uuid,
               e.first_name,
               e.last_name,
               e.department_id,
               d.name AS department_name,
               e.status,
               e.hired_at,
               e.terminated_at
          FROM employees e
          LEFT JOIN departments d ON d.id = e.department_id
        SQL;

    /** Orden estable: el recorte a `$limit` es el mismo con la misma plantilla. */
    private const string ORDER = 'ORDER BY e.last_name, e.first_name, e.uuid';

    public function __construct(private ConnectionInterface $connection) {}

    public function search(EmployeeSearch $search, int $limit): array
    {
        [$where, $bindings] = $search->isByNationalId()
            ? $this->byNationalId($search)
            : $this->byText($search);

        $rows = $this->connection->select(
            self::SELECT."\n".$where."\n".self::ORDER."\nLIMIT ?",
            [...$bindings, $limit],
        );

        $matches = [];

        foreach ($rows as $row) {
            $matches[] = $this->hydrate(Row::of($row));
        }

        return $matches;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function byNationalId(EmployeeSearch $search): array
    {
        if (! $search->nationalId instanceof NationalId) {
            throw new LogicException('Una busqueda por documento sin documento no se puede construir.');
        }

        return [
            'WHERE '.NationalIdDigest::matches('e.national_id_hash'),
            NationalIdDigest::bindings($search->nationalId),
        ];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function byText(EmployeeSearch $search): array
    {
        if ($search->text === null) {
            throw new LogicException('Una busqueda por texto sin texto no se puede construir.');
        }

        $pattern = '%'.addcslashes($search->text, '\\%_').'%';

        $where = <<<'SQL'
            WHERE unaccent(e.first_name) ILIKE unaccent(?)
               OR unaccent(e.last_name) ILIKE unaccent(?)
               OR unaccent(e.first_name || ' ' || e.last_name) ILIKE unaccent(?)
               OR e.employee_code = ?
            SQL;

        return [$where, [$pattern, $pattern, $pattern, $search->text]];
    }

    private function hydrate(Row $row): LegalExportEmployeeMatch
    {
        return new LegalExportEmployeeMatch(
            uuid: $row->string('uuid'),
            firstName: $row->string('first_name'),
            lastName: $row->string('last_name'),
            departmentId: $row->nullableInt('department_id'),
            departmentName: $row->nullableString('department_name'),
            status: EmploymentStatus::from($row->string('status')),
            hiredAt: $row->string('hired_at'),
            terminatedAt: $row->nullableString('terminated_at'),
        );
    }
}
