<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Response;

use Illuminate\Http\JsonResponse;

/**
 * **La unica respuesta de rechazo de un `confirm`** (RF-PD-06, regla dura 17).
 *
 * El codigo no existe, ha caducado o ya se uso: **una sola forma para las tres**.
 *
 * ## Aqui la ruta esta autenticada, asi que la razon es otra
 *
 * En el `claim` —publico— la respuesta unica protege un secreto. A un `admin` no
 * hay que ocultarle nada: lo que hace que las tres causas compartan respuesta es
 * que **tienen exactamente la misma accion siguiente**, que es pedirle a la tablet
 * que muestre otro codigo. Distinguirlas seria un mensaje mas que traducir,
 * mantener y probar sin que nadie hiciera nada distinto al leerlo. Quien si
 * necesita ver la caducidad es la tablet, y la tiene en su cuenta atras.
 *
 * ## Lo que SI se distingue, y esta en otro sitio
 *
 * Que el nombre lo tenga un quiosco activo. Eso no es un problema del codigo
 * —que sigue siendo valido— sino del formulario, y sale como
 * `urn:kronoqr:problem:validation-failed` con `errors.name`: al administrador le
 * cambia lo que tiene que hacer, que es cambiar el nombre y no pedir otro codigo.
 *
 * ## Clase con textos fijos, no `ProblemDetails::response()`
 *
 * Por lo mismo que {@see PairingRejectedResponse}: aquella funcion acepta `detail`
 * libre, y un `detail` libre es el hueco por el que la causa se filtra.
 */
final class PairingCodeRejectedResponse
{
    public const string TYPE = 'urn:kronoqr:problem:pairing-code-rejected';

    public const string TITLE = 'Codigo de emparejamiento no valido';

    public const string DETAIL = 'El codigo no se ha podido confirmar.';

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
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
