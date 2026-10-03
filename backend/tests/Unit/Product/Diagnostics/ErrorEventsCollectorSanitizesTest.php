<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\ErrorEventsCollector;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Tests\Support\Product\InMemoryErrorHistory;
use Tests\Support\Product\SeededPersonalData;
use Tests\Support\Time\FixedClock;

/*
 * El colector del paquete vuelve a sanear al leer, y el anonimizado no lleva
 * identificador de empleado (ADR-048, H2 y H7; RF-PD-09, RF-PD-15, RL-19).
 *
 * La fila «antigua» de aqui la escribio una version anterior a la 2.2.0, o un
 * camino que no pasa por `RecordErrorEvent`: lleva nombres en cada columna de
 * texto. No puede salir de ninguna.
 */

const ERROR_EVENTS_COLLECTOR_EMPLOYEE = '0199a1f0-1111-7000-8000-0000000000e1';

function errorEventsCollectorLegacyHistory(): InMemoryErrorHistory
{
    return new InMemoryErrorHistory([
        InMemoryErrorHistory::group(
            1,
            'Employee '.ERROR_EVENTS_COLLECTOR_EMPLOYEE.' (Rosa Ficticiana, 45678912K) has no open shift entry',
            ['reason' => 'Luz Inventadez', 'component' => 'Will Testerson', 'source' => 'https://kiosk.hotel-ejemplo.es/assets/index.js?u=ficticiana:12'],
            source: ErrorSource::Api,
            code: 'Ficticiana',
            appVersion: 'Ficticiana',
            file: '/home/ficticiana/Inventadez.php',
            exceptionClass: 'class@anonymous/home/ficticiana/x.php',
            employeeUuid: ERROR_EVENTS_COLLECTOR_EMPLOYEE,
            lastSeenAt: '2026-10-02T10:00:00Z',
        ),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function errorEventsCollectorGroups(DiagnosticsOptions $options): array
{
    $section = new ErrorEventsCollector(errorEventsCollectorLegacyHistory(), FixedClock::at('2026-10-03 10:00:00'))
        ->collect($options);

    /** @var list<array<string, mixed>> $groups */
    $groups = $section['groups'];

    return $groups;
}

it('no saca un nombre de una fila antigua por ninguna columna', function (DiagnosticsOptions $options): void {
    $volcado = (string) json_encode(errorEventsCollectorGroups($options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect(SeededPersonalData::allLeaksIn($volcado))->toBe([])
        ->and($volcado)->not->toContain('ficticiana')
        ->and($volcado)->not->toContain('hotel-ejemplo');
})->with([
    'anonimizado' => [DiagnosticsOptions::anonymized()],
    'con datos personales' => [DiagnosticsOptions::withPersonalData(7)],
])->group('RF-PD-09', 'RF-PD-15', 'RL-19');

it('vuelve a aplicar las reglas de las columnas al leer (H7)', function (): void {
    $grupo = errorEventsCollectorGroups(DiagnosticsOptions::withPersonalData(7))[0];

    expect($grupo['code'])->toBeNull()
        ->and($grupo['app_version'])->toBe('…')
        ->and($grupo['file'])->toBe('/home/…/….php')
        ->and($grupo['exception_class'])->toBe('class@anonymous/home/…/x.php')
        ->and($grupo['context'])->toBe('{"reason":"…","component":"…","source":"/assets/index.js:12"}');
})->group('RF-PD-09', 'RF-PD-15', 'RL-19');

it('en el anonimizado omite employee_uuid y cambia todo UUID del texto por [uuid] (H2)', function (): void {
    $grupo = errorEventsCollectorGroups(DiagnosticsOptions::anonymized())[0];

    expect($grupo)->not->toHaveKey('employee_uuid')
        ->and($grupo['message'])->toBe('Employee [uuid] (…, [id]) has no open shift entry')
        ->and(SeededPersonalData::uuidsIn((string) json_encode($grupo), [ERROR_EVENTS_COLLECTOR_EMPLOYEE]))->toBe([]);
})->group('RF-PD-09', 'RF-PD-15', 'RL-19');

it('con datos personales conserva el identificador de empleado (control positivo de H2)', function (): void {
    $grupo = errorEventsCollectorGroups(DiagnosticsOptions::withPersonalData(7))[0];

    expect($grupo['employee_uuid'])->toBe(ERROR_EVENTS_COLLECTOR_EMPLOYEE)
        ->and($grupo['message'])->toContain(ERROR_EVENTS_COLLECTOR_EMPLOYEE);
})->group('RF-PD-09', 'RF-PD-15');
