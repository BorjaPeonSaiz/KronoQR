<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use RuntimeException;

/**
 * La purga se ha pedido sin la frase de confirmacion, o con otra (RF-PR-03).
 *
 * **El mensaje no dice cual era la buena.** Si la dijera, `--confirm=loquesea`
 * seria un oraculo que la imprime y la puerta dejaria de serlo: quien quiera
 * ejecutar la purga tiene que pasar por el `--dry-run`, leer lo que se va a
 * llevar y copiar la frase de ese informe. La confirmacion no protege de un
 * atacante -quien lanza el comando ya esta dentro del servidor-, protege de
 * ejecutar a ciegas la unica operacion del sistema que borra datos
 * (regla dura 5).
 */
final class RetentionNotConfirmed extends RuntimeException
{
    public static function withoutPhrase(): self
    {
        return new self(
            'La purga real exige confirmacion explicita del responsable (RF-PR-03). '
            .'Lanza primero «php artisan compliance:apply-retention --dry-run», revisa el informe y '
            .'vuelve con la frase que imprime:  --confirm=PURGAR-...'
        );
    }

    public static function withWrongPhrase(): self
    {
        return new self(
            'La frase de confirmacion no corresponde a lo que se purgaria ahora. '
            .'Cambia cuando cambia el corte o el perfil de cumplimiento, para que no se pueda ejecutar un '
            .'informe caducado: vuelve a lanzar «--dry-run» y usa la frase del informe nuevo.'
        );
    }
}
