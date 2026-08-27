<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device as DeviceModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Workforce\WorkforceFixtures;

/**
 * El escenario minimo de un fichaje: un centro con su zona horaria, un
 * empleado y un quiosco emparejado.
 *
 * Se apoya en {@see WorkforceFixtures} para lo que ya existia y anade lo que la
 * tarea 1.4 necesita: el dispositivo y su token. Nada de esto pasa por un caso
 * de uso a proposito —las pruebas de escritura tienen que poder montar estados
 * que el dominio tardaria diez fichajes en producir— salvo el token, que se
 * emite con el mecanismo real de Sanctum porque es lo que la policy comprueba.
 *
 * **El token cuelga del propio dispositivo y no de una cuenta de `users`**
 * (RF-ID-04, tarea 1.5): es lo que hace que `ScanPolicy` y `ScanningDevice`
 * puedan identificar el quiosco por la tabla del `tokenable` sin que
 * `Attendance` importe nada de `Identity`.
 *
 * Datos ficticios: nada especifico de un cliente entra en el repositorio (regla
 * dura 13).
 */
final class AttendanceFixtures
{
    /**
     * Un quiosco activo del centro, con su UUID publico.
     *
     * @return array{id: int, uuid: string}
     */
    public static function device(int $siteId, string $name = 'Recepcion'): array
    {
        $uuid = Str::uuid7()->toString();

        $device = DeviceModel::query()->create([
            'uuid' => $uuid,
            'site_id' => $siteId,
            'name' => $name.' '.Str::random(6),
            'status' => 'active',
        ]);

        return ['id' => $device->id, 'uuid' => $uuid];
    }

    /**
     * Token de dispositivo con el ambito **exacto** del quiosco (§7.3).
     *
     * `scan:write`, `roster:read` y `heartbeat:write`, ni uno mas. Que sea la
     * lista real y no `['*']` es lo que hace que la prueba de autorizacion
     * negativa signifique algo: un token con comodin pasaria cualquier
     * comprobacion de ambito y ocultaria justo el fallo que se busca.
     */
    public static function tokenFor(int $deviceId): string
    {
        $device = DeviceModel::query()->findOrFail($deviceId);

        return $device->createToken('Quiosco de pruebas', [
            TokenAbility::SCAN_WRITE->value,
            TokenAbility::ROSTER_READ->value,
            TokenAbility::HEARTBEAT_WRITE->value,
        ])->plainTextToken;
    }

    /**
     * Centro, departamento, empleado y quiosco, listos para fichar.
     *
     * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string}
     */
    public static function scenario(string $timezone = 'Europe/Madrid'): array
    {
        $site = WorkforceFixtures::site('Hotel de fichaje', $timezone);
        $department = WorkforceFixtures::department($site);
        $employee = WorkforceFixtures::employee($site, $department);
        $device = self::device($site);

        return [
            'site' => $site,
            'department' => $department,
            'employee' => $employee,
            'device' => $device['id'],
            'deviceUuid' => $device['uuid'],
            'token' => self::tokenFor($device['id']),
        ];
    }

    /**
     * `employees.id` a partir del UUID publico, para las aserciones que miran
     * la tabla directamente.
     */
    public static function employeeIdOf(string $employeeUuid): int
    {
        $id = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

        return is_numeric($id)
            ? (int) $id
            : throw new RuntimeException('No existe ningun empleado con UUID '.$employeeUuid.'.');
    }

    /**
     * La comprobacion de integridad de RN-06 hecha como la hara la
     * reconciliacion de la tarea 2.7: **la proyeccion frente a sus eventos
     * origen**.
     *
     * Devuelve las jornadas en las que `daily_totals.total_minutes` no coincide
     * con la suma de los tramos vigentes. Tiene que estar siempre vacia (regla
     * dura 7).
     *
     * **El `FILTER` no es un adorno de PostgreSQL: es lo que hace correcta la
     * comprobacion cuando se anula (tarea 1.15, ADR-026).** Filtrando en el
     * `WHERE`, una jornada cuyos tramos se hayan anulado TODOS desaparece del
     * agrupado y su fila de `daily_totals` —que se queda a cero, y debe quedarse:
     * borrarla movería minutos entre dias sin que nadie lo pidiera— aparecia como
     * divergencia. Agrupando sobre todas las filas y sumando solo las vigentes,
     * el dia sigue existiendo con total cero, que es exactamente lo que la
     * proyeccion afirma.
     *
     * Y la deteccion de verdad sigue intacta: una jornada con tramos y sin fila
     * en la proyeccion da `projected` nulo frente a un `summed` numerico, y
     * `IS DISTINCT FROM` la senala.
     *
     * @return list<object>
     */
    public static function projectionDivergences(): array
    {
        /** @var list<object> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT COALESCE(t.employee_id, s.employee_id) AS employee_id,
                   COALESCE(t.work_date, s.work_date)     AS work_date,
                   t.total_minutes                        AS projected,
                   s.summed                               AS summed
              FROM daily_totals t
              FULL OUTER JOIN (
                    SELECT employee_id,
                           work_date,
                           COALESCE(
                               SUM(duration_minutes) FILTER (WHERE status NOT IN ('voided', 'superseded')),
                               0
                           ) AS summed
                      FROM shift_entries
                     GROUP BY employee_id, work_date
                   ) s
                ON s.employee_id = t.employee_id AND s.work_date = t.work_date
             WHERE t.total_minutes IS DISTINCT FROM s.summed
        SQL);

        return $rows;
    }
}
