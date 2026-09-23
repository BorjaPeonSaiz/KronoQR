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
 * `period` es `ReportExportPolicy::request` y `payroll` es
 * `ReportExportPolicy::requestPayroll` (Anexo B: la nomina es rol `rrhh`). Se
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
 * quien descarga. Y `payroll` **no admite PDF** (decision 5): un programa de
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

    public function authorize(): bool
    {
        return Gate::allows(
            $this->kind() === ReportExportKind::Payroll ? 'requestPayroll' : 'request',
            ReportExport::class,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->periodReportRules(),
            'kind' => ['required', 'string', 'in:'.implode(',', ReportExportKind::names())],
            // La lista base es la union de los dos catalogos; el cruce `kind` x
            // `format` lo comprueba `withValidator()`, que es donde ya se sabe
            // que `kind` es valido.
            'format' => ['required', 'string', 'in:'.implode(',', self::allFormats())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Primero lo comun —entrada desconocida y rango construible—, despues lo
        // propio de esta peticion. Ver el `use` de arriba.
        $this->validateWorkDateRange($validator);

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
     * es **lo que se pidio**, y el alcance viaja por separado (decision 1). Ver
     * {@see ReportExportParameters}.
     */
    public function toParameters(): ReportExportParameters
    {
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
     * Los formatos que admite alguna clase de informe. Una sola lista: la que
     * valida es la que traduce, asi que no pueden divergir.
     *
     * @return list<string>
     */
    private static function allFormats(): array
    {
        return array_values(array_unique(array_merge(
            ReportExportKind::Period->allows(),
            ReportExportKind::Payroll->allows(),
        )));
    }
}
