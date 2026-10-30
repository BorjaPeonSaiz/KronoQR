<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\LegalExportEmployeeDirectory;
use App\Modules\Compliance\Application\Port\LegalExportEmployeeMatch;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\NationalId;
use App\Modules\Shared\Infrastructure\Persistence\NationalIdDigest;
use App\Modules\Shared\Infrastructure\Persistence\PersonNameSearch;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * La busqueda de la persona de la exportacion legal sobre PostgreSQL (RF-IN-05).
 *
 * ## Por texto: subcadena sin acentos en el nombre, exacto en el codigo
 *
 * {@see PersonNameSearch} sobre el nombre, los apellidos y el nombre completo
 * concatenado en los dos ordenes —quien busca escribe «Youssef Amrani» de
 * corrido, y el requerimiento de la Inspeccion trae «Amrani, Youssef», y
 * ninguna de las dos cadenas esta en una columna por separado—. Es el mismo
 * tratamiento que `GET /employees` y la presencia en vivo, con el mismo escape
 * de `%`, `_` y `\` hecho despues de `unaccent()`: sin eso, un `%%%` —o un
 * `％％％` de ancho completo, que `unaccent()` convierte en el ASCII— casaria
 * con la plantilla entera y la busqueda dejaria de tener el minimo de tres
 * caracteres que RF-IN-05 le impone.
 *
 * **La coma del orden «Apellidos, Nombre» se cambia por un espacio** antes de
 * buscar, solo para el nombre: es como viene escrito el trabajador en un
 * requerimiento y en una nomina. Si al quitarla el termino se queda por debajo
 * de {@see EmployeeSearch::MIN_TEXT_LENGTH} —`",,,"` pasa el minimo del
 * dominio—, el nombre no se busca: un patron vacio casaria con todos.
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
               e.terminated_at,
               -- Todos sus periodos de alta (RN-27), por el indice de
               -- `employment_periods_no_overlap`: una persona readmitida no
               -- aparece como si solo hubiera trabajado el ultimo.
               (SELECT json_agg(json_build_object(
                           'uuid', p.uuid,
                           'hired_on', p.hired_on,
                           'terminated_on', p.terminated_on,
                           'offboarded', p.offboarded_at IS NOT NULL
                       ) ORDER BY p.hired_on)
                  FROM employment_periods p
                 WHERE p.employee_id = e.id) AS employment_periods
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

        // El codigo, exacto y con el termino tal cual llego del dominio.
        $where = 'WHERE e.employee_code = ?';
        $bindings = [$search->text];

        $name = $this->withoutListComma($search->text);

        if (mb_strlen($name) >= EmployeeSearch::MIN_TEXT_LENGTH) {
            [$byName, $nameBindings] = PersonNameSearch::anyOf([
                'e.first_name',
                'e.last_name',
                "e.first_name || ' ' || e.last_name",
                "e.last_name || ' ' || e.first_name",
            ], $name);

            $where .= ' OR '.$byName;
            $bindings = [...$bindings, ...$nameBindings];
        }

        return [$where, $bindings];
    }

    /**
     * «Pérez Amrani, José» → «Pérez Amrani José»: la coma, con los espacios
     * que la rodeen, pasa a ser un solo espacio, y se recortan los extremos.
     */
    private function withoutListComma(string $text): string
    {
        $spaced = preg_replace('/[\p{Z}\s]*,[\p{Z}\s]*/u', ' ', $text) ?? '';

        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $spaced) ?? '';
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
            employmentPeriods: self::periodsOf($row->nullableString('employment_periods')),
        );
    }

    /**
     * @return list<EmploymentPeriod>
     */
    private static function periodsOf(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);
        $periods = [];

        foreach (is_array($decoded) ? $decoded : [] as $period) {
            if (is_array($period) && is_string($period['hired_on'] ?? null)) {
                $periods[] = self::periodOf($period['hired_on'], $period);
            }
        }

        return $periods;
    }

    /**
     * @param  array<mixed>  $period
     */
    private static function periodOf(string $hiredOn, array $period): EmploymentPeriod
    {
        $terminatedOn = $period['terminated_on'] ?? null;
        $uuid = $period['uuid'] ?? null;

        return new EmploymentPeriod(
            hiredOn: $hiredOn,
            terminatedOn: is_string($terminatedOn) ? $terminatedOn : null,
            offboarded: ($period['offboarded'] ?? false) === true,
            uuid: is_string($uuid) ? $uuid : null,
        );
    }
}
