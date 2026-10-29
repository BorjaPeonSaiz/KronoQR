<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:doctor` avisa de las bajas programadas que siguen sin aplicar pasado
 * su tope (RF-PD-13, RN-14, ADR-062 §4).
 *
 * Contesta contra la base y no contra el *gauge* de la ultima pasada: si el
 * planificador lleva dias parado, la serie se quedo como estaba y esta sonda
 * no. «Hoy» es la fecha civil del centro. **Aviso y nunca fallo**: nadie ficha
 * despues del cese aunque la baja no se haya aplicado.
 */

uses(RefreshDatabase::class);

/** La comprobacion de las bajas programadas dentro del informe de `doctor`. */
function comprobacionDeLasBajasProgramadas(): DoctorCheck
{
    $informe = app(RunDoctorHandler::class)->handle('es');

    $encontrada = array_values(array_filter(
        $informe->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'scheduler.scheduled_offboardings',
    ));

    expect($encontrada)->not->toBeEmpty('`doctor` no incluye la comprobacion de las bajas programadas.');

    return $encontrada[0];
}

function bajaProgramadaParaElDoctor(int $site, string $cese, string $status = 'active'): void
{
    $uuid = WorkforceFixtures::employee($site, null, $status);
    DB::table('employees')->where('uuid', $uuid)->update(['terminated_at' => $cese]);
}

it('sale en verde sin centro y sin bajas pendientes', function (): void {
    FrozenTime::at('2026-10-05 09:00:00');

    expect(comprobacionDeLasBajasProgramadas()->status)->toBe(DoctorStatus::Ok);

    $site = WorkforceFixtures::site('Hotel del doctor');
    // Cese ayer u hoy: todavia esta dentro del plazo de la tarea.
    bajaProgramadaParaElDoctor($site, '2026-10-04');
    bajaProgramadaParaElDoctor($site, '2026-10-05');
    // Ya aplicada: no cuenta.
    bajaProgramadaParaElDoctor($site, '2026-09-01', 'terminated');

    expect(comprobacionDeLasBajasProgramadas()->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RN-14');

it('avisa con la cifra y la orden cuando una baja programada paso su tope sin aplicarse', function (): void {
    FrozenTime::at('2026-10-05 09:00:00');
    $site = WorkforceFixtures::site('Hotel del doctor');
    bajaProgramadaParaElDoctor($site, '2026-10-02');
    bajaProgramadaParaElDoctor($site, '2026-09-30');

    $comprobacion = comprobacionDeLasBajasProgramadas();

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('2 baja(s) programada(s)')
        ->and($comprobacion->fix)->toContain('workforce:apply-scheduled-offboardings')
        ->and($comprobacion->details)->toBe(['overdue' => 2]);
})->group('RF-PD-13', 'RN-14');

it('resuelve ayer con la fecha civil del centro y no con la UTC', function (): void {
    // 23:30 UTC del 4 son las 00:30 del 5 en Canarias: ayer es el 4, y un cese
    // el 3 ya paso el tope. En UTC aun seria el 4 y no contaria.
    FrozenTime::at('2026-10-04 23:30:00');
    $site = WorkforceFixtures::site('Hotel del doctor', 'Atlantic/Canary');
    bajaProgramadaParaElDoctor($site, '2026-10-03');

    expect(comprobacionDeLasBajasProgramadas()->status)->toBe(DoctorStatus::Warning);
})->group('RF-PD-13', 'RN-14');
