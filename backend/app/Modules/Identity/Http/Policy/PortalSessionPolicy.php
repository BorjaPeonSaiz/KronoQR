<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien puede actuar sobre una **sesion del portal del empleado** (RF-ID-05,
 * RF-ID-07, regla dura 18).
 *
 * Hoy solo hay una accion: cerrarla (`POST /api/v1/me/logout`). Abrirla es
 * publico —`/me/login` no tiene a nadie autenticado que autorizar— y leer lo
 * propio lo autoriza `Reporting\Http\Policy\SelfJournalPolicy`, nombrada en
 * prosa porque `Identity` no puede importar la capa Http de otro modulo
 * (doc 02 §1.6, Deptrac).
 *
 * ## El portador tiene que ser una persona de la plantilla
 *
 * El ambito `self:read` lo comprueba el middleware `ability` antes de llegar
 * aqui, y no basta: un token de gestion al que alguien le pusiera `self:read` a
 * mano pasaria ese middleware. Lo que lo distingue es el `tokenable`, que es una
 * fila de `employees` y no de `users` ni de `devices`.
 *
 * Se compara **la tabla y no la clase** por lo mismo que el resto del modulo
 * (`LogoutController`, `IdentityServiceProvider`): el modelo `Employee` vive en
 * `Workforce` y este modulo no puede importarlo.
 *
 * Un acceso de soporte del fabricante es una cuenta de `users`, asi que tambien
 * recibe `403`: no tiene sesion de portal que cerrar.
 */
final class PortalSessionPolicy
{
    /** La tabla del `tokenable` de una sesion de portal. */
    public const string EMPLOYEES_TABLE = 'employees';

    /**
     * `POST /api/v1/me/logout`.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a
     *                        proposito: el guard entrega lo que haya autenticado, y
     *                        lo que esta ruta acepta es una sola cosa.
     */
    public function logOut(mixed $actor): bool
    {
        return $actor instanceof Model && $actor->getTable() === self::EMPLOYEES_TABLE;
    }
}
