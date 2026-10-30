<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use Closure;
use Illuminate\Testing\TestResponse;
use LogicException;
use Spectator\RequestFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\ContractCoverage;
use Tests\Architecture\Support\Repo;
use Tests\Support\Http\Api;
use Tests\Support\Http\AuthorizationMatrix;

/**
 * Los barridos del contrato: un codigo de respuesta compartido, provocado y
 * validado con Spectator en **cada** operacion que lo declara (Q3, RQ-06).
 *
 * El contrato declara 719 pares (operacion, codigo), y mas de la mitad son
 * respuestas compartidas —`401`, `403`, `415`, `400`, `429`, `503`— que salen
 * del mismo middleware o del mismo renderizador de excepciones en todas las
 * rutas. Escribir una prueba a mano por cada par seria escribir cuatrocientas
 * veces la misma, y olvidar la cuatrocientas una. Un barrido recorre las
 * operaciones que declaran el codigo, leidas del propio `openapi.yaml`, pide
 * cada una por HTTP y devuelve una linea por cada operacion que no responde ese
 * codigo o lo responde con otra forma: {@see self::discrepancies()}.
 *
 * ## Una prueba por codigo, no una por operacion
 *
 * Como `AuthorizationMatrixTest`: cada caso de Pest arranca la aplicacion y
 * abre su transaccion, unos 0,2 s, y con 430 pares eso eran 90 s de suite para
 * pedir 430 veces lo mismo. El barrido pide todas las operaciones dentro de una
 * prueba, acumula las discrepancias y afirma la lista vacia, con la operacion,
 * el estado y el error de Spectator en cada linea.
 *
 * ## Lo que un barrido aparta
 *
 * Una operacion puede declarar un codigo compartido por un motivo que no es el
 * del barrido: el `401` de `POST /auth/login` son credenciales incorrectas, no
 * la falta de sesion. Esas se apartan aqui, **con su motivo**, en
 * {@see self::SET_ASIDE}, y entonces el par tiene que validarlo una prueba
 * explicita con el codigo escrito. La puerta
 * `Tests\Architecture\ContractStatusCoverageTest` lo exige: apartar un par del
 * barrido no lo da por cubierto.
 *
 * ## Como lo sabe la puerta
 *
 * La puerta lee las pruebas como texto y no puede ver que operaciones recorre
 * un barrido. Lo reconoce por su forma —`ContractSweep::discrepancies(N, ...)`
 * con `N` literal, en un fichero que carga el contrato— y pregunta a esta clase
 * que pares cubre: {@see self::sweptPairs()}. Las dos cosas salen del mismo
 * sitio, asi que no pueden divergir.
 */
final class ContractSweep
{
    private const string SINGLETON = 'Recurso unico de la instalacion: su 404 es la instalacion sin el, y la matriz siempre lo crea.';

    private const string BODY_FIRST = 'Valida el cuerpo antes de buscar el recurso: sin un cuerpo valido responde 422, y el 404 necesita uno escrito para la operacion.';

    private const string KIOSK_PATH = 'El camino del quiosco valida con 400 invalid-request; su 422 es el rechazo generico y de tiempo constante (RS-03), con sus pruebas propias.';

