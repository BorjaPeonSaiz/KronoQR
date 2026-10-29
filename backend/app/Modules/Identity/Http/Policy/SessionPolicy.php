<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien puede cerrar su propia sesion por `POST /api/v1/auth/logout`
 * (RF-ID-01, regla dura 18, ADR-039).
 *
 * **Solo una cuenta de gestion (`users`) y un acceso de soporte
 * (`support_grants`, al que el controlador responde `204` sin tocar nada).**
 * Cualquier otro portador recibe `403`, sin asiento y con su token intacto:
 *
 * - **un quiosco (`devices`)**, porque la tablet no cierra sesion y su token es
 *   lo unico que la deja fichar. Con ese token robado, una llamada aqui la
 *   dejaba sin servicio sin dejar rastro. La credencial de un quiosco se retira
 *   desvinculandolo desde el panel, que deja `device.revoked`;
 * - **una sesion del portal (`employees`)**, porque el portal cierra la suya por
 *   `POST /api/v1/me/logout` ({@see PortalSessionPolicy}) y ningun cliente
 *   oficial usa esta ruta con ese token.
 *
 * La lista se enumera en vez de devolver `true` para que un portador nuevo
 * obligue a decidir si puede cerrar sesion aqui en vez de heredarlo.
 *
 * Se compara **la tabla y no la clase** por lo mismo que el resto del modulo
 * ({@see PortalSessionPolicy}, `LogoutController`): el modelo `SupportGrant`
 * vive en `Product`, y este modulo no puede importarlo (doc 02 §1.6, Deptrac).
 */
final class SessionPolicy
{
    /**
     * Las tablas de los `tokenable` que pueden llamar a la ruta.
     *
     * @var list<string>
     */
    public const array BEARER_TABLES = ['users', 'support_grants'];

    /**
     * `POST /api/v1/auth/logout`.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a
     *                        proposito: el guard entrega lo que haya autenticado.
     */
    public function logOut(mixed $actor): bool
    {
        return $actor instanceof Model && \in_array($actor->getTable(), self::BEARER_TABLES, true);
    }
}
