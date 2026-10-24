<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\ElapsedMinutes;

/*
 * Los casos COMPARTIDOS de la regla de minutos (RN-09, RN-10; V21-1-RV-1).
 *
 * El servidor mide con `ElapsedMinutes`; el panel y el portal enseñan pausas y
 * retrasos con `minutesBetween()` de `packages/web-kit/src/datetime.ts`. Si las
 * dos reglas divergen, una pausa de 10:00:00.900 a 10:15:00.100 sale como
 * «14 min» en pantalla mientras el servidor cuenta 15.
 *
 * Por eso los casos viven en `tests/Fixtures/elapsed-minutes.json`, que lee
 * tambien la prueba unitaria de web-kit: aqui se fija que el dominio los cumple.
 */

const ELAPSED_MINUTES_FIXTURE = __DIR__.'/../../../Fixtures/elapsed-minutes.json';

it('da para cada caso compartido los minutos que enseñan el panel y el portal', function (): void {
    $raw = file_get_contents(ELAPSED_MINUTES_FIXTURE);
    expect($raw)->toBeString();

    /** @var array{cases: list<array{label: string, from: string, to: string, minutes: int}>} $fixture */
    $fixture = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);

    expect($fixture['cases'])->not->toBeEmpty();

    foreach ($fixture['cases'] as $case) {
        $elapsed = ElapsedMinutes::between(new DateTimeImmutable($case['from']), new DateTimeImmutable($case['to']));

        expect($elapsed->minutes)->toBe($case['minutes'], $case['label']);
    }
})->group('RN-09', 'RN-10');
