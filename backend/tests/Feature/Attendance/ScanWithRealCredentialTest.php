<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Identity\Infrastructure\Adapter\HmacSignatureVerifier;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FixedClock;

/*
 * El recorrido completo **sin ningun doble**: una tarjeta emitida de verdad,
 * firmada con HMAC, escaneada en un quiosco emparejado de verdad.
 *
 * ## Por que existe teniendo `RegisterScanTest` un doble del resolutor
 *
 * Son dos preguntas distintas y conviene que fallen por separado. Aquellas
 * pruebas comprueban que un escaneo abre o cierra un tramo y no deben romperse
 * cuando cambie el esquema de firma; esta comprueba que **las dos mitades
 * encajan**: el puerto que declara `Attendance` (ADR-025) y el adaptador que
 * implementa `Identity`. Es la unica que se entera si el enlace del contenedor
 * se rompe, si el formato del payload cambia o si el verificador empieza a
 * devolver otro objeto.
 *
 * Sin ella, la integracion entre las tareas 1.4 y 1.5 solo se comprobaria el dia
 * de la demostracion con una tarjeta impresa en la mano.
 */

uses(RefreshDatabase::class);

it('ficha con una tarjeta emitida y firmada de verdad', function (): void {
    // Que el resolutor sea el real y no un doble es la mitad de esta prueba.
    expect(app(CredentialResolver::class))->toBeInstanceOf(HmacSignatureVerifier::class);

    $escenario = AttendanceFixtures::scenario();
    $payload = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']));

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));
    Spectator::using('openapi.yaml');

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => $payload->toString(),
        ]);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and($respuesta->json('work_date'))->toBe('2026-03-14')
        ->and(DB::table('shift_entries')->count())->toBe(1);

    // El payload impreso no se almacena: solo su huella (RS-03, regla dura 10).
    $escaneo = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($escaneo?->payload_fingerprint)->toBe(hash('sha256', $payload->toString()))
        ->and($escaneo?->payload_fingerprint)->not->toContain('FH1');
})->group('RF-AT-01', 'RF-QR-01', 'RF-QR-02');

it('rechaza con la respuesta generica una tarjeta con la firma manipulada', function (): void {
    // Escenario «QR falsificado» del doc 01 §11, con el verificador real: se
    // cambia un caracter de la firma y el HMAC deja de validar.
    $escenario = AttendanceFixtures::scenario();
    $payload = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']))->toString();

    $manipulado = substr($payload, 0, -1).(str_ends_with($payload, 'A') ? 'B' : 'A');

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));
    Spectator::using('openapi.yaml');

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => $manipulado,
        ]);

    $respuesta->assertStatus(422)->assertValidResponse();

    expect($respuesta->json('type'))->toBe('urn:kronoqr:problem:scan-rejected')
        ->and(DB::table('shift_entries')->count())->toBe(0)
        // La causa concreta existe, del lado del servidor: es lo que alimenta el
        // contador de rechazos por firma del doc 01 §11.
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('rejected_signature');
})->group('RF-QR-02', 'RS-03');

it('rechaza una tarjeta revocada igual que una inexistente', function (): void {
    // RF-QR-03 y RN-14 vistos desde el fichaje: la credencial existe, es
    // valida y esta revocada, y el quiosco no puede notar la diferencia con una
    // que nunca existio (regla dura 17).
    $escenario = AttendanceFixtures::scenario();
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $payload = Credentials::issueFor($employeeId)->toString();

    // Posterior a la emision: `credentials_chk_lifecycle_order` no admite una
    // revocacion anterior a la fecha en que se emitio la tarjeta, y hace bien.
    DB::table('credentials')->where('employee_id', $employeeId)->update([
        'revoked_at' => '2026-08-15 12:00:00+00',
        'revoked_reason' => 'lost',
    ]);

    app()->instance(Clock::class, FixedClock::at('2026-03-14 07:02:31'));

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => $payload,
        ]);

    $respuesta->assertStatus(422);

    expect($respuesta->json('detail'))->toBe('El escaneo no se ha podido registrar.')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('rejected_revoked')
        ->and(DB::table('shift_entries')->count())->toBe(0);
})->group('RF-QR-03', 'RN-14', 'RS-03');