    /**
     * Las operaciones que un barrido no puede ejercitar por su via, por codigo,
     * con el motivo. Cada una tiene su prueba explicita en otro sitio, que la
     * puerta exige.
     *
     * @var array<int, array<string, string>>
     */
    private const array SET_ASIDE = [
        401 => [
            'POST /api/v1/auth/login' => 'Puerta de acceso sin sesion: su 401 son credenciales rechazadas, no la falta de token.',
            'POST /api/v1/me/login' => 'Puerta de acceso sin sesion: su 401 es PortalAccessDenied, el rechazo generico del codigo y el PIN.',
        ],
        403 => [
            'POST /api/v1/broadcasting/auth' => 'El 403 de la firma lo decide la policy del canal, con el canal en el cuerpo y el difusor real: lo valida PresenceChannelAuthorizationTest.',
        ],
        400 => [
            'GET /api/v1/kiosk/roster' => 'Un GET no lleva cuerpo que leer, asi que el 400 de un JSON mal formado (D6) no puede darse: su 400 es el invalid-request de la consulta (InvalidQuery), que valida RosterDisclosureTest.',
        ],
        404 => [
            'GET /api/v1/compliance-profile' => self::SINGLETON,
            'PATCH /api/v1/compliance-profile' => self::SINGLETON,
            'GET /api/v1/site' => self::SINGLETON,
            'PATCH /api/v1/site' => self::SINGLETON,
            'PATCH /api/v1/absences/{uuid}' => self::BODY_FIRST,
            'POST /api/v1/absences/{uuid}/void' => self::BODY_FIRST,
            'PATCH /api/v1/departments/{id}' => self::BODY_FIRST,
            'PATCH /api/v1/shift-entries/{uuid}' => self::BODY_FIRST,
            'POST /api/v1/shift-entries/{uuid}/void' => self::BODY_FIRST,
            'POST /api/v1/credentials' => self::BODY_FIRST,
            'POST /api/v1/credentials/{uuid}/revoke' => self::BODY_FIRST,
            'POST /api/v1/employees/{uuid}/contracts' => self::BODY_FIRST,
            'POST /api/v1/employees/{uuid}/offboard' => self::BODY_FIRST,
            'POST /api/v1/employees/{uuid}/rehire' => self::BODY_FIRST,
            'POST /api/v1/employees/{uuid}/employment-periods/{periodUuid}/termination-corrections' => self::BODY_FIRST,
            'POST /api/v1/incidents/{id}/resolve' => self::BODY_FIRST,
            'POST /api/v1/management-accounts/{uuid}/deactivate' => self::BODY_FIRST,
            'POST /api/v1/management-accounts/{uuid}/password/reset' => self::BODY_FIRST,
            'POST /api/v1/management-accounts/{uuid}/two-factor/reset' => self::BODY_FIRST,
            'PUT /api/v1/setup/steps/{step}' => self::BODY_FIRST,
        ],
        422 => [
            'POST /api/v1/kiosk/pair/claim' => self::KIOSK_PATH,
            'POST /api/v1/scan' => self::KIOSK_PATH,
            'POST /api/v1/scan/pin' => self::KIOSK_PATH,
        ],
        503 => [
            'POST /api/v1/me/login' => 'Valida el cuerpo antes de tocar PostgreSQL y su limitador falla abierto sin Redis (ThrottleFailOpen): el 503 necesita un acceso valido con la base caida.',
        ],
    ];

    /**
     * El `{id}` numerico de las rutas: no `1`, que es el departamento que crea
     * la instalacion de la matriz.
     */
    private const string MISSING_ID = '999999';

    /** @var array<string, list<int>>|null */
    private static ?array $responses = null;

    /**
     * Pide cada operacion que barre el codigo con `$send` y devuelve una linea
     * por cada una cuya respuesta no es ese codigo o no cumple el contrato.
     *
     * Exige el contrato cargado (`Spectator::using`): sin el, Spectator no
     * valida nada y el barrido pasaria en verde sin mirar.
     *
     * @param  Closure(string): TestResponse<Response>  $send
     * @return list<string>
     */
    public static function discrepancies(int $code, Closure $send): array
    {
        return self::discrepanciesAmong(self::sweptOperations($code), $code, $send);
    }

    /**
     * Como {@see self::discrepancies()}, pero sobre las operaciones dadas: para
     * comprobar **otra** forma de provocar un codigo que su barrido ya cubre
     * —el `400` de validacion, ademas del de un JSON mal formado—.
     *
     * La puerta no lo cuenta como barrido, y a proposito: lo que cubre lo
     * decide quien lo llama, no `SET_ASIDE`, y un subconjunto no puede dar por
     * cubierto el codigo entero.
     *
     * @param  list<string>  $operations
     * @param  Closure(string): TestResponse<Response>  $send
     * @return list<string>
     */
    public static function discrepanciesAmong(array $operations, int $code, Closure $send): array
    {
        if (resolve(RequestFactory::class)->getSpec() === null) {
            throw new LogicException('El barrido necesita el contrato cargado: Spectator::using(\'openapi.yaml\').');
        }

        $found = [];

        foreach ($operations as $operation) {
            self::forgetLastValidation();
            $found[] = self::discrepancy($operation, $code, $send($operation));
        }

        return array_values(array_filter($found, static fn (?string $line): bool => $line !== null));
    }

