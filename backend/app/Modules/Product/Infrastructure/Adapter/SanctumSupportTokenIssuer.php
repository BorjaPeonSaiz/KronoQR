<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\Port\SupportTokenIssuer;
use App\Modules\Product\Domain\Model\SupportGrant as SupportGrantEntity;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Product\Infrastructure\Persistence\SupportGrant;
use RuntimeException;

/**
 * El token de un acceso de soporte, sobre Laravel Sanctum (**RF-PD-11**,
 * ADR-020, doc 02 §7.3).
 *
 * Es el mismo patron que `Identity\Infrastructure\Adapter\SanctumDeviceTokenIssuer`
 * —un `tokenable` que no es una persona— y con las mismas tres decisiones.
 * **Se nombra en prosa y no con `@see`**, porque una referencia resoluble seria
 * una dependencia entre modulos que el §1.6 no concede y que Deptrac cuenta:
 *
 * 1. **Los ambitos salen de {@see SupportScope::abilities()}**
 *    y no de una lista escrita aqui. Es lo unico que impide que un token de
 *    soporte acabe con `employees:*` el dia que alguien copie y pegue este
 *    fichero, y lo que hace que la tabla de alcances del contrato sea cierta y
 *    no una promesa.
 * 2. **Emitir retira lo anterior.** Una concesion tiene un token y solo uno.
 * 3. **El hash se copia a `support_grants.token_hash` en la misma operacion**,
 *    para que la fila se explique sola sin unirse a `personal_access_tokens`.
 *
 * ## La caducidad del token es la de la concesion
 *
 * Y esa es la mitad de RF-PD-11 —«caducidad efectiva»— que no depende de que
 * nadie ejecute nada: llegado `expires_at`, Sanctum deja de aceptar el token por
 * su cuenta. La otra mitad la comprueba `IdentityServiceProvider` en cada
 * peticion, que es lo que hace que una revocacion valga **ya** y no cuando
 * expire.
 *
 * ## El token en claro no se registra en ningun sitio
 *
 * Sale como valor de retorno, viaja al `201` o a la salida del comando y se
 * olvida. Ni un `Log::debug`, ni una excepcion que lo lleve dentro.
 */
final readonly class SanctumSupportTokenIssuer implements SupportTokenIssuer
{
    public function __construct(private SupportGrantRepository $grants) {}

    public function issueFor(SupportGrantEntity $grant): string
    {
        $row = $this->rowOf($grant);

        $row->tokens()->delete();

        $token = $row->createToken(
            // El nombre no lleva ningun dato personal (regla dura 21) y si el
            // UUID publico, que es lo que permite reconocer el token al listarlo.
            name: 'support:'.$grant->uuid,
            abilities: $grant->scope->abilities(),
            expiresAt: $grant->expiresAt,
        );

        // Mismo algoritmo que usa Sanctum para su columna `token`, y misma copia
        // que hace el emisor del quiosco.
        $this->grants->storeTokenHash(
            $grant->id ?? $row->id,
            hash('sha256', self::secretOf($token->plainTextToken)),
        );

        return $token->plainTextToken;
    }

    public function revokeAllFor(SupportGrantEntity $grant): void
    {
        $this->rowOf($grant)->tokens()->delete();
    }

    private function rowOf(SupportGrantEntity $grant): SupportGrant
    {
        $row = SupportGrant::query()->where('uuid', $grant->uuid)->first();

        if (! $row instanceof SupportGrant) {
            // Solo puede ocurrir si la fila desaparece entre que el caso de uso
            // la guarda y emite su token, es decir, nunca: las dos cosas van en
            // la misma transaccion. No se degrada a «token sin dueño».
            throw new RuntimeException('La concesion de soporte ha dejado de existir mientras se emitia su token.');
        }

        return $row;
    }

    /**
     * La mitad secreta de un token de Sanctum: `<id>|<secreto>`.
     *
     * Se hashea solo el secreto porque es lo que Sanctum guarda en su columna;
     * hashear la cadena entera daria un valor que no se puede cotejar con
     * `personal_access_tokens.token`, que es toda la razon de ser de la copia.
     */
    private static function secretOf(string $plainTextToken): string
    {
        $separator = strpos($plainTextToken, '|');

        return $separator === false ? $plainTextToken : substr($plainTextToken, $separator + 1);
    }
}
