<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Application\Port\EmployeeImportSource;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\ValueObject\ImportColumnMap;
use App\Modules\Workforce\Domain\ValueObject\ImportedEmployee;
use App\Modules\Workforce\Domain\ValueObject\ImportMessage;
use App\Modules\Workforce\Domain\ValueObject\ImportMessageCode;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;
use App\Modules\Workforce\Domain\ValueObject\ImportRow;
use DateTimeImmutable;

/**
 * Lee el fichero y decide **que se haria con cada linea** (**RF-GP-05**, fase de
 * validacion).
 *
 * ## Esta clase no escribe nada, y esa es toda su razon de ser
 *
 * El modo simulacion de RF-GP-05 no es una bandera dentro del importador: es
 * este caso de uso, que no tiene acceso a ninguna escritura. La aplicacion es
 * {@see ApplyEmployeeImport}, que consume **este mismo informe**. Que sean dos
 * clases es lo que garantiza que lo que se aplica es lo que se simulo — con una
 * sola y un `if ($apply)`, los dos caminos podrian divergir con el tiempo y
 * nadie lo notaria hasta que el informe mintiera.
 *
 * ## El fichero se lee una vez por fase, y en streaming
 *
 * Nunca entero en memoria (doc 02 §3.1). Lo que si crece con el fichero es el
 * informe, y por eso hay un tope de lineas: sin el, un export completo de un ERP
 * tumbaria el proceso con un mensaje que no se parece a su causa.
 *
 * ## Las tres reglas de identidad, juntas y aqui
 *
 * 1. **Documento primero, correo despues.** El correo cambia y el documento no.
 * 2. **Sin ninguno de los dos, se rechaza.** No por rigor: sin clave no habria
 *    forma de reconocer esa linea la segunda vez, y reimportar el fichero
 *    duplicaria a esa persona (regla dura 5). No contradice la regla dura 12
 *    —el correo sigue siendo opcional—: lo que no puede faltar son **los dos**.
 * 3. **Repetida dentro del propio fichero, se rechaza la segunda.** Y no la
 *    primera: aplicar las dos dejaria el resultado a merced del orden de las
 *    filas.
 *
 * ## `hired_at` de quien ya existe no se toca
 *
 * Se avisa y no se aplica (regla dura 5). Cambiar la fecha de alta mueve el
 * punto desde el que corre la conservacion de RL-02 y desde el que se le pueden
 * imputar jornadas: eso se hace en la ficha, a conciencia, no de pasada en una
 * importacion de cuarenta lineas.
 */
