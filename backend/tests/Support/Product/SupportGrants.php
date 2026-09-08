<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Command\GrantSupportAccessCommand;
use App\Modules\Product\Application\UseCase\GrantSupportAccessHandler;
use App\Modules\Product\Application\UseCase\IssuedSupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Product\Infrastructure\Persistence\SupportGrant;
use App\Modules\Product\Infrastructure\Persistence\SupportGrant as SupportGrantModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity\ManagementUsers;

/**
 * Concesiones de acceso de soporte para las pruebas (RF-PD-11, ADR-020).
 *
 * Existe para que una prueba se lea como lo que comprueba —«un token de soporte
 * con alcance `diagnostics` no lee jornadas»— y no como diez lineas de creacion
 * de filas.
 *
 * **Se concede por el caso de uso real y no insertando filas a mano.** Es
 * deliberado: lo que hace valido el token es la maquinaria de Sanctum que emite
 * el emisor, y una fila fabricada a mano probaria una cosa distinta de la que
 * corre en produccion.
 */
final class SupportGrants
{
    /**
     * Concede un acceso y devuelve la concesion con su token en claro.
     *
     * Crea ademas la cuenta de `admin` que lo autoriza, porque `granted_by` no
     * admite nulos: una autorizacion sin firmante no acredita nada (RL-18).
     */
    public static function issue(
        SupportScope $scope = SupportScope::Diagnostics,
        int $hours = 24,
        string $reason = 'Incidencia #123 de prueba',
        ?int $grantedByUserId = null,
    ): IssuedSupportGrant {
        $userId = $grantedByUserId ?? ManagementUsers::withRole(UserRole::ADMIN)->id;

        /** @var GrantSupportAccessHandler $handler */
        $handler = app(GrantSupportAccessHandler::class);

        return $handler->handle(new GrantSupportAccessCommand(
            reason: $reason,
            scope: $scope,
            hours: $hours,
            grantedByUserId: $userId,
        ));
    }

    /** Solo el token en claro, que es lo que la mayoria de las pruebas necesita. */
    public static function tokenFor(SupportScope $scope = SupportScope::Diagnostics, int $hours = 24): string
    {
        return self::issue($scope, $hours)->token;
    }

    /**
     * Un token de soporte con un ambito que **ningun alcance concede**, emitido a
     * mano.
     *
     * Existe para probar la mitad de la regla dura 18 que de otro modo no se
     * puede ejercitar: **que la policy cierra aunque el ambito abra**. Con los
     * ambitos reales, un token de soporte no llega ni al controlador de licencia
     * —lo para el middleware— y una policy que devolviera `true` pasaria
     * desapercibida.
     *
     * Es exactamente el escenario contra el que existe la segunda comprobacion:
     * un token emitido a mano, un ambito añadido por error o un refactor de la
     * lista de alcances. Aqui se fabrica a proposito.
     *
     * @param  list<string>  $abilities  Los ambitos del token, tal cual.
     */
    public static function tokenWithAbilities(array $abilities, SupportScope $scope = SupportScope::Diagnostics): string
    {
        $issued = self::issue($scope);

        $row = SupportGrantModel::query()->where('uuid', $issued->grant->uuid)->firstOrFail();
        $row->tokens()->delete();

        return $row->createToken(
            name: 'support:'.$issued->grant->uuid,
            abilities: $abilities,
            expiresAt: $issued->grant->expiresAt,
        )->plainTextToken;
    }

    /**
     * Envejece la concesion hasta dejarla caducada, **sin tocar el reloj del
     * proceso**.
     *
     * Se mueven las dos fechas hacia atras en lugar de adelantar un reloj falso
     * porque la caducidad efectiva la comprueban dos sitios independientes —el
     * `expires_at` del token de Sanctum y la fila— y solo mintiendo a la base de
     * datos se ejercitan los dos a la vez, que es lo que ocurre en produccion.
     */
    public static function expire(string $grantUuid): void
    {
        $expiredAt = now()->subHour();

        DB::table('support_grants')
            ->where('uuid', $grantUuid)
            ->update([
                'granted_at' => $expiredAt->copy()->subDay(),
                'expires_at' => $expiredAt,
            ]);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', SupportGrant::class)
            ->update(['expires_at' => $expiredAt]);
    }
}
