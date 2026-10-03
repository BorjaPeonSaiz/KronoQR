<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\ResanitizeErrorHistory;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Tests\Support\Observability\RecordingLogger;
use Tests\Support\Product\InMemoryErrorHistory;

/*
 * Las filas anteriores a la 2.2.0 se vuelven a sanear y se funden por huella
 * (ADR-048 decision 9, H6; RF-PD-15, RL-19). Unitaria con el repositorio en
 * memoria: aqui viven las reglas de la fusion; el SQL lo prueba
 * `tests/Integration/Product/ResanitizeErrorHistoryTest.php`.
 */

function resanitizeErrorHistoryLogger(): RecordingLogger
{
    return new RecordingLogger;
}

it('vuelve a sanear el mensaje, el contexto y la version, y recalcula la huella', function (): void {
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(1, 'No se pudo fichar a Rosa Ficticiana', ['reason' => 'Luz Inventadez'], appVersion: 'Ficticiana'),
    ]);

    $resultado = new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();
    $grupo = $history->all()[0];

    expect($grupo->message)->toBe('No se pudo fichar a …')
        ->and($grupo->context)->toBe(['reason' => '…'])
        ->and($grupo->appVersion)->toBe('…')
        ->and($grupo->fingerprint)->toBe(
            ErrorFingerprint::forClient(ErrorSource::Admin, 'web.vue_error', 'No se pudo fichar a …')->value,
        )
        ->and($resultado->rows)->toBe(1)
        ->and($resultado->rewritten)->toBe(1)
        ->and($resultado->merged)->toBe(0);
})->group('RF-PD-15', 'RL-19');

it('funde los grupos que pasan a tener la misma huella: suma, primero y ultimo', function (): void {
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(1, 'No se pudo fichar a Rosa Ficticiana', occurrences: 3, firstSeenAt: '2026-09-02T10:00:00Z', lastSeenAt: '2026-09-03T10:00:00Z'),
        InMemoryErrorHistory::group(2, 'No se pudo fichar a Luz Inventadez', occurrences: 4, firstSeenAt: '2026-09-01T10:00:00Z', lastSeenAt: '2026-09-02T12:00:00Z'),
        InMemoryErrorHistory::group(3, 'No se pudo fichar a Will Testerson', occurrences: 5, firstSeenAt: '2026-09-04T10:00:00Z', lastSeenAt: '2026-09-05T10:00:00Z'),
    ]);

    $resultado = new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger(), batchSize: 2)->run();
    $grupos = $history->all();

    expect($grupos)->toHaveCount(1)
        ->and($grupos[0]->id)->toBe(1)
        ->and($grupos[0]->occurrences)->toBe(12)
        ->and($grupos[0]->firstSeenAt->format('Y-m-d'))->toBe('2026-09-01')
        ->and($grupos[0]->lastSeenAt->format('Y-m-d'))->toBe('2026-09-05')
        ->and($resultado->rows)->toBe(3)
        ->and($resultado->merged)->toBe(2);
})->group('RF-PD-15', 'RL-19');

it('deja abierto el grupo fundido si cualquiera de los dos lo estaba', function (bool $primeroResuelto, bool $segundoResuelto, bool $abierto): void {
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(
            1,
            'No se pudo fichar a Rosa Ficticiana',
            resolvedAt: $primeroResuelto ? '2026-09-10T10:00:00Z' : null,
            resolvedByUuid: $primeroResuelto ? '0199a1f0-0000-7000-8000-00000000000a' : null,
        ),
        InMemoryErrorHistory::group(
            2,
            'No se pudo fichar a Luz Inventadez',
            resolvedAt: $segundoResuelto ? '2026-09-11T10:00:00Z' : null,
            resolvedByUuid: $segundoResuelto ? '0199a1f0-0000-7000-8000-00000000000b' : null,
        ),
    ]);

    new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();
    $grupo = $history->all()[0];

    expect($grupo->isOpen())->toBe($abierto)
        ->and($grupo->resolvedByUuid === null)->toBe($abierto);
})->with([
    'abierto y resuelto' => [false, true, true],
    'resuelto y abierto' => [true, false, true],
    'los dos abiertos' => [false, false, true],
    'los dos resueltos' => [true, true, false],
])->group('RF-PD-15', 'RL-19');

