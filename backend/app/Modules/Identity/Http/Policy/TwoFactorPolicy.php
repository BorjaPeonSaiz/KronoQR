<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;

/**
 * Quien puede usar los tres endpoints de `/auth/2fa/*` (regla dura 18, RS-06).
 *
 * **Una sola respuesta: una cuenta de gestion.** No es una policy de rol —los
 * cuatro roles de panel pasan por aqui— sino de **clase de sujeto**: lo que
 * deniega es que un token de quiosco o una sesion de portal, a los que alguien
 * hubiera añadido el ambito `2fa:pending`, puedan pedir un secreto TOTP o
 * canjearlo por una sesion de gestion. El empleado no tiene segundo factor y no
 * puede tenerlo (regla dura 11 y 12, ADR-014, ADR-015).
 *
 * **Las dos comprobaciones, no una** (doc 02 §7.3). El ambito `2fa:pending` lo
 * verifica el middleware `ability` antes de llegar aqui; esto verifica **quien**
 * porta el token. Con las dos, un ambito concedido por error no basta para
 * atravesar el segundo factor.
 *
 * **Se invoca por su nombre y no por el `Gate`**, con el mismo motivo que
 * `Kiosk\Http\Policy\KioskPolicy` —nombrada en prosa porque una referencia
 * resoluble seria una dependencia entre modulos que el §1.6 no concede—: aqui no
 * hay ningun modelo de dominio sobre el que registrar la policy, porque lo que se
 * autoriza es un paso del propio acceso.
 *
 * **Los tres metodos hacen lo mismo y son tres.** La regla dura 18 pide una policy
 * por endpoint, y un endpoint que reutiliza el metodo de otro es indistinguible de
 * uno al que se le olvido la suya.
 */
final class TwoFactorPolicy
{
    /**
     * `POST /api/v1/auth/2fa/verify`.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a proposito:
     *                        el guard entrega lo que haya autenticado, y lo que este
     *                        endpoint acepta es una sola cosa.
     */
    public function verify(mixed $actor): bool
    {
        return $actor instanceof ManagementActor;
    }

    /**
     * `POST /api/v1/auth/2fa/enrol`.
     */
    public function enrol(mixed $actor): bool
    {
        return $actor instanceof ManagementActor;
    }

    /**
     * `POST /api/v1/auth/2fa/confirm`.
     */
    public function confirm(mixed $actor): bool
    {
        return $actor instanceof ManagementActor;
    }
}
