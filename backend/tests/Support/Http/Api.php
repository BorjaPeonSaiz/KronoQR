<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Quality\Support\Commands;

/**
 * Cliente HTTP tipado para las pruebas de la API.
 *
 * **Por que no `$this->getJson()` dentro de las clausuras de Pest.** Ahi `$this`
 * es un `TestCall`, no el caso de prueba, y PHPStan 9 no resuelve ninguno de sus
 * metodos: la suite entera saldria con decenas de errores de tipo o —peor— con
 * supresiones. Es el mismo motivo por el que
 * {@see Commands} existe para los comandos de
 * consola, y la misma solucion: una clase con tipos de verdad.
 *
 * Devuelve el `TestResponse` de Laravel, asi que siguen valiendo tanto sus
 * aserciones como las que anade Spectator (`assertValidRequest`,
 * `assertValidResponse`), que es como se comprueba el contrato.
 *
 * La peticion se envia con `Accept: application/json` siempre: la API no tiene
 * vistas y un error servido en HTML se convierte en «error de parseo» en el
 * cliente, escondiendo la causa.
 */
final readonly class Api
{
    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        private ?string $token,
        private array $headers = [],
        private ?string $ip = null,
    ) {}

    /**
     * Peticiones autenticadas con un token de Sanctum.
     */
    public static function as(string $token): self
    {
        return new self($token);
    }

    /**
     * Peticiones sin credenciales. Es la mitad de la regla dura 18 que mas se
     * olvida: comprobar tambien que sin token no se entra.
     */
    public static function guest(): self
    {
        return new self(null);
    }

    /**
     * La direccion de origen de la peticion.
     *
     * Existe por los limites y los bloqueos, que son la mitad de RS-12 que no se
     * puede probar sin ella. Dos preguntas distintas necesitan cambiar de IP para
     * responderse: que un limite **por cuenta** no deje sin cupo a las demas
     * cuentas —hay que aislar el eje de la IP para verlo—, y que un contador de
     * **fallos** no se reinicie cambiando de origen, que es la diferencia entre un
     * bloqueo y un obstaculo de un solo salto.
     *
     * Va en `REMOTE_ADDR` y no en una cabecera a proposito: `X-Forwarded-For` solo
     * lo lee Laravel si el origen es un proxy de confianza, asi que una prueba que
     * la usara estaria comprobando la configuracion de proxies y no el limite.
     */
    public function fromIp(string $ip): self
    {
        return new self($this->token, $this->headers, $ip);
    }

    /**
     * Cabeceras adicionales.
     *
     * Existe por `Idempotency-Key`, que en la escritura del quiosco es
     * **obligatoria** y tiene que coincidir con el `scan_id` del cuerpo
     * (regla dura 8, contrato de `POST /api/v1/scan`): sin poder enviarla, la
     * mitad de las pruebas de ese endpoint no se podrian escribir.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->token, [...$this->headers, ...$headers], $this->ip);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    public function get(string $uri, array $query = []): TestResponse
    {
        return $this->call('GET', $query === [] ? $uri : $uri.'?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public function post(string $uri, array $body = []): TestResponse
    {
        return $this->call('POST', $uri, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public function patch(string $uri, array $body = []): TestResponse
    {
        return $this->call('PATCH', $uri, $body);
    }

    /**
     * @return TestResponse<Response>
     */
    public function delete(string $uri): TestResponse
    {
        return $this->call('DELETE', $uri);
    }

    /**
     * Subida `multipart/form-data`.
     *
     * **Existe por `POST /api/v1/employees/import`** (RF-GP-05), que es el unico
     * endpoint del producto con cuerpo multipart: el fichero de plantilla se lee
     * en streaming desde disco y no se puede transportar en un JSON sin cargarlo
     * entero en memoria, que es justo lo que ese endpoint evita.
     *
     * Va por separado de {@see self::call()} y no como un caso mas suyo porque
     * las dos cosas que cambian —el `CONTENT_TYPE` y el hueco de `$files` de
     * `Request::create()`— no son un parametro mas: mezclarlas obligaria a que
     * cada peticion JSON pasara por una rama que no usa.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, UploadedFile>  $files
     * @return TestResponse<Response>
     */
    public function upload(string $uri, array $fields, array $files): TestResponse
    {
        return $this->send('POST', $uri, $fields, $files, multipart: true);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public function call(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->send($method, $uri, $body, [], multipart: false);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, UploadedFile>  $files
     * @return TestResponse<Response>
     */
    private function send(string $method, string $uri, array $body, array $files, bool $multipart): TestResponse
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            // `Request::create()` de Symfony finge un navegador en ingles
            // (`Accept-Language: en-us,en;q=0.5`) si no se le dice otra cosa, y
            // con la negociacion de idioma (`NegotiateLocale`) eso convertiria
            // cada prueba en «un cliente que pide ingles». El caso neutro es
            // SIN cabecera —la API responde en el idioma de la instalacion—, y
            // la prueba que quiera un idioma lo dice con `withHeaders()`.
            'HTTP_ACCEPT_LANGUAGE' => '',
        ];

        foreach ($this->headers as $name => $value) {
            // La convencion de PHP para una cabecera en `$_SERVER`: `HTTP_` y el
            // nombre en mayusculas con guiones bajos.
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($this->token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }

        if ($this->ip !== null) {
            $server['REMOTE_ADDR'] = $this->ip;
        }

        if ($multipart) {
            // El tipo de contenido lo compone Symfony a partir de `$files`, asi
            // que aqui se retira el `application/json` por defecto: dejarlo haria
            // que Laravel intentara deserializar el cuerpo como JSON y no viera
            // ni los campos ni el fichero.
            unset($server['CONTENT_TYPE']);
        }

        $request = Request::create(
            uri: $uri,
            method: $method,
            // En multipart los campos viajan como parametros de formulario, que
            // es como llegarian de un navegador de verdad.
            parameters: $multipart ? $body : [],
            files: $files,
            server: $server,
            content: $multipart || $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        // Cada llamada de este cliente simula una peticion HTTP NUEVA, y en una
        // peticion nueva no hay nadie autenticado todavia. Sin esta linea no es
        // asi: el `AuthManager` es un singleton del contenedor y `RequestGuard`
        // memoriza el usuario que resolvio la primera vez, asi que la segunda
        // llamada de una misma prueba reutiliza aquel sin volver a comprobar el
        // token. Eso hace invisibles justo las comprobaciones que se hacen en
        // CADA peticion —cuenta desactivada, quiosco revocado (RS-04), empleado
        // dado de baja o PIN restablecido (RN-14, RF-ID-09)—: una prueba que las
        // ejercite pasaria en verde con el codigo roto.
        Auth::forgetGuards();

        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        $response = $kernel->handle($request);

        // Una peticion real no termina al devolver la respuesta: el kernel la
        // **termina** despues de enviarla, y es ahi donde corren los
        // `terminating` del contenedor. Hoy vive ahi el asiento aplazado de
        // `auth.lockout_started` (ADR-039), que se saca del camino de la
        // respuesta para que el flanco del bloqueo no cueste distinto ni pueda
        // convertir un rechazo en un `500`. Sin esta linea, este cliente dejaba
        // fuera de cobertura todo lo que el producto hace despues de responder,
        // y una prueba de asiento pasaria en verde con la escritura sin ejecutar.
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }
}
