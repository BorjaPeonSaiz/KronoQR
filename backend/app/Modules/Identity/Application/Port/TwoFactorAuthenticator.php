<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use SensitiveParameter;

/**
 * El algoritmo del segundo factor (TOTP, RFC 6238), visto por los casos de uso
 * que lo usan (**RS-06**, RF-ID-01).
 *
 * Lo implementa un adaptador sobre `pragmarx/google2fa` (doc 02 §3.1). El puerto
 * existe por lo de siempre —que `Application` no importe una libreria— y por dos
 * razones propias:
 *
 * 1. **El algoritmo es una decision de producto, no de cada caso de uso.** Con
 *    cuantos digitos, con que ventana de tolerancia y con que periodo se calcula
 *    un codigo se decide en un solo sitio, y ese sitio es el adaptador. Si cada
 *    llamante pasara la ventana, bastaria con que uno la pusiera generosa para que
 *    un codigo caducado siguiera valiendo.
 * 2. **Se puede probar sin esperar treinta segundos.** Una prueba de la ventana de
 *    tolerancia o del rechazo de un codigo reutilizado no puede depender del reloj
 *    de la maquina que la ejecuta.
 *
 * **Ningun metodo de este puerto registra nada.** El secreto y el codigo son
 * credenciales: no aparecen en logs, ni en trazas, ni en `audit_log`.
 */
interface TwoFactorAuthenticator
{
    /**
     * Un secreto nuevo en base32, listo para entregarlo una sola vez.
     *
     * La longitud la decide el adaptador: es parte de la fuerza del algoritmo y
     * no una preferencia de quien llama.
     */
    public function generateSecret(): string;

    /**
     * La URI `otpauth://totp/...` que el panel convierte en QR.
     *
     * **La compone el servidor y no el cliente.** Lleva el algoritmo, los digitos
     * y el periodo, que son las mismas decisiones que aplica
     * {@see self::verify()}: repartirlas entre el servidor y tres SPA es como se
     * acaba con un autenticador que genera codigos de seis digitos contra un
     * servidor que espera ocho.
     *
     * @param  string  $account  Etiqueta de la cuenta en el autenticador —el correo de
     *                           gestion—. Es lo que la persona vera en su telefono para
     *                           distinguir esta entrada de las demas.
     */
    public function otpauthUriFor(string $account, #[SensitiveParameter] string $secret): string;

    /**
     * Comprueba un codigo y devuelve **la franja temporal que lo valido**, o
     * `null` si no vale.
     *
     * @param  int|null  $notBeforeSlice  Franja del ultimo codigo aceptado por esta
     *                                    cuenta. Un codigo de esa franja o anterior se
     *                                    rechaza aunque sea matematicamente correcto:
     *                                    **un codigo TOTP vale una sola vez**, y sin esto un
     *                                    codigo interceptado seguiria sirviendo durante el
     *                                    minuto siguiente. Es la unica proteccion contra
     *                                    reenvio que tiene TOTP.
     * @return int|null La franja aceptada, que quien llama debe recordar, o `null` si el
     *                  codigo no vale, esta caducado o ya se uso.
     */
    public function verify(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        ?int $notBeforeSlice,
    ): ?int;
}
