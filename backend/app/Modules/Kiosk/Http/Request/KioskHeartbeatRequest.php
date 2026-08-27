<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Application\Command\RecordHeartbeatCommand;
use App\Modules\Kiosk\Http\Policy\KioskPolicy;
use App\Modules\Kiosk\Http\Support\KioskDevice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validacion del latido (`POST /api/v1/kiosk/heartbeat`).
 *
 * ## Que se valida y por que tan poco
 *
 * Los tres campos son **telemetria declarada por el propio dispositivo** y
 * ninguno influye en el registro horario: lo que se comprueba es que no puedan
 * hacer daño —longitudes, rangos, formato de instante—, no que sean ciertos. Un
 * quiosco que mienta sobre su cola ensucia el panel de salud y no cambia ni un
 * fichaje.
 *
 * `pending_queue_size` lleva techo por la misma razon que `qr_payload` lleva
 * longitud maxima: es proteccion de recursos, no validacion de negocio. Una cola
 * de cien mil elementos es una averia, y aceptar un entero arbitrario solo sirve
 * para que alguien escriba un numero absurdo en una metrica.
 *
 * ## `oldest_pending_at`, en UTC como todo lo demas
 *
 * Regla dura 3: solo se acepta el sufijo `Z`. Aqui el motivo es mas debil que en
 * un fichaje —no se persiste, solo alimenta el diagnostico— pero la excepcion
 * seria peor que la regla: dos formatos de instante en la misma API son dos
 * formatos que alguien tendra que distinguir a mano algun dia.
 *
 * ## `400`, no `422`
 *
 * Como en el resto del camino del quiosco. El `422` significa «tarjeta rechazada»
 * para este cliente y no puede compartirse con un error de forma (regla dura 17).
 */
final class KioskHeartbeatRequest extends FormRequest
{
    use RejectsUnknownInput;

    private const string UTC_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /**
     * Mismo alfabeto que el contrato: alfanumerico, punto, guion y `+`. Cubre
     * SemVer con precompilacion y metadatos (`1.4.2-rc.1+build.7`) y deja fuera
     * cualquier cosa que acabe en una etiqueta de Prometheus sin escapar.
     */
    private const string APP_VERSION = '/^[0-9A-Za-z][0-9A-Za-z.+-]*$/';

    public function authorize(): bool
    {
        return (new KioskPolicy)->sendHeartbeat($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'app_version' => ['required', 'string', 'min:1', 'max:32', 'regex:'.self::APP_VERSION],
            'pending_queue_size' => ['required', 'integer', 'min:0', 'max:100000'],
            'oldest_pending_at' => ['sometimes', 'string', 'regex:'.self::UTC_INSTANT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'oldest_pending_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }

    public function toCommand(): RecordHeartbeatCommand
    {
        $device = KioskDevice::of($this);
        $oldest = $this->input('oldest_pending_at');

        return new RecordHeartbeatCommand(
            deviceId: $device->id,
            deviceUuid: $device->uuid,
            appVersion: $this->string('app_version')->value(),
            pendingQueueSize: $this->integer('pending_queue_size'),
            oldestPendingAt: is_string($oldest)
                ? new DateTimeImmutable($oldest, new DateTimeZone('UTC'))
                : null,
        );
    }
}
