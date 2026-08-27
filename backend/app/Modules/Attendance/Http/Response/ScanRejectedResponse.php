<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Response;

use Illuminate\Http\JsonResponse;

/**
 * **La unica respuesta de rechazo de un escaneo** (RS-03, regla dura 17,
 * doc 02 §5.2 punto 6).
 *
 * Prefijo que no es `FH1`, clave de firma desconocida, firma que no valida,
 * credencial revocada, empleado que no esta activo: **una sola forma para las
 * cinco**, byte a byte. Un atacante con una tarjeta cualquiera no puede
 * distinguir «este codigo no existe» de «este codigo existe y esta revocado», y
 * esa diferencia es la que convertiria el endpoint en un oraculo con el que
 * sondear que credenciales hay emitidas.
 *
 * **Por que es una clase con los textos fijos y no una llamada a
 * `ProblemDetails::response()`.** Porque esa funcion acepta `detail` libre, y un
 * `detail` libre es exactamente el hueco por el que la causa se filtra una tarde
 * de diagnostico. Aqui no hay parametro que variar: lo unico que cambia entre
 * dos respuestas es el `scan_id`, que es un eco de lo que envio el cliente y no
 * dice nada que el cliente no supiera ya. `ProblemDetails` lo declara por
 * escrito: *«lo que NO se hace aqui: el rechazo de escaneo»*.
 *
 * **El quiosco muestra su propio texto de i18n a partir de `type`.** Nunca
 * `title` ni `detail`, que son texto para quien depura y estan en el idioma del
 * codigo.
 *
 * **Lo que si existe, y esta en otro sitio:** la causa concreta se escribe en
 * `scan_events.result`, en el log estructurado del servidor y en la metrica
 * `scans_total{device,result}`, que `/metrics` sirve solo a la red interna.
 */
final class ScanRejectedResponse
{
    public const string TYPE = 'urn:kronoqr:problem:scan-rejected';

    public const string TITLE = 'Escaneo no valido';

    public const string DETAIL = 'El escaneo no se ha podido registrar.';

    /**
     * El cuerpo, sin envolver en una respuesta HTTP.
     *
     * Existe por el lote (`POST /api/v1/scan/batch`, tarea 1.7), donde el rechazo
     * de un elemento no es la respuesta de la peticion sino una entrada dentro de
     * ella. Se comparte el cuerpo **y no se copia** para que siga siendo cierto
     * que existe una sola forma de rechazo: si el lote construyera la suya, dos
     * sitios podrian divergir y uno de los dos acabaria diciendo de mas.
     *
     * @return array{type: string, title: string, status: int, detail: string, scan_id: string}
     */
    public static function body(string $scanId): array
    {
        return [
            'type' => self::TYPE,
            'title' => self::TITLE,
            'status' => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => self::DETAIL,
            'scan_id' => $scanId,
        ];
    }

    public static function for(string $scanId): JsonResponse
    {
        return new JsonResponse(
            self::body($scanId),
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            // El tipo de medio es parte del contrato: un cliente que espera
            // `problem+json` y recibe `json` no sabe que hacer con el cuerpo.
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
