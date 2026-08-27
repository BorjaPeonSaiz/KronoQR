<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\RegisterPinScanCommand;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Http\Policy\ScanPolicy;
use App\Modules\Attendance\Http\Support\ScanningDevice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validacion del fichaje de respaldo por PIN (`POST /api/v1/scan/pin`,
 * RF-AT-11).
 *
 * Hermano de {@see RegisterScanRequest} y con las mismas decisiones de fondo
 * —`400` para la forma y `422` para el rechazo, `Idempotency-Key` obligatoria,
 * instantes solo en UTC—. Lo que cambia son los dos campos que identifican a la
 * persona, y ahi hay tres cosas que explicar.
 *
 * ## `employee_code` no lleva patron, y es la regla dura 17
 *
 * Solo se comprueba la longitud. Un `regex` que describiera la forma del codigo
 * —prefijo, alfabeto, longitud exacta— haria que un codigo malformado devolviera
 * `400` y uno bien formado pero inexistente devolviera `422`, y con eso la
 * **validacion** distinguiria lo que el codigo se cuida de no distinguir: quien
 * quisiera saber si un codigo existe solo tendria que mirar el codigo de estado.
 * Es exactamente la misma ausencia que tiene `qr_payload` en el otro endpoint, y
 * el contrato la comparte con una prueba que la vigila.
 *
 * ## `pin_sealed` si lleva patron, y no contradice lo anterior
 *
 * El sobre es base64 de un criptograma de longitud fija: **su forma no depende
 * de si el PIN es correcto ni de si el empleado existe**. Un PIN bueno y uno
 * malo producen sobres indistinguibles. Validar la forma aqui frena a un cliente
 * mal escrito sin filtrar nada, y ademas evita descifrar basura: sin techo de
 * longitud, cualquiera podria obligar al servidor a intentar abrir megabytes.
 *
 * ## El PIN nunca aparece sin cerrar
 *
 * No hay campo `pin`. No lo hay a proposito: si existiera, el quiosco podria
 * mandarlo en claro «solo cuando hay red» y el dia que alguien encolara esa
 * peticion el PIN acabaria en IndexedDB. Un unico campo, siempre cerrado, hace
 * que la version insegura no se pueda ni expresar. El formato lo documenta
 * `Shared\Application\Port\SealedPinOpener`.
 *
 * ## Lo que este `FormRequest` NO hace
 *
 * **No comprueba que el PIN tenga seis digitos**, porque no puede: llega
 * cerrado. Lo hace el hash, que no coincide con nada que no sea el PIN emitido.
 * Y **no declara el origen**: este endpoint es el del PIN y su `ScanOrigin` lo
 * fija el caso de uso, no la peticion.
 */
final class PinScanRequest extends FormRequest
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownFields;
    }

    /**
     * UUID **v7**, no v4 (regla dura 8, doc 02 §6): ordenable temporalmente, lo
     * que mantiene la localidad del indice de `scan_events`.
     */
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const string UTC_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /** Base64 estandar, con o sin relleno. */
    private const string BASE64 = '/^[A-Za-z0-9+\/]+={0,2}$/';

    /**
     * Regla dura 18: la policy, siempre.
     *
     * Se llama por su nombre y no por el `Gate` global por lo mismo que en
     * `/scan`: el portador de este token es un quiosco, no una cuenta con roles
     * (ver {@see ScanPolicy}). Es la mitad de la autorizacion; la otra es el
     * ambito `scan:write`, que verifica el middleware `ability` de Sanctum antes
     * de llegar aqui.
     */
    public function authorize(): bool
    {
        return (new ScanPolicy)->recordByPin($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'scan_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'occurred_at' => ['required', 'string', 'regex:'.self::UTC_INSTANT],
            'employee_code' => ['required', 'string', 'min:1', 'max:32'],
            // 72 caracteres es el sobre de un PIN de seis digitos; el rango deja
            // sitio a un relleno distinto sin admitir nada que merezca la pena
            // intentar descifrar.
            'pin_sealed' => ['required', 'string', 'min:64', 'max:160', 'regex:'.self::BASE64],
            'intent' => ['sometimes', 'string', 'in:auto,break_start,break_end'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scan_id.regex' => 'El identificador del escaneo debe ser un UUID v7 generado en el cliente.',
            'occurred_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
            // El mensaje habla del SOBRE y no del PIN: describir el contenido
            // seria describir un secreto en un cuerpo de error.
            'pin_sealed.regex' => 'El PIN debe viajar cerrado con la clave publica de la instalacion, en base64.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator);

        $validator->after(function (Validator $validator): void {
            $header = $this->header('Idempotency-Key');

            if (! \is_string($header) || $header === '') {
                $validator->errors()->add('Idempotency-Key', 'La cabecera Idempotency-Key es obligatoria.');

                return;
            }

            if ($header !== $this->input('scan_id')) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'La cabecera Idempotency-Key debe coincidir con scan_id.',
                );
            }
        });
    }

    /**
     * `400` y no `422`: en este endpoint el `422` es el rechazo generico de
     * escaneo y no puede compartirse con un error de forma.
     */
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }

    public function toCommand(): RegisterPinScanCommand
    {
        $device = ScanningDevice::of($this);
        $intent = $this->input('intent');

        return new RegisterPinScanCommand(
            scanId: $this->string('scan_id')->value(),
            employeeCode: $this->string('employee_code')->value(),
            sealedPin: $this->string('pin_sealed')->value(),
            occurredAt: new DateTimeImmutable(
                $this->string('occurred_at')->value(),
                new DateTimeZone('UTC'),
            ),
            deviceId: $device->id,
            deviceUuid: $device->uuid,
            intent: \is_string($intent) ? ScanIntent::from($intent) : ScanIntent::AUTO,
        );
    }
}
