<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Support\WorkRecordInstant;

/*
 * Como lee la conciliacion una marca escrita, en la fila o en el asiento
 * (ADR-057 §4, RL-04, V1-AR-2): el mismo instante cuadra aunque se escriba con
 * otro desfase, y lo ilegible no es «ahora».
 */

it('dos marcas cuadran si son el mismo instante, con el desfase que sea', function (?string $esperada, ?string $real, bool $cuadran): void {
    expect(WorkRecordInstant::same($esperada, $real))->toBe($cuadran);
})->with([
    'Z frente a desfase' => ['2026-10-07T07:00:00Z', '2026-10-07 09:00:00+02', true],
    'microsegundos de mas' => ['2026-10-07T07:00:00.000000Z', '2026-10-07T07:00:00Z', true],
    'un segundo distinto' => ['2026-10-07T07:00:00Z', '2026-10-07T07:00:01Z', false],
    'las dos ausentes' => [null, null, true],
    'solo una ausente' => [null, '2026-10-07T07:00:00Z', false],
    'la otra ausente' => ['2026-10-07T07:00:00Z', null, false],
    'ilegibles iguales' => ['now', 'now', true],
    'ilegibles distintas' => ['now', 'tomorrow', false],
    'ilegible frente a legible' => ['now', '2026-10-07T07:00:00Z', false],
])->group('RL-04', 'RS-07');

it('decide al segundo si dos marcas son distintas, y lo ilegible cuenta como distinto', function (?string $antes, ?string $despues, bool $distintas): void {
    expect(WorkRecordInstant::differsToTheSecond($antes, $despues))->toBe($distintas);
})->with([
    'mismo segundo, otros microsegundos' => ['2026-10-07T07:00:00.100000Z', '2026-10-07T07:00:00.900000Z', false],
    'mismo instante con desfase' => ['2026-10-07T07:00:00Z', '2026-10-07T09:00:00+02:00', false],
    'un segundo despues' => ['2026-10-07T07:00:00Z', '2026-10-07T07:00:01Z', true],
    'las dos ausentes' => [null, null, false],
    'las dos ilegibles' => ['now', 'tomorrow', false],
    'falta la de antes' => [null, '2026-10-07T07:00:00Z', true],
    'falta la de despues' => ['2026-10-07T07:00:00Z', null, true],
    'ilegible la de antes' => ['now', '2026-10-07T07:00:00Z', true],
    'ilegible la de despues' => ['2026-10-07T07:00:00Z', 'now', true],
])->group('RL-04', 'RS-07');

it('lee la marca en UTC o devuelve nulo', function (): void {
    expect(WorkRecordInstant::parse(null))->toBeNull()
        ->and(WorkRecordInstant::parse('now'))->toBeNull()
        ->and(WorkRecordInstant::parse('2026-10-07 09:00:00+02')?->format('Y-m-d\TH:i:sP'))->toBe('2026-10-07T07:00:00+00:00')
        ->and(WorkRecordInstant::normalized('2026-10-07 09:00:00+02'))->toBe('2026-10-07T07:00:00.000000Z')
        ->and(WorkRecordInstant::normalized('no es una fecha'))->toBe('no es una fecha');
})->group('RL-04');
