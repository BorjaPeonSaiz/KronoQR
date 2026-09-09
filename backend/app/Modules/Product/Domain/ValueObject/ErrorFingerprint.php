<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/**
 * La huella que agrupa: `sha256` de origen, clase, punto de fallo y mensaje
 * normalizado (RF-PD-15, decision 4 de la ficha 5.12).
 *
 * ## Por que existe la agrupacion
 *
 * *«Un fallo en el endpoint de fichaje durante un cambio de turno genera
 * cientos de errores identicos. Sin agrupacion, la tabla se llena de ruido y el
 * error importante queda enterrado»* (doc 02 §8.2.1). Un fallo que se repite mil
 * veces es **una fila con `occurrences = 1000`**, no mil filas.
 *
 * ## Que entra en la huella, y por que cada cosa
 *
 * - **El origen** (`api`, `worker`, `kiosk`…). El mismo mensaje desde la API y
 *   desde la cola son dos problemas distintos: uno lo esta viendo alguien y el
 *   otro no.
 * - **La clase de la excepcion.** Es lo que distingue un fallo de conexion de
 *   uno de permisos aunque el mensaje se parezca.
 * - **El punto de fallo** (`fichero:linea`). Dos sitios que lanzan la misma
 *   excepcion son dos fallos, y arreglar uno no arregla el otro.
 * - **El mensaje normalizado** ({@see ErrorMessageNormalizer}), que es lo que
 *   distingue dos usos distintos de la misma linea.
 *
 * ## Los errores de cliente no tienen `fichero:linea`, y no es un olvido
 *
 * **El `stack` del navegador nunca viaja** (contrato, esquema
 * `ClientErrorReport`): una URL de bundle con un uuid dentro correlaciona a una
 * persona, y una traza minificada no le dice nada a nadie. Asi que en un cliente
 * la huella es `origen | codigo | mensaje normalizado`, donde el **codigo** hace
 * el trabajo que en el servidor hacen la clase y la linea: viene de un catalogo
 * cerrado (`kiosk.camera.unavailable`, `web.vue_error`) y es estable entre
 * versiones, que es justo lo que un numero de linea no es.
 *
 * ## El separador es `|` y los huecos van explicitos
 *
 * Sin separador, `origen=api` + `clase=Foo` seria indistinguible de
 * `origen=apiF` + `clase=oo`. Y una parte ausente se escribe como cadena vacia
 * entre dos barras en vez de omitirse, para que el numero de campos sea siempre
 * el mismo: dos huellas de distinta aridad podrian colisionar por construccion.
 *
 * ## Se sanea ANTES de normalizar
 *
 * Quien llama pasa el mensaje ya saneado. Es deliberado: si la huella dependiera
 * del texto crudo, dos apariciones del mismo fallo con nombres de persona
 * distintos dentro serian dos grupos, y la agrupacion —que es el motivo de esta
 * tabla— dejaria de funcionar precisamente en los mensajes que interpolan
 * valores.
 *
 * ## Dominio puro
 *
 * `hash('sha256', …)` es una funcion del propio PHP, no una libreria de
 * infraestructura: no hay estado, no hay E/S y se prueba sin base de datos.
 */
final readonly class ErrorFingerprint
{
    /** 64 hexadecimales, los de `sha256`. Es lo que declara la columna. */
    public const int LENGTH = 64;

    private function __construct(public string $value) {}

    /**
     * La huella de un error del **servidor**: `origen | clase | fichero:linea |
     * mensaje normalizado`.
     *
     * @param  string  $sanitizedMessage  Ya pasado por {@see ErrorMessageSanitizer}.
     */
    public static function forServer(
        ErrorSource $source,
        ?string $exceptionClass,
        ?string $file,
        ?int $line,
        string $sanitizedMessage,
    ): self {
        $location = $file === null
            ? ''
            : $file.':'.($line ?? 0);

        return self::of([
            $source->value,
            $exceptionClass ?? '',
            $location,
            ErrorMessageNormalizer::normalize($sanitizedMessage),
        ]);
    }

    /**
     * La huella de un error de **cliente**: `origen | codigo | mensaje
     * normalizado`. Ver el docblock de la clase.
     *
     * @param  string  $sanitizedMessage  Ya pasado por {@see ErrorMessageSanitizer}.
     */
    public static function forClient(ErrorSource $source, string $code, string $sanitizedMessage): self
    {
        return self::of([
            $source->value,
            $code,
            ErrorMessageNormalizer::normalize($sanitizedMessage),
        ]);
    }

    /**
     * La huella **fija** del grupo de desbordamiento de un origen (decision 14).
     *
     * Cuando un origen alcanza el techo de grupos abiertos
     * (`PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE`), las apariciones de huellas
     * nuevas se cuentan en este grupo en lugar de crear fila. Que la huella sea
     * fija por origen es lo que garantiza que el desbordamiento **no pueda
     * desbordar a su vez**: por muchas variantes distintas que lleguen, todas
     * caen en la misma fila y lo unico que sube es `occurrences`.
     *
     * No lleva mensaje dentro a proposito: si dependiera del texto, el grupo que
     * existe para contener la entropia la reintroduciria.
     */
    public static function overflowFor(ErrorSource $source): self
    {
        return self::of(['overflow', $source->value]);
    }

    /**
     * Una huella ya calculada, para poder reconstruir una fila leida de la base
     * de datos sin volver a hashear nada.
     */
    public static function fromHex(string $value): self
    {
        return new self($value);
    }

    /**
     * @param  list<string>  $parts
     */
    private static function of(array $parts): self
    {
        return new self(hash('sha256', implode('|', $parts)));
    }
}
