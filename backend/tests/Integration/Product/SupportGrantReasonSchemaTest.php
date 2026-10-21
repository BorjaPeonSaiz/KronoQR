<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las dos restricciones del motivo vaciado de `support_grants` y la
 * migracion que no se deja deshacer con motivos ya vaciados (tarea 11.3 C,
 * RL-11).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/** Una concesion caducada el 2026-01-02 a las 09:00 UTC. */
function concesionParaElEsquema(): string
{
    $uuid = SupportGrants::issue()->grant->uuid;

    DB::table('support_grants')->where('uuid', $uuid)->update([
        'granted_at' => '2026-01-01 09:00:00+00',
        'expires_at' => '2026-01-02 09:00:00+00',
    ]);

    return $uuid;
}

/**
 * @param  array<string, mixed>  $cambios
 */
function cambioDeMotivo(string $uuid, array $cambios): ?string
{
    try {
        DB::transaction(static fn (): int => DB::table('support_grants')->where('uuid', $uuid)->update($cambios));
    } catch (QueryException $rechazo) {
        return (string) ($rechazo->errorInfo[0] ?? '');
    }

    return null;
}

it('no admite un motivo vaciado sin fecha ni una fecha con el motivo dentro', function (array $cambios): void {
    expect(cambioDeMotivo(concesionParaElEsquema(), $cambios))->toBe('23514');
})->with([
    'motivo nulo sin fecha' => [['reason' => null]],
    'fecha con el motivo dentro' => [['reason_purged_at' => '2026-05-01 00:00:00+00']],
])->group('RL-11');

it('no admite vaciar el motivo antes de que la concesion se cierre', function (): void {
    expect(cambioDeMotivo(concesionParaElEsquema(), [
        'reason' => null,
        'reason_purged_at' => '2026-01-02 08:00:00+00',
    ]))->toBe('23514');
})->group('RL-11');

it('admite el vaciado despues del cierre', function (): void {
    $uuid = concesionParaElEsquema();

    expect(cambioDeMotivo($uuid, ['reason' => null, 'reason_purged_at' => '2026-05-01 00:00:00+00']))->toBeNull();
})->group('RL-11');

it('la migracion no se deja deshacer con motivos ya vaciados', function (): void {
    $uuid = concesionParaElEsquema();
    DB::table('support_grants')->where('uuid', $uuid)->update(['reason' => null, 'reason_purged_at' => '2026-05-01 00:00:00+00']);

    $migracion = require database_path('migrations/2026_10_21_100000_purgeable_support_grant_reason.php');

    expect(fn () => $migracion->down())->toThrow(RuntimeException::class, 'motivo ya vaciado');

    // Y no ha tocado nada.
    expect(DB::table('support_grants')->where('uuid', $uuid)->value('reason_purged_at'))->not->toBeNull();
})->group('RL-11');
