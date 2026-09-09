<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Product\Http\Policy\ErrorEventPolicy;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\ClientErrorCode;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/client-errors` — el buffer de errores del panel y del portal
 * (contrato `ClientErrorBatch`, **RF-PD-15**, decision 7 de la ficha 5.12).
 *
 * ## EL SERVIDOR DECIDE EL ORIGEN, no el cuerpo
 *
 * Es la regla central de este endpoint. `source` sale del **tipo de token**
 * —sesion de gestion → `admin`, sesion de portal → `portal`— y el cuerpo no
 * tiene ni siquiera un campo donde intentarlo (el contrato no lo declara). Si lo
 * decidiera el cliente, cualquier portador de una sesion podria escribir filas
 * `source: scheduler` en el historico y ensuciar el diagnostico de la
 * instalacion con errores que nunca ocurrieron en el servidor.
 *
 * Lo mismo vale para el **nivel**: lo pone {@see ErrorLevel::forClientCode()} a
 * partir del origen **y** del codigo, no el cliente. Un panel no decide que es
 * critico en esta instalacion.
 *
 * ## El codigo se valida contra el catalogo cerrado DE SU ORIGEN
 *
 * {@see ClientErrorCode} y no solo el patron del contrato, y es la correccion de
 * la revision de seguridad. Con el patron a secas pasaban dos cosas:
 *
 * 1. **Una sesion de portal podia enviar `kiosk.camera.unavailable`** y fabricar
 *    filas `critical` que disparan la alerta al IT del cliente de madrugada.
 *    Ahora un codigo de quiosco desde el portal es una peticion invalida, y
 *    ademas `forClientCode()` solo devuelve `critical` con origen `kiosk`: dos
 *    controles, no uno.
 * 2. **Un codigo libre daba una huella nueva por envio**, sin techo. El catalogo
 *    corta la entropia en el origen; el techo de grupos por origen
 *    (`PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE`) pone la cota que falta.
 *
 * ## El texto del error asciende del contexto a la columna
 *
 * Los tres reporters mandan el mensaje del navegador dentro del contexto, con la
 * clave `message` ({@see ErrorContextAllowlist::MESSAGE_KEY}). El servidor lo
 * saca de ahi, lo guarda en `error_events.message` y **lo retira del contexto**.
 * Sin eso, la fila del panel decia literalmente `web.vue_error` y nada mas —y,
 * peor, **todos los `web.vue_error` de la instalacion colapsaban en un unico
 * grupo**, porque la huella se calcula sobre el mensaje—.
 *
 * ## Y el servidor **vuelve a sanear**
 *
 * El cliente ya sanea. No se confia: el saneado del cliente es una cortesia, no
 * una garantia (ver {@see ErrorMessageSanitizer}).
 * Lo hace el caso de uso, que es el unico camino hacia la tabla.
 *
 * ## Rechaza lo desconocido, tambien dentro de cada error
 *
 * El contrato declara `additionalProperties: false` en `ClientErrorReport`, y
 * {@see RejectsUnknownInput} solo mira el primer nivel. Sin la comprobacion de
 * abajo, un cliente que enviara `stack` o `user` se iria convencido de haberlo
 * mandado y el servidor lo habria tirado en silencio — que es justo la clase de
 * malentendido con la que alguien acaba metiendo PII «porque total, se guarda».
 * Aqui se le dice que no, con el nombre del campo.
 *
 * Si no viene `message`, el mensaje que se guarda es el propio codigo: una fila
 * sin mensaje no dice nada, y el codigo al menos identifica el fallo.
 *
 * ## El techo son 50, y coincide con el buffer del cliente
 *
 * Ni uno mas por envio. Es el tamano del buffer de `clientErrorTransport` y el
 * `maxItems` del contrato: un envio que pudiera traer mil convertiria una sesion
 * autenticada en una forma barata de llenar la tabla del historico.
 */
final class ReportClientErrorsRequest extends FormRequest
{
    /** Tope por envio. El mismo que el buffer del cliente y que el contrato. */
    public const int MAX_ERRORS = 50;

    /** Tope de claves de contexto, el `maxProperties` del contrato. */
    private const int MAX_CONTEXT_KEYS = 12;

    /** Los campos que un error de cliente puede traer. Todo lo demas es un `422`. */
    private const array REPORT_FIELDS = ['code', 'occurred_at', 'app_version', 'context'];

    /*
     * El `withValidator()` del trait se conserva con otro nombre porque esta
     * clase define el suyo: hacen falta los dos —el del trait mira el primer
     * nivel y el de aqui mira dentro de cada error— y sin el alias el segundo
     * dejaria mudo al primero.
     */
    use RejectsUnknownInput {
        withValidator as private rejectUnknownRootFields;
    }

    public function authorize(): bool
    {
        /*
         * Por su nombre y no por el `Gate`: esta ruta acepta tambien una sesion
         * de portal, cuyo `tokenable` no es `Authorizable` y reventaria con un
         * `TypeError` en el `Gate::before` del paquete de permisos. Mismo
         * criterio que `SelfJournalPolicy`; el razonamiento completo esta en
         * {@see ErrorEventPolicy}.
         */
        return (new ErrorEventPolicy)->report($this->user());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'errors' => ['required', 'array', 'min:1', 'max:'.self::MAX_ERRORS],
            // El mismo patron que el contrato: minusculas, puntos y nada mas. Un
            // codigo que no case es un `422`, no una fila con texto libre.
            'errors.*.code' => [
                'required',
                'string',
                'min:3',
                'max:80',
                'regex:/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/',
                // Y ademas del CATALOGO CERRADO DE SU ORIGEN. Ver el docblock.
                Rule::in(ClientErrorCode::forSource($this->source())),
            ],
            'errors.*.occurred_at' => ['required', 'date'],
            'errors.*.app_version' => ['required', 'string', 'max:32', 'regex:/^[0-9A-Za-z][0-9A-Za-z.+-]*$/'],
            // `present` y no `required`: el contrato lo declara obligatorio pero
            // admite el objeto vacio -su propio ejemplo lo lleva asi-, y
            // `required` rechaza un `{}`. Un error de cliente sin contexto es un
            // caso normal, no una peticion mal formada.
            'errors.*.context' => ['present', 'array', 'max:'.self::MAX_CONTEXT_KEYS],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Lo del primer nivel (`errors` y nada mas).
        $this->rejectUnknownRootFields($validator);

        $validator->after(function (Validator $validator): void {
            $errors = $this->input('errors');

            if (! is_array($errors)) {
                return;
            }

            foreach ($errors as $index => $report) {
                if (! is_array($report)) {
                    continue;
                }

                foreach (array_keys($report) as $field) {
                    if (! in_array($field, self::REPORT_FIELDS, true)) {
                        $message = __('validation.unknown_field', ['attribute' => (string) $field]);

                        $validator->errors()->add(
                            'errors.'.$index.'.'.$field,
                            is_string($message) ? $message : 'The field is not part of this request.',
                        );
                    }
                }
            }
        });
    }

    /**
     * Lo que reporta el cliente, ya con **el origen que decide el servidor**.
     *
     * @return list<ErrorReport>
     */
    public function toReports(): array
    {
        $source = $this->source();
        $employeeUuid = $this->portalEmployeeUuid($source);

        /** @var list<array{code: string, occurred_at: string, app_version: string, context: array<array-key, mixed>}> $raw */
        $raw = $this->validated('errors');

        return array_map(
            fn (array $report): ErrorReport => new ErrorReport(
                source: $source,
                // Del codigo Y DEL ORIGEN, nunca del cliente. Ver el docblock.
                level: ErrorLevel::forClientCode($source, $report['code']),
                message: self::message($report['code'], $report['context']),
                occurredAt: new DateTimeImmutable($report['occurred_at'], new DateTimeZone('UTC')),
                appVersion: $report['app_version'],
                context: self::scalars($report['context']),
                code: $report['code'],
                // Sin `exception_class`, sin `file` y sin `line`: el `stack` del
                // navegador nunca viaja (una URL con un uuid dentro correlaciona
                // a una persona). El codigo hace ese trabajo en la huella.
                exceptionClass: null,
                file: null,
                line: null,
                /*
                 * Sin traza, y no por descuido: la unica que hay aqui es la de
                 * ESTA peticion —la que reporta—, no la de aquella en la que
                 * fallo algo dentro de un navegador. Guardarla mandaria a quien
                 * investigue al log de un `POST /client-errors` que salio bien.
                 */
                traceId: null,
                // Ningun dispositivo: el quiosco no usa esta ruta, tiene la suya
                // dentro del latido.
                deviceId: null,
                employeeUuid: $employeeUuid,
                // Un error de navegador no pertenece a ningun modulo del
                // monolito.
                module: null,
            ),
            $raw,
        );
    }

    /**
     * `admin` o `portal`, **segun el tipo de token**.
     *
     * La policy ya garantizo que sea uno de los dos; llegar aqui con otra cosa
     * seria un fallo del programa —una ruta sin autorizar— y no una respuesta
     * mas. Se cae del lado seguro, `portal`, que es el que menos concede.
     */
    private function source(): ErrorSource
    {
        return $this->user() instanceof ManagementActor ? ErrorSource::Admin : ErrorSource::Portal;
    }

    /**
     * El UUID publico del empleado cuando quien reporta es el portal.
     *
     * **Solo el uuid** (regla dura 21), y solo en el portal: en una sesion de
     * gestion quien esta detras es una cuenta de `users`, que no es un empleado,
     * y meter su identificador en la columna `employee_uuid` seria escribir un
     * dato en la columna de otro.
     *
     * Es informacion util y acotada: dice **desde que portal** falla algo, que es
     * lo que distingue «al portal le pasa a todo el mundo» de «a esta persona le
     * pasa con su movil».
     */
    private function portalEmployeeUuid(ErrorSource $source): ?string
    {
        if ($source !== ErrorSource::Portal) {
            return null;
        }

        $actor = $this->user();

        if (! $actor instanceof Model || $actor->getTable() !== ErrorEventPolicy::EMPLOYEES_TABLE) {
            return null;
        }

        $uuid = $actor->getAttribute('uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * El mensaje que se guarda. Ver el docblock de la clase.
     *
     * @param  array<array-key, mixed>  $context
     */
    private static function message(string $code, array $context): string
    {
        $message = ErrorContextAllowlist::messageIn($context);

        return $message ?? $code;
    }

    /**
     * El contexto con solo escalares y **sin `message`**.
     *
     * El filtrado por lista de permitidos y el saneado los hace despues el caso
     * de uso, que es el unico camino hacia la tabla; aqui solo se descarta lo
     * que no cabe en el tipo del puerto y se retira la clave que ya ha ascendido
     * a la columna `message`: guardarla dos veces la dejaria con dos longitudes
     * maximas distintas —1000 y 200— y el paquete de diagnostico la llevaria
     * duplicada.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, scalar|null>
     */
    private static function scalars(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if ($key === ErrorContextAllowlist::MESSAGE_KEY) {
                continue;
            }

            if (is_string($key) && is_scalar($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
