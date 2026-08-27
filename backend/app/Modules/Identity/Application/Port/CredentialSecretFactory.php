<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\CredentialSecret;

/**
 * Produce el token aleatorio que se acuña **al imprimir** una credencial
 * (doc 02 §5.1, ADR-034).
 *
 * **Hoy no lo usa ningun caso de uso, y es correcto.** Lo consumira la impresion
 * de la tarea 1.10, que es donde el token nace: la emision ya no lo pide. El
 * puerto y su adaptador se conservan —registrados y probados— porque son la
 * pieza que hace deterministica la prueba de la impresion, no porque sobren.
 *
 * **Es un puerto por la misma razon que `Clock`** (ADR-021, regla dura 2): la
 * aleatoriedad es un efecto, y un dominio que la produce por su cuenta no se
 * puede probar. Con el puerto, una prueba fija el token y comprueba que el
 * payload impreso es exactamente el esperado, caracter a caracter.
 *
 * **El adaptador de produccion usa `random_bytes()` y ningun otro generador.**
 * `rand`, `mt_rand`, `uniqid` y `Str::random` no son criptograficamente seguros:
 * con cualquiera de ellos, quien vea unos cuantos tokens puede predecir el
 * siguiente, y ese es el ataque que los 128 bits del §5.1 existen para cerrar.
 */
interface CredentialSecretFactory
{
    public function next(): CredentialSecret;
}
