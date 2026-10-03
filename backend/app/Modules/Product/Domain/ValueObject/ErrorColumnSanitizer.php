<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\ClientErrorCode;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/**
 * Las columnas de `error_events` que no son el mensaje ni el contexto:
 * `app_version`, `file`, `exception_class` y `code` (ADR-048 decision 5, H7;
 * RF-PD-15, RL-19, regla dura 21).
 *
 * Tambien son texto que llega de fuera —`app_version` lo escribe el cliente, y
 * `file` o `exception_class` pueden traer una ruta o una clase anonima con lo
 * que sea dentro—, asi que pasan por la misma lista blanca que el mensaje
 * **salvo cuando tienen la forma exacta que el producto produce**, que es lo
 * que las deja utiles.
 *
 * Lo usan las dos puertas: `RecordErrorEvent` al escribir y el colector del
 * paquete al leer. Las cuatro funciones son idempotentes.
 */
final readonly class ErrorColumnSanitizer
{
    /** Anchura de `error_events.app_version`. */
    public const int MAX_APP_VERSION = 32;

    /** Anchura de `error_events.exception_class` y de `error_events.file`. */
    public const int MAX_CLASS = 255;

    /** Anchura de `error_events.code`. */
    public const int MAX_CODE = 80;

    /** El codigo del grupo de desbordamiento, que no es ni SQLSTATE ni entero. */
    public const string OVERFLOW_CODE = 'overflow';

    /** Una ruta relativa del proyecto, como la escribe el enganche del servidor. */
    private const string PRODUCT_FILE = '#^(?:app|bootstrap|config|database|routes|vendor)/[A-Za-z0-9_./\-]+\.php$#';

    /** Un nombre de clase cualificado. */
    private const string CLASS_NAME = '/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/';

    /** Un `SQLSTATE` (que siempre lleva alguna cifra) o un entero corto: lo unico que se guarda como codigo de un error del servidor. */
    private const string SERVER_CODE = '/^(?:(?=[0-9A-Z]*\d)[0-9A-Z]{5}|\d{1,6})$/';

    public static function appVersion(string $version): string
    {
        return self::filtered($version, self::MAX_APP_VERSION);
    }

    public static function file(?string $file): ?string
    {
        if ($file === null || preg_match(self::PRODUCT_FILE, $file) === 1) {
            return $file === null ? null : mb_substr($file, 0, self::MAX_CLASS);
        }

        return self::filtered($file, self::MAX_CLASS);
    }

    /**
     * La clase de la excepcion si es un nombre cualificado; si no —una
     * `class@anonymous` con su ruta—, por la lista blanca.
     */
    public static function exceptionClass(?string $class): ?string
    {
        if ($class === null || preg_match(self::CLASS_NAME, $class) === 1) {
            return $class === null ? null : mb_substr($class, 0, self::MAX_CLASS);
        }

        return self::filtered($class, self::MAX_CLASS);
    }

    /**
     * El codigo de un error.
     *
     * - **Del servidor**: solo un `SQLSTATE` o un entero corto (o el del grupo
     *   de desbordamiento). Cualquier otra cosa se guarda como nulo: un codigo
     *   de excepcion de una libreria puede ser una cadena cualquiera.
     * - **De un cliente**: el del catalogo cerrado, que la peticion ya valida.
     *   Si llegara otro por un camino que no valida, por la lista blanca.
     */
    public static function code(ErrorSource $source, ?string $code): ?string
    {
        if ($code === null || $code === self::OVERFLOW_CODE) {
            return $code;
        }

        if (! $source->isClient()) {
            return preg_match(self::SERVER_CODE, $code) === 1 ? $code : null;
        }

        return ClientErrorCode::isKnown($source, $code) ? $code : self::filtered($code, self::MAX_CODE);
    }

    /**
     * **Una fila entera, saneada**: lo que guarda `RecordErrorEvent`, lo que
     * reescribe `ResanitizeErrorHistory` y lo que vuelve a sanear el colector
     * del paquete. Un solo sitio, para que los tres no puedan divergir.
     *
     * @param  array<array-key, mixed>  $context
     */
    public static function row(
        ErrorSource $source,
        string $message,
        array $context,
        ?string $code,
        ?string $exceptionClass,
        ?string $file,
        string $appVersion,
    ): SanitizedErrorRow {
        $cleanCode = self::code($source, $code);

        return new SanitizedErrorRow(
            message: ErrorMessageSanitizer::sanitize($message),
            context: ErrorContextAllowlist::apply($context),
            code: $cleanCode === null ? null : mb_substr($cleanCode, 0, self::MAX_CODE),
            exceptionClass: self::exceptionClass($exceptionClass),
            file: self::file($file),
            appVersion: self::appVersion($appVersion),
        );
    }

    /**
     * La lista blanca con techo, sin texto de relleno ni indicador de corte:
     * una columna vacia se queda vacia. El recorte es el de
     * {@see ErrorMessageSanitizer::bounded()}, que vuelve a filtrar lo cortado.
     */
    private static function filtered(string $text, int $limit): string
    {
        return ErrorMessageSanitizer::bounded($text, $limit, '');
    }
}
