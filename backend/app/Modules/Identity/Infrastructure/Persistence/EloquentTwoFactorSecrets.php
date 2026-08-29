<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * El secreto TOTP sobre las columnas `users.two_factor_*` (RS-06).
 *
 * **Cifrado en reposo por el `cast` `encrypted` del modelo** (`APP_KEY`), no por
 * este adaptador: asi no hay ninguna via —una consulta suelta, una lectura desde
 * otro sitio— por la que el secreto pueda leerse en claro por descuido. Quien
 * consiga una copia de la base de datos del cliente no puede generar los codigos
 * de nadie sin tener ademas la clave de la aplicacion, que vive en el entorno.
 *
 * **El estado «confirmado» es una columna aparte y no un valor centinela.** Un
 * secreto sin confirmar no autoriza nada, y distinguirlos por el contenido del
 * propio secreto —un prefijo, una marca— seria un invento que la primera lectura
 * descuidada convertiria en un alta a medias que sirve para entrar.
 *
 * **La franja del ultimo codigo aceptado es un entero y no una fecha.** Es el
 * indice de la ventana de TOTP, que es exactamente lo que la libreria compara; una
 * fecha obligaria a convertir en cada lectura y abriria la puerta a un error de
 * zona horaria en la unica proteccion contra reenvio que existe aqui.
 *
 * **El secreto entra marcado como parametro sensible.** Sin la marca, cualquier
 * excepcion lanzada por debajo —un fallo del cifrado, un error del driver— dejaria
 * el secreto en claro dentro de la traza que se escribe en el log, y ese log viaja
 * al fabricante en el paquete de diagnostico (ADR-020, regla dura 21). La segunda
 * defensa es `zend.exception_ignore_args=On` en el `php.ini` del producto, que
 * quita **todos** los argumentos de las trazas; se ponen las dos porque la marca
 * documenta y la directiva protege lo que nadie marco.
 */
final readonly class EloquentTwoFactorSecrets implements TwoFactorSecrets
{
    public function activeSecretFor(string $uuid): ?string
    {
        $user = $this->find($uuid);

        if (! $user instanceof User || $user->two_factor_confirmed_at === null) {
            return null;
        }

        return $user->two_factor_secret;
    }

    public function unconfirmedSecretFor(string $uuid): ?string
    {
        $user = $this->find($uuid);

        if (! $user instanceof User || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        return $user->two_factor_secret;
    }

    public function storeUnconfirmedSecret(string $uuid, #[SensitiveParameter] string $secret): void
    {
        $user = $this->find($uuid);

        if (! $user instanceof User) {
            return;
        }

        // Las tres a la vez: un alta nueva empieza sin confirmar y sin arrastrar la
        // franja del secreto anterior, que se calculaba con otra clave y no dice
        // nada de esta.
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_slice = null;
        $user->save();
    }

    public function confirm(string $uuid, DateTimeImmutable $at): void
    {
        $user = $this->find($uuid);

        if (! $user instanceof User) {
            return;
        }

        // A `Carbon` porque es el tipo de la columna en el modelo. El instante
        // sigue siendo el que dio el puerto `Clock`: aqui solo se cambia de clase,
        // no se lee ningun reloj (regla dura 2).
        $user->two_factor_confirmed_at = Carbon::instance($at);
        $user->save();
    }

    public function forget(string $uuid): void
    {
        $user = $this->find($uuid);

        if (! $user instanceof User) {
            return;
        }

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_slice = null;
        $user->save();
    }

    public function lastAcceptedSliceFor(string $uuid): ?int
    {
        return $this->find($uuid)?->two_factor_last_slice;
    }

    public function rememberAcceptedSlice(string $uuid, int $slice): void
    {
        $user = $this->find($uuid);

        if (! $user instanceof User) {
            return;
        }

        $user->two_factor_last_slice = $slice;
        $user->save();
    }

    private function find(string $uuid): ?User
    {
        return User::query()->where('uuid', $uuid)->first();
    }
}
