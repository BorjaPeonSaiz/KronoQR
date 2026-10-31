<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use App\Modules\Workforce\Application\Port\EmploymentPeriodRecord;
use App\Modules\Workforce\Application\Port\EmploymentRecordReader;
use App\Modules\Workforce\Application\Port\TerminationCorrectionRecord;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see EmploymentRecordReader} sobre `employment_periods`,
 * `employment_period_corrections` y `users`.
 *
 * **Una sentencia, sin N+1.** Los periodos van con sus correcciones por un
 * `LEFT JOIN`: una fila por correccion, y una con las columnas de la
 * correccion a nulo para el periodo que no tiene ninguna. Se agrupan aqui en el
 * orden en que llegan. Las dos tablas se alcanzan por sus indices
 * (`employment_periods_no_overlap` empieza por `employee_id`;
 * `employment_period_corrections_period_index`).
 *
 * **`users` por nombre de tabla, sin el modelo de `Identity`** (doc 02 §1.6,
 * Deptrac), la misma licencia que {@see DatabaseManagementAccountLookup}. El
 * autor se une con `JOIN` dentro del `LEFT JOIN`: `corrected_by_user_id` es
 * `NOT NULL` con clave ajena, y una correccion sin autor no explica nada
 * (RN-13). **La correccion no conserva la cuenta** (ADR-062 §7, ADR-060 §2):
 * minimizada, su nombre es `''` y aqui sale como `null` con
 * `authorMinimised`, nunca como un nombre vacio que parezca un dato.
 *
 * El orden de las correcciones es el de `corrected_at` y, a igualdad, el de
 * `id`, que es el de su insercion: dos correcciones del mismo periodo en la
 * misma transaccion no existen, pero el desempate no depende de ello.
 */
final readonly class DatabaseEmploymentRecordReader implements EmploymentRecordReader
{
    public function __construct(private ConnectionInterface $connection) {}

    public function periodsOf(string $employeeUuid): array
    {
        $rows = $this->connection->select(<<<'SQL'
            SELECT p.id                       AS period_id,
                   p.uuid::text               AS period_uuid,
                   p.hired_on::text           AS hired_on,
                   p.terminated_on::text      AS terminated_on,
                   p.offboarded_at            AS offboarded_at,
                   c.id                       AS correction_id,
                   c.previous_terminated_on::text AS previous_terminated_on,
                   c.terminated_on::text      AS corrected_terminated_on,
                   c.corrected_at             AS corrected_at,
                   c.reason                   AS reason,
                   u.uuid::text               AS author_uuid,
                   CASE WHEN u.anonymized_at IS NULL THEN u.name END AS author_name,
                   (u.anonymized_at IS NOT NULL) AS author_minimised
              FROM employment_periods p
              JOIN employees e ON e.id = p.employee_id
              LEFT JOIN (employment_period_corrections c
                         JOIN users u ON u.id = c.corrected_by_user_id)
                ON c.employment_period_id = p.id
             WHERE e.uuid = ?
             ORDER BY p.hired_on, c.corrected_at, c.id
        SQL, [strtolower($employeeUuid)]);

        /** @var array<int, array{period: EmploymentPeriod, offboardedAt: DateTimeImmutable|null, corrections: list<TerminationCorrectionRecord>}> $periods */
        $periods = [];

        foreach ($rows as $raw) {
            $row = Row::of($raw);
            $periodId = $row->int('period_id');

            if (! isset($periods[$periodId])) {
                $offboardedAt = $row->nullableInstant('offboarded_at');

                $periods[$periodId] = [
                    'period' => new EmploymentPeriod(
                        hiredOn: $row->string('hired_on'),
                        terminatedOn: $row->nullableString('terminated_on'),
                        offboarded: $offboardedAt !== null,
                        uuid: $row->string('period_uuid'),
                    ),
                    'offboardedAt' => $offboardedAt,
                    'corrections' => [],
                ];
            }

            if ($row->nullableInt('correction_id') === null) {
                continue;
            }

            $periods[$periodId]['corrections'][] = new TerminationCorrectionRecord(
                id: $row->int('correction_id'),
                previousTerminatedOn: $row->string('previous_terminated_on'),
                terminatedOn: $row->string('corrected_terminated_on'),
                correctedAt: $row->instant('corrected_at'),
                authorUuid: $row->string('author_uuid'),
                authorName: $row->nullableString('author_name'),
                reason: $row->string('reason'),
                authorMinimised: $row->bool('author_minimised'),
            );
        }

        return array_values(array_map(
            static fn (array $period): EmploymentPeriodRecord => new EmploymentPeriodRecord(
                $period['period'],
                $period['offboardedAt'],
                $period['corrections'],
            ),
            $periods,
        ));
    }

    public function offboardedAtOf(array $employeeUuids): array
    {
        if ($employeeUuids === []) {
            return [];
        }

        // El periodo en curso de cada persona es el de `hired_on` mas reciente
        // (ADR-062 §1): `DISTINCT ON` se queda con el y el `WHERE` de fuera, con
        // los que tienen la baja efectiva. Una sola sentencia por pagina.
        $placeholders = implode(', ', array_fill(0, \count($employeeUuids), '?::uuid'));

        $rows = $this->connection->select(<<<SQL
            SELECT latest.employee_uuid, latest.offboarded_at
              FROM (SELECT DISTINCT ON (e.id) e.uuid::text AS employee_uuid, p.offboarded_at
                      FROM employment_periods p
                      JOIN employees e ON e.id = p.employee_id
                     WHERE e.uuid IN ({$placeholders})
                     ORDER BY e.id, p.hired_on DESC) latest
             WHERE latest.offboarded_at IS NOT NULL
        SQL, array_map(strtolower(...), $employeeUuids));

        $instants = [];

        foreach ($rows as $raw) {
            $row = Row::of($raw);
            $instants[$row->string('employee_uuid')] = $row->instant('offboarded_at');
        }

        return $instants;
    }
}
