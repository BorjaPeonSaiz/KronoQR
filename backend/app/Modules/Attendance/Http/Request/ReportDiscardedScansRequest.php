<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\DiscardedScanReportInput;
use App\Modules\Attendance\Application\Command\ReportDiscardedScansCommand;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Http\Policy\ScanPolicy;
use App\Modules\Attendance\Http\Support\ScanningDevice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /api/v1/scan/discarded` (esquema `DiscardedScanReportBatch`, RN-22,
 * ADR-047).
 *
 * **El esquema esta congelado**: solo crece con campos opcionales, porque es el
 * canal de ultimo recurso precisamente cuando la tablet y el servidor no estan
 * en la misma version. Por eso la validacion es la del contrato y ni un campo
 * mas: un `400` aqui es un aviso que la tablet ya no puede entregar.
 *
 * El `qr_payload` va **sin patron** (lo que se leyo, tal cual) y solo con
 * `kind: qr`; el `employee_code`, solo con `kind: pin`. **Nunca el PIN** ni su
 * sobre: un campo `pin_sealed` es un campo desconocido y la peticion entera es
 * `400`.
 */
final class ReportDiscardedScansRequest extends FormRequest
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownFields;
    }

    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const string UTC_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    private const string PROBLEM_TYPE = '/^urn:kronoqr:problem:[a-z0-9-]+$/';

    /** Los campos de un aviso, tal cual los declara `DiscardedScanReport`. */
    private const array REPORT_FIELDS = [
        'scan_id', 'occurred_at', 'kind', 'http_status', 'problem_type', 'discarded_at', 'qr_payload', 'employee_code',
    ];

    public function authorize(): bool
    {
        return (new ScanPolicy)->reportDiscarded($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reports' => ['required', 'array', 'list', 'min:1', 'max:'.ReportDiscardedScansCommand::MAX_REPORTS],
            'reports.*' => ['required', 'array:'.implode(',', self::REPORT_FIELDS)],
            'reports.*.scan_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'reports.*.occurred_at' => ['required', 'string', 'regex:'.self::UTC_INSTANT],
            'reports.*.kind' => ['required', 'string', 'in:qr,pin'],
            'reports.*.http_status' => ['required', 'integer', 'min:400', 'max:499'],
            // `present` y `nullable`: el contrato lo exige siempre y admite `null`.
            'reports.*.problem_type' => ['present', 'nullable', 'string', 'max:200', 'regex:'.self::PROBLEM_TYPE],
            'reports.*.discarded_at' => ['required', 'string', 'regex:'.self::UTC_INSTANT],
            'reports.*.qr_payload' => ['prohibited_unless:reports.*.kind,qr', 'string', 'min:1', 'max:512'],
            'reports.*.employee_code' => ['prohibited_unless:reports.*.kind,pin', 'string', 'min:1', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reports.max' => 'Un envio no puede llevar mas de :max avisos.',
            'reports.*.scan_id.regex' => 'El identificador del escaneo debe ser un UUID v7 generado en el cliente.',
            'reports.*.occurred_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
            'reports.*.discarded_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator);
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }

    public function toCommand(): ReportDiscardedScansCommand
    {
        $device = ScanningDevice::of($this);

        $reports = [];

        foreach ($this->array('reports') as $report) {
            if (\is_array($report)) {
                $reports[] = $this->reportFrom($report);
            }
        }

        return new ReportDiscardedScansCommand($device->id, $device->uuid, $reports);
    }

    /**
     * Un aviso ya validado, en el vocabulario del caso de uso.
     *
     * @param  array<mixed>  $report
     */
    private function reportFrom(array $report): DiscardedScanReportInput
    {
        $status = $report['http_status'] ?? null;

        return new DiscardedScanReportInput(
            scanId: $this->stringField($report, 'scan_id'),
            occurredAt: $this->instant($report, 'occurred_at'),
            // El origen sale de la via declarada: es lo que la tablet sabe de su
            // propio fichaje, y solo decide como se intenta atribuir.
            origin: $this->stringField($report, 'kind') === 'pin' ? ScanOrigin::PIN_KIOSK : ScanOrigin::QR_KIOSK,
            httpStatus: is_numeric($status) ? (int) $status : 400,
            problemType: $this->optionalField($report, 'problem_type'),
            discardedAt: $this->instant($report, 'discarded_at'),
            qrPayload: $this->optionalField($report, 'qr_payload'),
            employeeCode: $this->optionalField($report, 'employee_code'),
        );
    }

    /**
     * @param  array<mixed>  $report
     */
    private function optionalField(array $report, string $field): ?string
    {
        $value = $report[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $report
     */
    private function stringField(array $report, string $field): string
    {
        $value = $report[$field] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param  array<mixed>  $report
     */
    private function instant(array $report, string $field): DateTimeImmutable
    {
        return new DateTimeImmutable($this->stringField($report, $field), new DateTimeZone('UTC'));
    }
}
