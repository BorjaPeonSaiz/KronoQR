<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Response;

use App\Modules\Kiosk\Application\UseCase\ClaimPairing;
use Illuminate\Http\JsonResponse;

/**
 * **La unica respuesta de rechazo de un `claim`** (RS-03, regla dura 17,
 * RF-PD-06).
 *
 * `pairing_id` desconocido, secreto que no coincide, y solicitud caducada o ya
 * consumida: **una sola forma para las tres**, byte a byte. Quien sondea con un
 * identificador inventado no puede distinguir «no existe» de «existe y no es
 * tuya», y esa diferencia es la que convertiria esta ruta publica en un
 * comprobador de `pairing_id` y, peor, en un oraculo con el que afinar la
 * busqueda de un secreto.
 *
 * ## Por que es una clase con los textos fijos y no `ProblemDetails::response()`
 *
 * Misma razon que el `ScanRejectedResponse` del fichaje,
 * y se repite aqui porque es la que se olvida: aquella funcion acepta `detail`
 * libre, y un `detail` libre es exactamente el hueco por el que la causa se filtra
 * una tarde de diagnostico. Aqui no hay ningun parametro que variar — ni siquiera
 * un eco del `pairing_id` que envio el cliente. El contrato lo declara igual:
 * todos los campos de `PairingRejected` tienen un unico valor posible y no admite
 * miembros adicionales.
 *
 * ## Ni `Retry-After` ni pistas de tiempo
 *
 * El suelo de tiempo lo aplica el caso de uso ({@see ClaimPairing}),
 * no esta clase: cuando se llega aqui, la espera ya se ha pagado.
 *
 * **La tablet muestra su propio texto de i18n a partir de `type`.** Nunca `title`
 * ni `detail`, que son texto para quien depura y estan en el idioma del codigo.
 */
final class PairingRejectedResponse
{
    public const string TYPE = 'urn:kronoqr:problem:pairing-rejected';

    public const string TITLE = 'Emparejamiento no valido';

    public const string DETAIL = 'La solicitud de emparejamiento no se ha podido completar.';

    /**
     * @return array{type: string, title: string, status: int, detail: string}
     */
    public static function body(): array
    {
        return [
            'type' => self::TYPE,
            'title' => self::TITLE,
            'status' => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => self::DETAIL,
        ];
    }

    public static function make(): JsonResponse
    {
        return new JsonResponse(
            self::body(),
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            // El tipo de medio es parte del contrato: un cliente que espera
            // `problem+json` y recibe `json` no sabe que hacer con el cuerpo.
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
