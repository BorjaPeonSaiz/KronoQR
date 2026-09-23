<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * La **plantilla** con la que se escribe la salida a nomina: que columnas, en
 * que orden, con que rotulos y con que forma (**RF-IN-07**, RF-PD-01, ADR-017,
 * regla dura 13).
 *
 * ## Es la pieza que hace que RF-IN-07 sea configuracion y no codigo
 *
 * El doc 05 §5.4 promete «exportacion de horas **en el formato que necesite la
 * herramienta de nomina del hotel**». Sin esta clase, cumplirlo significaria un
 * escritor por cliente —o peor, una rama por cliente—, que es literalmente lo que
 * la regla dura 13 prohibe. Con ella, el formato son seis filas de
 * `installation_settings` que el administrador edita desde el panel: cambiar el
 * separador o añadir una columna produce otro fichero **sin desplegar codigo**,
 * que es el resultado esperado que la ficha 3.9 exige comprobar.
 *
 * ## No lee configuracion: la recibe
 *
 * {@see self::fromSettings()} toma **escalares ya resueltos** y nada mas. Ni
 * consulta, ni cache, ni reloj, ni facade: eso es del adaptador
 * `Product\Infrastructure\Adapter\DbPayrollLayoutProvider` —nombrado en prosa
 * porque una referencia resoluble desde `Domain/` hacia `Infrastructure/` es una
 * arista que Deptrac prohibe—. Es la regla dura 14 aplicada tal cual: el dominio
 * recibe el valor ya resuelto por un puerto y nunca consulta la configuracion.
 *
 * ## Vive en `Shared/Domain`, como {@see HolidayCalendar}
 *
 * Porque la necesitan los dos lados y ninguno puede importar al otro (doc 02
 * §1.6, ADR-025): `Reporting` para escribir el fichero y `Product` para
 * construirla desde `installation_settings`. Y porque el puerto que la entrega
 * —`Shared\Application\Port\PayrollLayoutProvider`— solo puede hablar en tipos
 * de `Shared` o escalares (ADR-025, restriccion 2).
 *
 * ## Lectura tolerante, escritura estricta
 *
 * La misma regla que gobierna `installation_settings` entero. Aqui se **lee**:
 * un identificador de columna que este binario no conozca —porque el catalogo
 * encogio entre dos versiones— se descarta en lugar de lanzar, y si no quedara
 * ninguno rige la plantilla de serie. Una exportacion que reviente por una fila
 * de configuracion sobreviviente de otra version es un fallo que aparece el dia
 * de cierre de nomina y no se parece en nada a su causa.
 *
 * Lo estricto esta donde tiene que estar: al **guardar** el ajuste, donde hay una
 * persona delante a la que decirle cual es el identificador malo (`422`, ver
 * `Product\Domain\ValueObject\SettingDefinition`).
 */
