<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\ShiftEntryCorrected;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Infrastructure\Queue\RenamedQueuedClasses;
use Tests\Architecture\Support\Repo;

/*
 * Los alias de los nombres de la 2.2.x viven exactamente una version: la 2.3.0.
 *
 * Quitarlos antes deja sin leer los trabajos que una instalacion tenia en cola
 * al actualizar; dejarlos despues convierte un puente temporal en vocabulario
 * permanente, con dos nombres para el tramo que el glosario prohibe. La
 * version la decide el fichero `VERSION`, que es el que se publica.
 */

function versionDelRepositorio(): string
{
    return trim(explode("\n", Repo::contents('VERSION'), 2)[0]);
}

it('conserva los alias de la cola hasta la 2.4.0', function (): void {
    if (version_compare(versionDelRepositorio(), RenamedQueuedClasses::REMOVED_IN, '>=')) {
        expect(true)->toBeTrue();

        return;
    }

    expect(RenamedQueuedClasses::ALIASES)->toBe([
        'App\Modules\Attendance\Domain\Event\ShiftCorrected' => ShiftEntryCorrected::class,
        'App\Modules\Attendance\Domain\ValueObject\ShiftTimes' => ShiftEntryTimes::class,
        'App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly' => ShiftEntryAnomaly::class,
    ], 'Los alias de la cola se retiran en la '.RenamedQueuedClasses::REMOVED_IN.', no antes.')
        ->and(Repo::contents('backend/composer.json'))->toContain('"bootstrap/renamed-queued-classes.php"');
})->group('RNF-M-03');

it('obliga a retirar los alias de la cola al llegar a la 2.4.0', function (): void {
    if (version_compare(versionDelRepositorio(), RenamedQueuedClasses::REMOVED_IN, '<')) {
        expect(RenamedQueuedClasses::REMOVED_IN)->toBe('2.4.0');

        return;
    }

    // Llegados aqui, VERSION ya dice 2.4.0 o mas: borra RenamedQueuedClasses,
    // bootstrap/renamed-queued-classes.php, su entrada de `autoload.files` y
    // estas dos pruebas.
    expect(RenamedQueuedClasses::ALIASES)->toBe([], 'La '.versionDelRepositorio().' ya no lleva los alias de la 2.2.x: retiralos.');
})->group('RNF-M-03');
