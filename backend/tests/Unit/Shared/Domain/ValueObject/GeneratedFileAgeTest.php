<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\FileTimestamps;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;

/*
 * La edad de un fichero generado (ADR-045, condicion C9).
 *
 * Es lo unico que separa un resto huerfano de un trabajo en curso, asi que cada
 * frontera se prueba en los dos lados: `max(mtime, ctime)`, `ctime` cuando
 * `mtime` esta en el futuro, la entrada mas reciente de un directorio y el
 * empate exacto con la edad minima, que conserva.
 */

const GENERATED_FILE_AGE_NOW = '2026-10-02T12:00:00+00:00';

function edadAhora(): DateTimeImmutable
{
    return new DateTimeImmutable(GENERATED_FILE_AGE_NOW);
}

/** Marcas de tiempo a `$mtime` y `$ctime` segundos antes de ahora (negativo: en el futuro). */
function marcas(int $mtime, int $ctime): FileTimestamps
{
    $now = edadAhora()->getTimestamp();

    return FileTimestamps::fromEpochSeconds($now - $mtime, $now - $ctime);
}

it('toma como ultimo toque el mas reciente de mtime y ctime', function (int $mtime, int $ctime, int $edad): void {
    $entrada = new GeneratedFileEntry('fichero', [marcas($mtime, $ctime)]);

    expect($entrada->ageInSeconds(edadAhora()))->toBe($edad);
})->with([
    // `touch -d` hacia atras no envejece un fichero recien escrito: manda ctime.
    'mtime viejo y ctime reciente' => [86400, 60, 60],
    // Una herramienta de copia que conserva fechas no adelanta el borrado.
    'ctime viejo y mtime reciente' => [60, 86400, 60],
    'iguales' => [3600, 3600, 3600],
])->group('RF-PD-14', 'RL-11');

it('ignora un mtime en el futuro y usa ctime', function (): void {
    // Sin esto, `touch -d 2099-01-01` haria al fichero inmortal.
    $entrada = new GeneratedFileEntry('fichero', [marcas(-86400 * 365, 7200)]);

    expect($entrada->ageInSeconds(edadAhora()))->toBe(7200)
        ->and($entrada->isOlderThan(3600, edadAhora()))->toBeTrue();
})->group('RF-PD-14', 'RL-11');

it('un mtime exactamente en el instante actual no es futuro', function (): void {
    $entrada = new GeneratedFileEntry('fichero', [marcas(0, 7200)]);

    expect($entrada->ageInSeconds(edadAhora()))->toBe(0);
})->group('RF-PD-14');

it('un ctime en el futuro deja la edad negativa y nunca es viejo', function (): void {
    $entrada = new GeneratedFileEntry('fichero', [marcas(-60, -60)]);

    expect($entrada->ageInSeconds(edadAhora()))->toBe(-60)
        ->and($entrada->isOlderThan(0, edadAhora()))->toBeFalse();
})->group('RF-PD-14');

it('la edad de un directorio es la de su entrada mas reciente, el incluido', function (): void {
    // Un `.work-<uuid>/` creado hace dos horas en el que se escribio un CSV hace
    // un minuto esta en uso.
    $directorio = new GeneratedFileEntry('.work-x', [
        marcas(7200, 7200),
        marcas(60, 60),
        marcas(3600, 3600),
    ]);

    expect($directorio->ageInSeconds(edadAhora()))->toBe(60)
        ->and($directorio->lastTouchedAt(edadAhora())->getTimestamp())->toBe(edadAhora()->getTimestamp() - 60);
})->group('RF-PD-14', 'RL-11');

it('la entrada mas reciente cuenta aunque sea la primera', function (): void {
    $directorio = new GeneratedFileEntry('d', [marcas(10, 10), marcas(500, 500)]);

    expect($directorio->ageInSeconds(edadAhora()))->toBe(10);
})->group('RF-PD-14');

it('en el empate exacto con la edad minima se conserva; un segundo mas, se borra', function (): void {
    $entrada = new GeneratedFileEntry('fichero', [marcas(3600, 3600)]);

    expect($entrada->isOlderThan(3600, edadAhora()))->toBeFalse()
        ->and($entrada->isOlderThan(3599, edadAhora()))->toBeTrue();
})->group('RF-PD-14', 'RL-11');

it('sin ninguna marca legible, la entrada se trata como recien tocada', function (): void {
    $entrada = new GeneratedFileEntry('fichero', []);

    expect($entrada->ageInSeconds(edadAhora()))->toBe(0)
        ->and($entrada->isOlderThan(0, edadAhora()))->toBeFalse()
        ->and($entrada->lastTouchedAt(edadAhora()))->toEqual(edadAhora());
})->group('RF-PD-14');

it('construye las marcas desde segundos de epoch, en UTC y sin leer el reloj', function (): void {
    $marcas = FileTimestamps::fromEpochSeconds(1_000, 2_000);

    expect($marcas->modifiedAt->getTimestamp())->toBe(1_000)
        ->and($marcas->changedAt->getTimestamp())->toBe(2_000)
        ->and($marcas->modifiedAt->getOffset())->toBe(0);
})->group('RF-PD-14');
