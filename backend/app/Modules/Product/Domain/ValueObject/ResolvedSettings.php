<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\Exception\UnknownSettingKey;

/**
 * La configuracion de la instalacion ya resuelta: **el unico punto de la
 * cascada** (RF-PD-01, paso 4 de la tarea 5.1).
 *
 * ## La cascada, y por que tiene dos escalones y no tres
 *
 *     fila de installation_settings  ->  valor por defecto del catalogo
 *
 * El ambito `site` no aparece porque **hay exactamente un centro por
 * instalacion** (ADR-040): un escalon que siempre resuelve al mismo sitio no es
 * una cascada, es una consulta con una columna de mas. Y la variable de entorno
 * tampoco: es el valor de arranque con el que el instalador siembra la primera
 * fila, no un escalon. Si estuviera en la cascada, cambiar un umbral desde el
 * panel no tendria efecto mientras el `.env` dijera otra cosa, y el cliente
 * veria el valor nuevo guardado y el viejo aplicandose.
 *
 * ## Pura, y por eso se prueba sin base de datos
 *
 * Entra un mapa de lo que hay guardado —clave a valor decodificado del JSONB— y
 * sale el conjunto completo con tipo. Ni consulta, ni cache, ni reloj: eso es
 * del adaptador. Una instalacion **sin ninguna fila** produce el conjunto de
 * valores de serie, que es el requisito literal del paso 3 de la tarea.
 *
 * ## Lectura tolerante, escritura estricta
 *
 * Es la regla que gobierna esta clase, y no es una comodidad: **la lectura
 * ocurre en el camino de fichaje**. `RegisterScanHandler` pide la configuracion
 * operativa en cada escaneo, y si resolverla pudiera fallar, una fila corrupta
 * —un color de marca editado a mano como `rgb(17,24,39)`— haria que
 * `POST /api/v1/scan` respondiera un error y **nadie pudiera fichar** (regla
 * dura 19). Un fallo asi no se parece en nada a su causa y se descubre en la
 * puerta de servicio, a las seis de la mañana.
 *
 * Asi que:
 *
 * - {@see self::resolve()} **no lanza nunca**. Un valor guardado que su clave no
 *   admite se descarta, rige el valor de serie del catalogo, y la clave queda
 *   anotada en {@see self::$invalidKeys} con su motivo. Una clave que el
 *   catalogo no conoce se ignora y queda en {@see self::$unknownKeys}.
 * - {@see self::with()} **lanza siempre**. Es el camino de escritura: quien
 *   guarda un valor imposible recibe un `422` con el campo señalado, porque ahi
 *   hay una persona delante que puede corregirlo.
 *
 * Descartar en silencio seria la otra forma de equivocarse: una clave de impacto
 * `worked_hours` con valor corrupto cambia los minutos calculados. Por eso
 * `invalidKeys` viaja en `meta.invalid_keys` de `GET /api/v1/settings`, se
 * registra como `warning` sin datos personales y lo enseñara `doctor` (5.9).
 *
 * ## La incoherencia de idiomas se resuelve, no se hereda
 *
 * `LOCALE_DEFAULT` fuera de `LOCALE_AVAILABLE` es un conjunto imposible que
 * ninguna clave puede detectar sola. Al escribir se rechaza. Al leer **rige el
 * primer idioma disponible** y se anota la incoherencia: es la unica salida
 * determinista —caer al valor de serie no sirve, porque el valor de serie
 * tampoco tiene por que estar entre los disponibles— y deja la instalacion
 * sirviendo un idioma que existe de verdad.
 */
