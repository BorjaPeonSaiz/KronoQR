<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien puede cerrar su propia sesion por `POST /api/v1/auth/logout`
 * (RF-ID-01, RF-ID-05, regla dura 18; R4-QA-06 de la re-verificacion de la
 * 2.2.0).
 *
 * La ruta revoca el token de quien llama y nada mas: no lee ni escribe ningun
 * dato del cliente. Por eso la admite **todo portador que el producto emite**, y
 * esta policy los enumera en vez de devolver `true`: una cuenta de gestion
 * (`users`), una sesion del portal (`employees`), un quiosco (`devices`) y un
 * acceso de soporte (`support_grants`, al que el controlador responde `204` sin
 * tocar nada). Un `tokenable` de cualquier otra tabla recibe `403`.
 *
 * Hoy ese `403` no lo alcanza nadie, y no es un descuido: el autenticador de
 * Sanctum que registra `IdentityServiceProvider` ya falla cerrado ante un
 * `tokenable` que no reconoce, y responde `401` antes de llegar aqui. Las dos
 * listas tienen que decir lo mismo; si un dia aparece un quinto portador, esta
 * policy obliga a decidir si puede cerrar sesion en vez de heredarlo.
 *
 * Se compara **la tabla y no la clase** por lo mismo que el resto del modulo
 * ({@see PortalSessionPolicy}, `LogoutController`): los modelos `Employee` y
 * `SupportGrant` viven en `Workforce` y `Product`, y este modulo no puede
 * importarlos (doc 02 §1.6, Deptrac).
 */
final class SessionPolicy
{
    /**
     * Las tablas de los cuatro `tokenable` que el producto emite.
     *
     * @var list<string>
     */
    public const array BEARER_TABLES = ['users', 'employees', 'devices', 'support_grants'];

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