final readonly class PlanEmployeeImport
{
    /** Campos que una linea nueva necesita si o si. */
    private const string FIELD_FIRST_NAME = 'first_name';

    private const string FIELD_LAST_NAME = 'last_name';

    private const string FIELD_HIRED_AT = 'hired_at';

    public function __construct(
        private EmployeeImportSource $source,
        private EmployeeImportDirectory $directory,
        private EmployeeRepository $employees,
    ) {}

    /**
     * @param  array<string, list<string>>  $columnAliases
     *
     * @throws UnreadableImportFile
     */
    public function handle(string $path, int $maxRows, array $columnAliases): ImportReport
    {
        $map = ImportColumnMap::of($columnAliases);

        $checksum = $this->source->checksum($path);
        $warnings = $this->fileWarnings($path, $map);
        $departments = $this->directory->departmentsByNormalisedName();

        $rows = [];

        // Los dos mapas de «ya visto» del fichero, y son dos por una razon: la
        // clave de identidad detecta la MISMA persona repetida y el correo
        // detecta a DOS personas distintas peleandose por el mismo correo. La
        // segunda existe desde la revision de la 5.5: sin ella, dos filas con
        // documentos distintos y el mismo correo salian las dos como `create` y
        // reventaban el lote entero en la fase de aplicacion con un `409` del
        // indice unico —sin decir que linea—, despues de que quien importa
        // hubiera revisado un informe que decia que todo iba bien.
        $seen = [];
        $seenEmails = [];

        foreach ($this->source->rows($path, $maxRows) as $line => $raw) {
            $rows[] = $this->planRow($line, $this->normalise($raw, $map), $departments, $seen, $seenEmails);
        }

        return ImportReport::of($checksum, $rows, $this->source->wasTruncated(), $warnings);
    }

    /**
     * Avisos que son **del fichero entero**, no de una linea: hoy, las cabeceras
     * que el mapa no reconoce.
     *
     * **Se avisan una vez, y desde la revision de la 5.5 de verdad.** Antes se
     * componian aqui y se copiaban en cada fila, que es exactamente lo que este
     * docblock decia evitar: una cabecera con tres columnas desconocidas y
     * cuarenta filas producia **ciento veinte mensajes identicos** que
     * sepultaban los rechazos de verdad. Ahora viven en `file.warnings` del
     * informe y las filas no los llevan.
     *
     * Se avisan aunque no impidan nada, porque el caso que importa no es la
     * exportacion con veinte columnas de nomina, sino el `e-mail` escrito donde
     * el mapa espera `email`: sin el aviso, quien importa cree que ha cargado
     * los correos.
     *
     * @return list<ImportMessage>
     */
    private function fileWarnings(string $path, ImportColumnMap $map): array
    {
        $warnings = [];

        foreach ($this->source->headers($path) as $header) {
            $column = trim($header);

            if ($column !== '' && $map->fieldFor($column) === null) {
                $warnings[] = ImportMessage::of(ImportMessageCode::UNKNOWN_COLUMN, $column);
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

            // Una celda vacia es «no viene», no «ponlo a cadena vacia»: la
            // diferencia decide si el correo se borra o se conserva.
            if ($field !== null && $trimmed !== '') {
                $fields[$field] ??= $trimmed;
            }
        }

        return $fields;
    }

    /**
     * La fila ya traducida, como objeto.
     *
     * **Metodo aparte y no en linea** por el limite de complejidad ciclomatica
     * del §3.5: siete `??` en una expresion son siete puntos de decision, y
     * juntos con las tres ramas de {@see self::planRow()} pasaban del maximo. La
     * separacion tiene ademas su propio valor: aqui esta, en un solo sitio, que
     * campos del fichero conoce el importador.
     *
     * @param  array<string, string>  $fields
     */
    private static function employeeFrom(array $fields): ImportedEmployee
    {
        return new ImportedEmployee(
            firstName: $fields[self::FIELD_FIRST_NAME] ?? '',
            lastName: $fields[self::FIELD_LAST_NAME] ?? '',
            email: $fields['email'] ?? null,
            nationalId: $fields['national_id'] ?? null,
            department: $fields['department'] ?? null,
            hiredAt: $fields[self::FIELD_HIRED_AT] ?? null,
            locale: $fields['locale'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, int>  $departments
     * @param  array<string, int>  $seen  Clave de identidad -> linea en la que aparecio.
     * @param  array<string, int>  $seenEmails  Correo normalizado -> linea en la que aparecio.
     */
    private function planRow(
        int $line,
        array $fields,
        array $departments,
        array &$seen,
        array &$seenEmails,
    ): ImportRow {
        $employee = self::employeeFrom($fields);

        $match = $this->matchOf($employee);

        $errors = [...$this->errorsOf($employee, $departments, $seen, $seenEmails), ...$match->errors];

        if ($errors !== []) {
            return ImportRow::rejected($line, $this->labelOf($employee), $errors);
        }

        // Se apunta DESPUES de validar: una linea ya rechazada no debe consumir
        // ni la clave ni el correo, y hacer que la siguiente —correcta— salga
        // como duplicada de algo que no se llego a importar.
        $key = $employee->identityKey();

        if ($key !== null) {
            $seen[$key] = $line;
        }

        if ($employee->email !== null) {
            $seenEmails[ImportedEmployee::normaliseEmail($employee->email)] = $line;
        }

        $existing = $match->employee;

        return $existing instanceof Employee
            ? ImportRow::matched(
                $line,
                $employee,
                $existing->uuid,
                $this->changesFor($existing, $employee, $departments),
                $this->hiredAtWarning($existing, $employee),
            )
            : ImportRow::created($line, $employee);
    }

    /**
     * @param  array<string, int>  $departments
     * @param  array<string, int>  $seen
     * @param  array<string, int>  $seenEmails
     * @return list<ImportMessage>
     */
    private function errorsOf(
        ImportedEmployee $employee,
        array $departments,
        array $seen,
        array $seenEmails,
    ): array {
        $errors = [];

        if ($employee->firstName === '') {
            $errors[] = ImportMessage::of(ImportMessageCode::MISSING_FIRST_NAME, self::FIELD_FIRST_NAME);
        }

        if ($employee->lastName === '') {
            $errors[] = ImportMessage::of(ImportMessageCode::MISSING_LAST_NAME, self::FIELD_LAST_NAME);
        }

        $key = $employee->identityKey();
        $repeated = $key !== null && isset($seen[$key]);

        if ($key === null) {
            $errors[] = ImportMessage::of(ImportMessageCode::MISSING_IDENTITY);
        } elseif ($repeated) {
            $errors[] = ImportMessage::of(ImportMessageCode::DUPLICATE_IN_FILE);
        }

        $errors = [...$errors, ...$this->emailErrors($employee, $seenEmails, $repeated)];

        if ($employee->nationalId !== null && mb_strlen($employee->nationalId) < 4) {
            // La misma cota que `POST /employees`. No se valida la FORMA de un
            // DNI: el producto se vende a hoteles que contratan a personas con
            // NIE, pasaporte y documentos de otros paises, y un patron espanol
            // rechazaria a media plantilla de temporada.
            $errors[] = ImportMessage::of(ImportMessageCode::INVALID_NATIONAL_ID, 'national_id');
        }

        return [...$errors, ...$this->hiredAtErrors($employee), ...$this->departmentErrors($employee, $departments)];
    }

    /**
     * Lo que puede ir mal con el correo de una linea.
     *
     * @param  array<string, int>  $seenEmails  Correos ya consumidos por lineas anteriores, por linea.
     * @param  bool  $repeated  La linea ya salio como duplicada de la misma persona.
     * @return list<ImportMessage>
     */
    private function emailErrors(ImportedEmployee $employee, array $seenEmails, bool $repeated): array
    {
        if ($employee->email === null) {
            return [];
        }

        if (filter_var($employee->email, FILTER_VALIDATE_EMAIL) === false) {
            return [ImportMessage::of(ImportMessageCode::INVALID_EMAIL, 'email')];
        }

        // DOS PERSONAS DISTINTAS CON EL MISMO CORREO, dentro del propio fichero.
        // No es lo mismo que `duplicate_in_file` —que es la MISMA persona dos
        // veces— y por eso no comparte codigo: aqui hay dos documentos y un solo
        // correo, y el indice unico parcial de `employees.email` no dejaria
        // escribir la segunda. Se dice aqui, con su numero de linea, en lugar de
        // dejar que la fase de aplicacion reviente el lote entero con un `409`
        // que no nombra ninguna.
        //
        // No se emite si la fila ya salio como repetida: seria el mismo hecho
        // dicho dos veces con dos palabras distintas.
        return ! $repeated && isset($seenEmails[ImportedEmployee::normaliseEmail($employee->email)])
            ? [ImportMessage::of(ImportMessageCode::EMAIL_TAKEN, 'email')]
            : [];
    }

    /**
     * @return list<ImportMessage>
     */
    private function hiredAtErrors(ImportedEmployee $employee): array
    {
        if ($employee->hiredAt === null) {
            return [ImportMessage::of(ImportMessageCode::MISSING_HIRED_AT, self::FIELD_HIRED_AT)];
        }

        return self::parseDate($employee->hiredAt) === null
            ? [ImportMessage::of(ImportMessageCode::INVALID_HIRED_AT, self::FIELD_HIRED_AT)]
            : [];
    }

    /**
     * @param  array<string, int>  $departments
     * @return list<ImportMessage>
     */
    private function departmentErrors(ImportedEmployee $employee, array $departments): array
    {
        if ($employee->department === null) {
            // Sin departamento es legitimo: la columna es opcional y
            // `employees.department_id` es nullable.
            return [];
        }

        return isset($departments[ImportColumnMap::normalise($employee->department)])
            ? []
            : [ImportMessage::of(ImportMessageCode::UNKNOWN_DEPARTMENT, 'department')];

    }

    /**
     * @return list<ImportMessage>
     */
    private function hiredAtWarning(Employee $existing, ImportedEmployee $employee): array
    {
        $date = $employee->hiredAt === null ? null : self::parseDate($employee->hiredAt);

        if ($date === null || $date->format('Y-m-d') === $existing->hiredAt->format('Y-m-d')) {
            return [];
        }

        return [ImportMessage::of(ImportMessageCode::HIRED_AT_NOT_UPDATED, self::FIELD_HIRED_AT)];
    }

    /**
     * A quien corresponde esta linea de la plantilla ya existente, y si el
     * intento de emparejarla ha encontrado algo que impide seguir.
     *
     * ## EL DOCUMENTO MANDA, Y CUANDO HAY DOCUMENTO EL CORREO NO EMPAREJA
     *
     * Es la correccion mas importante de la revision de la 5.5, y el fallo que
     * cierra era el peor que este endpoint podia producir. Antes se buscaba por
     * documento y, **si no aparecia nadie, se buscaba por correo**: una linea con
     * un documento nuevo y el correo de otra persona —dos camareros compartiendo
     * `recepcion@hotel.example`, o una errata en una celda— salia como `update`
     * de la ficha de esa otra persona. Se le reescribia el nombre y los
     * apellidos, y a partir de ahi **su registro horario quedaba a nombre de
     * quien no era** (RL-04, regla dura 5). Nadie lo habria notado hasta una
     * nomina o una inspeccion.
     *
     * Ahora: si la linea trae documento, se busca **solo** por documento. Si no
     * aparece nadie, es un alta — aunque su correo ya exista.
     *
     * ## Ese correo repetido no se ignora: se rechaza la linea
     *
     * Porque el indice unico parcial de `employees.email` no dejaria escribirla,
     * y el `409` que produciria llega en la fase de aplicacion **sin decir que
     * linea**, despues de que quien importa haya revisado un informe que decia
     * que todo iba bien. Con `email_taken` se dice antes, con su numero de linea
     * y con un texto que explica que hay dos fichas peleandose por un correo.
     *
     * ## Solo sin documento se empareja por correo
     *
     * Y sigue siendo necesario: el fichero puede no traer columna de documento
     * (regla dura 12), y sin ninguna de las dos claves la reimportacion
     * duplicaria. Ahi el correo **es** la identidad de la linea, asi que
     * emparejar por el es lo correcto y no hay conflicto que detectar.
     */
    private function matchOf(ImportedEmployee $employee): ImportMatch
    {
        if ($employee->nationalId !== null) {
            $byDocument = $this->directory->uuidByNationalId($employee->nationalId);
            $byEmail = $employee->email === null
                ? null
                : $this->directory->uuidByEmail($employee->email);

            // El correo esta ocupado por ALGUIEN QUE NO ES esta persona. Cubre
            // los dos casos: documento nuevo con correo ajeno, y documento
            // conocido cuyo correo ha pasado a ser de un tercero.
            if ($byEmail !== null && $byEmail !== $byDocument) {
                return ImportMatch::rejected(ImportMessage::of(ImportMessageCode::EMAIL_TAKEN, 'email'));
            }

            return ImportMatch::of($byDocument === null ? null : $this->employees->findByUuid($byDocument));
        }

        $byEmail = $employee->email === null
            ? null
            : $this->directory->uuidByEmail($employee->email);

        return ImportMatch::of($byEmail === null ? null : $this->employees->findByUuid($byEmail));
    }

    /**
     * Que cambia respecto de lo guardado. **Nunca `hired_at`** (regla dura 5).
     *
     * @param  array<string, int>  $departments
     * @return list<string>
     */
    private function changesFor(Employee $existing, ImportedEmployee $employee, array $departments): array
    {
        $departmentId = $employee->department === null
            ? null
            : ($departments[ImportColumnMap::normalise($employee->department)] ?? null);

        // Una tabla campo -> «¿cambia?» en lugar de una cadena de `if`: son
        // cinco preguntas independientes y del mismo tipo, y escritas asi el
        // limite de complejidad del §3.5 no obliga a repartirlas por cinco
        // metodos que nadie leeria juntos.
        //
        // LOS TRES CAMPOS OPCIONALES COMPARTEN LA MISMA REGLA: si el fichero no
        // trae el valor, NO cambia nada. Una columna ausente o una celda vacia no
        // son una instruccion de borrado —el fichero puede no traer correo
        // (regla dura 12)— y tratarlas como tal vaciaria el correo de media
        // plantilla en la primera reimportacion.
        $candidates = [
            self::FIELD_FIRST_NAME => $employee->firstName !== $existing->firstName,
            self::FIELD_LAST_NAME => $employee->lastName !== $existing->lastName,
            'email' => $employee->email !== null
                && mb_strtolower($employee->email) !== mb_strtolower($existing->email ?? ''),
            'department_id' => $departmentId !== null && $departmentId !== $existing->departmentId,
            'locale' => $employee->locale !== null && $employee->locale !== $existing->locale,
        ];

        // `hired_at` NO esta en la tabla, y no puede estarlo: una importacion no
        // reescribe la fecha de alta de nadie (regla dura 5). Su divergencia sale
        // como aviso en {@see self::hiredAtWarning()}.
        return array_keys(array_filter($candidates));
    }

    private function labelOf(ImportedEmployee $employee): string
    {
        $label = $employee->label();

        // Una linea sin nombre tiene que poder localizarse igual: el informe la
        // sitúa por su numero de linea y este texto solo evita una celda vacia.
        return $label === '' ? '—' : $label;
    }

    /**
     * Fecha del fichero, o `null` si no lo es.
     *
     * **Tres formatos y en este orden**: ISO primero porque es lo que exporta
     * cualquier sistema, y despues los dos que teclea una persona en una hoja de
     * calculo espanola. `d/m/Y` **antes** que `m/d/Y`, que ni siquiera se acepta:
     * `03/04/2026` es el 3 de abril para quien lo escribio, y aceptar el formato
     * americano convertiria esa fecha en el 4 de marzo sin que nadie lo notara.
     * Una fecha de alta mal leida por un mes es un mes de jornadas imputables que
     * no deberian existir.
     */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

            // `createFromFormat` acepta `2026-02-31` y lo desborda a marzo: la
            // comparacion de vuelta es lo unico que caza esas fechas.
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}
