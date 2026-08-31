<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\SettingKey;

/**
 * El valor no cumple lo que su clave declara (RF-PD-01, ADR-017).
 *
 * ADR-017 lo anticipa entre sus consecuencias: *«aparece un modo de fallo nuevo:
 * la configuracion incorrecta. Un umbral mal puesto produce alertas de
 * cumplimiento erroneas, asi que los valores llevan validacion y valores de
 * serie sensatos»*. Esa validacion es esta, y vive junto a la definicion de la
 * clave para que no exista una segunda copia en el FormRequest que se olvide de
 * actualizar.
 *
 * ## El mensaje es tecnico y en ingles; el texto de usuario se traduce fuera
 *
 * `getMessage()` es para el log y para quien lee una traza, asi que va en ingles
 * como el resto del codigo (doc 02 §3.5). Lo que ve una persona sale de
 * {@see self::$translationKey} y {@see self::$parameters}, que el borde HTTP
 * resuelve con `__()` en el idioma negociado por `NegotiateLocale`. Antes el
 * `render()` volcaba `getMessage()` directamente, y eso metia literales
 * castellanos dentro de `Domain/` y le respondia en castellano a un panel puesto
 * en ingles.
 *
 * ## Dos usos, y solo uno es un error del cliente
 *
 * Al **escribir** es un `422`: hay un campo que corregir. Al **leer** no se
 * lanza: `ResolvedSettings::resolve()` la atrapa, cae al valor de serie y la
 * anota en `invalidKeys` con estos mismos dos campos. Una fila corrupta no puede
 * dejar a un centro sin fichar (regla dura 19).
 */
final class InvalidSettingValue extends ProductDomainException
{
    /**
     * @param  array<string, string|int>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAnInteger(SettingKey $key, string $received): self
    {
        return self::of($key, 'not_integer', ['received' => $received], 'expects an integer, got '.$received);
    }

    public static function notText(SettingKey $key, string $received): self
    {
        return self::of($key, 'not_text', ['received' => $received], 'expects a string, got '.$received);
    }

    public static function notAList(SettingKey $key, string $received): self
    {
        return self::of($key, 'not_list', ['received' => $received], 'expects a list, got '.$received);
    }

    public static function notAListOfText(SettingKey $key, string $received): self
    {
        return self::of($key, 'not_list_of_text', ['received' => $received], 'expects a list of strings, got '.$received);
    }

    public static function outOfRange(SettingKey $key, int $value, int $minimum, int $maximum): self
    {
        return self::of(
            $key,
            'out_of_range',
            ['minimum' => $minimum, 'maximum' => $maximum, 'value' => $value],
            'accepts '.$minimum.' to '.$maximum.', got '.$value,
        );
    }

    public static function notEmpty(SettingKey $key): self
    {
        return self::of($key, 'not_empty', [], 'does not accept an empty value');
    }

    public static function tooLong(SettingKey $key, int $length, int $maximumLength): self
    {
        return self::of(
            $key,
            'too_long',
            ['maximum' => $maximumLength, 'length' => $length],
            'accepts up to '.$maximumLength.' characters, got '.$length,
        );
    }

    public static function malformed(SettingKey $key, string $expectedShape): self
    {
        return self::of($key, 'malformed', ['shape' => $expectedShape], 'has a malformed value; expected '.$expectedShape);
    }

    public static function duplicated(SettingKey $key): self
    {
        return self::of($key, 'duplicated', [], 'does not accept repeated values');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function notAllowed(SettingKey $key, string $value, array $allowed): self
    {
        return self::of(
            $key,
            'not_allowed',
            ['allowed' => implode(', ', $allowed), 'value' => $value],
            'only accepts '.implode(', ', $allowed).', got '.$value,
        );
    }

    /**
     * El idioma por defecto tiene que estar entre los disponibles.
     *
     * Es una invariante **entre claves**, y por eso no la comprueba ninguna
     * definicion por separado: se comprueba sobre el conjunto ya resuelto, que
     * es lo que hace que un `PATCH` de varias claves no pueda dejar la
     * instalacion en un estado imposible por el orden en que se escriban.
     *
     * **Solo se lanza al escribir.** Al leer, la incoherencia se resuelve de
     * forma determinista —rige el primer idioma disponible— y se anota; ver
     * `ResolvedSettings`.
     */
    public static function defaultLocaleIsNotAvailable(string $default, string $available): self
    {
        return new self(
            'settings.errors.default_locale_not_available',
            ['default' => $default, 'available' => $available],
            'Default locale "'.$default.'" is not among the available locales ('.$available.').',
        );
    }

    /**
     * @param  array<string, string|int>  $parameters
     */
    private static function of(SettingKey $key, string $reason, array $parameters, string $detail): self
    {
        return new self(
            'settings.errors.'.$reason,
            ['key' => $key->value, ...$parameters],
            'Setting "'.$key->value.'" '.$detail.'.',
        );
    }
}
