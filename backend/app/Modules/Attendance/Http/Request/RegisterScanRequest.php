<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Http\Policy\ScanPolicy;
use App\Modules\Attendance\Http\Support\ScanningDevice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validacion del escaneo del quiosco (`POST /api/v1/scan`).
 *
 * ## Lo que se valida, y lo que deliberadamente NO
 *
 * `qr_payload` se comprueba **solo por longitud** (regla dura 17, RS-03). No hay
 * `regex`, ni prefijo, ni formato: si lo hubiera, un payload con el prefijo
 * cambiado devolveria `400` y una credencial revocada devolveria `422`, y con
 * eso la **validacion** distinguiria causas que el codigo se cuida de no
 * distinguir. La longitud es proteccion de recursos y no depende del contenido,
 * asi que no filtra nada. El contrato tiene la misma ausencia, con una prueba
 * que la vigila.
 *
 * ## Por que un error de forma es `400` y no `422`
 *
 * En este endpoint el `422` esta reservado al rechazo generico de escaneo. Un
 * campo que falta tiene que ser distinguible de una tarjeta que no vale, porque
 * el quiosco hace cosas opuestas con cada uno: la peticion mal formada no se
 * reintenta —volveria a fallar—, y el rechazo de credencial se muestra al
 * empleado y se descarta de la cola (RF-KI-04, ADR-031). Es la unica razon por
 * la que este `FormRequest` redefine `failedValidation()`.
 *
 * ## La cabecera `Idempotency-Key`
 *
 * Obligatoria y **debe coincidir con `scan_id`**. Va en cabecera ademas de en el
 * cuerpo porque es la convencion que entienden los intermediarios HTTP y porque
 * deja la intencion explicita en el borde, antes de deserializar nada. Pero **la
 * garantia real la da el UNIQUE de `scan_events.scan_id`** (regla dura 8), no
 * esta comprobacion: la cabecera solo sirve para detectar un cliente mal escrito
 * antes de que su error se confunda con otra cosa.
 *
 * ## Instantes
 *
 * `occurred_at` se acepta **solo en UTC con sufijo `Z`** (regla dura 3): admitir
 * un desplazamiento explicito convertiria la zona horaria en un dato del
 * cliente, y con turnos nocturnos y cambio de hora eso es una jornada atribuida
 * al dia equivocado (RN-05, RN-09). El patron es el mismo del esquema
 * `UtcTimestamp` del contrato.
 */
final class RegisterScanRequest extends FormRequest
{
    /**
     * El alias existe porque este `FormRequest` necesita **dos** comprobaciones
     * posteriores —los campos desconocidos y la cabecera de idempotencia— y las
     * dos entran por `withValidator()`. Renombrar la del trait y llamarla desde
     * la propia es la forma de componerlas sin que una pise a la otra.
     */
    use RejectsUnknownInput {
        withValidator as private rejectUnknownFields;
    }

    /**
     * UUID **v7**, no v4 (regla dura 8, doc 02 §6): es ordenable temporalmente,
     * lo que mantiene la localidad del indice de `scan_events` y evita la
     * fragmentacion que produciria un v4 aleatorio con millones de filas.
     */
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const string UTC_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /**
     * Regla dura 18: la policy, siempre.
     *
     * Se llama por su nombre y no por el `Gate` global porque el pipeline de
     * permisos da por hecho que quien autoriza es una cuenta con roles, y el
     * portador de este token es un quiosco (ver {@see ScanPolicy}). Es la mitad
     * de la autorizacion: la otra es el ambito `scan:write`, que verifica el
     * middleware `ability` de Sanctum antes de llegar aqui.
     */
    public function authorize(): bool
    {
        return (new ScanPolicy)->record($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'scan_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'occurred_at' => ['required', 'string', 'regex:'.self::UTC_INSTANT],
            'qr_payload' => ['required', 'string', 'min:1', 'max:128'],
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator);

        $validator->after(function (Validator $validator): void {
            $header = $this->header('Idempotency-Key');

            if (! is_string($header) || $header === '') {
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

    public function toCommand(): RegisterScanCommand
    {
        $device = ScanningDevice::of($this);
        $intent = $this->input('intent');

        return new RegisterScanCommand(
            scanId: $this->string('scan_id')->value(),
            qrPayload: $this->string('qr_payload')->value(),
            occurredAt: new DateTimeImmutable(
                $this->string('occurred_at')->value(),
                new DateTimeZone('UTC'),
            ),
            deviceId: $device->id,
            deviceUuid: $device->uuid,
            // El origen no lo declara el cliente: este endpoint es el de la
            // tarjeta. El fichaje por PIN de RF-AT-11 tiene el suyo (tarea
            // 1.13), y dejar que la peticion eligiera el origen permitiria
            // presentar un fichaje por PIN como un escaneo de tarjeta.
            origin: ScanOrigin::QR_KIOSK,
            intent: is_string($intent) ? ScanIntent::from($intent) : ScanIntent::AUTO,
        );
    }
}
