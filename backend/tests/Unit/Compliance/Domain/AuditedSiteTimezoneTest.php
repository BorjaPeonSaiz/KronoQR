<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezone;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;

/*
 * La zona del centro frente a la de su ultimo asiento `site.*` (RN-23, ADR-056,
 * ADR-057 §4; bloque 2 de la 2.3.0).
 *
 * Con registro horario la base de datos solo deja cambiar la zona por la
 * funcion `site_correct_timezone`, que no escribe asiento. Si alguien la llama
 * fuera de la orden de consola —o hace un `UPDATE` sin tramos por fuera del
 * producto—, la zona de `sites` deja de ser la del ultimo asiento: eso es lo que
 * se mira.
 */

it('denuncia una zona que no es la del ultimo asiento, con el centro y el asiento y sin valores', function (): void {
    $discrepancia = AuditedSiteTimezone::fromEntry(1, 'Atlantic/Canary', 42, [
        'previous_value' => ['name' => 'Hotel Marina', 'timezone' => 'Atlantic/Canary'],
        'new_value' => ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'],
    ])->discrepancy();

    expect($discrepancia)->not->toBeNull();
    \assert($discrepancia !== null);

    expect($discrepancia->kind)->toBe(WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit)
        ->and($discrepancia->kind->value)->toBe('site_timezone_differs_from_audit')
        ->and($discrepancia->siteId)->toBe(1)
        ->and($discrepancia->shiftEntryUuid)->toBeNull()
        ->and($discrepancia->auditEntryId)->toBe(42)
        ->and($discrepancia->fields)->toBe(['timezone'])
        // Nombres de campo, nunca valores (regla dura 21).
        ->and($discrepancia->describe())->toBe('sites #1 · site_timezone_differs_from_audit · timezone · audit_log #42')
        ->and($discrepancia->describe())->not->toContain('Canary')
        ->and($discrepancia->describe())->not->toContain('Madrid');
})->group('RN-23', 'RL-04', 'RS-07');

it('da por buena la zona que coincide con la del ultimo asiento, del alta o de una correccion', function (): void {
    expect(AuditedSiteTimezone::fromEntry(1, 'Europe/Madrid', 7, ['previous_value' => null, 'new_value' => ['timezone' => 'Europe/Madrid']])->discrepancy())->toBeNull()
        ->and(AuditedSiteTimezone::fromEntry(1, 'Atlantic/Canary', 9, [
            'previous_value' => ['timezone' => 'Europe/Madrid'],
            'new_value' => ['timezone' => 'Atlantic/Canary'],
            'via' => 'console',
        ])->discrepancy())->toBeNull();
})->group('RN-23', 'RL-04');

it('no denuncia un centro sin asiento: no hay con que comparar', function (): void {
    // Un centro anterior a la tarea 5.5, o cuyo asiento se fue con la
    // particion de su año (ADR-027).
    expect(AuditedSiteTimezone::fromEntry(1, 'Europe/Madrid', null, null)->discrepancy())->toBeNull();
})->group('RN-23', 'RL-04');

it('un asiento que no dice zona tampoco la justifica', function (array $payload): void {
    $site = AuditedSiteTimezone::fromEntry(1, 'Europe/Madrid', 42, $payload);

    expect($site->auditedTimezone)->toBeNull()
        ->and($site->discrepancy()?->kind)->toBe(WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit);
})->with([
    'sin new_value' => [['previous_value' => null]],
    'new_value sin zona' => [['new_value' => ['name' => 'Hotel Marina']]],
    'zona que no es texto' => [['new_value' => ['timezone' => 3]]],
    'new_value que no es objeto' => [['new_value' => 'Europe/Madrid']],
])->group('RN-23', 'RL-04');
