<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezone;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezoneChange;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezoneReview;
use App\Modules\Compliance\Domain\ValueObject\RecordedSiteTimezoneChange;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
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

// --- La historia de la zona: traza, firma y lo no verificable (revision del bloque 2) ---

/** Un `site.updated` de `$from` a `$to`, firmado por consola o desde el panel. */
function zonaAuditadaCambio(int $id, string $from, string $to, bool $firmado, bool $conRegistro = true): AuditedSiteTimezoneChange
{
    return AuditedSiteTimezoneChange::fromEntry($id, [
        'previous_value' => ['name' => 'Hotel Marina', 'timezone' => $from],
        'new_value' => ['name' => 'Hotel Marina', 'timezone' => $to],
        ...($firmado ? ['via' => 'console', 'reason' => 'Se puso Madrid y era Canarias', 'shift_entry_count' => 3, 'responsible_user_id' => 7] : []),
    ], $conRegistro);
}

function zonaAuditadaFila(string $from, string $to, string $cuando = '2026-10-18T09:00:00Z', int $id = 1): RecordedSiteTimezoneChange
{
    return new RecordedSiteTimezoneChange($id, $from, $to, new DateTimeImmutable($cuando));
}

/**
 * @param  list<AuditedSiteTimezoneChange>  $asientos
 * @param  list<RecordedSiteTimezoneChange>  $filas
 * @param  list<int>  $sellados
 * @return list<string>
 */
function zonaAuditadaCampos(string $zona, array $asientos, array $filas, array $sellados = []): array
{
    $ultimo = $asientos === [] ? null : $asientos[\count($asientos) - 1];
    $site = new AuditedSiteTimezone(1, $zona, $ultimo === null ? 5 : $ultimo->auditEntryId, $ultimo === null ? $zona : $ultimo->to, $asientos, $filas);

    return array_map(static fn (WorkRecordDiscrepancy $d): string => $d->fields[0], $site->assess($sellados)->discrepancies);
}

it('da por buena una correccion de consola con su fila en la traza y su asiento firmado', function (): void {
    expect(zonaAuditadaCampos('Atlantic/Canary', [zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', true)], [zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary')]))
        ->toBe([]);
})->group('RN-23', 'RL-04');

