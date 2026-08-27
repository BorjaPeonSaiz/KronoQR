<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Http\Policy\ScanPolicy;
use App\Modules\Attendance\Http\Support\ScanningDevice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Config;

/**
 * Validacion del lote de sincronizacion (`POST /api/v1/scan/batch`).
 *
 * Las mismas reglas que {@see RegisterScanRequest}, elemento a elemento, y por
 * los mismos motivos: `qr_payload` **sin patron** (regla dura 17), `occurred_at`
 * solo en UTC con `Z` (regla dura 3) y `scan_id` como UUID v7 (regla dura 8). Lo
 * que cambia es lo que las envuelve.
 *
 * ## Un lote mal formado es `400` entero, y es una decision
 *
 * Si un solo elemento no cumple el contrato, la peticion completa se rechaza con
 * `400`. La alternativa —aceptar el lote y devolver el elemento invalido con su
 * propio error— parece mas amable con la regla dura 19, pero no lo es: los tres
 * campos de cada elemento **los genera el propio quiosco** (el `scan_id` al
 * encolar, el `occurred_at` de su reloj, el `qr_payload` de la camara), asi que
 * un elemento mal formado no describe una tarjeta mala, describe un cliente roto.
 * Aceptarlo a medias significaria escribir en el registro legal lo que un cliente
 * roto haya decidido enviar.
 *
 * El riesgo que esto deja abierto —una cola que no drena porque arrastra un
 * elemento imposible— es real y se resuelve **en el quiosco**, que ante un `400`
 * no debe reintentar el mismo lote sin cambios (tarea 1.9). Queda escrito aqui y
 * en el contrato para que sea una decision y no un descubrimiento.
 *
 * ## La cabecera es del lote, no de ningun escaneo
 *
 * A diferencia del endpoint individual, `Idempotency-Key` **no** coincide con
 * ningun `scan_id`: identifica el envio. La idempotencia real es elemento a
 * elemento y la da el UNIQUE de `scan_events.scan_id` (regla dura 8), no esta
 * cabecera; se exige igualmente porque es lo que permite reconocer en los
 * registros que dos envios eran el mismo reintento.
 *
 * ## El techo del lote sale de la configuracion
 *
 * `config('kiosk.batch_max_size')`, no una constante (regla dura 13): un hotel
 * con veinte quioscos puede querer otro. El contrato declara el mismo valor en
 * `ScanBatchRequest.maxItems` y una prueba ata los dos.
 */
