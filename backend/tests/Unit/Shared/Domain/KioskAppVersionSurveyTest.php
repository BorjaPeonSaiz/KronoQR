<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\KioskAppVersionEntry;
use App\Modules\Shared\Domain\ValueObject\KioskAppVersionSurvey;
use App\Modules\Shared\Domain\ValueObject\QueueStorage;

/*
 * El sondeo de versiones de la PWA que `Kiosk` entrega a `product:doctor`
 * (RF-PD-13, RF-KI-07, V21-1-AR-4): la cola es el enum del latido, no una
 * cadena, y los recuentos no pueden ser imposibles.
 */

function sondeoDeVersionTablet(QueueStorage $cola = QueueStorage::Durable, ?int $pendientes = 0, string $uuid = '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11'): KioskAppVersionEntry
{
    return new KioskAppVersionEntry($uuid, '2.2.0', $cola, $pendientes);
}

it('solo una tablet con la cola en disco y vacia esta lista para recargar', function (QueueStorage $cola, ?int $pendientes, bool $lista): void {
    expect(sondeoDeVersionTablet($cola, $pendientes)->isReadyToReload())->toBe($lista);
})->with([
    'en disco y vacia' => [QueueStorage::Durable, 0, true],
    'en disco con pendientes' => [QueueStorage::Durable, 3, false],
    'en disco sin saber cuantos' => [QueueStorage::Durable, null, false],
    'en memoria' => [QueueStorage::Memory, 0, false],
    'sin almacen' => [QueueStorage::Unavailable, 0, false],
])->group('RF-KI-07', 'RF-KI-04');

it('rechaza una tablet sin uuid o con una cola negativa', function (Closure $tablet): void {
    expect($tablet)->toThrow(InvalidArgumentException::class);
})->with([
    'sin uuid' => [static fn (): KioskAppVersionEntry => sondeoDeVersionTablet(uuid: '')],
    'cola negativa' => [static fn (): KioskAppVersionEntry => sondeoDeVersionTablet(pendientes: -1)],
])->group('RF-KI-07');

it('acepta el sondeo sin version y el que lista parte de lo comparado', function (): void {
    $sinVersion = KioskAppVersionSurvey::unchecked();
    $sondeo = new KioskAppVersionSurvey('2.2.0', 3, [sondeoDeVersionTablet()], [sondeoDeVersionTablet()]);
    $justo = new KioskAppVersionSurvey('2.2.0', 1, [sondeoDeVersionTablet()]);

    expect($sinVersion->isEnforced())->toBeFalse()
        ->and($sinVersion->examined)->toBe(0)
        ->and($sondeo->isEnforced())->toBeTrue()
        ->and($sondeo->examined)->toBe(3)
        ->and($justo->behind)->toHaveCount(1);
})->group('RF-PD-13', 'RF-KI-07');

it('rechaza un sondeo con recuentos imposibles', function (Closure $sondeo): void {
    expect($sondeo)->toThrow(InvalidArgumentException::class);
})->with([
    // El caso reproducido en la verificacion de la 2.2.1.
    'comparados negativos' => [static fn (): KioskAppVersionSurvey => new KioskAppVersionSurvey('2.2.0', -1)],
    'lista mas de los comparados' => [static fn (): KioskAppVersionSurvey => new KioskAppVersionSurvey('2.2.0', 1, [sondeoDeVersionTablet()], [sondeoDeVersionTablet()])],
    'compara sin version del servidor' => [static fn (): KioskAppVersionSurvey => new KioskAppVersionSurvey(null, 1)],
])->group('RF-PD-13', 'RF-KI-07');