it('denuncia una fila de la traza sin su asiento firmado, aunque la zona haya vuelto a ser la de antes', function (): void {
    // A->B->A con la funcion fuera de la orden: la zona vuelve a coincidir con
    // el asiento, pero la traza tiene dos filas y ningun asiento firmado.
    expect(zonaAuditadaCampos('Europe/Madrid', [], [
        zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary'),
        zonaAuditadaFila('Atlantic/Canary', 'Europe/Madrid'),
    ]))->toBe(['site_timezone_changes', 'site_timezone_changes']);

    // Con un solo asiento firmado para dos cambios iguales, sobra una fila.
    expect(zonaAuditadaCampos('Atlantic/Canary', [
        zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', true),
    ], [
        zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary'),
        zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary'),
    ]))->toBe(['site_timezone_changes']);

    // Un asiento sin firma no justifica una fila.
    expect(zonaAuditadaCampos('Atlantic/Canary', [zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false, false)], [zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary')]))
        ->toBe(['site_timezone_changes']);
})->group('RN-23', 'RL-04', 'RS-07');

it('no denuncia una correccion firmada sin fila: las de la 2.2.1 son anteriores a la traza', function (): void {
    expect(zonaAuditadaCampos('Atlantic/Canary', [zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', true)], []))->toBe([]);
})->group('RN-23', 'RL-04');

it('denuncia un cambio de zona sin firma despues del primer tramo, y no antes ni el de solo el nombre', function (): void {
    expect(zonaAuditadaCampos('Atlantic/Canary', [zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false)], []))->toBe(['correction'])
        ->and(zonaAuditadaCampos('Atlantic/Canary', [zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false, false)], []))->toBe([])
        ->and(zonaAuditadaCampos('Europe/Madrid', [zonaAuditadaCambio(9, 'Europe/Madrid', 'Europe/Madrid', false)], []))->toBe([]);
})->group('RN-23', 'RL-04', 'RS-07');

it('lee la firma de la consola: via, motivo, recuento y cuenta', function (array $sobra): void {
    $firmado = zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', true);
    $payload = [
        'previous_value' => ['timezone' => 'Europe/Madrid'],
        'new_value' => ['timezone' => 'Atlantic/Canary'],
        'via' => 'console', 'reason' => 'Se puso Madrid y era Canarias', 'shift_entry_count' => 3, 'responsible_user_id' => 7,
        ...$sobra,
    ];

    expect($firmado->signed)->toBeTrue()
        ->and(AuditedSiteTimezoneChange::fromEntry(9, $payload, true)->signed)->toBeFalse();
})->with([
    'otra via' => [['via' => 'panel']],
    'sin motivo' => [['reason' => '  ']],
    'sin tramos' => [['shift_entry_count' => 0]],
    'sin cuenta' => [['responsible_user_id' => null]],
])->group('RN-23', 'RL-04');

it('cuenta como no verificable la zona sin asiento, con o sin año sellado, y no la denuncia', function (array $sellados): void {
    // Sin asiento `site.*`: una instalacion anterior a la tarea 5.5, o su asiento
    // se fue con la particion purgada de su año (ADR-027). No se apaga en
    // silencio: sale como no verificable.
    $evaluacion = AuditedSiteTimezone::fromEntry(1, 'Europe/Madrid', null, null)->assess(array_values(array_map(intval(...), $sellados)));

    expect($evaluacion->discrepancies)->toBe([])
        ->and($evaluacion->unverifiableTimezone)->toBeTrue()
        ->and($evaluacion->unverifiableChanges)->toBe(0);
})->with([
    'con año sellado' => [[2021]],
    'sin año sellado' => [[]],
])->group('RN-23', 'RL-04');

it('no coteja las filas de la traza de un año cuya auditoria ya se purgo: las cuenta aparte', function (): void {
    $site = new AuditedSiteTimezone(1, 'Atlantic/Canary', 50, 'Atlantic/Canary', [], [
        zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary', '2021-12-31T23:30:00Z'),
        zonaAuditadaFila('Atlantic/Canary', 'Europe/Lisbon', '2022-01-01T00:30:00Z'),
    ]);

    $evaluacion = $site->assess([2020, 2021]);

    expect($evaluacion->unverifiableChanges)->toBe(1)
        ->and($evaluacion->unverifiableTimezone)->toBeFalse()
        ->and(array_map(static fn (WorkRecordDiscrepancy $d): string => $d->fields[0], $evaluacion->discrepancies))->toBe(['site_timezone_changes']);
})->group('RN-23', 'RL-04');

// --- Linea base y revisiones (segunda revision del bloque 2) ---------------------

it('cuenta como historico, y no denuncia, un cambio sin firma anterior a la linea base', function (): void {
    // Hasta la 2.2.1 el panel cambiaba la zona con fichajes hechos.
    $site = new AuditedSiteTimezone(1, 'Atlantic/Canary', 9, 'Atlantic/Canary', [
        zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false),
    ], [], [], 9);

    $evaluacion = $site->assess([]);

    expect($evaluacion->discrepancies)->toBe([])
        ->and($evaluacion->unverifiableChanges)->toBe(1);

    // El mismo asiento despues de la linea base si es discrepancia.
    $despues = new AuditedSiteTimezone(1, 'Atlantic/Canary', 9, 'Atlantic/Canary', [
        zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false),
    ], [], [], 8);

    expect(array_map(static fn (WorkRecordDiscrepancy $d): string => $d->fields[0], $despues->assess([])->discrepancies))->toBe(['correction']);
})->group('RN-23', 'RL-04');

it('no usa una correccion firmada anterior a la linea base para justificar una fila de la traza', function (): void {
    $site = new AuditedSiteTimezone(1, 'Atlantic/Canary', 9, 'Atlantic/Canary', [
        zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', true),
    ], [zonaAuditadaFila('Europe/Madrid', 'Atlantic/Canary', '2026-10-18T09:00:00Z', 4)], [], 9);

    $discrepancias = $site->assess([])->discrepancies;

    expect($discrepancias)->toHaveCount(1)
        ->and($discrepancias[0]->fields)->toBe(['site_timezone_changes'])
        ->and($discrepancias[0]->siteTimezoneChangeId)->toBe(4)
        ->and($discrepancias[0]->describe())->toBe('sites #1 · site_timezone_differs_from_audit · site_timezone_changes #4');
})->group('RN-23', 'RL-04', 'RS-07');

it('saca de las pendientes lo revisado, y la cuenta como revisada', function (): void {
    $site = new AuditedSiteTimezone(1, 'Atlantic/Canary', 9, 'Atlantic/Canary', [
        zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false),
    ], [zonaAuditadaFila('Atlantic/Canary', 'Europe/Lisbon', '2026-10-18T09:00:00Z', 4)], [
        AuditedSiteTimezoneReview::fromEntry(20, ['reviewed_audit_entry_id' => 9]),
        AuditedSiteTimezoneReview::fromEntry(21, ['reviewed_site_timezone_change_id' => 4]),
    ]);

    $evaluacion = $site->assess([]);

    expect($evaluacion->discrepancies)->toBe([])
        ->and($evaluacion->reviewed)->toBe(2)
        ->and($site->reviewable([]))->toBe([]);
})->group('RN-23', 'RL-04');

it('no da por revisado un asiento con una revision anterior a el ni con otra fila', function (): void {
    $site = new AuditedSiteTimezone(1, 'Atlantic/Canary', 9, 'Atlantic/Canary', [
        zonaAuditadaCambio(9, 'Europe/Madrid', 'Atlantic/Canary', false),
    ], [zonaAuditadaFila('Atlantic/Canary', 'Europe/Lisbon', '2026-10-18T09:00:00Z', 4)], [
        AuditedSiteTimezoneReview::fromEntry(5, ['reviewed_audit_entry_id' => 9]),
        AuditedSiteTimezoneReview::fromEntry(21, ['reviewed_site_timezone_change_id' => 99]),
        AuditedSiteTimezoneReview::fromEntry(22, ['reviewed_site_timezone_change_id' => '4']),
    ]);

    expect($site->assess([])->reviewed)->toBe(0)
        ->and(array_map(static fn (WorkRecordDiscrepancy $d): string => $d->fields[0], $site->reviewable([])))
        ->toBe(['correction', 'site_timezone_changes']);
})->group('RN-23', 'RL-04');

it('no ofrece revisar la zona actual: se devuelve por el producto', function (): void {
    $site = AuditedSiteTimezone::fromEntry(1, 'Europe/Lisbon', 42, ['new_value' => ['timezone' => 'Atlantic/Canary']]);

    expect($site->assess([])->discrepancies)->toHaveCount(1)
        ->and($site->reviewable([]))->toBe([]);
})->group('RN-23', 'RL-04');