final class RegisterScanBatchRequest extends FormRequest
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownFields;
    }

    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const string UTC_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /**
     * Regla dura 18: policy propia del endpoint, invocada por su nombre.
     *
     * No pasa por el `Gate` global por el motivo tecnico que documenta
     * {@see ScanPolicy}: el `tokenable` de un quiosco es una fila de `devices`,
     * que no implementa `Authorizable`, y el pipeline de permisos reventaria con
     * un `TypeError` antes de llegar a decidir nada.
     */
    public function authorize(): bool
    {
        return (new ScanPolicy)->sync($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'scans' => ['required', 'array', 'min:1', 'max:'.$this->maxBatchSize()],
            'scans.*' => ['required', 'array'],
            'scans.*.scan_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'scans.*.occurred_at' => ['required', 'string', 'regex:'.self::UTC_INSTANT],
            'scans.*.qr_payload' => ['required', 'string', 'min:1', 'max:128'],
            'scans.*.intent' => ['sometimes', 'string', 'in:auto,break_start,break_end'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scans.max' => 'Un lote no puede llevar mas de :max escaneos.',
            'scans.*.scan_id.regex' => 'El identificador del escaneo debe ser un UUID v7 generado en el cliente.',
            'scans.*.occurred_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownFields($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateBatchKey($validator);
            $this->rejectUnknownFieldsInsideScans($validator);
        });
    }

    /**
     * `400` y no `422`, igual que en el escaneo suelto: el `422` de este camino
     * esta reservado al rechazo generico de credencial y no puede compartirse con
     * un error de forma (regla dura 17).
     */
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }

    public function toBatch(): ScanBatch
    {
        $device = ScanningDevice::of($this);

        /** @var list<array<string, mixed>> $scans */
        $scans = $this->array('scans');

        $commands = [];

        foreach ($scans as $scan) {
            $intent = $scan['intent'] ?? null;

            $commands[] = new RegisterScanCommand(
                // Los tres campos ya pasaron por `rules()`, que exige `string`:
                // esta funcion los vuelve a estrechar porque el analisis estatico
                // no puede saberlo, y porque un `(string)` sobre un array seria un
                // aviso de PHP convertido en dato en la base.
                scanId: $this->stringField($scan, 'scan_id'),
                qrPayload: $this->stringField($scan, 'qr_payload'),
                occurredAt: new DateTimeImmutable($this->stringField($scan, 'occurred_at'), new DateTimeZone('UTC')),
                deviceId: $device->id,
                deviceUuid: $device->uuid,
                // El origen no lo declara el cliente, igual que en el escaneo
                // suelto: este endpoint es el de la tarjeta. Dejar que la peticion
                // lo eligiera permitiria presentar un fichaje por PIN como un
                // escaneo de tarjeta.
                origin: ScanOrigin::QR_KIOSK,
                intent: is_string($intent) ? ScanIntent::from($intent) : ScanIntent::AUTO,
            );
        }

        // El orden lo pone `ScanBatch`, no este metodo: es la regla que decide si
        // una entrada y una salida encoladas se convierten en la jornada real o
        // en una inventada (doc 02 §6).
        return ScanBatch::of($commands);
    }

    private function maxBatchSize(): int
    {
        return max(1, Config::integer('kiosk.batch_max_size', 50));
    }

    /**
     * Un campo de texto de un elemento del lote, ya estrechado a `string`.
     *
     * `rules()` garantiza que los tres existen y son cadenas —si no, esta funcion
     * no se llega a ejecutar—, pero el analisis estatico no puede saberlo y un
     * `(string)` sobre lo que devuelve el cuerpo de una peticion es un `(string)`
     * sobre `mixed`. La cadena vacia es inalcanzable en la practica; devolverla en
     * lugar de lanzar mantiene esta funcion sin ramas que ninguna prueba pueda
     * cubrir.
     *
     * @param  array<string, mixed>  $scan
     */
    private function stringField(array $scan, string $field): string
    {
        $value = $scan[$field] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * La cabecera del lote: obligatoria y UUID v7.
     *
     * No se compara con ningun `scan_id` —seria comparar el envio con uno de sus
     * elementos— pero se exige con la misma forma que ellos: un cliente que la
     * omite es un cliente que no ha leido el contrato, y prefiero enterarme aqui
     * que en el primer reintento raro de un lote a las seis de la manana.
     */
    private function validateBatchKey(Validator $validator): void
    {
        $header = $this->header('Idempotency-Key');

        if (! is_string($header) || $header === '') {
            $validator->errors()->add('Idempotency-Key', 'La cabecera Idempotency-Key es obligatoria.');

            return;
        }

        if (preg_match(self::UUID_V7, $header) !== 1) {
            $validator->errors()->add(
                'Idempotency-Key',
                'La cabecera Idempotency-Key del lote debe ser un UUID v7.',
            );
        }
    }

    /**
     * `RejectsUnknownInput` compara con las claves de primer nivel de
     * `rules()`, asi que ve `scans` y se queda ahi: un campo de mas **dentro** de
     * un elemento pasaria sin que nadie lo mire.
     *
     * Importa por la misma razon que en el resto del producto: quien envia
     * `device_id` en un elemento se va convencido de haber atribuido ese fichaje a
     * otro quiosco, y no ha atribuido nada — el dispositivo sale del token
     * (ver {@see ScanningDevice}). Fallar en voz alta es mejor que acertar por
     * casualidad.
     */
    private function rejectUnknownFieldsInsideScans(Validator $validator): void
    {
        $known = ['scan_id' => true, 'occurred_at' => true, 'qr_payload' => true, 'intent' => true];

        $scans = $this->input('scans');

        if (! is_array($scans)) {
            return;
        }

        foreach ($scans as $index => $scan) {
            if (! is_array($scan)) {
                continue;
            }

            foreach (array_keys($scan) as $field) {
                if (! \array_key_exists((string) $field, $known)) {
                    $validator->errors()->add(
                        'scans.'.$index.'.'.$field,
                        'El campo '.$field.' no forma parte de esta peticion.',
                    );
                }
            }
        }
    }
}
