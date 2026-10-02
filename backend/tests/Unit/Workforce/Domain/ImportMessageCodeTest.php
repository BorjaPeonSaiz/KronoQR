<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\ValueObject\ImportMessageCode;

/*
 * Avisos y errores del informe de importacion (RF-GP-05). Un aviso no impide la
 * linea; un error si. Recorre TODOS los codigos: uno nuevo que se declare sin
 * decidir que es sale aqui como error, que es lo seguro.
 */

it('solo avisan la fecha de alta no aplicada y la columna desconocida; el resto rechaza la linea', function (): void {
    $avisos = array_values(array_filter(
        ImportMessageCode::cases(),
        static fn (ImportMessageCode $code): bool => $code->isWarning(),
    ));

    expect($avisos)->toBe([ImportMessageCode::HIRED_AT_NOT_UPDATED, ImportMessageCode::UNKNOWN_COLUMN]);
})->group('RF-GP-05');
