<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Workforce\Infrastructure\Persistence\Department;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;
use App\Modules\Workforce\Infrastructure\Persistence\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Centros, departamentos y empleados para las pruebas de plantilla.
 *
 * Los datos son ficticios y ninguno viene de un cliente (regla dura 13). Las
 * zonas horarias no son decorativas: `Atlantic/Canary` tiene una hora menos que
 * `Europe/Madrid` siendo el mismo pais, que es lo que hace que una prueba de
 * RN-05 signifique algo.
 */
final class WorkforceFixtures
{
    /**
     * El centro de la instalacion. **Hay exactamente uno** (ADR-040): la
     * primera llamada de cada prueba lo crea y las siguientes devuelven el
     * mismo, con el nombre y la zona de la primera. Un segundo `create()`
     * chocaria con `sites_single_row_uidx`, que es justo lo que
     * `SingleSiteSchemaTest` afirma.
     */
    public static function site(string $name = 'Hotel de pruebas', string $timezone = 'Europe/Madrid'): int
    {
        $existing = Site::query()->orderBy('id')->value('id');

        if (\is_int($existing)) {
            return $existing;
        }

        $site = Site::query()->create([
            'name' => $name.' '.Str::random(4),
            'timezone' => $timezone,
        ]);

        return $site->id;
    }

    public static function department(int $siteId, string $name = 'Recepcion'): int
    {
        $department = Department::query()->create([
            'site_id' => $siteId,
            'name' => $name.' '.Str::random(4),
        ]);

        return $department->id;
    }

    /**
     * Un empleado escrito con el constructor de consultas, sin pasar por el caso
     * de uso: las pruebas de esquema tienen que poder crear filas que el dominio
     * no crearia.
     *
     * **Nombre, apellidos y codigo se pueden fijar** porque la busqueda libre de
     * `GET /employees?q=` (RF-GP-01) casa precisamente contra ellos: con el
     * nombre por defecto, «Persona De Prueba» para todo el mundo, no habria
     * forma de afirmar que la consulta encuentra a quien tiene que encontrar y
     * deja fuera al resto. Omitidos siguen siendo los de siempre.
     */
    public static function employee(
        int $siteId,
        ?int $departmentId = null,
        string $status = 'active',
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $employeeCode = null,
    ): string {
        $uuid = Str::uuid7()->toString();

        Employee::query()->create([
            'uuid' => $uuid,
            'site_id' => $siteId,
            'department_id' => $departmentId,
            'first_name' => $firstName ?? 'Persona',
            'last_name' => $lastName ?? 'De Prueba',
            'employee_code' => $employeeCode ?? 'E'.Str::upper(Str::random(9)),
            'email' => null,
            'status' => $status,
            'hired_at' => '2026-01-01',
            'terminated_at' => $status === 'terminated' ? '2026-06-30' : null,
            'locale' => 'es',
        ]);

        return $uuid;
    }

    /**
     * Da de baja a un empleado sin pasar por el caso de uso.
     *
     * Existe para las pruebas que comprueban que RN-14 se aplica **en cada
     * peticion** y no solo al emitir un token: lo que importa ahi es el estado
     * de la fila, no el acto administrativo que lo produjo, que tiene sus
     * propias pruebas en `Workforce`.
     */
    public static function terminate(string $employeeUuid): void
    {
        Employee::query()
            ->where('uuid', $employeeUuid)
            ->update([
                'status' => 'terminated',
                'terminated_at' => '2026-06-30',
            ]);
    }

    /**
     * El identificador del **unico** centro de la instalacion (ADR-040).
     *
     * Existe para que las pruebas que crearon el centro en un `beforeEach` no
     * tengan que guardarlo en una propiedad dinamica del caso —PHPStan 9 no
     * conoce las de Pest y el analisis corre tambien sobre `tests/`— ni repetir
     * un `(int) DB::table('sites')->value('id')` que el analizador rechaza por
     * castear `mixed`.
     */
    public static function onlySiteId(): int
    {
        /** @var int|string|null $id */
        $id = DB::table('sites')->orderBy('id')->value('id');

        return \is_numeric($id)
            ? (int) $id
            : throw new RuntimeException('La instalacion no tiene ningun centro configurado.');
    }
}
