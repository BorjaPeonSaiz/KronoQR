<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/reports/exports` — que informe se genera en diferido y en que
 * formato (**RF-IN-06**, **RF-IN-07**).
 *
 * ## Los parametros del informe son **los mismos** que los de la consulta
 *
 * Y lo son por construccion, no por acuerdo: los declara
 * {@see DescribesPeriodReport}, el mismo rasgo que usan la consulta del panel y
 * su descarga sincrona. Con tres listas separadas bastaria añadir un filtro en
 * una para que el fichero que llega por el enlace describiera un informe distinto
 * del que estaba mirando en pantalla — y el que se creeria seria el equivocado.
 *
 * Lo unico propio son `kind` y `format`.
 *
 * ## La autorizacion depende de `kind`, y por eso se decide aqui
 *
 * `period` es `ReportExportPolicy::request`, `payroll` es
 * `ReportExportPolicy::requestPayroll` (Anexo B: la nomina es rol `rrhh`), y
 * desde la 2.3.0 `legal` es `requestLegal` y `adoption` es `requestAdoption`.
 * Con `legal` y `adoption` solo valen `from`, `to` y —en la legal—
 * `employee_uuid`; la legal no tiene techo de dias, como la sincrona. Se
 * resuelve en `authorize()` y no en el controlador porque es lo que decide si la
 * peticion llega a existir, y porque asi la prueba de autorizacion negativa
 * apunta a un solo sitio.
 *
 * **`kind` se lee con `tryFrom` antes de validar**, que es la unica forma de
 * saber que policy aplicar cuando el cuerpo todavia no ha pasado por las reglas.
 * Un `kind` desconocido cae en la policy mas estrecha —la de nomina— y despues
 * en el `422` de validacion: nunca en la mas ancha por no saber cual era.
 *
 * ## `format` es obligatorio y depende de `kind`
 *
 * **Sin valor por omision.** Suponer CSV porque es el mas comun seria decidir por
 * quien descarga. Y `payroll` **no admite PDF**: un programa de
 * nomina no importa un PDF, y ofrecerlo produciria descargas inservibles cuyo
 * unico desenlace es una llamada de soporte. La lista la da
 * {@see ReportExportKind::allows()}, que es tambien de donde se compone el
 * `CHECK` de la migracion.
 *
 * ## `json` no se admite
 *
 * Esa forma la sirve `GET /reports/period`. Un informe en diferido siempre acaba
 * en fichero.
 */
final class RequestReportExportRequest extends FormRequest
{
    /*
     * **El `withValidator` del rasgo se renombra en lugar de perderse.** Un
     * metodo declarado en la clase gana al del rasgo, asi que sin este alias la
     * comprobacion del orden de las fechas y del techo de 366 dias —que vive en
     * `ValidatesWorkDateRange` y llega hasta aqui a traves de
     * {@see DescribesPeriodReport}— **dejaria de ejecutarse en silencio**: un
     * rango invertido pasaria la validacion, llegaria al trabajo en cola y
     * moriria alli, dejando una fila `failed` en lugar de un `422`.
     */
    use DescribesPeriodReport {
        withValidator as private validateWorkDateRange;
    }

    /**
     * El ambito que exige toda clase que no sea `legal`. Es una copia de
     * `TokenAbility::REPORTS_ALL` de `Identity`, que este modulo no puede
     * importar (Deptrac); `ReportExportAbilityTest` falla si dejan de coincidir.
     */
    public const string ALL_REPORTS_ABILITY = 'reports:*';

    /**
     * Los parametros que solo tienen sentido en el informe por periodo y la
     * nomina. Con `legal` y `adoption` son `422`: un filtro ignorado en silencio
     * dejaria a quien atiende un requerimiento convencido de haber acotado la
     * exportacion.
     */
    private const array PERIOD_REPORT_ONLY = ['granularity', 'group_by', 'include_open_shifts', 'department_id'];

    /**
     * La policy de cada clase y, ademas del rol, **el ambito del token** (doc 02
     * §7.3). La ruta acepta `reports:legal` o `reports:*` porque el `auditor`
     * tiene que poder pedir la exportacion para la Inspeccion; todo lo demas
     * exige `reports:*`, y sin esta comprobacion un token estrecho alcanzaria un
     * informe de horas solo con que su rol lo permitiera.
     */
    public function authorize(): bool
    {
        $kind = $this->kind();

        if ($kind !== ReportExportKind::Legal && ! $this->tokenCanAllReports()) {
            return false;
        }

        return Gate::allows(match ($kind) {
            ReportExportKind::Period => 'request',
            ReportExportKind::Payroll => 'requestPayroll',
            ReportExportKind::Legal => 'requestLegal',
            ReportExportKind::Adoption => 'requestAdoption',
        }, ReportExport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [
            ...$this->periodReportRules(),
            'kind' => ['required', 'string', 'in:'.implode(',', ReportExportKind::names())],
            // La lista base es la union de los catalogos; el cruce `kind` x
            // `format` lo comprueba `withValidator()`, que es donde ya se sabe
            // que `kind` es valido.
            'format' => ['required', 'string', 'in:'.implode(',', ReportExportKind::formats())],
        ];

        foreach (self::PERIOD_REPORT_ONLY as $field) {
            $rules[$field][] = 'prohibited_if:kind,'.ReportExportKind::Legal->value.','.ReportExportKind::Adoption->value;
        }

        $rules['employee_uuid'][] = 'prohibited_if:kind,'.ReportExportKind::Adoption->value;

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        /*
         * `legal` no tiene techo de dias, como la exportacion sincrona: un
         * requerimiento puede abarcar los cuatro años de conservacion (RL-02).
         * Pasa por lo comun —entrada desconocida— y comprueba solo el orden. El
         * resto, por el rango construible de `ValidatesWorkDateRange`, con su
         * techo de 366 dias.
         */
        if ($this->kind() === ReportExportKind::Legal) {
            $this->rejectUnknownInput($validator);
            $validator->after(fn (Validator $validator) => $this->validatePeriodIsNotInverted($validator));
        } else {
            $this->validateWorkDateRange($validator);
        }

        $validator->after(function (Validator $validator): void {
            $kind = ReportExportKind::tryFrom($this->string('kind')->value());
            $format = $this->string('format')->value();

            if ($kind === null) {
                return;
            }

            if ($format !== '' && ! \in_array($format, $kind->allows(), true)) {
                $validator->errors()->add('format', __('validation.in', ['attribute' => 'format']));
            }

            $this->validatePayrollShape($validator, $kind);
        });
    }

    /**
     * Las dos acotaciones propias de la nomina, **las mismas que la descarga
     * sincrona** (RF-IN-07, `ExportPayrollRequest`).
     *
     * - **`group_by` es siempre `employee`.** Una nomina se paga a personas; un
     *   fichero agregado por departamento no tiene ningun uso en un programa de
     *   nomina y, aceptado en silencio, seria una forma comoda de generar un
     *   fichero que no se puede importar.
     * - **`granularity` admite tres de las cuatro, sin `week`.** Los periodos de
     *   nomina son el mes o un rango libre, nunca la semana ISO, que produciria
     *   filas a caballo de dos meses que ningun importador sabe repartir.
     *
     * Se rechazan con `422` en lugar de corregirse en silencio, que es la
     * diferencia entre el diferido y el sincrono: alli el `group_by` ni siquiera
     * se pide, y aqui **se guarda en la fila**. Un parametro corregido por dentro
     * dejaria una fila que dice una cosa y un fichero que dice otra.
     */
    private function validatePayrollShape(Validator $validator, ReportExportKind $kind): void
    {
        if ($kind !== ReportExportKind::Payroll) {
            return;
        }

        $grouping = $this->string('group_by')->value();

        if ($grouping !== '' && $grouping !== ReportGrouping::Employee->value) {
            $validator->errors()->add('group_by', __('validation.in', ['attribute' => 'group_by']));
        }

        if ($this->string('granularity')->value() === ReportGranularity::Week->value) {
            $validator->errors()->add('granularity', __('validation.in', ['attribute' => 'granularity']));
        }
    }

    /**
     * El orden del periodo de la exportacion legal, la unica comprobacion de
     * rango que comparte con la sincrona (`after_or_equal:from`). Las fechas ya
     * pasaron `date_format:Y-m-d`, asi que comparar el texto es comparar fechas.
     */
    private function validatePeriodIsNotInverted(Validator $validator): void
    {
        $from = $this->isoDate('from');
        $to = $this->isoDate('to');

        if ($validator->errors()->isNotEmpty() || $from === null || $to === null) {
            return;
        }

        if ($to < $from) {
            $validator->errors()->add('to', __('validation.after_or_equal', ['attribute' => 'to', 'date' => 'from']));
        }
    }

    /**
     * La clase de informe pedida, **antes** de validar.
     *
     * Devuelve `payroll` ante cualquier valor desconocido a proposito: lo que
     * decide es que policy corre, y ante la duda tiene que correr la mas
     * estrecha. El valor invalido lo rechaza despues `rules()` con un `422`.
     */
    public function kind(): ReportExportKind
    {
        return ReportExportKind::tryFrom($this->string('kind')->value()) ?? ReportExportKind::Payroll;
    }

    /** El formato pedido, ya validado. */
    public function exportFormat(): string
    {
        return $this->string('format')->value();
    }

    /**
     * Los parametros del informe, sin el alcance: ese lo pone el servidor.
     *
     * Se construyen aqui y no con `toQuery()` porque lo que se guarda en la fila
     * es **lo que se pidio**, y el alcance viaja por separado. Con
     * `legal` y `adoption`, solo el periodo y —en la legal— la persona: ver
     * {@see ReportExportParameters::span()}.
     */
    public function toParameters(): ReportExportParameters
    {
        $kind = $this->kind();

        if (! $kind->isPeriodReport()) {
            return ReportExportParameters::span(
                (string) $this->isoDate('from'),
                (string) $this->isoDate('to'),
                $kind === ReportExportKind::Legal ? $this->requestedEmployeeUuid() : null,
            );
        }

        return new ReportExportParameters(
            from: (string) $this->isoDate('from'),
            to: (string) $this->isoDate('to'),
            granularity: $this->requestedGranularity(),
            grouping: $this->requestedGrouping(),
            includeOpenShifts: $this->boolean('include_open_shifts'),
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            employeeUuid: $this->requestedEmployeeUuid(),
        );
    }

    /**
     * `day` por omision para el informe de horas y `range` para la nomina, que es
     * lo mismo que hacen sus dos caminos sincronos.
     *
     * No es una inconsistencia: son dos preguntas distintas. El informe por
     * periodo se lee, y `day` es el grano de la fuente —el unico que no agrega
     * nada—; la nomina se importa, y la pregunta normal es «lo de este mes, en
     * una fila por persona».
     */
    private function requestedGranularity(): ReportGranularity
    {
        $value = $this->string('granularity')->value();

        if ($value !== '') {
            return ReportGranularity::from($value);
        }

        return $this->kind() === ReportExportKind::Payroll
            ? ReportGranularity::Range
            : ReportGranularity::Day;
    }

    /** `employee` por omision, que es la pregunta de partida de RF-IN-01. */
    private function requestedGrouping(): ReportGrouping
    {
        $value = $this->string('group_by')->value();

        return $value === '' ? ReportGrouping::Employee : ReportGrouping::from($value);
    }

    private function requestedEmployeeUuid(): ?string
    {
        $value = $this->input('employee_uuid');

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Las fechas llegan **en el cuerpo**, no en la cadena de consulta.
     *
     * Sobrescribe el ayudante del rasgo {@see ValidatesWorkDateRange}, que lee de
     * `query()` porque las otras tres peticiones del informe son `GET`. Aqui es
     * un `POST` con cuerpo JSON, y sin esto la comprobacion del orden y del techo
     * de dias del rango —la que usa `DateRange::between()`— **no encontraria las
     * fechas y se saltaria en silencio**: un rango invertido llegaria hasta el
     * trabajo en cola y moriria alli, con una fila `failed` en lugar de un `422`.
     *
     * Un metodo de la clase gana al del rasgo, que es exactamente el mecanismo
     * que se usa aqui.
     */
    private function isoDate(string $parameter): ?string
    {
        $value = $this->input($parameter);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Por `method_exists` y no por tipo: quien lleva el rasgo `HasApiTokens` es
     * un modelo de `Identity` —o la concesion de soporte de `Product`—, y este
     * modulo no puede nombrarlo. Mismo patron que `UpdateDepartmentRequest`.
     */
    private function tokenCanAllReports(): bool
    {
        /** @var mixed $actor */
        $actor = $this->user();

        if (! \is_object($actor) || ! method_exists($actor, 'tokenCan')) {
            return false;
        }

        return $actor->tokenCan(self::ALL_REPORTS_ABILITY) === true;
    }
}
