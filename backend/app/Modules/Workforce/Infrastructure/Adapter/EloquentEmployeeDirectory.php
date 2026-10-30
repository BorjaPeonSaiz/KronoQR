<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el nucleo necesita saber de un empleado para decidir un fichaje
 * (RN-14, y el centro del que sale la zona horaria de RN-05).
 *
 * **Es la arista de ADR-025**: el puerto lo declara `Attendance`, que es quien
 * lo necesita, y lo implementa `Workforce`, que es quien tiene la tabla
 * `employees`. La dependencia va del satelite al nucleo y alcanza
 * `Attendance\Application\Port` y nada mas.
 *
 * Devuelve un objeto de valor de `Shared` y **nunca un modelo Eloquent**: es lo
 * que impide que el acoplamiento se cuele por el tipo de retorno.
 *
 * `displayName` sale del modelo de dominio para que la forma minima del nombre
 * —nombre de pila e inicial del primer apellido, §7.3— se decida en un solo
 * sitio. Un token de quiosco robado no debe permitir reconstruir la plantilla.
 *
 * **Los periodos de alta (RN-27) viajan en la misma consulta**, como una
 * subconsulta correlacionada que los agrega en JSON por el indice de
 * `employment_periods_no_overlap`: una sola ida a la base y una sola forma de
 * consulta, exista o no la persona, como pide el aviso de RN-22.
 */
final readonly class EloquentEmployeeDirectory implements EmployeeDirectory
{
    private const array COLUMNS = ['uuid', 'employee_code', 'first_name', 'last_name', 'status', 'site_id', 'department_id', 'hired_at', 'terminated_at'];

    private const string PERIODS = <<<'SQL'
        (SELECT json_agg(json_build_object(
                    'uuid', p.uuid,
                    'hired_on', p.hired_on,
                    'terminated_on', p.terminated_on,
                    'offboarded', p.offboarded_at IS NOT NULL
                ) ORDER BY p.hired_on)
           FROM employment_periods p
          WHERE p.employee_id = employees.id) AS employment_periods
        SQL;

    public function find(string $employeeUuid): ?EmployeeSnapshot
    {
        return $this->snapshotOf($this->query()->where('uuid', $employeeUuid)->first());
    }

    public function findByCode(string $employeeCode): ?EmployeeSnapshot
    {
        // La misma consulta, exista o no el codigo: un acceso al indice
        // UNIQUE de `employee_code` y nada mas.
        return $this->snapshotOf($this->query()->where('employee_code', $employeeCode)->first());
    }

    /**
     * @return Builder<Employee>
     */
    private function query(): Builder
    {
        return Employee::query()->select(self::COLUMNS)->addSelect(DB::raw(self::PERIODS));
    }

    private function snapshotOf(?Employee $row): ?EmployeeSnapshot
    {
        if (! $row instanceof Employee) {
            // `null` NO autoriza a bloquear a nadie en el quiosco (regla dura 19,
            // RN-15): quien decide que hacer con esto es el caso de uso.
            return null;
        }

        return new EmployeeSnapshot(
            employeeUuid: $row->uuid,
            employeeCode: $row->employee_code,
            displayName: $this->displayName($row),
            status: EmploymentStatus::from($row->status),
            siteId: $row->site_id,
            departmentId: $row->department_id,
            // Fechas civiles tal cual estan en la columna `date`: el alta manual
            // de tramos acota con ellas las jornadas de una baja (RN-14, 2.2.0).
            hiredOn: $row->hired_at->format('Y-m-d'),
            terminatedOn: $row->terminated_at?->format('Y-m-d'),
            employmentPeriods: self::periodsOf($row->getAttribute('employment_periods')),
        );
    }

    /**
     * @return list<EmploymentPeriod>
     */
    private static function periodsOf(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;

        if (! \is_array($decoded)) {
            return [];
        }

        $periods = [];

        foreach ($decoded as $period) {
            if (\is_array($period) && \is_string($period['hired_on'] ?? null)) {
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
            terminatedOn: \is_string($terminatedOn) ? $terminatedOn : null,
            offboarded: ($period['offboarded'] ?? false) === true,
            uuid: \is_string($uuid) ? $uuid : null,
        );
    }

    private function displayName(Employee $row): string
    {
        $initial = mb_substr(trim($row->last_name), 0, 1);

        return trim($row->first_name).' '.mb_strtoupper($initial).'.';
    }
}
