<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\TwoFactorAuthenticator;
use PragmaRX\Google2FA\Exceptions\Google2FAException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) sobre `pragmarx/google2fa` (doc 02 §3.1, RS-06).
 *
 * **Las tres decisiones del algoritmo se toman aqui y en ningun otro sitio**, que
 * es para lo que existe el puerto:
 *
 * - **Seis digitos y periodo de 30 s**, que son los valores por omision de la
 *   libreria y lo que espera cualquier autenticador del mercado. Cambiarlos
 *   obligaria a que la persona reinstalara su entrada, y no compran nada.
 * - **Ventana de tolerancia**, en franjas de 30 s a cada lado. Existe porque el
 *   reloj de un telefono se desvia: sin ella, un movil treinta segundos adelantado
 *   no puede entrar nunca y el sintoma —«a veces me deja y a veces no»— es de los
 *   mas caros de diagnosticar. Con la ventana de serie, la vida util de un codigo
 *   son unos noventa segundos, que es el compromiso estandar. Es configuracion
 *   (regla dura 13): un cliente con relojes sincronizados puede bajarla a cero.
 * - **La longitud del secreto**, tambien configurable, con 32 caracteres base32
 *   de serie: mas que los 16 del minimo de la libreria, porque el coste es nulo y
 *   el secreto vive años.
 *
 * **Ni el secreto ni el codigo salen de aqui hacia ningun log.** Las excepciones
 * de la libreria —secreto corto, caracteres invalidos— se traducen a «no vale»:
 * dejarlas subir convertiria un secreto corrupto en un `500` que ademas llevaria
 * el secreto en la traza, y para quien intenta entrar el desenlace es el mismo.
 *
 * **El reloj lo pone la libreria y no el puerto `Clock`, y es la excepcion que
 * confirma la regla.** La regla dura 2 protege al **dominio**; esto es un
 * adaptador de infraestructura cuyo trabajo es hablar con un algoritmo definido
 * sobre el tiempo Unix. Lo que la prueba necesita fijar —la franja— entra y sale
 * por el puerto como un entero, asi que el caso de uso sigue siendo comprobable
 * sin esperar treinta segundos.
 */
final readonly class Google2faAuthenticator implements TwoFactorAuthenticator
{
    public function __construct(
        private Google2FA $google2fa,
        private string $issuer,
        private int $secretLength,
        private int $window,
    ) {}

    public function generateSecret(): string
    {
        try {
            return $this->google2fa->generateSecretKey($this->secretLength);
        } catch (Google2FAException $exception) {
            // Solo puede ocurrir si la configuracion pide una longitud imposible,
            // que es un fallo de instalacion y no del usuario.
            throw new \RuntimeException('No se ha podido generar el secreto del segundo factor.', 0, $exception);
        }
    }

    public function otpauthUriFor(string $account, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($this->issuer, $account, $secret);
    }

    public function verify(string $secret, string $code, ?int $notBeforeSlice): ?int
    {
        try {
            // `verifyKeyNewer` es lo que hace que un codigo valga UNA vez: rechaza
            // el que caiga en la franja del ultimo aceptado o antes, aunque sea
            // matematicamente correcto.
            //
            // **`0` y no `null` cuando no hay franja previa, y no es cosmetica.**
            // Con `null`, la libreria devuelve `true` en lugar de la franja —lo
            // hace en `findValidOTP`—, y entonces no habria numero que recordar:
            // el primer codigo de una cuenta quedaria sin marcar y se podria
            // reenviar. Con `0`, la ventana de busqueda es la misma —arranca en
            // `ahora - window`— y la respuesta siempre es un entero.
            $slice = $this->google2fa->verifyKeyNewer($secret, $code, $notBeforeSlice ?? 0, $this->window);
        } catch (Google2FAException) {
            // Secreto corrupto o codigo con caracteres imposibles. Para quien
            // intenta entrar es lo mismo que un codigo equivocado, y el detalle no
            // puede acabar en un log con el secreto dentro (RS-03).
            return null;
        }

        return \is_int($slice) ? $slice : null;
    }
}
