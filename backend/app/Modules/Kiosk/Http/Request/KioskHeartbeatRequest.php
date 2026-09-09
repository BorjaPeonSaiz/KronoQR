<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Application\Command\RecordHeartbeatCommand;
use App\Modules\Kiosk\Http\Policy\KioskPolicy;
use App\Modules\Kiosk\Http\Support\KioskDevice;
use App\Modules\Shared\Domain\ValueObject\ClientErrorCode;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Closure;
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
 * Los tres campos de telemetria son **declarados por el propio dispositivo** y
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
 * ## `client_errors`: el cuarto campo, y el unico que se persiste
 *
 * Los errores de la propia tablet suben **dentro del latido** (RF-PD-15, tarea
 * 5.12, decision 7). Aqui se comprueba la **forma** —techo de 50, instante en
 * UTC, contexto de doce claves escalares— y, sobre todo, que el `code` este en
 * el **catalogo cerrado del quiosco** ({@see ClientErrorCode}); despues se
 * convierte cada elemento en un {@see ErrorReport}. Lo que NO se hace aqui es
 * sanear: el saneado es del servidor y ocurre al persistir, porque no se confia
 * en que el cliente lo haya hecho (decision 5).
 *
 * **Tres campos los pone el servidor y no el cuerpo**: el `source` (`kiosk`, que
 * lo decide el tipo de token), el `device_id` (el UUID publico del dispositivo
 * autenticado) y el `level`, que sale de {@see ErrorLevel::forClientCode()}
 * **con el origen delante**. Un cliente no elige como se le clasifica ni de parte
 * de quien habla.
 *
 * El texto del error viaja en `context.message` y **se eleva a la columna
 * `message`**, retirandolo del contexto: es la clave canonica que comparten los
 * tres reporters y `/api/v1/client-errors` (decision 5).
 *
 * ## `400`, no `422`
 *
 * Como en el resto del camino del quiosco. El `422` significa «tarjeta rechazada»
 * para este cliente y no puede compartirse con un error de forma (regla dura 17).
 * Un `client_errors` malformado tumba el latido entero con `400` —el contrato lo
 * dice— y no pasa nada: la cola de fichajes drena por `/scan/batch`, que es otro
 * endpoint (regla dura 19).
 */
final class KioskHeartbeatRequest extends FormRequest
{
    use RejectsUnknownInput;

    /** Techo del contrato (`KioskHeartbeatRequest.client_errors.maxItems`). */
    private const int MAX_CLIENT_ERRORS = 50;

    /** Claves de contexto del contrato (`ClientErrorReport.context.maxProperties`). */
    private const int MAX_CONTEXT_KEYS = 12;

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
     * @return array<string, list<string|Closure(string, mixed, Closure): void>>
     */
    public function rules(): array
    {
        return [
            'app_version' => ['required', 'string', 'min:1', 'max:32', 'regex:'.self::APP_VERSION],
            'pending_queue_size' => ['required', 'integer', 'min:0', 'max:100000'],
            'oldest_pending_at' => ['sometimes', 'string', 'regex:'.self::UTC_INSTANT],
            'client_errors' => ['sometimes', 'array', 'max:'.self::MAX_CLIENT_ERRORS],
            // `array:` con las cuatro claves reproduce el `additionalProperties:
            // false` del contrato dentro de cada elemento: sin el, un campo de mas
            // —un `stack`, un `device_id`— se ignoraria en silencio y quien lo
            // envio creeria que se guarda.
            'client_errors.*' => ['array:code,occurred_at,app_version,context'],
            // Contra el CATALOGO CERRADO del quiosco y no solo contra el patron
            // del contrato (decision 3, revision de seguridad): un codigo libre
            // pasaba la forma, creaba una huella nueva por envio -600 grupos por
            // minuto y actor, sin techo- y, si imitaba uno de camara, fabricaba
            // una fila `critical` que despierta al IT del cliente.
            'client_errors.*.code' => ['required', 'string', $this->knownKioskCode()],
            'client_errors.*.occurred_at' => ['required', 'string', 'regex:'.self::UTC_INSTANT],
            'client_errors.*.app_version' => ['required', 'string', 'min:1', 'max:32', 'regex:'.self::APP_VERSION],
            // `present` y no `required`: el contrato lo declara obligatorio pero
            // admite `{}` —el ejemplo del propio `openapi.yaml` lo lleva vacio— y
            // `required` rechaza el array vacio. Tumbar un latido por un error
            // que no traia contexto seria perder los otros 49 (regla dura 19).
            'client_errors.*.context' => ['present', 'array', 'max:'.self::MAX_CONTEXT_KEYS],
            // `nullable` porque `convertEmptyStringsToNull` corre antes que la
            // validacion: un `context` con una cadena vacia —valida en el
            // contrato— llegaria aqui como `null` y tumbaria el latido entero por
            // un valor que no dice nada (regla dura 19).
            'client_errors.*.context.*' => ['nullable', $this->scalarValue()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'oldest_pending_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
            'client_errors.*.occurred_at.regex' => 'El instante debe ir en UTC con sufijo Z.',
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
            clientErrors: $this->clientErrors($device->uuid),
        );
    }

    /**
     * Los errores de la tablet, ya tipados y con el origen puesto por el servidor.
     *
     * @return list<ErrorReport>
     */
    private function clientErrors(string $deviceUuid): array
    {
        $raw = $this->input('client_errors');

        if (! is_array($raw)) {
            return [];
        }

        $reports = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reports[] = $this->toReport($item, $deviceUuid);
        }

        return $reports;
    }

