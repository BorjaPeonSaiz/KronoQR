<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use DateTimeImmutable;

/**
 * Donde vive el secreto TOTP de una cuenta de gestion (**RS-06**, RF-ID-01).
 *
 * Lo implementa `Identity/Infrastructure/Persistence`, que es donde estan las
 * columnas `users.two_factor_*`. Puerto propio y no metodos mas en `UserAccounts`
 * por una razon de superficie: aquel devuelve el objeto que viaja hacia arriba
 * —{@see AuthenticatedUser}, sin
 * secretos— y este maneja una credencial. Mezclarlos haria que cualquier
 * colaborador que necesita saber el rol de alguien tuviera tambien a mano su
 * secreto.
 *
 * ## Dos estados y no uno
 *
 * Un secreto **sin confirmar** existe entre `/auth/2fa/enrol` y
 * `/auth/2fa/confirm` y **no autoriza nada**: si alguien se equivoca al escanear
 * el QR, repite el alta y el anterior se pierde sin consecuencias. Un secreto
 * **confirmado** es el que verifica los accesos. Un solo campo «secreto» obligaria
 * a decidir en cada lectura cual de los dos es, y la primera vez que alguien se
 * equivoque en esa decision, un alta a medias servira para entrar.
 *
 * ## La franja del ultimo codigo aceptado
 *
 * TOTP no tiene proteccion contra reenvio por si mismo: un codigo vale durante
 * toda su franja. Recordar la ultima aceptada es lo que hace que un codigo
 * interceptado —por encima del hombro, en una captura de pantalla— no sirva dos
 * veces. Se guarda la franja, no el codigo: el codigo es la credencial.
 */
interface TwoFactorSecrets
{
    /**
     * El secreto **confirmado** de la cuenta, o `null` si no tiene ninguno activo.
     */
    public function activeSecretFor(string $uuid): ?string;

    /**
     * El secreto emitido y **todavia sin confirmar**, o `null`.
     */
    public function unconfirmedSecretFor(string $uuid): ?string;

    /**
     * Guarda un secreto nuevo sin confirmar, sustituyendo cualquier alta anterior
     * que no llegara a completarse.
     *
     * **Cifrado en reposo.** La implementacion lo guarda con el cifrado de la
     * instalacion (`APP_KEY`), nunca en claro: quien pueda leer una copia de la
     * base de datos no debe poder generar los codigos de nadie.
     */
    public function storeUnconfirmedSecret(string $uuid, string $secret): void;

    /**
     * Da por bueno el secreto sin confirmar de la cuenta y lo deja activo.
     *
     * Recibe el instante ya resuelto por el puerto `Clock` (ADR-021, regla dura
     * 2): ni el caso de uso ni el adaptador preguntan la hora al sistema.
     */
    public function confirm(string $uuid, DateTimeImmutable $at): void;

    /**
     * Retira el segundo factor: la cuenta vuelve a tener que darlo de alta.
     *
     * Es la respuesta a un telefono perdido. **Deja asiento en `audit_log`** —lo
     * publica el caso de uso, no este puerto— porque retirarselo a alguien deja su
     * cuenta a un paso de su contrasena.
     */
    public function forget(string $uuid): void;

    /**
     * La franja temporal del ultimo codigo aceptado, o `null` si nunca hubo uno.
     */
    public function lastAcceptedSliceFor(string $uuid): ?int;

    /**
     * Recuerda la franja del codigo que se acaba de aceptar.
     */
    public function rememberAcceptedSlice(string $uuid, int $slice): void;
}
