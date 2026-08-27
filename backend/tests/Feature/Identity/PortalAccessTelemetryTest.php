<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El `trace_id` del acceso al portal (doc 02 §8.1).
 *
 * ## El defecto que esta prueba fija
 *
 * `PortalAccessTelemetry` escribe sus tres apuntes —aceptado, rechazado,
 * bloqueado— desde metodos que el caso de uso invoca **dentro** del acto medido,
 * donde no tiene el span a mano: los fecha leyendo la traza en curso. Mientras el
 * span se abria SIN activar, esa lectura daba una respuesta distinta de la del
 * span propio en el unico caso que importa: **una peticion que llega sin
 * `traceparent`**, que es la mayoria —el portal se abre escribiendo la direccion,
 * no navegando desde otra pagina instrumentada—. El span propio abria entonces
 * una traza nueva que el contexto ambiente no conocia, y el log salia con
 * `trace_id: null`.
 *
 * Un apunte de acceso sin `trace_id` no es un detalle de observabilidad: es el
 * unico hilo por el que se une «alguien intento entrar 40 veces» con la traza que
 * dice desde donde.
 *
 * ## Por que sin `traceparent` y no con el
 *
 * Con cabecera de propagacion las dos lecturas coincidian ya, asi que una prueba
 * que la enviara habria pasado igual con el defecto dentro. El caso que
 * distingue es este.
 */

uses(RefreshDatabase::class);

/**
 * Un empleado con su PIN emitido, listo para entrar por el portal.
 *
 * @return array{uuid: string, code: string}
 */
function empleadoConTrazaReal(): array
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la traza'));

    EmployeePins::issue($uuid, PortalLogins::PIN);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

/**
 * Recoge los apuntes de acceso al portal que se escriban a partir de ahora.
 *
 * @param  list<array{message: string, trace_id: string|null}>  $apuntes
 */
function capturaApuntesDePortal(array &$apuntes): void
{
    Log::listen(function (MessageLogged $evento) use (&$apuntes): void {
        if (! str_starts_with($evento->message, 'identity.portal_login_')) {
            return;
        }

        $traceId = $evento->context['trace_id'] ?? null;

        $apuntes[] = [
            'message' => $evento->message,
            'trace_id' => is_string($traceId) ? $traceId : null,
        ];
    });
}

/**
 * @param  list<array{message: string, trace_id: string|null}>  $apuntes
 * @return list<string|null>
 */
function traceIdsDe(array $apuntes): array
{
    return array_map(
        static fn (array $apunte): ?string => $apunte['trace_id'],
        $apuntes,
    );
}

it('fecha el apunte de acceso aceptado con el trace_id del span, sin traceparent en la peticion', function (): void {
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        $empleado = empleadoConTrazaReal();

        /** @var list<array{message: string, trace_id: string|null}> $apuntes */
        $apuntes = [];
        capturaApuntesDePortal($apuntes);

        // Sin `traceparent`: `Api` no la envia. Es el caso que destapa el defecto.
        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => PortalLogins::PIN,
        ])->assertStatus(200);

        $spans = $tracer->finishedSpans();

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->getName())->toBe('identity.portal_login');

        expect(traceIdsDe($apuntes))->toBe([$spans[0]->getContext()->getTraceId()]);
    });
})->group('RF-ID-06', 'RL-05');

it('fecha tambien el apunte de acceso rechazado con el trace_id de su propio span', function (): void {
    // El rechazo es el apunte que de verdad se consulta —«¿quien estuvo probando
    // PIN anoche?»— y el que se escribia sin identificador.
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        $empleado = empleadoConTrazaReal();

        /** @var list<array{message: string, trace_id: string|null}> $apuntes */
        $apuntes = [];
        capturaApuntesDePortal($apuntes);

        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => '999111',
        ])->assertStatus(401);

        $spans = $tracer->finishedSpans();

        expect($spans)->toHaveCount(1)
            ->and($apuntes[0]['message'] ?? '')->toBe('identity.portal_login_rejected');

        expect(traceIdsDe($apuntes))->toBe([$spans[0]->getContext()->getTraceId()]);
    });
})->group('RF-ID-06', 'RS-03');

it('da un trace_id distinto a cada intento, para que dos accesos no se confundan', function (): void {
    // El control que impide que la prueba pase con un `trace_id` constante: dos
    // intentos son dos trazas. Sin el, una implementacion que devolviera siempre
    // el mismo identificador cumpliria las dos pruebas de arriba.
    RecordingTracer::around(function (): void {
        $empleado = empleadoConTrazaReal();

        /** @var list<array{message: string, trace_id: string|null}> $apuntes */
        $apuntes = [];
        capturaApuntesDePortal($apuntes);

        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => PortalLogins::PIN,
        ])->assertStatus(200);

        Api::guest()->post('/api/v1/me/login', [
            'employee_code' => $empleado['code'],
            'pin' => PortalLogins::PIN,
        ])->assertStatus(200);

        $ids = traceIdsDe($apuntes);

        expect($ids)->toHaveCount(2)
            ->and($ids[0])->not->toBeNull()
            ->and($ids[0])->not->toBe($ids[1]);
    });
})->group('RF-ID-06', 'RL-05');
