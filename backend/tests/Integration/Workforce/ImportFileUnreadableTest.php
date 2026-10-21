<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use App\Modules\Workforce\Application\Exception\ImportFileUnavailable;
use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;
use App\Modules\Workforce\Infrastructure\Adapter\SimpleExcelImportSource;

/*
 * UN FICHERO DE IMPORTACION ILEGIBLE POR SU CONTENIDO ES UN RECHAZO; NO PODER
 * LEERLO DEL DISCO ES UNA AVERIA (RF-GP-05, revision del bloque 10).
 *
 * El rechazo (`UnreadableImportFile`, `422` en `file`) le dice a quien sube el
 * fichero que lo exporte otra vez. Si lo que falla es el disco del servidor,
 * eso seria mentira: `ImportFileUnavailable`, sin el marcador
 * `BusinessRejection`, sale `500` y llega al historico de errores.
 */

/** Lo que lanza `$lectura`, o un fallo de la prueba si no lanza. */
function importFileUnreadableFailure(Closure $lectura): Throwable
{
    try {
        $lectura();
    } catch (Throwable $fallo) {
        return $fallo;
    }

    throw new RuntimeException('La lectura tenia que fallar.');
}

it('un fichero que no esta en el disco es una averia, no un rechazo', function (): void {
    $ruta = sys_get_temp_dir().'/kq-import-no-existe-'.bin2hex(random_bytes(6)).'.csv';

    foreach ([
        importFileUnreadableFailure(static fn (): string => (new SimpleExcelImportSource)->checksum($ruta)),
        importFileUnreadableFailure(static fn (): array => (new SimpleExcelImportSource)->headers($ruta)),
    ] as $fallo) {
        expect($fallo)->toBeInstanceOf(ImportFileUnavailable::class)
            ->and($fallo)->not->toBeInstanceOf(BusinessRejection::class);
    }
})->group('RF-GP-05', 'RF-PD-15');

it('un fichero sin cabecera es un rechazo del contenido', function (): void {
    $ruta = sys_get_temp_dir().'/kq-import-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($ruta, '');

    try {
        $fallo = importFileUnreadableFailure(static fn (): array => (new SimpleExcelImportSource)->headers($ruta));

        expect($fallo)->toBeInstanceOf(UnreadableImportFile::class)
            ->and($fallo)->toBeInstanceOf(BusinessRejection::class);
    } finally {
        unlink($ruta);
    }
})->group('RF-GP-05');
