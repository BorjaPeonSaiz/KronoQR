<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Los tokens de acceso caducados se borran cada noche (tarea 11.3 D, F4; RGPD
 * art. 5.1.e, RL-11): `sanctum:prune-expired --hours=24` a las 03:40 UTC. Se
 * conservan los vivos y los que caducaron hace menos de un dia —el solape de
 * la rotacion del quiosco—; los que no tienen caducidad no los toca y los
 * cuenta `product:doctor`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    FrozenTime::at('2026-10-21 03:40:00');
});

/** Un token de la cuenta, con la caducidad dada (`null`: sin caducidad). */
function tokenCaducadoDe(User $cuenta, ?string $caduca): int
{
    return (int) DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => $cuenta->getMorphClass(),
        'tokenable_id' => $cuenta->id,
        'name' => 'panel',
        'token' => hash('sha256', bin2hex(random_bytes(16))),
        'abilities' => '["*"]',
        'expires_at' => $caduca,
        'created_at' => '2026-10-01 00:00:00+00',
        'updated_at' => '2026-10-01 00:00:00+00',
    ]);
}

it('programa la purga de tokens caducados cada dia a las 03:40 UTC, en segundo plano y sin solaparse', function (): void {
    $eventos = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'sanctum:prune-expired'),
    ));

    expect($eventos)->toHaveCount(1)
        ->and((string) $eventos[0]->command)->toContain('--hours=24')
        ->and($eventos[0]->expression)->toBe('40 3 * * *')
        ->and($eventos[0]->withoutOverlapping)->toBeTrue()
        ->and($eventos[0]->runInBackground)->toBeTrue();
})->group('RL-11', 'RS-05');

it('borra lo caducado hace mas de 24 h y conserva lo vivo, lo del solape y lo que no caduca', function (): void {
    $cuenta = ManagementUsers::withRole(UserRole::RRHH);

    $viejo = tokenCaducadoDe($cuenta, '2026-10-19 03:39:00+00');
    $solape = tokenCaducadoDe($cuenta, '2026-10-20 12:00:00+00');
    $vivo = tokenCaducadoDe($cuenta, '2026-11-01 00:00:00+00');
    $sinCaducidad = tokenCaducadoDe($cuenta, null);

    expect(Artisan::call('sanctum:prune-expired', ['--hours' => 24]))->toBe(0);

    expect(DB::table('personal_access_tokens')->where('id', $viejo)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->whereIn('id', [$solape, $vivo, $sinCaducidad])->count())->toBe(3);
})->group('RL-11', 'RS-05');

it('doctor cuenta los tokens sin caducidad sin decir de quien son', function (): void {
    $cuenta = ManagementUsers::withRole(UserRole::RRHH);
    tokenCaducadoDe($cuenta, null);
    tokenCaducadoDe($cuenta, null);
    tokenCaducadoDe($cuenta, '2026-11-01 00:00:00+00');

    $checks = array_values(array_filter(
        app(RunDoctorHandler::class)->handle('es')->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'access.tokens_without_expiry',
    ));

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe(DoctorStatus::Warning)
        ->and($checks[0]->details)->toBe(['tokens_without_expiry' => 2])
        ->and($checks[0]->summary)->toContain('2 token(s)')
        ->and($checks[0]->summary)->not->toContain((string) $cuenta->email)
        ->and((string) $checks[0]->fix)->toContain('identity:reset-password');
})->group('RL-11', 'RF-PD-13');

it('doctor sale en verde sin tokens sin caducidad', function (): void {
    $checks = array_values(array_filter(
        app(RunDoctorHandler::class)->handle('en')->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'access.tokens_without_expiry',
    ));

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe(DoctorStatus::Ok)
        ->and($checks[0]->fix)->toBeNull();
})->group('RL-11', 'RF-PD-13');
