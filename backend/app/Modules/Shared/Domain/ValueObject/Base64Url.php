<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * base64url sin relleno (RFC 4648 §5), que es como viaja **todo el material
 * criptografico de este producto**.
 *
 * ## Por que no vale `base64_encode` a secas
 *
 * Porque su alfabeto incluye `+` y `/`, y esos dos caracteres se rompen en los
 * tres sitios por los que pasan estos valores: una URL, un nombre de fichero y un
 * QR impreso. El `=` de relleno sobra por lo mismo y ademas alarga el codigo. La
 * variante `-`/`_` sin relleno es la que usan JWT, WebAuthn y el resto del
 * ecosistema, asi que ademas es la que espera cualquiera que mire un valor.
 *
 * ## Por que una clase y no cuatro llamadas repetidas
 *
 * Porque estaba escrito cuatro veces —el token de la credencial, la clave de
 * firma del QR, la clave de licencia y el secreto de emparejamiento— y las cuatro
 * copias tenian que decir exactamente lo mismo para que un valor emitido por una
 * lo pudiera leer otra. Una de ellas olvidando el `rtrim` no rompe nada visible el
 * dia que se escribe: rompe el dia que alguien intenta validar una tarjeta emitida
 * con la version anterior.
 *
 * ## `decode` es estricto
 *
 * `base64_decode(..., strict: true)` devuelve `false` ante un caracter que no
 * pertenece al alfabeto, en lugar de ignorarlo en silencio. Aqui eso importa: un
 * token con basura dentro tiene que fallar, no decodificarse a otra cosa. Se
 * devuelve `null` para que quien llama decida, sin excepciones en un camino que
 * suele ser de rechazo.
 *
 * PHP puro y sin dependencias: vive en `Domain/ValueObject` porque es vocabulario
 * compartido, no infraestructura, y lo usan modulos que no pueden importarse
 * entre si (ADR-021).
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return string|null `null` si la cadena no es base64url valida.
     */
    public static function decode(string $encoded): ?string
    {
        // El relleno se repone antes de decodificar: `base64_decode` estricto lo
        // exige, y quitarlo al codificar es justo lo que hace esta variante.
        $padded = strtr($encoded, '-_', '+/');
        $remainder = \strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
