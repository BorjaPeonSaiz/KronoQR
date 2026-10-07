<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * Genera las contrasenas **temporales** de las cuentas de gestion (RF-ID-10,
 * RF-ID-01).
 *
 * **Generadas y no elegidas**: quien las fija no las va a usar, y una
 * contrasena pensada por un tecnico acaba siendo la misma en las cuatro
 * instalaciones que atiende. Las usan el alta y el restablecimiento, por API y
 * por consola, y por eso el generador es un puerto y no un metodo privado de un
 * comando.
 *
 * El adaptador garantiza, **por construccion** y no por suerte:
 *
 * - longitud igual al mayor entre 20 y `$minLength`;
 * - una letra minuscula, una mayuscula, una cifra y un simbolo como minimo, que
 *   es la politica de robustez de RF-ID-01: la temporal la cumple sin que nadie
 *   la compruebe;
 * - ningun caracter que se confunda al leerlo de una pantalla y teclearlo
 *   (`l`, `I`, `O`, `0`, `1`): se entrega de viva voz o en papel;
 * - un generador criptograficamente seguro.
 */
interface TemporaryPasswordGenerator
{
    /**
     * @param  int  $minLength  El minimo de la politica de la instalacion
     *                          (`IDENTITY_PASSWORD_MIN_LENGTH`). Si es mayor que 20,
     *                          manda el.
     */
    public function generate(int $minLength): string;
}
