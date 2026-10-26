<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Workforce\Application\UseCase\ApplyEmployeeImport;
use App\Modules\Workforce\Application\UseCase\PlanEmployeeImport;
use App\Modules\Workforce\Domain\Event\EmployeesImported;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA PLANTILLA IMPORTADA Y SU ASIENTO ENTRAN JUNTOS O NO ENTRA NINGUNO**
 * (RF-GP-05, regla dura 6, ADR-010; hallazgo V3-BE-1, tarea 19.6 de la 2.3.0).
 *
 * El asiento del lote (`employee.imported`) se publicaba despues de confirmar
 * las altas: si fallaba, la plantilla quedaba dentro sin el «quien cargo que
 * fichero». Ahora va en la misma transaccion. Para demostrarlo se fuerza un
 * fallo **despues** del asiento —un suscriptor mas de `EmployeesImported`,
 * registrado detras del de `Compliance`— y se comprueba que no queda ni el
 * asiento del lote, ni las altas, ni sus asientos `employee.hired`.
 *
 * Con `RefreshDatabase` la transaccion de la importacion es un punto de
 * guardado dentro de la de la prueba: deshacerlo es exactamente lo que se
 * afirma, y lo que se lee despues es lo que veria cualquier otra sesion tras
 * el `ROLLBACK`.
 */

uses(RefreshDatabase::class);

/** Las dos personas del fichero, por su documento: ninguna existe antes. */
function employeeImportAuditTransactionReport(): ImportReport
{
    WorkforceFixtures::site();

    $fichero = ImportFiles::csv(ImportFiles::rows(
        ['nombre', 'apellidos', 'dni', 'fecha_alta'],
        [
            ['Persona', 'Primera', '12345678Z', '2026-01-15'],
            ['Persona', 'Segunda', '87654321X', '2026-02-01'],
        ],
    ));

    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.import.column_aliases');

    return app(PlanEmployeeImport::class)->handle((string) $fichero->getRealPath(), 500, $aliases);
}

function employeeImportAuditTransactionEntries(AuditAction $action): int
{
    return DB::table('audit_log')->where('action', $action->value)->count();
}

it('un fallo despues del asiento del lote deshace el asiento y las altas', function (): void {
    $informe = employeeImportAuditTransactionReport();
    $asientoVisto = false;

    // Detras de `RecordEmployeeImport`, que se registra al arrancar: cuando este
    // corre, el asiento del lote ya esta escrito en la transaccion.
    Event::listen(EmployeesImported::class, function () use (&$asientoVisto): void {
        $asientoVisto = employeeImportAuditTransactionEntries(AuditAction::EmployeesImported) === 1;

        throw new RuntimeException('Fallo forzado despues del asiento del lote.');
    });

    expect(fn () => app(ApplyEmployeeImport::class)->handle($informe))
        ->toThrow(RuntimeException::class, 'Fallo forzado despues del asiento del lote.');

    // El asiento existia dentro de la transaccion: el fallo llego DESPUES de el.
    expect($asientoVisto)->toBeTrue()
        // Y al revertir no queda nada: ni el lote, ni las altas, ni sus asientos.
        ->and(employeeImportAuditTransactionEntries(AuditAction::EmployeesImported))->toBe(0)
        ->and(employeeImportAuditTransactionEntries(AuditAction::EmployeeHired))->toBe(0)
        ->and(DB::table('employees')->count())->toBe(0);
})->group('RF-GP-05', 'RL-04');

it('sin fallos, las altas y el asiento del lote confirman juntos', function (): void {
    $informe = employeeImportAuditTransactionReport();

    app(ApplyEmployeeImport::class)->handle($informe);

    expect(employeeImportAuditTransactionEntries(AuditAction::EmployeesImported))->toBe(1)
        ->and(employeeImportAuditTransactionEntries(AuditAction::EmployeeHired))->toBe(2)
        ->and(DB::table('employees')->count())->toBe(2);
})->group('RF-GP-05', 'RL-04');
