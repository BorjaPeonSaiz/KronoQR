<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;

/**
 * **El rastro de la autenticacion** (OWASP A09, RS-05, RS-07, RS-12, regla dura
 * 6).
 *
 * ## Por que existe
 *
 * Antes de este puerto, un ataque de credenciales contra el panel o contra el
 * portal no dejaba nada consultable: `audit_log` no tenia ni una accion de
 * autenticacion y los rechazos morian en un `401` generico. Se podia probar
 * contrasenas toda la noche y, a la manana siguiente, no habia ninguna consulta
 * que respondiera «¿paso algo?».
 *
 * ## Que hecho va a que almacen lo decide ADR-039
 *
 * `docs/adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md`: el exito y el
 * cierre en `audit_log` solo en el panel, la apertura de un bloqueo en los tres
 * canales y despues de responder, y el fallo **nunca** en `audit_log` sino en el
 * log tecnico y en `kronoqr_auth_attempts_total`. Quien llama a este puerto no
 * elige: describe el hecho y el adaptador lo reparte.
 *
 * ## Lo que no puede hacer una implementacion de este puerto
 *
 * 1. **Cambiar el tiempo de respuesta segun si el sujeto existe** (RS-03, regla
 *    dura 17). Por eso {@see self::failed()} recibe el `subjectUuid` ya resuelto
 *    —que es `null` en todos los caminos donde el servidor no lo tiene a mano— y
 *    no puede ir a buscarlo: una consulta que solo ocurre cuando la cuenta existe
 *    es un oraculo medible con un cronometro.
 * 2. **Registrar el correo, el codigo de empleado, el nombre, el PIN o la
 *    contrasena** (regla dura 21). Se identifica a las personas por su UUID; en
 *    el log tecnico, los origenes por un hash con clave de la instalacion, porque
 *    ese log acaba en el paquete de diagnostico que viaja al fabricante
 *    (ADR-020).
 * 3. **Romper la accion si el rastro falla.** El log y el contador son
 *    observabilidad y no pueden tumbar un acceso legitimo; el asiento del
 *    bloqueo tampoco, por lo que dice ADR-039 al sacarlo del camino de la
 *    respuesta.
 */
interface AuthenticationJournal
{
    /**
     * Alguien ha entrado.
     *
     * Deja asiento en `audit_log` si el canal lo permite
     * ({@see AuthChannel::sessionEventsAreAudited()}), escribe
     * `auth.login_succeeded` en el log tecnico y cuenta el intento como `success`
     * en los tres canales.
     *
     * @param  string  $subjectUuid  UUID publico de la cuenta de gestion o del empleado.
     *                               Nunca su correo, su codigo ni su nombre.
     */
    public function succeeded(AuthChannel $channel, string $subjectUuid): void;

    /**
     * Alguien no ha entrado. **No escribe en `audit_log`** (ADR-039).
     *
     * @param  string|null  $subjectUuid  Solo si el servidor ya lo tiene delante sin ir a
     *                                    buscarlo. Es `null` en la mayoria de los caminos, y
     *                                    eso no es una carencia: los verificadores devuelven
     *                                    un rechazo sin identidad justamente para que no
     *                                    exista ninguna rama que distinga «no existe» de «no
     *                                    coincide» (RS-03).
     */
    public function failed(
        AuthChannel $channel,
        ?string $subjectUuid,
        AuthFailureReason $reason,
    ): void;

    /**
     * Se acaba de abrir un bloqueo por intentos fallidos (RS-12).
     *
     * Deja asiento en `audit_log` **en los tres canales y despues de responder**
     * (ADR-039): el hecho lo decide el servidor, asi que el actor es el sistema
     * —o el quiosco desde el que se tecleaba— y no hace falta un tipo de actor
     * que hoy no existe; y como es el unico asiento que provoca quien ataca, no
     * puede pagarse dentro del camino del rechazo.
     *
     * Se llama **una vez por bloqueo abierto**, en el flanco: no en cada intento
     * posterior mientras el bloqueo sigue activo. Repetirlo llenaria la cadena
     * de hash con la insistencia de quien ataca.
     *
     * @param  string|null  $subjectUuid  El sujeto bloqueado, si se conoce. En el panel es
     *                                    `null`: el bloqueo cuenta fallos por «correo mas
     *                                    origen» y el servidor no sabe —ni debe averiguar—
     *                                    si ese correo corresponde a una cuenta real.
     * @param  int  $lockSeconds  Duracion del bloqueo que se acaba de abrir. Es lo que
     *                            distingue el primer escalon del tercero al leer el trail.
     */
    public function lockoutStarted(
        AuthChannel $channel,
        ?string $subjectUuid,
        int $lockSeconds,
    ): void;

    /**
     * Alguien ha cerrado su sesion y su token queda revocado.
     *
     * @param  string|null  $subjectUuid  `null` cuando el token no es de una cuenta de
     *                                    gestion; ver {@see AuthChannel::sessionEventsAreAudited()}.
     */
    public function loggedOut(AuthChannel $channel, ?string $subjectUuid): void;
}
