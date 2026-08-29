<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario de **gestion** (tabla `users`, doc 01 §5.5, RF-ID-01).
 *
 * Es un detalle de persistencia y vive donde le corresponde —
 * `Infrastructure/Persistence` del modulo que posee la tabla— y no en
 * `App\Models`, que en este proyecto esta vacio a proposito.
 *
 * **No es un empleado.** El empleado entra a su portal con codigo y PIN y puede
 * no tener correo (regla dura 12, ADR-015); quien tiene fila aqui es personal de
 * gestion con correo y contrasena. Son dos poblaciones y dos tablas.
 *
 * **Implementa el puerto {@see ManagementActor}** para que las policies de otros
 * modulos —la de empleados vive en `Workforce`— puedan preguntar por el rol sin
 * importar nada de `Identity`, que es lo que la frontera del doc 02 §1.6
 * prohibe. El modulo que tiene el dato implementa el puerto que declara quien lo
 * necesita (ADR-025).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $locale
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret Cifrado en reposo por el cast `encrypted`.
 * @property Carbon|null $two_factor_confirmed_at Nulo mientras el alta esta a medias.
 * @property int|null $two_factor_last_slice Franja del ultimo codigo aceptado (antirreenvio).
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class User extends Authenticatable implements ManagementActor
{
    use HasApiTokens;
    use HasRoles;

    /**
     * El guard con el que se resuelven los roles de Spatie.
     *
     * **Explicito y no deducido.** Con dos guards apuntando al mismo proveedor
     * —`web` y `sanctum`—, Spatie tendria que adivinar cual de los dos usar para
     * buscar los roles, y un rol creado con un guard no es visible desde el
     * otro: el sintoma seria un 403 intermitente imposible de explicar. Los
     * roles se siembran con `web` y se leen con `web`, se entre por donde se
     * entre.
     */
    public string $guard_name = 'web';

    protected $table = 'users';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'locale',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function actorUuid(): string
    {
        return $this->uuid;
    }

    public function actsAs(UserRole ...$roles): bool
    {
        return $this->hasAnyRole(
            array_map(static fn (UserRole $role): string => $role->value, $roles)
        );
    }

    /**
     * Hasta donde alcanza esta cuenta (**RF-ID-03**).
     *
     * **La regla, en dos lineas:** quien tenga cualquiera de los roles de alcance
     * global —`admin`, `rrhh`, `auditor`— ve la plantilla entera; el
     * `responsable_departamento` ve los departamentos que dirige, que son los que
     * le apuntan por `departments.manager_user_id`.
     *
     * **El orden importa.** Se comprueba primero el alcance global: una cuenta que
     * fuera a la vez `rrhh` y responsable de un departamento no puede quedar
     * acotada a ese departamento, porque su otro rol le da mas. Con la
     * comprobacion al reves, ascender a alguien le quitaria acceso.
     *
     * **La lista se lee con el constructor de consultas y no con una relacion
     * Eloquent** hacia `Department`, que vive en `Workforce`: este modulo no puede
     * importarlo (doc 02 §1.6, verificado por Deptrac). La dependencia es sobre el
     * nombre de la tabla y dos columnas, que es la misma licencia que ya se toma
     * `Compliance` con `users`.
     *
     * **Sin departamentos no alcanza a nadie**, y eso es lo correcto: un
     * responsable existe antes de que se le asigne el primero, y en ese hueco no
     * puede ver la plantilla entera.
     */
    public function accessScope(): AccessScope
    {
        if ($this->actsAs(UserRole::ADMIN, UserRole::RRHH, UserRole::AUDITOR)) {
            return AccessScope::unrestricted();
        }

        $departments = DB::table('departments')
            ->where('manager_user_id', $this->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $ids = [];

        /** @var mixed $id */
        foreach ($departments as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return AccessScope::forDepartments(...$ids);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            // RS-06: el secreto del segundo factor no puede estar en claro en la
            // base de datos del cliente. `encrypted` lo cifra con `APP_KEY`, que
            // vive en el entorno y no en la copia de seguridad de la base.
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_slice' => 'integer',
        ];
    }
}
