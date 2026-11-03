<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Identity\ConsoleSignature;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Shared\DeactivatedWhileSigning;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Shared\InteractiveConsole;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `compliance:legal-export` firmada por la cuenta de `--responsible` (doc 01
 * §7.2, «Firmas por consola»; RF-IN-05).
 *
 * Lo que fijan estas pruebas: la cuenta se nombra por su uuid o su correo y
 * nunca por su id; firman `admin`, `rrhh` y `auditor`, como en el panel; con
 * `--force` o sin terminal hace falta `--responsible-confirm`; el asiento
 * `legal_export.generated` es de esa cuenta y no de `system`; y cada negativa
 * sale con su codigo sin asiento y sin fichero, tambien si la baja llega
 * mientras se escribe.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->setLocale('es');

    $site = WorkforceFixtures::site('Hotel de la firma', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site);

    $workDay = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $workDay->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    app(WorkDayRepository::class)->save($workDay);
});

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

/** Donde escribe la orden en cada prueba: un directorio propio, vacio. */
function exportacionLegalFirmadaDestino(): string
{
    return GeneratedFilesSandbox::directory('legal-firma').'/registro-horario-2026-03-01_2026-03-31.csv';
}

/**
 * @param  array<string, string|bool>  $opciones
 * @return array{code: int, output: string}
 */
function exportacionLegalFirmadaOrden(string $destino, array $opciones): array
{
    $code = Artisan::call('compliance:legal-export', [
        '--from' => '2026-03-01',
        '--to' => '2026-03-31',
        '--output' => $destino,
        ...$opciones,
    ]);

    return ['code' => $code, 'output' => Artisan::output()];
}

/** Lo que una negativa no puede dejar: ni asiento ni fichero. */
function exportacionLegalFirmadaSinRastro(string $destino): void
{
    expect(DB::table('audit_log')->where('action', 'legal_export.generated')->count())->toBe(0)
        ->and(is_file($destino))->toBeFalse();
}

it('genera la exportacion con el asiento a nombre de la cuenta de --responsible, no de system', function (UserRole $rol, bool $porUuid): void {
    $cuenta = ManagementUsers::withRole($rol);
    $destino = exportacionLegalFirmadaDestino();

    $orden = exportacionLegalFirmadaOrden($destino, $porUuid ? ConsoleSignature::byUuid($cuenta) : ConsoleSignature::for($cuenta));

    /** @var object{actor_type: string, actor_id: int} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'legal_export.generated')->sole();

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$cuenta->name.' <'.$cuenta->email.'> · '.$rol->value.' · '.$cuenta->uuid)
        ->and($orden['output'])->toContain($cuenta->email)
        ->and(is_file($destino))->toBeTrue()
        ->and($asiento->actor_type)->toBe('user')
        ->and($asiento->actor_id)->toBe($cuenta->id);
})->with([
    'rrhh por correo' => [UserRole::RRHH, false],
    'admin por uuid' => [UserRole::ADMIN, true],
    'auditor por correo' => [UserRole::AUDITOR, false],
])->group('RF-IN-05', 'RL-04', 'RS-05');

it('sale con 2 sin asiento ni fichero si la firma no vale', function (Closure $opciones, string $dice): void {
    $destino = exportacionLegalFirmadaDestino();

    $orden = exportacionLegalFirmadaOrden($destino, ['--no-interaction' => true, ...$opciones()]);

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain($dice);

    exportacionLegalFirmadaSinRastro($destino);
})->with([
    'sin --responsible' => [fn (): array => [], 'Falta --responsible'],
    'id numerico' => [fn (): array => ['--responsible' => (string) ManagementUsers::withRole(UserRole::RRHH)->id], 'ya no admite el id interno'],
    'responsable de departamento' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)), '(admin, rrhh, auditor)'],
    'cuenta dada de baja' => [function (): array {
        $baja = ManagementUsers::withRole(UserRole::RRHH);
        DB::table('users')->where('id', $baja->id)->update(['is_active' => false]);

        return ConsoleSignature::for($baja);
    }, 'dada de baja'],
    'sin terminal y sin confirmar la firma' => [fn (): array => ['--responsible' => ManagementUsers::withRole(UserRole::RRHH)->email], '--responsible-confirm=<correo de la cuenta>'],
    'confirmacion de otra cuenta' => [fn (): array => [
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::RRHH)),
        '--responsible-confirm' => 'otra@kronoqr.test',
    ], 'no coincide'],
])->group('RF-IN-05', 'RL-04', 'RS-05');

it('con --force y terminal no pregunta, pero exige --responsible-confirm', function (): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $destino = exportacionLegalFirmadaDestino();
    $periodo = ['--from' => '2026-03-01', '--to' => '2026-03-31', '--output' => $destino];

    $sinConfirmar = InteractiveConsole::run('compliance:legal-export', [...$periodo, '--responsible' => $rrhh->email, '--force' => true], 'si');

    expect($sinConfirmar['code'])->toBe(2, $sinConfirmar['output'])
        ->and($sinConfirmar['output'])->toContain('--responsible-confirm=<correo de la cuenta>');

    exportacionLegalFirmadaSinRastro($destino);

    $confirmada = InteractiveConsole::run('compliance:legal-export', [
        ...$periodo,
        '--responsible' => $rrhh->email,
        '--force' => true,
        '--responsible-confirm' => $rrhh->email,
    ], '');

    expect($confirmada['code'])->toBe(0, $confirmada['output'])
        ->and(DB::table('audit_log')->where('action', 'legal_export.generated')->value('actor_id'))->toBe($rrhh->id);
})->group('RF-IN-05', 'RL-04');

it('con terminal pregunta por la firma y, si se responde que no, sale con 1 sin asiento ni fichero', function (): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $destino = exportacionLegalFirmadaDestino();

    $orden = InteractiveConsole::run('compliance:legal-export', [
        '--from' => '2026-03-01',
        '--to' => '2026-03-31',
        '--output' => $destino,
        '--responsible' => $rrhh->email,
    ], 'no');

    expect($orden['code'])->toBe(1, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$rrhh->name)
        ->and($orden['output'])->toContain('Cancelado. No se ha escrito nada.');

    exportacionLegalFirmadaSinRastro($destino);
})->group('RF-IN-05', 'RL-04');

it('una baja entre la pregunta y la firma no deja asiento ni fichero (salida 2)', function (int $comprobacionesValidas): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $destino = exportacionLegalFirmadaDestino();
    DeactivatedWhileSigning::after($comprobacionesValidas);

    $orden = exportacionLegalFirmadaOrden($destino, ConsoleSignature::for($rrhh));

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain('ya no es una cuenta de gestión activa');

    // Con `1`, la baja llega mientras se escribe el fichero: el fichero ya
    // existia y la orden lo borra, porque sin asiento no se entrega.
    exportacionLegalFirmadaSinRastro($destino);
})->with([
    'antes de leer el registro' => [0],
    'mientras se escribe el fichero' => [1],
])->group('RF-IN-05', 'RL-04', 'RS-07');
