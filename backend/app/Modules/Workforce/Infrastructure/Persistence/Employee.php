<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Fila de `employees` (doc 01 §5.5). **Detalle de persistencia**, no el modelo
 * de dominio: ese es {@see \App\Modules\Workforce\Domain\Model\Employee} y es el
 * unico que sale de este modulo hacia arriba.
 *
 * Los dos se llaman igual a proposito —es la convencion de Laravel para el
 * modelo y la del dominio para la entidad— y el repositorio los distingue con un
 * alias. La alternativa, `EmployeeRecord` o `EmployeeModel`, mete el patron en
 * el nombre, que es justo lo que el §3.5 pide no hacer.
 *
 * **`national_id_hash` no es `fillable` y no aparece en `casts`**: no se escribe
 * nunca desde PHP. Lo calcula `pgcrypto` en la sentencia del repositorio (RL-08),
 * de modo que el documento en claro no pasa por un modelo, ni por un atributo,
 * ni por un `toArray()` de depuracion.
 *
 * **Es un `tokenable` de Sanctum desde la tarea 1.11** (portal del empleado,
 * RF-ID-05..07, ADR-015). El token del portal cuelga de la persona y no de una
 * cuenta de `users`, por lo mismo que el del quiosco cuelga de `devices`: dar de
 * alta una cuenta de gestion por empleado habria significado exigir correo
 * —regla dura 12—, politica de contraseña y, desde la tarea 2.1, segundo factor,
 * para algo cuya credencial es un PIN de seis digitos. Y habria dejado una
 * cuenta espejo que alguien tiene que acordarse de desactivar al dar una baja.
 *
 * El unico ambito que lleva ese token es `self:read` (RF-ID-07), y su validez
 * la vuelve a comprobar `IdentityServiceProvider` en cada peticion contra
 * `status`: una baja tiene efecto en la peticion siguiente, no cuando caduque el
 * token.
 *
 * @property int $id
 * @property string $uuid
 * @property int $site_id
 * @property int|null $department_id
 * @property string $first_name
 * @property string $last_name
 * @property string $employee_code
 * @property string|null $email
 * @property string $status
 * @property Carbon $hired_at
 * @property Carbon|null $terminated_at
 * @property string $locale
 * @property CarbonImmutable|null $pin_issued_at
 * @property CarbonImmutable|null $pin_delivered_at
 * @property int|null $pin_delivered_by_user_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class Employee extends Model
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    protected $table = 'employees';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'site_id',
        'department_id',
        'first_name',
        'last_name',
        'employee_code',
        'email',
        'status',
        'hired_at',
        'terminated_at',
        'locale',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'national_id_hash',
        'pin_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'terminated_at' => 'date',
            // Instantes y no fechas: la emision y la entrega del PIN ocurren a
            // una hora concreta y se guardan en UTC (regla dura 3).
            'pin_issued_at' => 'immutable_datetime',
            'pin_delivered_at' => 'immutable_datetime',
        ];
    }
}
