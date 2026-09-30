<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;

/*
 * **Los errores del framework tambien son `problem+json`** (F4a-1, CH4, RQ-06).
 *
 * El contrato promete `application/problem+json` para TODA respuesta de error,
 * y `bootstrap/app.php` solo traducia las que nombraba: validacion,
 * autenticacion, autorizacion, `404`, `429` y el `503` de mantenimiento. El
 * resto —un `405`, un `413`, un `419`, un `500`— salia como el JSON del
 * framework, y con `APP_DEBUG=true` el `500` de una `QueryException` llevaba el
 * SQL, los valores enlazados y la traza (tanda 3 de la verificacion de la
 * 2.1.0).
 *
 * **`APP_DEBUG=true` en las pruebas del `500`**, a proposito: es el modo en el
 * que la fuga se veia. Produccion lo tiene apagado por `ProductionSafetyGuard`,
 * pero el cuerpo de un error no puede depender de que nadie se equivoque con
 * esa variable.
 *
 * Las rutas que lanzan cada excepcion se declaran en la prueba y cuelgan de
 * `/api/v1/__pruebas/`, que no existe en el producto.
 */

uses(RefreshDatabase::class);

const FRAMEWORK_ERRORS_CODIGO_EMPLEADO = 'EMP-0042';

/**
 * Comprueba la forma comun: `problem+json`, `type` y `status`, y nada de la
 * excepcion en el cuerpo.
 *
 * @param  TestResponse<Response>  $respuesta
 */
function esProblemaDelFramework(TestResponse $respuesta, int $status, string $type): void
{
    $respuesta->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', $type)
        ->assertJsonPath('status', $status);

    expect(array_keys((array) $respuesta->json()))->toBe(['type', 'title', 'status', 'detail']);
}

it('responde problem+json a una ruta que no existe', function (): void {
    esProblemaDelFramework(Api::guest()->get('/api/v1/no-existe-esta-ruta'), 404, ProblemDetails::TYPE_NOT_FOUND);
})->group('RQ-06');

it('responde problem+json con la cabecera Allow a un metodo no admitido', function (): void {
    config()->set('app.debug', true);

    $respuesta = Api::guest()->get('/api/v1/scan');

    esProblemaDelFramework($respuesta, 405, ProblemDetails::TYPE_METHOD_NOT_ALLOWED);

    expect((string) $respuesta->headers->get('Allow'))->toContain('POST');
})->group('RQ-06');

it('responde problem+json a un cuerpo demasiado grande', function (): void {
    Route::post('/api/v1/__pruebas/413', static fn (): never => throw new PostTooLargeException);

    esProblemaDelFramework(Api::guest()->post('/api/v1/__pruebas/413'), 413, ProblemDetails::TYPE_PAYLOAD_TOO_LARGE);
})->group('RQ-06');

it('responde problem+json a un token CSRF caducado', function (): void {
    Route::post('/api/v1/__pruebas/419', static fn (): never => throw new TokenMismatchException);

    esProblemaDelFramework(Api::guest()->post('/api/v1/__pruebas/419'), 419, ProblemDetails::TYPE_CSRF_TOKEN_MISMATCH);
})->group('RQ-06');

it('responde problem+json a un codigo HTTP sin tipo propio', function (): void {
    Route::get('/api/v1/__pruebas/418', static fn (): never => throw new HttpException(418));

    esProblemaDelFramework(Api::guest()->get('/api/v1/__pruebas/418'), 418, ProblemDetails::TYPE_HTTP_ERROR);
})->group('RQ-06');

it('responde un 500 problem+json sin el mensaje de la excepcion, tambien en desarrollo', function (): void {
    config()->set('app.debug', true);

    Route::get('/api/v1/__pruebas/500', static fn (): never => throw new RuntimeException(
        'No se pudo cargar a '.FRAMEWORK_ERRORS_CODIGO_EMPLEADO.' desde /var/www/html/app/Secreto.php',
    ));

    $respuesta = Api::guest()->get('/api/v1/__pruebas/500');

    esProblemaDelFramework($respuesta, 500, ProblemDetails::TYPE_INTERNAL_ERROR);

    expect($respuesta->getContent())->not->toContain(FRAMEWORK_ERRORS_CODIGO_EMPLEADO)
        ->and((string) $respuesta->getContent())->not->toContain('Secreto.php');
})->group('RQ-06', 'RS-03');

it('responde un 500 problem+json sin SQL ni valores cuando falla la base de datos', function (): void {
    // CH4: la caida de PostgreSQL a mitad de una peticion. Una `QueryException`
    // lleva la sentencia y los valores enlazados en el mensaje.
    config()->set('app.debug', true);

    Route::get('/api/v1/__pruebas/sql', static fn (): array => DB::select(
        'select * from tabla_que_no_existe where employee_code = ?',
        [FRAMEWORK_ERRORS_CODIGO_EMPLEADO],
    ));

    $respuesta = Api::guest()->get('/api/v1/__pruebas/sql');

    esProblemaDelFramework($respuesta, 500, ProblemDetails::TYPE_INTERNAL_ERROR);

    expect((string) $respuesta->getContent())->not->toContain(FRAMEWORK_ERRORS_CODIGO_EMPLEADO)
        ->and((string) $respuesta->getContent())->not->toContain('tabla_que_no_existe')
        ->and((string) $respuesta->getContent())->not->toContain('SQLSTATE');
})->group('RQ-06', 'RS-03');

it('deja intacta la respuesta que ya trae su propio error', function (): void {
    // El `400` de `invalid-request` del quiosco sale de una
    // `HttpResponseException`: el `500` generico no puede tragarselo.
    $respuesta = Api::as(AttendanceFixtures::scenario()['token'])->post('/api/v1/scan', [
        'scan_id' => Str::uuid7()->toString(),
        'occurred_at' => '2026-03-14T07:02:31Z',
        'qr_payload' => 'FH1.a3.x.y',
    ]);

    $respuesta->assertStatus(400)->assertJsonPath('type', ProblemDetails::TYPE_INVALID_REQUEST);
})->group('RQ-06');