    /**
     * @param  array<array-key, mixed>  $item  Ya validado: los cuatro campos existen y tienen la forma del contrato.
     */
    private function toReport(array $item, string $deviceUuid): ErrorReport
    {
        $code = is_string($item['code'] ?? null) ? $item['code'] : '';
        $context = $this->contextOf($item);

        /*
         * `message` es la CLAVE CANONICA del texto del error y se ELEVA a la
         * columna `message` (decision 5). Se retira del contexto en el mismo
         * gesto: repetido en los dos sitios ocuparia dos veces el mismo dato,
         * gastaria una de las doce claves permitidas y obligaria a sanearlo dos
         * veces con dos reglas distintas -la del mensaje admite 1 000 caracteres
         * y la del contexto 200-.
         *
         * Los tres reporters de cliente la usan (`errorReporter.ts`,
         * `clientErrors.ts`) y `/client-errors` hace lo mismo con ella: sin una
         * clave acordada, cada `kiosk.unhandled_error` llegaba sin texto y todos
         * colapsaban en una sola fila del panel.
         */
        $message = $context['message'] ?? null;

        unset($context['message']);

        return new ErrorReport(
            // Lo decide el TIPO DE TOKEN y no el cuerpo (decision 7): quien llega
            // por aqui se autentico como dispositivo.
            source: ErrorSource::Kiosk,
            // Con el ORIGEN delante (decision 3, revision de seguridad): solo un
            // quiosco produce un `critical` de cliente.
            level: ErrorLevel::forClientCode(ErrorSource::Kiosk, $code),
            // El mensaje del contexto si lo hay, y si no el propio codigo: una
            // fila del panel sin mensaje seria una fila que no dice nada, y el
            // codigo al menos identifica el fallo.
            message: is_string($message) && $message !== '' ? $message : $code,
            occurredAt: new DateTimeImmutable(
                is_string($item['occurred_at'] ?? null) ? $item['occurred_at'] : 'now',
                new DateTimeZone('UTC'),
            ),
            appVersion: is_string($item['app_version'] ?? null) ? $item['app_version'] : '',
            context: $context,
            code: $code,
            // El identificador PUBLICO del quiosco, el unico que puede aparecer en
            // un log tecnico o en `error_events` (regla dura 21). Del token, nunca
            // del cuerpo.
            deviceId: $deviceUuid,
        );
    }

    /**
     * El contexto tal cual lo mando la tablet, quedandose solo con los escalares.
     *
     * **No filtra por nombre de clave y no es un olvido**: la lista de permitidos
     * es del servidor y vive en el saneado de `Product` (decision 5), que es quien
     * tiene que decidirla para los tres clientes a la vez. Aqui solo se descarta
     * lo que no cabe en la columna —una estructura anidada—, que es lo que el
     * tipo de {@see ErrorReport} exige.
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, scalar|null>
     */
    private function contextOf(array $item): array
    {
        $raw = $item['context'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $context = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * El codigo tiene que estar en el catalogo **del quiosco**.
     *
     * `ClientErrorCode` es copia literal del tipo `ClientErrorCode` de
     * `errorReporter.ts`, atada por prueba de arquitectura. Validar contra el
     * catalogo y no contra el patron es lo que impide las dos cosas que encontro
     * la revision de seguridad: la entropia sin techo de un codigo libre —cada
     * envio, una huella y una fila nuevas— y que un origen fabrique la severidad
     * de otro.
     *
     * El mensaje nombra el catalogo pero **no lo enumera**: veinticinco codigos
     * en un `400` no ayudan a la tablet, que solo emite los suyos, y una lista de
     * lo que el servidor acepta es informacion que no hace falta dar.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function knownKioskCode(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! ClientErrorCode::isKnown(ErrorSource::Kiosk, $value)) {
                $fail('El codigo no pertenece al catalogo de errores del quiosco.');
            }
        };
    }

    /**
     * El `oneOf` de escalares del contrato (`string`, `integer`, `number`,
     * `boolean`), que Laravel no tiene como regla.
     *
     * Rechazar una estructura anidada aqui —y no descartarla en silencio al
     * construir el informe— es lo que hace que quien la envie se entere: un
     * `context` con un objeto dentro es casi siempre una fila de datos que alguien
     * metio por comodidad, y esa es exactamente la via por la que se cuela un
     * nombre (regla dura 21).
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function scalarValue(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_scalar($value)) {
                $fail('El contexto solo admite valores simples.');
            }
        };
    }
}
