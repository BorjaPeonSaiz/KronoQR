<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Application\Port\EmployeeImportSource;
use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportMessage;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportMessageCode;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportReport;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportRow;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use App\Modules\Workforce\Domain\ValueObject\ImportColumnMap;
use App\Modules\Workforce\Domain\ValueObject\ImportedAbsence;
use DateTimeImmutable;

/**
 * Lee el fichero de ausencias y decide **que se haria con cada linea**
 * (**RF-GP-04**, fase de comprobacion).
 *
 * ## Esta clase no escribe nada, y esa es toda su razon de ser
 *
 * El modo simulacion no es una bandera dentro del importador: es este caso de
 * uso, que no tiene acceso a ninguna escritura. La aplicacion es
 * {@see ApplyAbsenceImport}, que consume **este mismo informe**. Que sean dos
 * clases es lo que garantiza que lo que se aplica es lo que se simulo — con una
 * sola y un `if ($apply)`, los dos caminos podrian divergir y nadie lo notaria
 * hasta que el informe mintiera. Mismo reparto que en la carga de plantilla.
 *
 * ## Reutiliza el lector de la plantilla, que ya era generico
 *
 * {@see EmployeeImportSource} no sabe nada de empleados: da la huella, las
 * cabeceras y las filas en streaming. No hacia falta un puerto nuevo, y tener
 * dos habria significado dos sitios donde arreglar la deteccion del delimitador
 * o de la codificacion.
 *
 * ## Las tres reglas de esta carga
 *
 * 1. **La persona se busca por su codigo de empleado y por nada mas.** No se
 *    empareja por nombre: dos personas se llaman igual con mas frecuencia de lo
 *    que parece en un hotel de temporada, y atribuir una baja medica a quien no
 *    es seria el peor desenlace posible de este endpoint.
 * 2. **Una linea identica a una ausencia activa sale como `unchanged`**, no como
 *    solape. Reimportar el mismo cuadrante con una fila corregida es lo normal, y
 *    la respuesta correcta a las otras treinta y nueve no es «solapan».
 * 3. **Dos lineas del mismo fichero que se pisan: se rechaza la segunda.** No la
 *    primera: aplicar las dos dejaria el resultado a merced del orden de las
 *    filas.
 */
