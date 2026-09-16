<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Shared\Application\Port\Clock;
use Carbon\CarbonImmutable;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `FrozenTime` detiene los dos relojes de una prueba con framework —el puerto
 * `Clock` del dominio y Carbon— en el mismo instante.
 *
 * La segunda prueba es la regresion del rojo de `main` del 13-09-2026: un token
 * de quiosco emitido con el dominio detenido en una fecha lejana caducaba, para
 * Sanctum, respecto al reloj REAL, y `PlanLimitsDoNotBlockTest` fallaba con
 * 401 a los 90 dias de escribirse. Aqui el reloj se detiene el 1 de enero de
 * 2026 a proposito —la fecha mas antigua con particion de `audit_log`—: el
 * token caduca el 1 de abril y, si Carbon no estuviera detenido con el, la
 * peticion seria 401 desde entonces.
 */

uses(RefreshDatabase::class);

it('detiene el reloj del dominio y el de Carbon en el mismo instante', function (): void {
    $clock = FrozenTime::at('2026-06-15 07:00:00');

    expect(app(Clock::class))->toBe($clock)
        ->and(now()->toIso8601ZuluString())->toBe('2026-06-15T07:00:00Z')
        ->and(CarbonImmutable::now()->getTimestamp())->toBe($clock->now()->getTimestamp());
})->group('RQ-13');

it('un token de quiosco emitido bajo el reloj detenido sigue valiendo para Sanctum aunque la fecha sea lejana', function (): void {
    FrozenTime::at('2026-01-01 07:00:00');

    $siteId = WorkforceFixtures::site();
    $device = AttendanceFixtures::device($siteId, 'Recepcion');

    $token = app(IssueDeviceToken::class)->handle(
        new IssueDeviceTokenCommand($device['uuid'], rotation: false, actorUserId: null),
    );

    expect($token)->not->toBeNull();

    Api::as($token->plainTextToken ?? '')
        ->get('/api/v1/kiosk/roster')
        ->assertOk();
})->group('RQ-13', 'RF-ID-04');
