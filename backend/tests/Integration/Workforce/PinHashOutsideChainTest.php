<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Workforce\Application\Command\ImportEmployeesCommand;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;
use App\Modules\Workforce\Application\Command\ResetEmployeePinCommand;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\UseCase\ImportEmployeesHandler;
use App\Modules\Workforce\Application\UseCase\PlanEmployeeImport;
use App\Modules\Workforce\Application\UseCase\RegisterEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\ResetEmployeePinHandler;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\ChainProbingPinHasher;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL PIN SE CALCULA SIN LA CADENA DE AUDITORIA EN LA MANO**
 * (ADR-046 §1.1 punto 5 y §6 punto 4; condicion A-3; regla dura 19).
 *
 * Con la cadena tomada, los ~160 ms de bcrypt los esperaria cada fichaje del
 * hotel. Se comprueba en los tres caminos que emiten un PIN: el
 * restablecimiento, el alta y el alta por importacion. Y un control que
 * demuestra que la sonda ve la cadena cuando de verdad esta tomada.
 *
 * `CommittedDatabase`: con la transaccion envolvente de `RefreshDatabase`, un
 * asiento escrito al preparar el escenario dejaria la cadena tomada hasta el
 * final de la prueba y la sonda veria un candado que no es del caso de uso.
 */

uses(CommittedDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
});

function sondaDelPinFueraDeLaCadena(): ChainProbingPinHasher
{
    $sonda = new ChainProbingPinHasher(app(PinHasher::class));
    app()->instance(PinHasher::class, $sonda);

    return $sonda;
}

it('el restablecimiento calcula el PIN sin tener la cadena', function (): void {
    $persona = WorkforceFixtures::employee(WorkforceFixtures::site());
    $sonda = sondaDelPinFueraDeLaCadena();

    app(ResetEmployeePinHandler::class)->handle(new ResetEmployeePinCommand($persona));

    expect($sonda->heldTheChain)->toBe([false])
        ->and(DB::table('audit_log')->where('action', 'pin.reset')->count())->toBe(1);
})->group('RF-ID-09', 'RN-15');

it('el alta calcula el PIN sin tener la cadena', function (): void {
    WorkforceFixtures::site();
    $sonda = sondaDelPinFueraDeLaCadena();

    app(RegisterEmployeeHandler::class)->handle(new RegisterEmployeeCommand(
        departmentId: null,
        firstName: 'Lucia',
        lastName: 'Ferrer',
        email: null,
        nationalId: null,
        hiredAt: '2026-10-01',
        locale: 'es',
    ));

    expect($sonda->heldTheChain)->toBe([false])
        ->and(DB::table('employees')->count())->toBe(1);
})->group('RF-ID-09', 'RF-GP-01');

it('la importacion calcula los PIN de sus altas sin tener la cadena', function (): void {
    WorkforceFixtures::site();
    $path = (string) ImportFiles::csv(ImportFiles::rows(
        ['nombre', 'apellidos', 'dni', 'fecha_alta'],
        [['Lucia', 'Ferrer', '12345678Z', '2026-10-01'], ['Marta', 'Vidal', '87654321X', '2026-10-01']],
    ))->getRealPath();
    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.import.column_aliases');
    $huella = app(PlanEmployeeImport::class)->handle($path, 500, $aliases)->sha256;
    $sonda = sondaDelPinFueraDeLaCadena();

    app(ImportEmployeesHandler::class)->handle(new ImportEmployeesCommand($path, apply: true, confirmChecksum: $huella), 500, $aliases);

    expect($sonda->heldTheChain)->toBe([false, false])
        ->and(DB::table('employees')->count())->toBe(2);
})->group('RF-ID-09', 'RF-GP-05');

it('el control: con la cadena tomada, la sonda la ve', function (): void {
    // Demuestra que las tres de arriba pueden fallar.
    $sonda = sondaDelPinFueraDeLaCadena();

    app(SerializedLedgerWrite::class)->withChainLock(static fn (): mixed => app(PinHasher::class)->hash('374195'));

    expect($sonda->heldTheChain)->toBe([true]);
})->group('RF-ID-09');
