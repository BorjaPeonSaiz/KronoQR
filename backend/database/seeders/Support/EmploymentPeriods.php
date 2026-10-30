<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\DB;

/**
 * Las fichas de la semilla nacen con su periodo de alta (RN-27, ADR-062 §1).
 *
 * La semilla escribe `employees` con el constructor de consultas y no pasa por
 * el repositorio, que es quien escribe ficha y periodo juntos. Sin el periodo,
 * el disparador diferido `employees_current_period_matches` rechazaria el
 * `COMMIT`. Por eso la insercion y el periodo van en la misma transaccion: el
 * periodo es el de la proyeccion, como el relleno de la migracion.
 */
final class EmploymentPeriods
{
    /**
     * Inserta las fichas (ignorando las que ya existen) y les abre su periodo.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function insertEmployees(array $rows): void
    {
        DB::transaction(static function () use ($rows): void {
            DB::table('employees')->insertOrIgnore($rows);
            self::openMissing();
        });
    }

    /**
     * Un periodo para cada ficha que aun no tiene ninguno, con sus mismas
     * fechas y estado. Idempotente.
     */
    public static function openMissing(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO employment_periods (uuid, employee_id, hired_on, terminated_on, offboarded_at, created_at)
            SELECT gen_random_uuid(), e.id, e.hired_at, e.terminated_at,
                   CASE WHEN e.status = 'terminated' THEN COALESCE(e.updated_at, now()) END,
                   COALESCE(e.created_at, now())
              FROM employees e
             WHERE NOT EXISTS (SELECT 1 FROM employment_periods p WHERE p.employee_id = e.id)
             ORDER BY e.id
        SQL);
    }
}
