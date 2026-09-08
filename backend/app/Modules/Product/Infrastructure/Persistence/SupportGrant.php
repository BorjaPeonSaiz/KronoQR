<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Fila de `support_grants` (doc 01 §5, **RF-PD-11**). **Detalle de
 * persistencia**: el modelo de dominio es
 * {@see \App\Modules\Product\Domain\Model\SupportGrant}.
 *
 * ## Es un `tokenable` de Sanctum, y no una cuenta de `users`
 *
 * Es la decision central de la tarea y viene directa de ADR-020. La alternativa
 * —dar de alta una cuenta del fabricante en cada instalacion— es exactamente la
 * primera fila de la tabla de alternativas descartadas de ese ADR: encargo de
 * tratamiento continuado sin necesidad, llave maestra sobre N clientes y sin
 * rastro distinguible de por que se entro.
 *
 * Colgando el token de la propia concesion se consiguen tres cosas que una
 * cuenta no da:
 *
 * 1. **Caduca sola.** El token se emite con la caducidad de la concesion, asi
 *    que el acceso muere sin que nadie ejecute nada (RF-PD-11). Una cuenta hay
 *    que acordarse de desactivarla.
 * 2. **Tiene motivo.** `reason` esta en la fila de la que cuelga el token: el
 *    acceso lleva escrito para que se concedio, y usarlo para otra cosa es
 *    visible.
 * 3. **La baja es la fila.** No queda una cuenta fantasma en la tabla de
 *    personas del cliente, con su politica de contraseñas y su 2FA, para algo
 *    que no es una persona de la organizacion.
 *
 * ## Implementa `ManagementActor`, y por eso las policies ya la entienden
 *
 * `actsAs()` devuelve el rol que le corresponde a su alcance (ver
 * {@see SupportScope::actsAs()}), asi que las policies que ya existen la
 * autorizan sin reescribir ninguna. Lo que impide que eso se convierta en una
 * puerta trasera son las otras dos mitades: los **ambitos del token**, que dejan
 * fuera familias enteras de la API en el middleware, y
 * {@see self::isSupportActor()}, que es lo que consultan las tres policies en
 * las que ningun alcance debe entrar nunca.
 *
 * **`accessScope()` es `unrestricted()`**, y no es un descuido: el alcance por
 * departamento (RF-ID-03) acota a un responsable dentro de la organizacion, y
 * una concesion de soporte no es de ningun departamento. Lo que la acota es su
 * alcance y su caducidad, no un trozo de la plantilla.
 *
 * **`actsAs()` devuelve `admin` para los tres alcances**, y lo que separa a uno
 * de otro son sus AMBITOS (ver {@see SupportScope::actsAs()}): `read_only` lleva
 * solo los tres de lectura del §7.3, que no abren ni una ruta de escritura en
 * toda la API. El rol dice hasta donde se ve; el ambito, que se puede hacer.
 *
 * `token_hash` duplica a proposito el hash que Sanctum guarda, por lo mismo que
 * en `devices`: la fila tiene que explicarse sola —«¿esta concesion llego a
 * emitir token?»— sin unirse a la tabla de tokens. El token en claro no esta en
 * ninguno de los dos sitios.
 *
 * @property int $id
 * @property string $uuid
 * @property int $granted_by_user_id
 * @property string $reason
 * @property string $scope
 * @property Carbon $granted_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property Carbon|null $accessed_at
 * @property string|null $token_hash
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class SupportGrant extends Model implements AuthenticatableContract, AuthorizableContract, ManagementActor
{
    /**
     * Los dos contratos que el borde exige de quien esta autenticado.
     *
     * `Authenticatable` porque el pipeline pide `getAuthIdentifier()` en varios
     * sitios —el limitador de tasa por cuenta, entre otros— y sin el una peticion
     * de soporte acaba en `500` en lugar de en la policy. Que ese identificador
     * sea el de la CONCESION y no el de una persona es correcto y deliberado: no
     * hay ninguna cuenta detras (ADR-020).
     *
     * **`Authorizable` y no `HasRoles`**, y esta segunda mitad es toda la
     * seguridad de la clase.
     *
     * El `Gate` de Laravel exige que quien autoriza implemente este contrato, y
     * el `before` que registra Spatie tipa su primer parametro con el: sin
     * implementarlo, cualquier policy alcanzada por un token de soporte revienta
     * con un `TypeError` en lugar de decidir. Eso lo descubrio la prueba de
     * `configuration` cambiando un ajuste.
     *
     * Lo que **no** se añade es el `HasRoles` de Spatie, y no por descuido: ese
     * `before` concede el permiso en cuanto el actor tiene `checkPermissionTo()`,
     * **saltandose la policy entera**. Con roles de Spatie encima, una concesion
     * de soporte con el permiso adecuado pasaria por delante de
     * `Product\Http\Policy\SupportGrantPolicy` —nombrada en prosa, porque una
     * capa de persistencia no conoce las policies— y podria ampliarse a si
     * misma. Sin ese metodo, el `before` devuelve `null` y la policy decide, que
     * es exactamente lo que se quiere.
     *
     * El rol de esta fila no vive en `model_has_roles`: lo deduce
     * {@see self::actsAs()} de su alcance, y es temporal por definicion.
     */
    use Authenticatable;

    use Authorizable;

    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    protected $table = 'support_grants';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'granted_by_user_id',
        'reason',
        'scope',
        'granted_at',
        'expires_at',
    ];

    /**
     * No es `fillable`, igual que en `devices`: lo escribe el repositorio y solo
     * el, con el hash que acaba de calcular el emisor.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * El identificador publico de la concesion, que es lo unico suyo que puede
     * aparecer en un log tecnico o en `audit_log`.
     */
    public function actorUuid(): string
    {
        return $this->uuid;
    }

    /**
     * El rol con el que se presenta ante las policies, segun su alcance.
     *
     * Una fila con un `scope` que este binario no reconoce —imposible con el
     * `CHECK` de la migracion, salvo tras una restauracion parcial o un
     * despliegue a medias— **no actua como nada**. Es fallar cerrado: lo
     * contrario seria que un valor ilegible concediera el rol por defecto.
     */
    public function actsAs(UserRole ...$roles): bool
    {
        $scope = SupportScope::tryFrom($this->scope);

        if (! $scope instanceof SupportScope) {
            return false;
        }

        return \in_array($scope->actsAs(), $roles, true);
    }

    /** Sin restriccion por departamento. Ver el docblock de la clase. */
    public function accessScope(): AccessScope
    {
        return AccessScope::unrestricted();
    }

    /**
     * Siempre. Es lo que distingue a esta fila de una cuenta del hotel en las
     * tres puertas que ningun alcance de soporte cruza: conceder o revocar
     * accesos de soporte, activar licencias e incluir datos personales en un
     * paquete de diagnostico (RL-19).
     */
    public function isSupportActor(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_by_user_id' => 'integer',
            'revoked_by_user_id' => 'integer',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'accessed_at' => 'datetime',
        ];
    }
}