    /**
     * La linea de la discrepancia de una respuesta, o null si es el codigo
     * esperado y Spectator la da por buena.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function discrepancy(string $operation, int $code, TestResponse $response): ?string
    {
        $status = $response->getStatusCode();
        $invalid = resolve(RequestFactory::class)->responseException;

        return match (true) {
            $status !== $code => sprintf('%s: esperado %d, recibido %d %s', $operation, $code, $status, mb_substr((string) $response->getContent(), 0, 200)),
            $invalid !== null => sprintf('%s: %d que no cumple el contrato: %s', $operation, $code, self::plain($invalid->getMessage())),
            default => null,
        };
    }

    /**
     * Las operaciones que barre un codigo: todas las que lo declaran, menos las
     * apartadas.
     *
     * @return list<string>
     */
    public static function sweptOperations(int $code): array
    {
        return array_values(array_diff(self::declaring($code), array_keys(self::setAside($code))));
    }

    /**
     * Los pares `METODO /ruta CODIGO` que cubre el barrido de un codigo.
     *
     * @return list<string>
     */
    public static function sweptPairs(int $code): array
    {
        return array_map(static fn (string $operation): string => $operation.' '.$code, self::sweptOperations($code));
    }

    /**
     * Las operaciones apartadas del barrido de un codigo, con su motivo.
     *
     * @return array<string, string>
     */
    public static function setAside(int $code): array
    {
        return self::SET_ASIDE[$code] ?? [];
    }

    /**
     * Todas las operaciones del contrato que declaran el codigo.
     *
     * @return list<string>
     */
    public static function declaring(int $code): array
    {
        return array_keys(array_filter(
            self::responses(),
            static fn (array $codes): bool => \in_array($code, $codes, true),
        ));
    }

