<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Event\InstallationSettingChanged;
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
 *
 * ## `confidential`: el valor tiene UNA sola salida
 *
 * Una cuarta propiedad, y vive aqui por lo mismo que las otras tres: si la
 * decision de no copiar un valor estuviera repartida por el listener de
 * auditoria, el coleccionista del diagnostico y la consulta de la exportacion,
 * la clave siguiente que hubiera que proteger se olvidaria en alguno de los
 * tres. Marcada aqui, las cuatro salidas la respetan a la vez:
 *
 * | Salida | Que sale |
 * |---|---|
 * | `audit_log` ({@see InstallationSettingChanged}) | **Que la clave cambio**, con autor y momento; `value_redacted: true` en lugar del antes y el despues. |
 * | Paquete de diagnostico (`DiagnosticsConfigurationAllowlist`, `SettingsDrift`) | Nada. La clave no esta en la lista de permitidos y la diferencia con el `.env` publica el nombre, nunca los valores. |
 * | Logs (`SettingsTelemetry`) | Solo el nombre de la clave, como con todas. |
 * | Exportacion integra (`DataExportCatalog`, RF-PD-14) | La fila, con `value` nulo y `value_redacted: true`. |
 *
 * **La unica superficie que muestra el valor es `GET /api/v1/settings` servido a
 * un `admin` DEL CLIENTE**, que es quien lo escribio y en la pantalla donde lo
 * escribio. A un actor de soporte se le sirve `value: null` y `redacted: true`
 * (`Http\Resource\SettingResource`; sin `{@see}`, porque una referencia
 * resoluble desde `Domain/` hacia `Http/` es una arista que Deptrac prohibe
 * —regla dura 1— aunque solo viva en un comentario), y un `PATCH` suyo
 * que la toque recibe `403`: el fabricante configura la instalacion, no se lleva
 * sus secretos (ADR-020, regla dura 16).
 *
 * Hoy la lleva `KIOSK_SERVICE_CODE` (RF-KI-08): es un secreto compartido con
 * cada tablet del hotel, y tanto `audit_log` como el ZIP de la exportacion se
 * enseñan, se archivan y se reenvian. Que quede constancia de quien lo cambio y
 * cuando es lo que exige RL-04; el valor en si no aporta nada a esa pregunta y
 * si abre una via para leerlo.
 */
