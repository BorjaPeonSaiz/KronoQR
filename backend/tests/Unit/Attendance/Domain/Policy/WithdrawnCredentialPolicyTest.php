<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Policy\WithdrawnCredentialPolicy;

/*
 * RN-20 (ADR-047): ¿valia la tarjeta cuando se uso? Solo un fichaje dentro de la
 * vida de la tarjeta —desde su emision, incluida, hasta su retirada, excluida—
 * pide revision. La cota inferior es F2 del dictamen de seguridad del bloque 18:
 * el `occurred_at` lo pone la tablet.
 */

const WITHDRAWN_CREDENTIAL_POLICY_ISSUED = '2026-08-01 09:00:00';

const WITHDRAWN_CREDENTIAL_POLICY_WITHDRAWN = '2026-08-15 16:00:00';

function retiradaALas(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

it('decide la revision por los dos lados de la vida de la tarjeta', function (string $occurredAt, bool $expected): void {
    $policy = new WithdrawnCredentialPolicy;

    expect($policy->requiresReview(
        retiradaALas($occurredAt),
        retiradaALas(WITHDRAWN_CREDENTIAL_POLICY_ISSUED),
        retiradaALas(WITHDRAWN_CREDENTIAL_POLICY_WITHDRAWN),
    ))->toBe($expected);
})->with([
    'un segundo antes de la retirada: valia' => ['2026-08-15 15:59:59', true],
    'el mismo instante de la retirada: ya no valia' => ['2026-08-15 16:00:00', false],
    'un segundo despues de la retirada: ya no valia' => ['2026-08-15 16:00:01', false],
    'un microsegundo antes de la retirada: valia' => ['2026-08-15 15:59:59.999999', true],
    'la salida del ultimo dia, sin red' => ['2026-08-15 13:00:00', true],
    'un segundo antes de la emision: no existia' => ['2026-08-01 08:59:59', false],
    'el mismo instante de la emision: ya valia' => ['2026-08-01 09:00:00', true],
    'una fecha elegida de hace años: no existia' => ['2019-01-01 00:00:00', false],
])->group('RN-20', 'RN-14');

it('no afirma nada con el reloj de la tablet adelantado sobre la recepcion', function (): void {
    // Sin credencial retirada, la retirada es la recepcion: un `occurred_at`
    // posterior a ella solo puede venir de un reloj adelantado.
    $recibido = retiradaALas('2026-08-15 10:00:00');

    expect((new WithdrawnCredentialPolicy)->requiresReview(
        retiradaALas('2026-08-15 10:05:00'),
        retiradaALas(WITHDRAWN_CREDENTIAL_POLICY_ISSUED),
        $recibido,
    ))->toBeFalse();
})->group('RN-20', 'RF-AT-10');