final readonly class PayrollLayout
{
    /**
     * Las columnas de serie, en su orden.
     *
     * **Este es el valor de serie del producto** y de aqui lo toma el catalogo de
     * `SettingKey`: escribirlo dos veces seria tener dos plantillas de serie, y la
     * que ganaria seria la que nadie mira. Son las diez que casi toda nomina pide
     * —quien, de cuando a cuando, cuanto trabajo, cuanto tenia contratado, cuanto
     * de mas y cuantos dias falto justificadamente—, sin `employee_uuid` ni
     * `time_zone`, que se añaden cuando el programa de destino los necesita.
     *
     * @var list<string>
     */
    public const array DEFAULT_COLUMNS = [
        'employee_code',
        'last_name',
        'first_name',
        'department',
        'period_from',
        'period_to',
        'worked_hours',
        'contracted_hours',
        'overtime_hours',
        'absence_days',
    ];

    /** El valor que enciende la fila de rotulos (`PAYROLL_EXPORT_HEADER_ROW`). */
    public const string HEADER_ROW_ENABLED = 'enabled';

    /** El valor que la apaga. Los importadores que esperan solo datos la rechazan. */
    public const string HEADER_ROW_DISABLED = 'disabled';

    /** El delimitador que separa el identificador de columna de su rotulo: `id=Etiqueta`. */
    public const string LABEL_SEPARATOR = '=';

    /**
     * Cuanto puede medir un rotulo configurado.
     *
     * Sesenta caracteres es lo que cabe en la cabecera de cualquier importador
     * que se haya visto, y el techo existe para que un rotulo no pueda usarse
     * como via para meter una linea entera dentro de una celda.
     */
    public const int MAXIMUM_LABEL_LENGTH = 60;

    /**
     * @param  list<PayrollColumn>  $columns  en el orden en que se escriben
     * @param  array<string, string>  $labels  rotulo configurado por identificador de columna;
     *                                         lo que no este aqui usa el rotulo traducido del producto
     * @param  list<string>  $rejected  identificadores descartados por no estar en el catalogo
     */
    private function __construct(
        public array $columns,
        private array $labels,
        public PayrollDelimiter $delimiter,
        public PayrollHoursFormat $hoursFormat,
        public PayrollDateFormat $dateFormat,
        public PayrollEncoding $encoding,
        public bool $hasHeaderRow,
        public array $rejected,
    ) {}

    /**
     * La plantilla, construida sobre los seis valores **ya resueltos** de
     * `installation_settings`.
     *
     * Escalares y no objetos de `Product`: este metodo esta del lado del dominio
     * de la frontera y no puede conocer `SettingValue` ni `ResolvedSettings`
     * (doc 02 §1.6). Quien los traduce es el adaptador, que es quien tiene la
     * tabla.
     *
     * **Nunca lanza.** Ver el docblock de la clase.
     *
     * @param  list<string>  $columns  cada entrada, `id` o `id=Etiqueta`
     */
    public static function fromSettings(
        array $columns,
        string $delimiter,
        string $hoursFormat,
        string $dateFormat,
        string $encoding,
        string $headerRow,
    ): self {
        [$parsed, $labels, $rejected] = self::parseColumns($columns);

        if ($parsed === []) {
            // Ni una columna reconocible. Se cae a la plantilla de serie en vez
            // de entregar un fichero sin ninguna celda: un CSV vacio se parece a
            // «no hay nadie con horas», que es una afirmacion muy distinta.
            [$parsed, $labels] = self::parseColumns(self::DEFAULT_COLUMNS);
        }

        return new self(
            columns: $parsed,
            labels: $labels,
            delimiter: PayrollDelimiter::tryFrom($delimiter) ?? PayrollDelimiter::Semicolon,
            hoursFormat: PayrollHoursFormat::tryFrom($hoursFormat) ?? PayrollHoursFormat::HoursMinutes,
            dateFormat: PayrollDateFormat::tryFrom($dateFormat) ?? PayrollDateFormat::Iso,
            encoding: PayrollEncoding::tryFrom($encoding) ?? PayrollEncoding::Utf8Bom,
            // Cualquier cosa que no sea `disabled` deja la cabecera puesta. Es el
            // lado seguro: un fichero con rotulos de mas se arregla mirandolo, y
            // uno sin rotulos obliga a contar columnas a mano.
            hasHeaderRow: $headerRow !== self::HEADER_ROW_DISABLED,
            rejected: $rejected,
        );
    }

    /** La plantilla de serie del producto, sin ninguna fila de configuracion. */
    public static function shipped(): self
    {
        return self::fromSettings(
            self::DEFAULT_COLUMNS,
            PayrollDelimiter::Semicolon->value,
            PayrollHoursFormat::HoursMinutes->value,
            PayrollDateFormat::Iso->value,
            PayrollEncoding::Utf8Bom->value,
            self::HEADER_ROW_ENABLED,
        );
    }

    /**
     * El rotulo configurado para esta columna, o `null` si rige el del producto.
     *
     * `null` y no el rotulo traducido: el dominio no tiene idioma (ver
     * {@see PayrollColumn::label()}). Quien escribe el fichero resuelve la clave
     * de traduccion cuando aqui no hay nada.
     */
    public function labelFor(PayrollColumn $column): ?string
    {
        return $this->labels[$column->value] ?? null;
    }

    /** Cuantas columnas lleva el fichero. */
    public function width(): int
    {
        return \count($this->columns);
    }

    /**
     * Separa identificadores, rotulos y descartes.
     *
     * Un identificador repetido **se descarta la segunda vez** en lugar de
     * duplicar la columna: al guardar el ajuste eso ya es un `422`, asi que aqui
     * solo puede venir de una fila escrita por otra version, y una columna dos
     * veces descuadra la plantilla de importacion sin que se note.
     *
     * @param  list<string>  $entries
     * @return array{0: list<PayrollColumn>, 1: array<string, string>, 2: list<string>}
     */
    private static function parseColumns(array $entries): array
    {
        $columns = [];
        $labels = [];
        $rejected = [];
        $seen = [];

        foreach ($entries as $entry) {
            [$id, $label] = self::split($entry);

            $column = PayrollColumn::fromId($id);

            if (! $column instanceof PayrollColumn || isset($seen[$id])) {
                $rejected[] = $entry;

                continue;
            }

            $seen[$id] = true;
            $columns[] = $column;

            if ($label !== null && $label !== '') {
                $labels[$id] = $label;
            }
        }

        return [$columns, $labels, $rejected];
    }

    /**
     * `id=Etiqueta` en sus dos mitades; `id` a secas deja la segunda a `null`.
     *
     * Se parte por el **primer** `=` y el resto se toma entero, aunque un rotulo
     * no pueda contenerlo: asi, si alguna vez llegara uno con dos signos, la
     * entrada se descarta por rotulo invalido al guardar en lugar de guardarse a
     * medias.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function split(string $entry): array
    {
        $position = mb_strpos($entry, self::LABEL_SEPARATOR);

        if ($position === false) {
            return [trim($entry), null];
        }

        return [
            trim(mb_substr($entry, 0, $position)),
            trim(mb_substr($entry, $position + 1)),
        ];
    }
}