final readonly class SettingDefinition
{
    /**
     * Lo que separa el identificador de su rotulo en una lista con rotulo:
     * `id=Etiqueta` (ver {@see self::labelledChoiceList()}).
     *
     * Aqui y no en quien la consume, para que la validacion del ajuste y el
     * parseo de la plantilla usen el mismo caracter. Que los dos sitios
     * coincidan lo ata una prueba unitaria, no la buena voluntad.
     */
    public const string LABEL_SEPARATOR = '=';

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
        public bool $confidential = false,
        public bool $allowsLabels = false,
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
     * del logotipo vacia es «el logotipo del producto», no «sin logotipo»; el
     * codigo de servicio vacio es «la pantalla de diagnostico se abre sin
     * codigo», no «no hay pantalla».
     *
     * **La forma exigida no se comprueba sobre el vacio**, y es deliberado: el
     * vacio es un valor legitimo de estas claves y se acepta antes de llegar al
     * patron. Un `^[0-9]{8,12}$` que rechazara la cadena vacia haria imposible
     * **quitar** un codigo ya configurado.
     */
    public static function optionalText(
        string $default,
        int $maximumLength,
        SettingImpact $impact,
        ?string $pattern = null,
        bool $confidential = false,
    ): self {
        return new self(
            SettingType::TEXT, $default, $impact, null, null, $pattern, null, $maximumLength, true, $confidential,
        );
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
     * Una lista ordenada de un conjunto cerrado en la que **cada entrada puede
     * llevar su propio rotulo**: `id` o `id=Etiqueta` (RF-IN-07, ADR-017).
     *
     * ## Por que no basta con {@see self::choiceList()}
     *
     * La plantilla de la salida a nomina necesita dos cosas del cliente a la vez:
     * **que columnas y en que orden** —que es una eleccion de un catalogo cerrado,
     * porque el significado de cada columna lo fija el producto— y **como se
     * llama cada una en la cabecera**, que es texto libre porque tiene que casar
     * con la plantilla de importacion de su programa de nomina. Con dos claves
     * separadas —una lista y un mapa— habria dos filas que pueden dejar de
     * corresponderse, y el dia que no lo hagan el fichero saldra con un rotulo
     * pegado a la columna equivocada.
     *
     * Aqui el identificador y su rotulo son **la misma entrada**, asi que no
     * pueden descuadrar.
     *
     * ## Lo que se valida, y lo que no
     *
     * El identificador tiene que estar en `$allowed` y no puede repetirse: una
     * columna que no existe produciria una celda vacia que nadie ve hasta que el
     * gestor cuadra la nomina, y una repetida descuadra la importacion. El rotulo
     * se acota en longitud y **no puede contener el delimitador**, porque si lo
     * contuviera la entrada tendria dos lecturas posibles.
     *
     * Lo que **no** se valida es el contenido del rotulo: es texto que el cliente
     * escribe para su programa, y limitarlo a un alfabeto seria decidir por el.
     * La neutralizacion de formulas de la hoja de calculo la hace el escritor, que
     * es donde corresponde.
     *
     * @param  list<string>  $default
     * @param  list<string>  $allowed  identificadores admitidos, sin rotulo
     * @param  int  $maximumLabelLength  cuanto puede medir el rotulo configurado
     */
    public static function labelledChoiceList(
        array $default,
        array $allowed,
        SettingImpact $impact,
        int $maximumLabelLength,
    ): self {
        return new self(
            SettingType::TEXT_LIST, $default, $impact, null, null, null, $allowed,
            $maximumLabelLength, false, false, allowsLabels: true,
        );
    }

    /**
     * La forma que admite **una entrada** de una lista con rotulo, como
     * expresion regular.
     *
     * Existe para que el `FormRequest` pueda señalar el elemento exacto del array
     * en el `422` sin escribir una segunda copia del catalogo: la regla que valida
     * en el borde se **deriva** de la misma definicion que valida en el dominio.
     * Devuelve `null` cuando la definicion no admite rotulos, que es donde la
     * regla `in:` de toda la vida sigue sirviendo.
     */
    public function labelledItemPattern(): ?string
    {
        if (! $this->allowsLabels || $this->allowed === null) {
            return null;
        }

        $ids = implode('|', array_map(
            static fn (string $id): string => preg_quote($id, '/'),
            $this->allowed,
        ));

        // El rotulo: de uno a `maximumLength` caracteres, ninguno el
        // delimitador. `u` porque los rotulos llevan acentos.
        return '/^(?:'.$ids.')(?:=[^=]{1,'.max(1, $this->maximumLength).'})?$/u';
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
        $identifiers = [];

        foreach ($raw as $item) {
            if (! is_string($item)) {
                throw InvalidSettingValue::notAListOfText($key, get_debug_type($item));
            }

            $identifiers[] = $this->validatedListItem($key, $item);
            $values[] = $item;
        }

        // Sin repetidos: una lista con «es» dos veces no significa nada distinto
        // de tenerlo una, pero dibuja el selector de idioma dos veces. En una
        // lista con rotulo se compara el IDENTIFICADOR y no la entrada entera:
        // `worked_hours` y `worked_hours=Horas` son la misma columna dos veces, y
        // aceptarlas descuadraria la plantilla de importacion del programa de
        // nomina sin que se note.
        if (count(array_unique($identifiers)) !== count($identifiers)) {
            throw InvalidSettingValue::duplicated($key);
        }

        return $values;
    }

    /**
     * Una entrada de la lista, validada. Devuelve el **identificador**, que es
     * la entrada entera salvo en las listas con rotulo.
     */
    private function validatedListItem(SettingKey $key, string $item): string
    {
        if (! $this->allowsLabels) {
            $this->assertAllowed($key, $item);

            return $item;
        }

        $position = mb_strpos($item, self::LABEL_SEPARATOR);

        if ($position === false) {
            $this->assertAllowed($key, $item);

            return $item;
        }

        $identifier = mb_substr($item, 0, $position);
        $label = mb_substr($item, $position + 1);

        $this->assertAllowed($key, $identifier);

        // Un rotulo vacio —`worked_hours=`— no es «sin rotulo»: es una plantilla
        // a medias que dejaria una cabecera en blanco donde el importador espera
        // un nombre de campo. Se rechaza en vez de caer al rotulo del producto,
        // que seria adivinar lo que quiso decir quien lo escribio.
        if ($label === '') {
            throw InvalidSettingValue::notEmpty($key);
        }

        if ($this->maximumLength > 0 && mb_strlen($label) > $this->maximumLength) {
            throw InvalidSettingValue::tooLong($key, mb_strlen($label), $this->maximumLength);
        }

        // Un segundo delimitador dentro del rotulo daria dos lecturas posibles a
        // la misma entrada.
        if (str_contains($label, self::LABEL_SEPARATOR)) {
            throw InvalidSettingValue::malformed($key, $this->labelledItemPattern() ?? self::LABEL_SEPARATOR);
        }

        return $identifier;
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