final readonly class ResolvedSettings
{
    /**
     * @param  array<string, SettingValue>  $values  todas las claves del catalogo, en su orden
     * @param  list<string>  $unknownKeys  filas guardadas que este binario no reconoce
     * @param  list<InvalidSetting>  $invalidKeys  filas guardadas descartadas por no cumplir su definicion
     */
    private function __construct(
        private array $values,
        public array $unknownKeys,
        public array $invalidKeys,
    ) {}

    /**
     * Resuelve la cascada sobre lo que haya guardado. **Nunca lanza.**
     *
     * @param  array<string, mixed>  $stored  valor decodificado de cada fila, por clave
     */
    public static function resolve(array $stored): self
    {
        return self::build($stored, self::unknownKeysIn($stored), strict: false);
    }

    /**
     * El conjunto que quedaria tras escribir estos valores. **Lanza.**
     *
     * Lo usa `UpdateSettings` **antes** de tocar la base de datos: las
     * invariantes entre claves —el idioma por defecto tiene que estar entre los
     * disponibles— no las puede comprobar ninguna clave por separado, y
     * comprobarlas sobre el resultado es lo que impide que un `PATCH` de dos
     * claves deje la instalacion en un estado imposible segun el orden en que se
     * escriban.
     *
     * **Parte de lo que ESTA VIGENTE, no de lo que hay en la tabla.** Si una
     * fila estaba corrupta, aqui ya rige su valor de serie: un `PATCH` de una
     * clave ajena no se bloquea por una corrupcion anterior, y tampoco la repara
     * ni la borra — la fila sigue en la tabla, y `UpdateSettings` solo escribe
     * las claves que se le piden.
     */
    public function with(SettingValue ...$values): self
    {
        $stored = [];

        foreach ($this->values as $key => $value) {
            if (! $value->isProductDefault) {
                $stored[$key] = $value->value();
            }
        }

        foreach ($values as $value) {
            $stored[$value->key->value] = $value->value();
        }

        return self::build($stored, $this->unknownKeys, strict: true);
    }

    public function get(SettingKey $key): SettingValue
    {
        return $this->values[$key->value] ?? throw new UnknownSettingKey($key->value);
    }

    public function integer(SettingKey $key): int
    {
        return $this->get($key)->asInteger();
    }

    public function text(SettingKey $key): string
    {
        return $this->get($key)->asText();
    }

    /**
     * @return list<string>
     */
    public function textList(SettingKey $key): array
    {
        return $this->get($key)->asTextList();
    }

    /**
     * Todas las claves del catalogo, en el orden en que las declara.
     *
     * Es lo que sirve `GET /api/v1/settings`: el catalogo entero, no solo lo
     * guardado. Un panel que solo enseñara las filas existentes ocultaria justo
     * lo que el cliente todavia no ha configurado.
     *
     * @return list<SettingValue>
     */
    public function all(): array
    {
        return array_values($this->values);
    }

    /** Si hay algo que contar: una fila descartada o una clave que nadie lee. */
    public function hasAnomalies(): bool
    {
        return $this->invalidKeys !== [] || $this->unknownKeys !== [];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  list<string>  $unknownKeys
     */
    private static function build(array $stored, array $unknownKeys, bool $strict): self
    {
        $values = [];
        $invalid = [];

        foreach (SettingKey::cases() as $key) {
            if (! array_key_exists($key->value, $stored)) {
                $values[$key->value] = SettingValue::productDefault($key);

                continue;
            }

            try {
                $values[$key->value] = SettingValue::of($key, $stored[$key->value]);
            } catch (InvalidSettingValue $rejection) {
                if ($strict) {
                    throw $rejection;
                }

                $values[$key->value] = SettingValue::productDefault($key);
                $invalid[] = InvalidSetting::from($key, $rejection);
            }
        }

        return self::withCoherentLocales(new self($values, $unknownKeys, $invalid), $strict);
    }

    /**
     * Deja el conjunto con un idioma por defecto que de verdad este disponible.
     *
     * Al escribir, lanza. Al leer, sustituye el idioma por defecto por el primer
     * disponible y anota la incoherencia: ver el docblock de la clase.
     */
    private static function withCoherentLocales(self $settings, bool $strict): self
    {
        $default = $settings->text(SettingKey::LOCALE_DEFAULT);
        $available = $settings->textList(SettingKey::LOCALE_AVAILABLE);

        if (in_array($default, $available, true)) {
            return $settings;
        }

        if ($strict) {
            throw InvalidSettingValue::defaultLocaleIsNotAvailable($default, implode(', ', $available));
        }

        // `LOCALE_AVAILABLE` nunca esta vacia: su definicion lo prohibe, y una
        // lista vacia guardada ya se habria descartado unas lineas mas arriba
        // dejando la de serie.
        $effective = $available[0];

        $values = $settings->values;
        $values[SettingKey::LOCALE_DEFAULT->value] = SettingValue::of(SettingKey::LOCALE_DEFAULT, $effective);

        return new self(
            $values,
            $settings->unknownKeys,
            [...$settings->invalidKeys, InvalidSetting::incoherentDefaultLocale(
                stored: $default,
                effective: $effective,
                available: implode(', ', $available),
            )],
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<string>
     */
    private static function unknownKeysIn(array $stored): array
    {
        $known = array_column(SettingKey::cases(), 'value');

        return array_values(array_diff(array_keys($stored), $known));
    }
}
