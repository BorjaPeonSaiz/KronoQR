<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
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
    private function __construct(private ?string $token, private array $headers = []) {}

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
        return new self($this->token, [...$this->headers, ...$headers]);
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
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public function call(string $method, string $uri, array $body = []): TestResponse
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        foreach ($this->headers as $name => $value) {
            // La convencion de PHP para una cabecera en `$_SERVER`: `HTTP_` y el
            // nombre en mayusculas con guiones bajos.
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($this->token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }

        $request = Request::create(
            uri: $uri,
            method: $method,
            server: $server,
            content: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR),
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
