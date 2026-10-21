<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las cuentas de gestion activas sin uso no se purgan: se cuentan (ADR-060,
 * tarea 11.3 B; RL-11, RS-06). `product:doctor` avisa por rol y
 * `identity:dormant-accounts` publica `management_accounts_dormant{role}`,
 * sin decir nunca cuales.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    Config::set('identity.dormant_account_days', 90);
    Config::set('observability.metrics.textfile_path', sys_get_temp_dir().'/kronoqr-dormant-'.bin2hex(random_bytes(6)));
});

/** Una cuenta del rol, con su ultimo acceso hace `$dias` dias (o nunca, desde su alta). */
function cuentaSinUso(UserRole $rol, ?int $dias, bool $activa = true): int
{
    $user = ManagementUsers::withRole($rol);
    DB::table('users')->where('id', $user->id)->update([
        'last_login_at' => $dias === null ? null : now()->subDays($dias),
        'created_at' => now()->subDays(400),
        'is_active' => $activa,
    ]);

    return $user->id;
}

it('doctor cuenta por rol las cuentas activas sin uso, sin decir cuales', function (): void {
    cuentaSinUso(UserRole::RRHH, 120);
    cuentaSinUso(UserRole::AUDITOR, null);
    cuentaSinUso(UserRole::RRHH, 10);
    cuentaSinUso(UserRole::RRHH, 200, activa: false);

    $checks = array_values(array_filter(
        app(RunDoctorHandler::class)->handle('es')->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'access.dormant_accounts',
    ));

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe(DoctorStatus::Warning)
        ->and($checks[0]->details['by_role'] ?? null)->toBe(['auditor' => 1, 'rrhh' => 1])
        ->and($checks[0]->summary)->toContain('2 cuenta(s)')
        ->and((string) $checks[0]->fix)->toContain('identity:deactivate-user');
})->group('RL-11', 'RS-06', 'RF-PD-13');

it('identity:dormant-accounts publica la metrica por rol, a cero los que no tienen', function (): void {
    cuentaSinUso(UserRole::RRHH, 120);

    expect(Artisan::call('identity:dormant-accounts'))->toBe(0)
        ->and(Artisan::output())->toContain('rrhh: 1');

    $fichero = (string) file_get_contents(Config::string('observability.metrics.textfile_path').'/kronoqr_management_accounts.prom');

    expect($fichero)->toContain('management_accounts_dormant{role="rrhh"} 1')
        ->and($fichero)->toContain('management_accounts_dormant{role="auditor"} 0')
        ->and($fichero)->toContain('management_accounts_dormant_days 90');
})->group('RL-11', 'RS-06');

it('programa el recuento cada dia a las 05:00 UTC, en segundo plano y sin solaparse', function (): void {
    $eventos = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'identity:dormant-accounts'),
    ));

    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]->expression)->toBe('0 5 * * *')
        ->and($eventos[0]->withoutOverlapping)->toBeTrue()
        ->and($eventos[0]->runInBackground)->toBeTrue();
})->group('RL-11');