final readonly class PlanAbsenceImport
{
    private const string FIELD_EMPLOYEE_CODE = 'employee_code';

    private const string FIELD_TYPE = 'type';

    private const string FIELD_STARTS_ON = 'starts_on';

    private const string FIELD_ENDS_ON = 'ends_on';

    public function __construct(
        private EmployeeImportSource $source,
        private EmployeeImportDirectory $directory,
        private AbsenceRepository $absences,
    ) {}

    /**
     * @param  array<string, list<string>>  $columnAliases
     *
     * @throws UnreadableImportFile
     */
    public function handle(string $path, int $maxRows, array $columnAliases): AbsenceImportReport
    {
        $map = ImportColumnMap::of($columnAliases);

        $checksum = $this->source->checksum($path);
        $warnings = $this->fileWarnings($path, $map);

        $rows = [];

        // Lo ya planificado en ESTE fichero, por persona: es lo que detecta que
        // dos lineas se pisan entre si. Se lleva aparte de lo ya registrado
        // porque son dos preguntas distintas y dan dos codigos distintos.
        /** @var array<string, list<Absence>> $plannedByEmployee */
        $plannedByEmployee = [];

        foreach ($this->source->rows($path, $maxRows) as $line => $raw) {
            $rows[] = $this->planRow($line, $this->normalise($raw, $map), $plannedByEmployee);
        }

        return AbsenceImportReport::of($checksum, $rows, $this->source->wasTruncated(), $warnings);
    }

    /**
     * Avisos del fichero entero: las cabeceras que el mapa no reconoce.
     *
     * **Una vez y no repetidos en cada fila**, por lo mismo que en la carga de
     * plantilla: tres columnas desconocidas y cuarenta filas eran ciento veinte
     * mensajes identicos que sepultaban los rechazos de verdad.
     *
     * @return list<AbsenceImportMessage>
     */
    private function fileWarnings(string $path, ImportColumnMap $map): array
    {
        $warnings = [];

        foreach ($this->source->headers($path) as $header) {
            $column = trim($header);

            if ($column !== '' && $map->fieldFor($column) === null) {
                $warnings[] = AbsenceImportMessage::of(AbsenceImportMessageCode::UNKNOWN_COLUMN, $column);
            }
        }

        return $warnings;
    }

    /**
     * La fila cruda, traducida a los campos del producto.
     *
     * @param  array<string, string>  $raw
     * @return array<string, string>
     */
    private function normalise(array $raw, ImportColumnMap $map): array
    {
        $fields = [];

        foreach ($raw as $header => $value) {
            $field = $map->fieldFor($header);
            $trimmed = trim($value);

            // Una celda vacia es «no viene», no «ponlo a cadena vacia».
            if ($field !== null && $trimmed !== '') {
                $fields[$field] ??= $trimmed;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, list<Absence>>  $plannedByEmployee
     */
    private function planRow(int $line, array $fields, array &$plannedByEmployee): AbsenceImportRow
    {
        $imported = new ImportedAbsence(
            employeeCode: $fields[self::FIELD_EMPLOYEE_CODE] ?? '',
            type: $fields[self::FIELD_TYPE] ?? '',
            startsOn: $fields[self::FIELD_STARTS_ON] ?? '',
            endsOn: $fields[self::FIELD_ENDS_ON] ?? '',
            note: $fields['note'] ?? null,
        );

        $label = $imported->employeeCode === '' ? '—' : $imported->employeeCode;

        $errors = $this->shapeErrorsOf($imported);

        if ($errors !== []) {
            return AbsenceImportRow::rejected($line, $label, $errors);
        }

        $employeeUuid = $this->directory->uuidByEmployeeCode($imported->employeeCode);

        if ($employeeUuid === null) {
            return AbsenceImportRow::rejected($line, $label, [
                AbsenceImportMessage::of(
                    AbsenceImportMessageCode::UNKNOWN_EMPLOYEE,
                    self::FIELD_EMPLOYEE_CODE,
                ),
            ]);
        }

        return $this->planAgainstWhatExists($line, $label, $imported, $employeeUuid, $plannedByEmployee);
    }

    /**
     * La linea ya interpretable, contrastada con lo registrado y con lo que ya
     * se ha planificado en el mismo fichero.
     *
     * **Metodo aparte y no en linea** por el limite de complejidad del §3.5: son
     * tres comparaciones sobre dos conjuntos distintos, y juntas con las ramas
     * de {@see self::planRow()} pasaban del maximo.
     *
     * @param  array<string, list<Absence>>  $plannedByEmployee
     */
    private function planAgainstWhatExists(
        int $line,
        string $label,
        ImportedAbsence $imported,
        string $employeeUuid,
        array &$plannedByEmployee,
    ): AbsenceImportRow {
        $candidate = $this->candidateFrom($imported, $employeeUuid);

        foreach ($this->absences->activeWithin($employeeUuid, $candidate->startsOn, $candidate->endsOn) as $existing) {
            // IDENTICA no es solape: es una reimportacion, y reimportar el mismo
            // cuadrante tiene que ser seguro.
            if ($candidate->describesTheSameFactAs($existing)) {
                return AbsenceImportRow::unchanged($line, $imported, $existing->uuid);
            }

            return AbsenceImportRow::rejected($line, $label, [
                AbsenceImportMessage::of(AbsenceImportMessageCode::OVERLAPPING_ABSENCE, self::FIELD_STARTS_ON),
            ]);
        }

        foreach ($plannedByEmployee[$employeeUuid] ?? [] as $planned) {
            if ($candidate->overlaps($planned)) {
                // La SEGUNDA aparicion, no la primera: aplicar las dos dejaria el
                // resultado a merced del orden de las filas.
                return AbsenceImportRow::rejected($line, $label, [
                    AbsenceImportMessage::of(AbsenceImportMessageCode::DUPLICATE_IN_FILE, self::FIELD_STARTS_ON),
                ]);
            }
        }

        // Se apunta DESPUES de decidir: una linea rechazada no debe consumir el
        // hueco y hacer que la siguiente —correcta— salga como duplicada de algo
        // que no se llego a importar.
        $plannedByEmployee[$employeeUuid][] = $candidate;

        return AbsenceImportRow::created($line, $imported);
    }

    /**
     * La ausencia que registraria esa linea, ya como modelo de dominio.
     *
     * Se construye aqui —y no en la fase de aplicacion— porque es lo que permite
     * comparar solapes con {@see Absence::overlaps()} sin reimplementar la
     * comparacion de intervalos en dos sitios.
     *
     * El `uuid` es provisional: la fila que de verdad se escriba recibira el suyo
     * en {@see ApplyAbsenceImport}, que pasa por `RegisterAbsenceHandler` y
     * genera uno v7. Este solo existe para que el modelo se pueda construir.
     */
    private function candidateFrom(ImportedAbsence $imported, string $employeeUuid): Absence
    {
        return new Absence(
            uuid: 'planned',
            employeeUuid: $employeeUuid,
            // Los tres ya estan validados por `shapeErrorsOf()`: una linea con
            // tipo o fechas ilegibles no llega hasta aqui. Los respaldos existen
            // porque el tipo lo permite, no porque el caso sea alcanzable, y el
            // del tipo es `Leave` y no `Other` a proposito: `Other` exigiria nota
            // y el constructor reventaria en un camino imposible.
            type: AbsenceType::fromImportLabel($imported->type) ?? AbsenceType::Leave,
            startsOn: self::parseDate($imported->startsOn) ?? new DateTimeImmutable('1970-01-01'),
            endsOn: self::parseDate($imported->endsOn) ?? new DateTimeImmutable('1970-01-01'),
            note: $imported->note,
        );
    }

    /**
     * Lo que puede ir mal con la **forma** de la linea, sin consultar nada.
     *
     * Se devuelven todos los problemas de forma juntos y no el primero: quien
     * corrige el fichero tiene que poder arreglar la linea de una pasada en lugar
     * de descubrir un error nuevo en cada reintento.
     *
     * @return list<AbsenceImportMessage>
     */
    private function shapeErrorsOf(ImportedAbsence $imported): array
    {
        $errors = [];

        if ($imported->employeeCode === '') {
            $errors[] = AbsenceImportMessage::of(
                AbsenceImportMessageCode::MISSING_EMPLOYEE_CODE,
                self::FIELD_EMPLOYEE_CODE,
            );
        }

        $type = $imported->type === '' ? null : AbsenceType::fromImportLabel($imported->type);

        if ($imported->type === '') {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::MISSING_TYPE, self::FIELD_TYPE);
        } elseif ($type === null) {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::UNKNOWN_TYPE, self::FIELD_TYPE);
        } elseif ($type->requiresNote() && ($imported->note === null || trim($imported->note) === '')) {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::NOTE_REQUIRED, 'note');
        }

        /*
         * EL TECHO DE LA NOTA SE COMPRUEBA AQUI, y no es simetria con el
         * `FormRequest`: esta carga **no pasa por el**. Sin esta rama, una celda
         * de 501 caracteres salia como `create` en la comprobacion y reventaba al
         * aplicar contra `absences_chk_note_length`; el mensaje de esa
         * `QueryException` lleva los valores enlazados —la nota entera y el
         * tipo— y acababa en `storage/logs`, que no esta saneado (regla dura 21).
         *
         * El numero sale de {@see Absence::MAX_NOTE_LENGTH}, que es la unica
         * copia del producto. `mb_strlen` y no `strlen`: el `CHECK` usa
         * `char_length`, que cuenta caracteres y no bytes, y con `strlen` una
         * nota de 400 caracteres con acentos se rechazaria aqui y entraria alli.
         */
        if ($imported->note !== null && mb_strlen(trim($imported->note)) > Absence::MAX_NOTE_LENGTH) {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::NOTE_TOO_LONG, 'note');
        }

        return [...$errors, ...$this->dateErrorsOf($imported)];
    }

    /**
     * @return list<AbsenceImportMessage>
     */
    private function dateErrorsOf(ImportedAbsence $imported): array
    {
        $errors = [];
        $starts = $imported->startsOn === '' ? null : self::parseDate($imported->startsOn);
        $ends = $imported->endsOn === '' ? null : self::parseDate($imported->endsOn);

        if ($imported->startsOn === '') {
            $errors[] = AbsenceImportMessage::of(
                AbsenceImportMessageCode::MISSING_STARTS_ON,
                self::FIELD_STARTS_ON,
            );
        } elseif ($starts === null) {
            $errors[] = AbsenceImportMessage::of(
                AbsenceImportMessageCode::INVALID_STARTS_ON,
                self::FIELD_STARTS_ON,
            );
        }

        if ($imported->endsOn === '') {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::MISSING_ENDS_ON, self::FIELD_ENDS_ON);
        } elseif ($ends === null) {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::INVALID_ENDS_ON, self::FIELD_ENDS_ON);
        }

        if ($starts instanceof DateTimeImmutable && $ends instanceof DateTimeImmutable && $ends < $starts) {
            $errors[] = AbsenceImportMessage::of(AbsenceImportMessageCode::INVERTED_PERIOD, self::FIELD_ENDS_ON);
        }

        return $errors;
    }

    /**
     * Fecha del fichero, o `null` si no lo es.
     *
     * **La misma funcion que la carga de plantilla**, y a proposito: los tres
     * formatos aceptados y el rechazo del formato americano —`03/04/2026` es el 3
     * de abril para quien lo escribio— valen igual aqui, y una segunda copia
     * acabaria aceptando algo distinto. Una fecha de baja leida con un mes de
     * error son treinta dias de absentismo mal atribuidos.
     */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        return PlanEmployeeImport::parseDate($value);
    }
}
