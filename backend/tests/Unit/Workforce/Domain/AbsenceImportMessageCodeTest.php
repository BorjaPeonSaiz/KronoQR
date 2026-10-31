<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\AbsenceImportMessageCode;

/*
 * Avisos y errores del informe de importacion de ausencias (RF-GP-04). Un aviso
 * no impide la linea; un error si. Recorre TODOS los codigos: uno nuevo que se
 * declare sin decidir que es sale aqui como error, que es lo seguro.
 */

it('solo avisa la columna desconocida; el resto rechaza la linea', function (): void {
    $avisos = array_values(array_filter(
        AbsenceImportMessageCode::cases(),
        static fn (AbsenceImportMessageCode $code): bool => $code->isWarning(),
    ));

    expect($avisos)->toBe([AbsenceImportMessageCode::UNKNOWN_COLUMN]);
})->group('RF-GP-04');

it('rechaza la linea fuera del periodo de alta con el codigo del contrato', function (): void {
    // RN-27: la revision dice lo mismo que la escritura (`Absence::fallsWithin()`).
    expect(AbsenceImportMessageCode::OUTSIDE_EMPLOYMENT->value)->toBe('outside_employment')
        ->and(AbsenceImportMessageCode::OUTSIDE_EMPLOYMENT->isWarning())->toBeFalse();
})->group('RF-GP-04', 'RN-27');
