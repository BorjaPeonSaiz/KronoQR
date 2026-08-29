<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;

/**
 * Emision y revocacion de los tokens de sesion del panel.
 *
 * Lo implementa un adaptador sobre Laravel Sanctum (doc 02 §3.1). El puerto
 * existe porque el caso de uso no puede conocer el mecanismo: el dia que la
 * sesion de gestion pase a llevar segundo factor (tarea 2.1) lo que cambia es
 * quien emite el token, no que se emita uno al autenticarse.
 */
interface AccessTokenIssuer
{
    /**
     * Emite un token para la cuenta, con **los ambitos de su rol** (§7.3) y con
     * caducidad explicita.
     *
     * `$deviceName` es el nombre con el que se listara y se podra revocar esa
     * sesion concreta. No lleva PII: es «Panel de gestion», no el nombre de una
     * persona.
     */
    public function issueFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken;

    /**
     * Emite la **sesion pendiente de segundo factor** (RS-06): un token con un
     * unico ambito, `2fa:pending`, y una vida de minutos.
     *
     * **Metodo propio y no un parametro de {@see self::issueFor()}.** Lo que
     * distingue a los dos tokens no es un detalle de configuracion: uno abre el
     * producto entero y el otro no abre nada. Con un booleano por medio, un
     * descuido en el sitio equivocado emite una sesion completa a quien todavia no
     * ha presentado su segundo factor, y ese fallo no tiene sintoma visible: todo
     * funciona, solo que sin 2FA.
     *
     * `$deviceName` se conserva para que la sesion definitiva herede el nombre con
     * el que el cliente pidio entrar.
     */
    public function issuePendingFor(AuthenticatedUser $user, string $deviceName): IssuedAccessToken;

    /**
     * Revoca **un** token por su identificador.
     *
     * Uno y no todos: cerrar sesion en el portatil no puede echar a la misma
     * persona de la tablet donde estaba revisando incidencias.
     */
    public function revoke(int|string $tokenId): void;

    /**
     * Revoca **todos** los tokens de una cuenta: sesiones abiertas y retos a
     * medias.
     *
     * **La excepcion a la regla de arriba, y por eso es un metodo aparte.** Existe
     * para retirar el segundo factor (`identity:2fa-reset`, RS-06), que es lo que
     * se hace cuando alguien perdio el telefono — o cuando se sospecha que la
     * cuenta esta en manos de otro. Dejar vivas las sesiones abiertas convertiria
     * ese comando en una molestia para el legitimo dueño y en nada para quien
     * ya estaba dentro: la credencial se retira y el acceso que produjo sigue
     * funcionando hasta doce horas.
     *
     * No distingue por ambito ni por nombre a proposito: si hay que echar a
     * alguien, se le echa de todas partes.
     *
     * **No devuelve cuantas cerro**, aunque el asiento de `audit_log` lo
     * agradeceria: llevarlo hasta alli exigiria un campo nuevo en el evento de
     * dominio `TwoFactorReset`, y el dominio no se toca desde aqui. Anotado como
     * deuda; el hecho —que se retiro el segundo factor, con motivo y autor— ya
     * queda escrito.
     */
    public function revokeAllFor(string $userUuid): void;
}