    /**
     * Las operaciones del contrato cuyo codigo es la respuesta compartida dada
     * de `components.responses`: las que declaran el `400` como
     * `InvalidRequest` validan el cuerpo con `400`, y las que lo declaran como
     * `UnreadableBody`, no.
     *
     * @return list<string>
     */
    public static function declaringResponse(int $code, string $component): array
    {
        $document = Yaml::parseFile(Repo::file('docs/api/openapi.yaml'));
        $paths = \is_array($document) && \is_array($document['paths'] ?? null) ? $document['paths'] : [];
        $reference = '#/components/responses/'.$component;
        $operations = [];

        foreach ($paths as $path => $item) {
            foreach (\is_array($item) ? $item : [] as $verb => $operation) {
                if (self::referenceOf($operation, $code) === $reference) {
                    $operations[] = strtoupper((string) $verb).' '.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * El `$ref` de la respuesta del codigo en una operacion del YAML, o null si
     * no la declara o la escribe en linea.
     */
    private static function referenceOf(mixed $operation, int $code): mixed
    {
        $responses = \is_array($operation) && \is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $response = $responses[$code] ?? null;

        return \is_array($response) ? $response['$ref'] ?? null : null;
    }

    /**
     * Los codigos de cada operacion del contrato.
     *
     * @return array<string, list<int>>
     */
    public static function responses(): array
    {
        return self::$responses ??= ContractCoverage::responses(Repo::file('docs/api/openapi.yaml'));
    }

    /**
     * Verbo y URI concreta de la operacion, con sus parametros de ruta
     * rellenados con valores sinteticos que no existen: un `uuid` v7, un
     * identificador numerico que ninguna instalacion de prueba alcanza y el paso
     * `site` del asistente.
     *
     * @return array{0: string, 1: string}
     */
    public static function requestOf(string $operation): array
    {
        [$method, $path] = explode(' ', $operation, 2);

        return [$method, str_replace(['{uuid}', '{periodUuid}', '{id}', '{step}'], [AuthorizationMatrix::UUID, AuthorizationMatrix::UUID, self::MISSING_ID, 'site'], $path)];
    }

    /**
     * La clave de la operacion en {@see AuthorizationMatrix::routes()}, o null
     * si la ruta es publica.
     */
    public static function matrixKey(string $operation): ?string
    {
        [$method, $path] = explode(' ', $operation, 2);
        $key = $method.' '.ltrim($path, '/');

        return \array_key_exists($key, AuthorizationMatrix::routes()) ? $key : null;
    }

    /**
     * Un cliente con el token del primer actor al que la operacion autoriza, o
     * sin credenciales si la ruta es publica.
     *
     * @param  array<string, string>  $tokens  los de {@see AuthorizationMatrix::tokens()}
     */
    public static function clientAllowed(string $operation, array $tokens): Api
    {
        $key = self::matrixKey($operation);

        return $key === null ? Api::guest() : Api::as($tokens[AuthorizationMatrix::routes()[$key][0]]);
    }

    /**
     * Un cliente con el token del primer actor al que la matriz deniega la
     * operacion con `403`. Lanza si no hay ninguno: un `403` declarado sin nadie
     * a quien denegar es una fila de la matriz o del contrato que revisar.
     *
     * @param  array<string, string>  $tokens  los de {@see AuthorizationMatrix::tokens()}
     */
    public static function clientDenied(string $operation, array $tokens): Api
    {
        $key = self::matrixKey($operation)
            ?? throw new LogicException($operation.' es publica y el contrato le declara 403.');

        $denied = array_values(array_diff(
            AuthorizationMatrix::actors(),
            AuthorizationMatrix::routes()[$key],
            AuthorizationMatrix::unauthenticatedDenials()[$key] ?? [],
        ));

        return Api::as($tokens[$denied[0] ?? throw new LogicException('La matriz no deniega '.$operation.' a nadie y el contrato le declara 403.')]);
    }

    /**
     * Un cliente con un token que no existe, o sin credenciales si la ruta es
     * publica: con PostgreSQL caido, comprobar el token es la primera consulta.
     */
    public static function clientWithAnyToken(string $operation): Api
    {
        return self::matrixKey($operation) === null ? Api::guest() : Api::as('1|token-de-un-portador-cualquiera');
    }

    /**
     * La operacion pedida sin cuerpo.
     *
     * @return TestResponse<Response>
     */
    public static function send(Api $client, string $operation): TestResponse
    {
        [$method, $uri] = self::requestOf($operation);
        self::forgetLastValidation();

        return $client->call($method, $uri);
    }

    /**
     * La operacion pedida con un campo que no admite: en la consulta si es un
     * `GET`, en el cuerpo JSON si no.
     *
     * @param  array<string, mixed>  $field
     * @return TestResponse<Response>
     */
    public static function sendWithUnknownField(Api $client, string $operation, array $field): TestResponse
    {
        [$method, $uri] = self::requestOf($operation);
        self::forgetLastValidation();

        return $method === 'GET' ? $client->get($uri, $field) : $client->call($method, $uri, $field);
    }

    /**
     * La operacion pedida con un cuerpo crudo del tipo dado.
     *
     * @return TestResponse<Response>
     */
    public static function sendRaw(Api $client, string $operation, string $contentType, string $content): TestResponse
    {
        [$method, $uri] = self::requestOf($operation);
        self::forgetLastValidation();

        return $client->raw($method, $uri, $contentType, $content);
    }

    /**
     * Olvida lo que Spectator valido en la peticion anterior. `discrepancy()`
     * lee `responseException` del singleton, y Spectator solo lo reinicia
     * cuando su middleware resuelve el contrato: una peticion que no pasa por
     * el (una ruta fuera del contrato, una respuesta fabricada) heredaria la
     * excepcion de la anterior y saldria como incumplimiento suyo.
     */
    private static function forgetLastValidation(): void
    {
        $spectator = resolve(RequestFactory::class);
        $spectator->requestException = null;
        $spectator->responseException = null;
    }

    /**
     * El mensaje de Spectator sin los colores de la consola, en una linea.
     */
    private static function plain(string $message): string
    {
        return mb_substr(trim((string) preg_replace(['/\e\[[0-9;]*m/', '/\s+/'], ['', ' '], $message)), 0, 300);
    }
}
