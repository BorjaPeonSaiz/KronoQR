<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Donde dejan sus ficheros las dos exportaciones legales (RF-IN-05; ADR-045 §1 y
 * §f).
 *
 * - El temporal de la descarga HTTP vive en `storage/app/tmp/legal-exports`,
 *   dentro del volumen compartido, y la purga del `scheduler` lo encuentra.
 *   Antes vivia en `storage/framework`, en la capa de `app`, y nadie lo veia.
 * - La exportacion de consola termina diciendo que no se borra sola.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    App::setLocale('es');
});

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

/** Una jornada registrada en marzo y la sesion de RRHH que atiende el requerimiento. */
function sesionConJornadaParaExportar(): string
{
    $site = WorkforceFixtures::site('Hotel de descargas', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site);

    $workDay = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $workDay->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    app(WorkDayRepository::class)->save($workDay);

    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

it('la raiz del temporal HTTP esta dentro de storage/app y no en storage/framework', function (): void {
    expect(config()->string('compliance.legal_export_temp_path'))->toBe(storage_path('app/tmp/legal-exports'))
        ->and(config()->string('compliance.legal_export_console_path'))->toBe(storage_path('app/legal-exports'));
})->group('RF-IN-05');

it('el temporal de una descarga abortada queda en storage/app/tmp/legal-exports y su purga lo encuentra', function (): void {
    $raiz = GeneratedFilesSandbox::directory('legal-tmp');
    config(['compliance.legal_export_temp_path' => $raiz]);
    $token = sesionConJornadaParaExportar();

    // La respuesta no se envia: es exactamente una descarga abortada. El
    // `deleteFileAfterSend()` nunca corre y el temporal se queda en el disco.
    Api::as($token)->get('/api/v1/reports/legal-export', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();

    $temporales = glob($raiz.'/registro-horario-2026-03-01_2026-03-31-*.csv') ?: [];

    expect($temporales)->toHaveCount(1);

    FrozenTime::at(gmdate('Y-m-d H:i:s', time() + 7 * 3600));
    Commands::run('compliance:purge-legal-export-temp');

    expect(is_file($temporales[0]))->toBeFalse();
})->group('RF-IN-05');

it('la exportacion de consola termina diciendo que se borre en cuanto se entregue', function (): void {
    sesionConJornadaParaExportar();
    $destino = GeneratedFilesSandbox::directory('legal-console').'/registro-horario-2026-03-01_2026-03-31.csv';

    [$codigo, $salida] = Commands::run(
        'compliance:legal-export --from=2026-03-01 --to=2026-03-31 --output='.$destino
    );

    expect($codigo)->toBe(0)
        ->and(is_file($destino))->toBeTrue()
        ->and($salida)->toContain('borralo en cuanto lo hayas entregado');
})->group('RF-IN-05', 'RL-06');

it('la exportacion legal nace con directorio 0700 y fichero 0600, como todo el volumen', function (): void {
    // ADR-045: el fichero es el registro nominal de la plantilla. Con la umask
    // del proceso saldria legible por otras cuentas del servidor.
    sesionConJornadaParaExportar();
    $directorio = GeneratedFilesSandbox::directory('legal-modo').'/nuevo';
    $destino = $directorio.'/registro-horario-2026-03-01_2026-03-31.csv';

    [$codigo] = Commands::run('compliance:legal-export --from=2026-03-01 --to=2026-03-31 --output='.$destino);

    clearstatcache();

    expect($codigo)->toBe(0)
        ->and(fileperms($directorio) & 0o777)->toBe(0o700)
        ->and(fileperms($destino) & 0o777)->toBe(0o600);
})->group('RF-IN-05', 'RL-12');

it('el aviso de la exportacion de consola existe tambien en ingles', function (): void {
    expect(trans('legal-export.console.delete_after_delivery', [], 'en'))
        ->toContain('delete it as soon as you have delivered it');
})->group('RF-IN-05');
