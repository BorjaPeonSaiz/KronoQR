<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Response;

use App\Modules\Identity\Application\UseCase\PrintedCards;
use Symfony\Component\HttpFoundation\Response;

/**
 * La respuesta que **transmite** un PDF de tarjetas y no lo deja en ningun sitio.
 *
 * **Un solo lugar donde se construye**, para que las cabeceras que importan no
 * dependan de que cada controlador se acuerde de ponerlas:
 *
 * - **`Cache-Control: no-store`.** El cuerpo permite fichar en nombre de otras
 *   personas. Sin esto, un proxy corporativo o el disco del navegador se quedan
 *   con una copia del instrumento al portador.
 * - **`Content-Disposition: attachment`** con un nombre de fichero que **no lleva
 *   ningun nombre de persona** (regla dura 21): ese nombre acaba en el historial
 *   de descargas.
 * - **`X-Kronoqr-Printed-Count`**, porque el cuerpo es binario y el panel
 *   necesita poder decir «se han impreso 40» sin abrir el PDF.
 *
 * **`204` cuando no habia nada pendiente**, y no un `200` con un PDF en blanco.
 * Es la forma que toma la idempotencia del lote (ADR-034): la segunda llamada no
 * encuentra nada. Un PDF de cero paginas obligaria al cliente a abrirlo para
 * enterarse.
 */
final readonly class PrintedCardsResponse
{
    public static function of(PrintedCards $printed): Response
    {
        if ($printed->isEmpty()) {
            return new Response(status: Response::HTTP_NO_CONTENT, headers: [
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response($printed->pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$printed->fileName().'"',
            'Cache-Control' => 'no-store',
            'X-Kronoqr-Printed-Count' => (string) $printed->count(),
        ]);
    }
}
