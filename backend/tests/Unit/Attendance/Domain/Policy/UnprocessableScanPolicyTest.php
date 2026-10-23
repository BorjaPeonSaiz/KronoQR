<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;
use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;
use App\Modules\Attendance\Domain\ValueObject\UnprocessableScanVerdict;

/*
 * RN-22 (descarte por el servidor), ADR-061: cuando deja el servidor de
 * intentar un fichaje que no consigue procesar, para que la cola del quiosco
 * no se atasque para siempre (RN-21, RF-KI-04).
 *
 * Tres condiciones a la vez —intentos, ventana y cupo— y cada una con su borde:
 * es justo donde se equivoca una comparacion escrita a mano. El primer fallo
 * de todos los casos es a las 07:00:00 UTC.
 */

function unprocessableAt(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

function unprocessableHistory(int $attempts, string $firstFailedAt = '2026-10-23 07:00:00'): ScanFailureHistory
{
    return new ScanFailureHistory($attempts, unprocessableAt($firstFailedAt));
}

/** Los valores de serie de ADR-061: 10 intentos, 60 minutos, 3 descartes por quiosco y 24 h. */
function unprocessableThreshold(int $minAttempts = 10, int $minSpanMinutes = 60, int $dailyBudget = 3): ScanDiscardThreshold
{
    return new ScanDiscardThreshold($minAttempts, $minSpanMinutes, $dailyBudget);
}

it('descarta cuando se cumplen las tres condiciones, y conserva si falta una', function (int $attempts, string $now, int $discards, UnprocessableScanVerdict $expected): void {
    $verdict = (new UnprocessableScanPolicy)->decide(
        unprocessableHistory($attempts),
        $discards,
        unprocessableAt($now),
        unprocessableThreshold(),
    );

    expect($verdict)->toBe($expected);
})->with([
    'diez intentos a los 60 min justos: descarta' => [10, '2026-10-23 08:00:00', 0, UnprocessableScanVerdict::Discard],
    'muchos intentos y mucho tiempo: descarta' => [40, '2026-10-23 12:00:00', 0, UnprocessableScanVerdict::Discard],
    'nueve intentos: conserva' => [9, '2026-10-23 12:00:00', 0, UnprocessableScanVerdict::KeepRetrying],
    'un intento tras un dia sin red: conserva' => [1, '2026-10-24 07:00:00', 0, UnprocessableScanVerdict::KeepRetrying],
    'a 59 min 59 s: conserva' => [10, '2026-10-23 07:59:59', 0, UnprocessableScanVerdict::KeepRetrying],
    'a 59 min 59,9 s: conserva' => [10, '2026-10-23 07:59:59.900000', 0, UnprocessableScanVerdict::KeepRetrying],
    'en el primer fallo: conserva' => [10, '2026-10-23 07:00:00', 0, UnprocessableScanVerdict::KeepRetrying],
    'reloj del servidor corregido hacia atras: conserva' => [10, '2026-10-23 06:00:00', 0, UnprocessableScanVerdict::KeepRetrying],
    'dos descartes en el dia: aun cabe el tercero' => [10, '2026-10-23 08:00:00', 2, UnprocessableScanVerdict::Discard],
    'tres descartes en el dia: cupo agotado' => [10, '2026-10-23 08:00:00', 3, UnprocessableScanVerdict::BudgetExhausted],
    'mas descartes que cupo: cupo agotado' => [10, '2026-10-23 08:00:00', 7, UnprocessableScanVerdict::BudgetExhausted],
])->group('RN-22', 'RN-21', 'RF-KI-04');

it('mira el cupo el ultimo: sin prueba de veneno no dice cupo agotado', function (int $attempts, string $now): void {
    $verdict = (new UnprocessableScanPolicy)->decide(
        unprocessableHistory($attempts),
        3,
        unprocessableAt($now),
        unprocessableThreshold(),
    );

    expect($verdict)->toBe(UnprocessableScanVerdict::KeepRetrying);
})->with([
    'faltan intentos' => [9, '2026-10-23 12:00:00'],
    'falta ventana' => [10, '2026-10-23 07:30:00'],
])->group('RN-22');

it('con el cupo a cero no descarta nunca ni avisa de cupo agotado', function (int $discards): void {
    $verdict = (new UnprocessableScanPolicy)->decide(
        unprocessableHistory(500),
        $discards,
        unprocessableAt('2026-10-30 07:00:00'),
        unprocessableThreshold(dailyBudget: 0),
    );

    expect($verdict)->toBe(UnprocessableScanVerdict::KeepRetrying);
})->with([
    'sin descartes previos' => [0],
    'con descartes previos' => [4],
])->group('RN-22');

it('usa los intentos y la ventana que trae el umbral, no los de serie', function (): void {
    $policy = new UnprocessableScanPolicy;
    $threshold = unprocessableThreshold(minAttempts: 3, minSpanMinutes: 15, dailyBudget: 1);

    expect($policy->decide(unprocessableHistory(3), 0, unprocessableAt('2026-10-23 07:15:00'), $threshold))
        ->toBe(UnprocessableScanVerdict::Discard)
        ->and($policy->decide(unprocessableHistory(2), 0, unprocessableAt('2026-10-23 07:15:00'), $threshold))
        ->toBe(UnprocessableScanVerdict::KeepRetrying)
        ->and($policy->decide(unprocessableHistory(3), 0, unprocessableAt('2026-10-23 07:14:59'), $threshold))
        ->toBe(UnprocessableScanVerdict::KeepRetrying)
        ->and($policy->decide(unprocessableHistory(3), 1, unprocessableAt('2026-10-23 07:15:00'), $threshold))
        ->toBe(UnprocessableScanVerdict::BudgetExhausted);

    $longer = unprocessableThreshold(minAttempts: 20, minSpanMinutes: 180);

    expect($policy->decide(unprocessableHistory(19), 0, unprocessableAt('2026-10-23 12:00:00'), $longer))
        ->toBe(UnprocessableScanVerdict::KeepRetrying)
        ->and($policy->decide(unprocessableHistory(20), 0, unprocessableAt('2026-10-23 09:59:59'), $longer))
        ->toBe(UnprocessableScanVerdict::KeepRetrying)
        ->and($policy->decide(unprocessableHistory(20), 0, unprocessableAt('2026-10-23 10:00:00'), $longer))
        ->toBe(UnprocessableScanVerdict::Discard);
})->group('RN-22');

it('rechaza un recuento de descartes negativo y admite el cero', function (): void {
    $policy = new UnprocessableScanPolicy;

    expect(fn (): UnprocessableScanVerdict => $policy->decide(
        unprocessableHistory(10),
        -1,
        unprocessableAt('2026-10-23 08:00:00'),
        unprocessableThreshold(),
    ))->toThrow(InvalidArgumentException::class)
        ->and($policy->decide(unprocessableHistory(10), 0, unprocessableAt('2026-10-23 08:00:00'), unprocessableThreshold()))
        ->toBe(UnprocessableScanVerdict::Discard);
})->group('RN-22');

it('exige el instante actual en UTC', function (): void {
    $madrid = new DateTimeImmutable('2026-10-23 10:00:00', new DateTimeZone('Europe/Madrid'));

    expect(fn (): UnprocessableScanVerdict => (new UnprocessableScanPolicy)->decide(
        unprocessableHistory(10),
        0,
        $madrid,
        unprocessableThreshold(),
    ))->toThrow(InstantIsNotUtc::class)
        ->and(fn (): DateTimeImmutable => (new UnprocessableScanPolicy)->budgetWindowStartsAt($madrid))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-22');

it('cuenta el cupo en las ultimas 24 horas exactas', function (): void {
    $starts = (new UnprocessableScanPolicy)->budgetWindowStartsAt(unprocessableAt('2026-10-25 01:30:00.250000'));

    expect(UnprocessableScanPolicy::BUDGET_WINDOW_HOURS)->toBe(24)
        ->and($starts->format('Y-m-d H:i:s.u'))->toBe('2026-10-24 01:30:00.250000')
        ->and($starts->getOffset())->toBe(0);
})->group('RN-22');

it('solo el descarte es un desenlace terminal', function (UnprocessableScanVerdict $verdict, string $value, bool $terminal): void {
    expect($verdict->value)->toBe($value)
        ->and($verdict->isTerminal())->toBe($terminal);
})->with([
    'conservar' => [UnprocessableScanVerdict::KeepRetrying, 'keep_retrying', false],
    'descartar' => [UnprocessableScanVerdict::Discard, 'discard', true],
    'cupo agotado' => [UnprocessableScanVerdict::BudgetExhausted, 'budget_exhausted', false],
])->group('RN-22');
