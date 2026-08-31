<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidSettingValue;

/**
 * Que puede valer una clave de configuracion, y que es cuando cambia
 * (RF-PD-01, ADR-017).
 *
 * Tres cosas en un solo sitio: el **tipo**, el **valor por defecto del
 * producto** y el **impacto** del cambio. Que esten juntas es la razon de ser de
 * la clase: si el tipo viviera en el FormRequest, el valor de serie en una
 * migracion y el impacto en el listener de auditoria, añadir una clave serian
 * tres ficheros y olvidarse de uno no lo notaria nadie hasta que el cliente
 * configurase algo que no se aplica.
 *
 * **El valor por defecto vive aqui y no en la base de datos** (paso 3 de la
 * tarea 5.1): el valor de serie **es** el producto, y una instalacion sin
 * ninguna fila en `installation_settings` tiene que arrancar y funcionar. La
 * migracion siembra ademas los cuatro umbrales operativos para que se vean y se
 * puedan editar desde el panel, pero borrarlos no rompe nada.
 *
 * **Constructor privado y cinco fabricas con nombre.** Una definicion de entero
 * con una expresion regular, o una de texto con maximo y minimo numericos, son
 * estados imposibles: aqui no se pueden construir, en lugar de validarse mas
 * tarde.
 */
final readonly class SettingDefinition
{
    /**
     * @param  int|string|list<string>  $default
     * @param  list<string>|null  $allowed
     */
    private function __construct(
        public SettingType $type,
        public int|string|array $default,
        public SettingImpact $impact,
        public ?int $minimum,
        public ?int $maximum,
        public ?string $pattern,
        public ?array $allowed,
        public int $maximumLength,
        public bool $allowsEmpty,
    ) {}

    /**
     * Un entero acotado. Todos los umbrales operativos lo son.
     *
     * El maximo no es decoracion: un `ATTENDANCE_MAX_SHIFT_HOURS` de 100 no
     * detectaria un solo turno anomalo y nadie se enteraria hasta que Inspeccion
     * preguntase por que no hay incidencias.
     */
    public static function integer(int $default, int $minimum, int $maximum, SettingImpact $impact): self
    {
        return new self(SettingType::INTEGER, $default, $impact, $minimum, $maximum, null, null, 0, false);
    }

    /** Una cadena obligatoria, con longitud maxima y, si procede, una forma exigida. */
    public static function text(string $default, int $maximumLength, SettingImpact $impact, ?string $pattern = null): self
    {
        return new self(SettingType::TEXT, $default, $impact, null, null, $pattern, null, $maximumLength, false);
    }

    /**
     * Una cadena que puede estar vacia, y cuyo vacio **significa algo**: la ruta
     * del logotipo vacia es «el logotipo del producto», no «sin logotipo».
     */
    public static function optionalText(string $default, int $maximumLength, SettingImpact $impact): self
    {
        return new self(SettingType::TEXT, $default, $impact, null, null, null, null, $maximumLength, true);
    }

    /**
     * Una cadena de un conjunto cerrado. El idioma por defecto lo es.
     *
     * @param  list<string>  $allowed
     */
    public static function choice(string $default, array $allowed, SettingImpact $impact): self
    {
        return new self(SettingType::TEXT, $default, $impact, null, null, null, $allowed, 0, false);
    }

    /**
     * Una lista no vacia y sin repetidos de un conjunto cerrado.
     *
     * @param  list<string>  $default
     * @param  list<string>  $allowed
     */
    public static function choiceList(array $default, array $allowed, SettingImpact $impact): self
    {
        return new self(SettingType::TEXT_LIST, $default, $impact, null, null, null, $allowed, 0, false);
    }

    /**
     * El valor, validado contra esta definicion, o {@see InvalidSettingValue}.
     *
     * Recibe la clave solo para poder decir cual falla: una definicion no sabe
     * de quien es, y duplicar el nombre dentro seria una segunda fuente de
     * verdad que se desincroniza.
     *
     * @return int|string|list<string>
     */
    public function validate(SettingKey $key, mixed $raw): int|string|array
    {
        return match ($this->type) {
            SettingType::INTEGER => $this->validatedInteger($key, $raw),
            SettingType::TEXT => $this->validatedText($key, $raw),
            SettingType::TEXT_LIST => $this->validatedTextList($key, $raw),
        };
    }

    private function validatedInteger(SettingKey $key, mixed $raw): int
    {
        // `is_int` y no `is_numeric`: «12» y 12.5 llegan de un JSON mal escrito a
        // mano, y aceptarlos convertiria un error de configuracion en un umbral
        // silenciosamente distinto del que el cliente cree haber puesto.
        if (! is_int($raw)) {
            throw InvalidSettingValue::notAnInteger($key, get_debug_type($raw));
        }

        $minimum = $this->minimum ?? PHP_INT_MIN;
        $maximum = $this->maximum ?? PHP_INT_MAX;

        if ($raw < $minimum || $raw > $maximum) {
            throw InvalidSettingValue::outOfRange($key, $raw, $minimum, $maximum);
        }

        return $raw;
    }

    private function validatedText(SettingKey $key, mixed $raw): string
    {
        if (! is_string($raw)) {
            throw InvalidSettingValue::notText($key, get_debug_type($raw));
        }

        if ($raw === '') {
            return $this->allowsEmpty ? $raw : throw InvalidSettingValue::notEmpty($key);
        }

        if ($this->maximumLength > 0 && mb_strlen($raw) > $this->maximumLength) {
            throw InvalidSettingValue::tooLong($key, mb_strlen($raw), $this->maximumLength);
        }

        if ($this->pattern !== null && preg_match($this->pattern, $raw) !== 1) {
            throw InvalidSettingValue::malformed($key, $this->shape());
        }

        $this->assertAllowed($key, $raw);

        return $raw;
    }

    /**
     * @return list<string>
     */
    private function validatedTextList(SettingKey $key, mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw InvalidSettingValue::notAList($key, get_debug_type($raw));
        }

        if ($raw === []) {
            throw InvalidSettingValue::notEmpty($key);
        }

        $values = [];

        foreach ($raw as $item) {
            if (! is_string($item)) {
                throw InvalidSettingValue::notAListOfText($key, get_debug_type($item));
            }

            $this->assertAllowed($key, $item);
            $values[] = $item;
        }

        // Sin repetidos: una lista con «es» dos veces no significa nada distinto
        // de tenerlo una, pero dibuja el selector de idioma dos veces.
        if (count(array_unique($values)) !== count($values)) {
            throw InvalidSettingValue::duplicated($key);
        }

        return $values;
    }

    private function assertAllowed(SettingKey $key, string $value): void
    {
        if ($this->allowed !== null && ! in_array($value, $this->allowed, true)) {
            throw InvalidSettingValue::notAllowed($key, $value, $this->allowed);
        }
    }

    /**
     * La forma exigida, tal cual: la propia expresion regular.
     *
     * **En ingles y sin adornos**, porque va al mensaje TECNICO de la excepcion
     * y como parametro del mensaje traducido. Quien lo lee en un 422 lo recibe
     * dentro de una frase que si esta en su idioma.
     */
    private function shape(): string
    {
        return $this->pattern ?? 'the shape declared by the key';
    }
}
