<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * El motor de composicion de PDF no esta disponible en esta instalacion
 * (**RF-IN-04**).
 *
 * ## Por que no es un `500`
 *
 * Un `500` dice «algo se ha roto y no sabemos que». Aqui se sabe exactamente
 * que: falta Chromium, o esta ahi y no arranca. La peticion es correcta, el
 * informe existe, y la salida esta escrita —descargarlo en CSV o en XLSX, que no
 * pasan por el navegador—. Eso es un `503` con un `problem+json` que dice que
 * hacer, no un error opaco que manda a alguien a abrir una incidencia.
 *
 * Es tambien la unica forma de que el producto se pueda instalar en un servidor
 * sin Chromium sin perder la exportacion: **degrada un formato, no la
 * funcionalidad** (mismo criterio que la regla dura 15 con la licencia).
 *
 * ## El `detail` va al cliente, y aqui si puede
 *
 * En el camino de fichaje los rechazos son genericos y de tiempo constante
 * (regla dura 17), pero eso protege de un oraculo sobre credenciales ajenas.
 * Esto es un estado de la instalacion que ve una cuenta de gestion ya
 * autenticada: esconderlo solo conseguiria que quien administra el servidor
 * tuviera que leer los logs para enterarse de que le falta un paquete.
 *
 * **Sin datos personales** (regla dura 21): lo que se cuenta es el motor, no el
 * informe.
 */
final class ReportRenderingUnavailable extends RuntimeException
{
    public static function engineFailed(Throwable $cause): self
    {
        return new self(
            'El motor de composicion de PDF no ha podido generar el documento. '
            .'Comprueba que Chromium esta instalado y es ejecutable por el usuario de la aplicacion.',
            previous: $cause,
        );
    }
}
