<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\QrKeyring;

/**
 * De donde salen las claves HMAC de las credenciales (doc 02 §5.3 y §7.7).
 *
 * **Existe para que el dominio no pregunte por la configuracion.** Es la misma
 * regla que con los umbrales legales (regla dura 14) y con el reloj (regla dura
 * 2): el objeto de dominio recibe la clave ya resuelta y no sabe si vino de una
 * variable de entorno, de un fichero o de un gestor de secretos. Cambiar de
 * origen —que es lo que hara un cliente con HSM— no toca ni una linea del
 * dominio.
 *
 * **Las claves son configuracion, nunca codigo** (regla dura 13, ADR-017): el
 * instalador las genera en el servidor del cliente y no las transmite.
 */
interface QrKeyProvider
{
    /**
     * Las claves vigentes: la actual y, durante una rotacion, la anterior.
     *
     * **No lanza si no hay ninguna.** Devuelve un llavero vacio: sin clave, el
     * fichaje rechaza de forma generica —que es lo correcto— en lugar de
     * responder con un error 500 que ademas delataria el problema. Quien si
     * falla, y con ruido, es la emision.
     */
    public function keyring(): QrKeyring;
}
