<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
