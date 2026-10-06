<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Policy\OriginLockAuditCeiling;
use App\Modules\Identity\Domain\Policy\OriginLockoutPolicy;
use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;

/*
 * La regla del bloqueo por origen del portal (RS-12, ADR-050 §2) y el techo de
 * asientos por hora (dictamen B1), sin reloj, sin cache y sin HTTP.
 */

const ORIGIN_LOCKOUT_POLICY_T0 = 1_791_000_000;

function politicaDeOrigen(): OriginLockoutPolicy
{
    // Los valores de serie: 20 fallos en 15 minutos, 60 minutos de bloqueo.
    return new OriginLockoutPolicy(maxFailures: 20, windowSeconds: 900, lockoutSeconds: 3600);
}

/**
 * Aplica `$veces` fallos, uno por segundo a partir de `$desde`.
 */
function tras(OriginLockoutPolicy $politica, int $veces, int $desde, ?OriginAttemptHistory $inicial = null): OriginAttemptHistory
{
    $estado = $inicial ?? OriginAttemptHistory::empty();

    for ($i = 0; $i < $veces; $i++) {
        $estado = $politica->afterFailure($estado, $desde + $i);
    }

    return $estado;
}

it('no bloquea con diecinueve fallos y bloquea una hora con el vigesimo', function (): void {
    $politica = politicaDeOrigen();

    $diecinueve = tras($politica, 19, ORIGIN_LOCKOUT_POLICY_T0);

    expect($politica->secondsUntilUnlock($diecinueve, ORIGIN_LOCKOUT_POLICY_T0 + 19))->toBe(0);

    $veinte = $politica->afterFailure($diecinueve, ORIGIN_LOCKOUT_POLICY_T0 + 19);

    expect($politica->secondsUntilUnlock($veinte, ORIGIN_LOCKOUT_POLICY_T0 + 19))->toBe(3600)
        ->and($politica->opened($diecinueve, $veinte, ORIGIN_LOCKOUT_POLICY_T0 + 19))->toBeTrue();
})->group('RS-12');

it('olvida los fallos que salen de la ventana deslizante', function (): void {
    // Diez fallos, dieciseis minutos de silencio y otros diez: nunca hay veinte
    // dentro de quince minutos.
    $politica = politicaDeOrigen();

    $primeros = tras($politica, 10, ORIGIN_LOCKOUT_POLICY_T0);
    $despues = tras($politica, 10, ORIGIN_LOCKOUT_POLICY_T0 + 960, $primeros);

    expect($politica->secondsUntilUnlock($despues, ORIGIN_LOCKOUT_POLICY_T0 + 970))->toBe(0)
        ->and($despues->failures)->toHaveCount(10);
})->group('RS-12');

it('cuenta como deslizante y no como bloques fijos', function (): void {
    // Quince fallos al final de un cuarto de hora y cinco al principio del
    // siguiente siguen siendo veinte en quince minutos.
    $politica = politicaDeOrigen();

    $estado = tras($politica, 15, ORIGIN_LOCKOUT_POLICY_T0 + 800);
    $estado = tras($politica, 5, ORIGIN_LOCKOUT_POLICY_T0 + 905, $estado);

    expect($politica->secondsUntilUnlock($estado, ORIGIN_LOCKOUT_POLICY_T0 + 910))->toBeGreaterThan(0);
})->group('RS-12');

it('no alarga el bloqueo y lo levanta a su hora', function (): void {
    // Durante el bloqueo no se evalua nada (quien llama no registra fallos),
    // asi que el fin es el de la apertura.
    $politica = politicaDeOrigen();

    $bloqueado = tras($politica, 20, ORIGIN_LOCKOUT_POLICY_T0);
    $abierto = ORIGIN_LOCKOUT_POLICY_T0 + 19;

    expect($politica->secondsUntilUnlock($bloqueado, $abierto + 1800))->toBe(1800)
        ->and($politica->secondsUntilUnlock($bloqueado, $abierto + 3600))->toBe(0)
        ->and($politica->secondsUntilUnlock($bloqueado, $abierto + 7200))->toBe(0);
})->group('RS-12');

it('empieza de cero cuando termina el bloqueo', function (): void {
    // Abrir el bloqueo vacia la cuenta: los fallos que lo abrieron ya pagaron.
    $politica = politicaDeOrigen();

    $bloqueado = tras($politica, 20, ORIGIN_LOCKOUT_POLICY_T0);
    $tras = $politica->afterFailure($bloqueado, ORIGIN_LOCKOUT_POLICY_T0 + 3700);

    expect($bloqueado->failures)->toBe([])
        ->and($tras->failures)->toHaveCount(1)
        ->and($politica->secondsUntilUnlock($tras, ORIGIN_LOCKOUT_POLICY_T0 + 3700))->toBe(0);
})->group('RS-12');

it('conserva el estado lo que dure lo mas largo entre ventana y bloqueo', function (): void {
    expect(politicaDeOrigen()->retentionSeconds())->toBe(3600)
        ->and(new OriginLockoutPolicy(5, 7200, 60)->retentionSeconds())->toBe(7200);
})->group('RS-12');

it('rechaza umbrales que no tienen sentido', function (int $fallos, int $ventana, int $bloqueo): void {
    expect(static fn (): OriginLockoutPolicy => new OriginLockoutPolicy($fallos, $ventana, $bloqueo))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'cero fallos' => [0, 900, 3600],
    'ventana cero' => [20, 0, 3600],
    'bloqueo cero' => [20, 900, 0],
])->group('RS-12');

it('deja asiento hasta el techo por hora y ninguno por encima', function (): void {
    // Dictamen B1: el asiento pasa por el candado de la cadena (ADR-010); por
    // encima del techo el bloqueo se aplica igual y queda solo el log.
    $techo = new OriginLockAuditCeiling(60);

    expect($techo->allowsAuditEntry(1))->toBeTrue()
        ->and($techo->allowsAuditEntry(60))->toBeTrue()
        ->and($techo->allowsAuditEntry(61))->toBeFalse()
        ->and(new OriginLockAuditCeiling(0)->allowsAuditEntry(1))->toBeFalse();
})->group('RS-12', 'RS-13');

it('agrupa por hora natural UTC', function (): void {
    $techo = new OriginLockAuditCeiling(60);

    // 2026-10-06T09:59:59Z y 09:00:00Z son la misma hora; 10:00:00Z, la siguiente.
    expect($techo->hourOf(1_791_280_799))->toBe(1_791_277_200)
        ->and($techo->hourOf(1_791_277_200))->toBe(1_791_277_200)
        ->and($techo->hourOf(1_791_280_800))->toBe(1_791_280_800);
})->group('RS-12');

it('no admite un techo negativo', function (): void {
    expect(static fn (): OriginLockAuditCeiling => new OriginLockAuditCeiling(-1))
        ->toThrow(InvalidArgumentException::class);
})->group('RS-12');
