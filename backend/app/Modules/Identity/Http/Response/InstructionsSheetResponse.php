<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Response;

use App\Modules\Identity\Application\UseCase\InstructionsSheet;
use Symfony\Component\HttpFoundation\Response;

/**
 * La respuesta que **transmite** la hoja de instrucciones del empleado (tarea
 * 5.11b, RL-05).
 *
 * Hermana de {@see PrintedCardsResponse} y con las mismas cabeceras, por motivos
 * distintos que conviene no confundir:
 *
 * - **`Cache-Control: no-store, private`.** Aqui el cuerpo **no** es un
 *   instrumento al portador —no lleva ningun secreto ni ningun dato de persona—,
 *   asi que el motivo es otro: la hoja lleva la marca de la instalacion y la
 *   direccion del portal **vigentes en el momento**, y un proxy que sirviera la
 *   de ayer haria que RRHH entregase cuarenta hojas con una direccion que ya no
 *   responde. El contrato lo declara asi.
 * - **`Content-Disposition: attachment`** con un nombre que lleva el idioma y
 *   nada mas: `hoja-empleado-es.pdf`. No hay nombre de persona que evitar —no lo
 *   hay en el documento— pero el nombre acaba en el historial de descargas y
 *   tiene que distinguir las dos versiones sin abrirlas.
 *
 * **Sin `X-Kronoqr-Printed-Count` y sin `204`**: no hay lote, no hay nada
 * pendiente y no hay idempotencia que expresar. Siempre hay hoja.
 */
final readonly class InstructionsSheetResponse
{
    public static function of(InstructionsSheet $sheet): Response
    {
        return new Response($sheet->pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$sheet->fileName().'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