it('si los dos estaban resueltos se queda la resolucion mas reciente', function (string $primero, string $segundo, string $autor): void {
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(1, 'No se pudo fichar a Rosa Ficticiana', resolvedAt: $primero, resolvedByUuid: '0199a1f0-0000-7000-8000-00000000000a'),
        InMemoryErrorHistory::group(2, 'No se pudo fichar a Luz Inventadez', resolvedAt: $segundo, resolvedByUuid: '0199a1f0-0000-7000-8000-00000000000b'),
    ]);

    new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();

    expect($history->all()[0]->resolvedByUuid)->toBe($autor);
})->with([
    'la del absorbido es posterior' => ['2026-09-10T10:00:00Z', '2026-09-11T10:00:00Z', '0199a1f0-0000-7000-8000-00000000000b'],
    'la del superviviente es posterior' => ['2026-09-12T10:00:00Z', '2026-09-11T10:00:00Z', '0199a1f0-0000-7000-8000-00000000000a'],
    'empate: se queda la del superviviente' => ['2026-09-11T10:00:00Z', '2026-09-11T10:00:00Z', '0199a1f0-0000-7000-8000-00000000000a'],
])->group('RF-PD-15');

it('es idempotente: la segunda pasada no escribe nada', function (): void {
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(1, 'No se pudo fichar a Rosa Ficticiana'),
        InMemoryErrorHistory::group(2, 'No se pudo fichar a Luz Inventadez'),
        InMemoryErrorHistory::group(3, 'TypeError: Cannot read properties of undefined', ['component' => 'ScanView']),
    ]);

    new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();
    $despues = $history->all();
    $escrituras = $history->rewrites + $history->merges;

    $segunda = new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();

    expect($history->all())->toEqual($despues)
        ->and($history->rewrites + $history->merges)->toBe($escrituras)
        ->and($segunda->rewritten)->toBe(0)
        ->and($segunda->merged)->toBe(0)
        ->and($segunda->rows)->toBe(2);
})->group('RF-PD-15', 'RL-19');

it('no reescribe un grupo que ya esta limpio', function (): void {
    $mensaje = ErrorMessageSanitizer::sanitize('TypeError: Cannot read properties of undefined');
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(
            1,
            $mensaje,
            ['scope' => 'vue', 'cause' => 'network'],
            appVersion: '2.2.0',
            fingerprint: ErrorFingerprint::forClient(ErrorSource::Admin, 'web.vue_error', $mensaje)->value,
        ),
    ]);

    $resultado = new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();

    expect($history->rewrites)->toBe(0)
        ->and($resultado->rows)->toBe(1)
        ->and($resultado->rewritten)->toBe(0);
})->group('RF-PD-15');

it('deja una sola linea de log con dos recuentos y ningun contenido', function (): void {
    $logger = resanitizeErrorHistoryLogger();
    $history = new InMemoryErrorHistory([
        InMemoryErrorHistory::group(1, 'No se pudo fichar a Rosa Ficticiana'),
        InMemoryErrorHistory::group(2, 'No se pudo fichar a Luz Inventadez'),
    ]);

    new ResanitizeErrorHistory($history, $logger)->run();

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0]['message'])->toBe('product.error_history_resanitized')
        ->and($logger->lines[0]['context'])->toBe(['rows' => 2, 'merged' => 1]);
})->group('RF-PD-15', 'RL-19');

it('no hace nada con un historico vacio', function (): void {
    $resultado = new ResanitizeErrorHistory(new InMemoryErrorHistory, resanitizeErrorHistoryLogger())->run();

    expect($resultado->rows)->toBe(0);
})->group('RF-PD-15');

it('funde con el grupo que aparece entre la busqueda y la escritura', function (): void {
    // El sumidero sigue escribiendo mientras corre la migracion: si crea la
    // huella nueva justo antes de la reescritura, el UNIQUE la rechaza y el
    // caso de uso funde en lugar de abortar.
    $sucio = InMemoryErrorHistory::group(1, 'No se pudo fichar a Rosa Ficticiana', occurrences: 2);
    $history = new InMemoryErrorHistory([$sucio]);
    $limpia = ResanitizeErrorHistory::sanitized($sucio)->fingerprint;

    $history->beforeRewrite = static function (InMemoryErrorHistory $tabla) use ($limpia): void {
        $tabla->put(InMemoryErrorHistory::group(99, 'No se pudo fichar a …', occurrences: 5, fingerprint: $limpia));
    };

    $resultado = new ResanitizeErrorHistory($history, resanitizeErrorHistoryLogger())->run();
    $grupos = $history->all();

    expect($grupos)->toHaveCount(1)
        ->and($grupos[0]->id)->toBe(99)
        ->and($grupos[0]->occurrences)->toBe(7)
        ->and($resultado->merged)->toBe(1)
        ->and($resultado->rewritten)->toBe(0);
})->group('RF-PD-15', 'RL-19');
