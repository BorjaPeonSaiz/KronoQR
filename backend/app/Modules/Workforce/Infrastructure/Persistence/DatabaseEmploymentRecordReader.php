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
 * (RN-13). La cuenta se conserva como autora mientras quede una correccion
 * suya (`management_account_retention_candidates`): su nombre no es `''`.
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
                   u.name                     AS author_name
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
                authorName: $row->string('author_name'),
                reason: $row->string('reason'),
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
}
