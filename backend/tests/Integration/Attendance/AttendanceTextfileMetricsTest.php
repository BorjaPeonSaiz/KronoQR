<?php

declare(strict_types=1);

use App\Modules\Attendance\Infrastructure\Metrics\TextfileIncidentDetectionMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\TextfilePatternDetectionMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\TextfileProjectionMetrics;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/*
 * Los dos adaptadores *textfile* de `Attendance`, serie a serie (doc 02 §8.2,
 * RF-PR-01, RF-PR-02, tarea 3.2 paso 9).
 *
 * QUE SE FIJA AQUI Y QUE NO. La mecanica de escritura —el guard del colector, el
 * temporal, el `rename()` ruidoso— es de `TextfileExposition` y la comprueba
 * `TextfileMetricsConsistencyTest`. Lo que se fija aqui es el CONTENIDO: que las
 * series se llamen como dice el §8.2, que lleven su `# TYPE`, que se publiquen
 * **tambien cuando todo esta a cero** y que la pasada sin centro deje rastro.
 *
 * POR QUE INTEGRACION Y NO UNITARIA. `TextfileExposition` resuelve el directorio
 * con `Config` y escribe en disco: sin contenedor de servicios no hay nada que
 * escribir. La suite `Unit` no arranca el framework a proposito (`tests/Pest.php`).
 */

beforeEach(function (): void {
    Config::set('observability.metrics.enabled', true);
    Config::set(
        'observability.metrics.textfile_path',
        storage_path('framework/testing/textfile-attendance-'.Str::random(10)),
    );
});

afterEach(function (): void {
    $directory = rtrim(Config::string('observability.metrics.textfile_path'), '/');

    foreach (glob($directory.'/*') ?: [] as $leftover) {
        if (is_file($leftover)) {
            unlink($leftover);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
});

function ficheroDeAttendance(string $nombre): string
{
    return rtrim(Config::string('observability.metrics.textfile_path'), '/').'/'.$nombre;
}

function publicadoEnAttendance(string $nombre): string
{
    return (string) file_get_contents(ficheroDeAttendance($nombre));
}

// --- Reconciliacion ---------------------------------------------------------

it('publica los fallos de la ultima reconciliacion junto al resto de la pasada', function (): void {
    // El hueco que cierra la tarea 3.2: la pasada podia encontrar una divergencia
    // y NO conseguir corregirla, y eso solo constaba en el codigo de salida del
    // comando, que con `runInBackground()` no llega a ninguna parte.
    (new TextfileProjectionMetrics)->reconciliationCompleted(
        workDaysInspected: 214,
        divergences: 3,
        corrected: 2,
        failures: 1,
        selfResolved: 4,
        at: new DateTimeImmutable('2026-03-15T03:50:00+00:00'),
    );

    $publicado = publicadoEnAttendance('kronoqr_projection.prom');

    expect($publicado)
        ->toContain('# TYPE projection_reconciliation_last_failures gauge')
        ->toContain('projection_reconciliation_last_failures 1')
        ->toContain('projection_reconciliation_last_corrections 2')
        ->toContain('projection_reconciliation_work_days_inspected 214')
        ->toContain('projection_divergence_total 3')
        ->toContain('projection_reconciliation_last_run_timestamp_seconds '.strtotime('2026-03-15T03:50:00+00:00'))
        // Tarea 3.6: las resueltas solas van en su propia serie y **no** suman al
        // contador de divergencias, que es el que dispara la alerta critica de
        // integridad. Cuatro carreras con el turno de noche y tres divergencias:
        // el fichero tiene que poder decir las dos cosas por separado.
        ->toContain('# TYPE projection_reconciliation_last_self_resolved gauge')
        ->toContain('projection_reconciliation_last_self_resolved 4');
})->group('RF-PR-02', 'RN-06');

it('vuelve a cero los fallos en cuanto una pasada sale limpia', function (): void {
    // Es un `gauge` de la ultima pasada y no un contador: lo que se pregunta es
    // «¿la de anoche dejo algo sin hacer?». Si acumulara, la alerta `> 0` seguiria
    // sonando eternamente por un fallo de hace un mes ya resuelto.
    $metricas = new TextfileProjectionMetrics;

    $metricas->reconciliationCompleted(1, 1, 0, 1, 2, new DateTimeImmutable('2026-03-15T03:50:00+00:00'));
    $metricas->reconciliationCompleted(1, 0, 0, 0, 0, new DateTimeImmutable('2026-03-16T03:50:00+00:00'));

    $publicado = publicadoEnAttendance('kronoqr_projection.prom');

    expect($publicado)
        ->toContain('projection_reconciliation_last_failures 0')
        // Lo mismo vale para las resueltas solas: es la foto de la ultima pasada,
        // no un historico de carreras.
        ->toContain('projection_reconciliation_last_self_resolved 0')
        // Y el contador de divergencias SI acumula: es la unica serie del fichero
        // que solo puede subir.
        ->toContain('projection_divergence_total 1');
})->group('RF-PR-02');

// --- Deteccion de incidencias -----------------------------------------------

it('publica las tres series de la revision diaria con su tipo', function (): void {
    (new TextfileIncidentDetectionMetrics)->scanCompleted(
        workDaysInspected: 42,
        findings: 3,
        failures: 1,
        at: new DateTimeImmutable('2026-03-15T04:30:00+00:00'),
    );

    $publicado = publicadoEnAttendance('kronoqr_incident_detection.prom');

    expect($publicado)
        ->toContain('# TYPE incident_detection_last_run_timestamp_seconds gauge')
        ->toContain('incident_detection_last_run_timestamp_seconds '.strtotime('2026-03-15T04:30:00+00:00'))
        ->toContain('# TYPE incident_detection_last_findings gauge')
        ->toContain('incident_detection_last_findings 3')
        ->toContain('# TYPE incident_detection_last_failures gauge')
        ->toContain('incident_detection_last_failures 1')
        ->toContain('incident_detection_work_days_inspected 42');
})->group('RF-PR-01');

it('publica la pasada sin centro con ceros, y no la calla', function (): void {
    // Antes de la puesta en marcha no hay centro (RF-PD-03) y no hay nada que
    // revisar. Si la serie no apareciera, una instalacion recien instalada y un
    // planificador parado se leerian igual — y es justo lo que
    // `DeteccionDeIncidenciasAusente` tiene que distinguir.
    (new TextfileIncidentDetectionMetrics)->scanCompleted(
        workDaysInspected: 0,
        findings: 0,
        failures: 0,
        at: new DateTimeImmutable('2026-03-15T04:30:00+00:00'),
    );

    $publicado = publicadoEnAttendance('kronoqr_incident_detection.prom');

    expect($publicado)
        ->toContain('incident_detection_last_run_timestamp_seconds '.strtotime('2026-03-15T04:30:00+00:00'))
        ->toContain('incident_detection_last_findings 0')
        ->toContain('incident_detection_last_failures 0');
})->group('RF-PR-01', 'RF-PD-03');

it('no escribe el fichero de la revision diaria con el colector apagado', function (): void {
    Config::set('observability.metrics.enabled', false);

    (new TextfileIncidentDetectionMetrics)->scanCompleted(1, 0, 0, new DateTimeImmutable('2026-03-15T04:30:00+00:00'));

    expect(ficheroDeAttendance('kronoqr_incident_detection.prom'))->not->toBeFile();
})->group('RF-PR-01');

// --- Deteccion de patrones anomalos (RF-PR-06, tarea 3.11) ------------------

it('publica las dos series de la deteccion de patrones en su propio fichero', function (): void {
    // FICHERO PROPIO y no dos lineas mas en el de las incidencias: son dos
    // pasadas distintas, a horas distintas, y una puede dejar de correr sin que
    // la otra se entere. Compartir fichero haria que la segunda en escribir
    // borrase la frescura de la primera.
    (new TextfilePatternDetectionMetrics)->scanCompleted(
        failures: 2,
        at: new DateTimeImmutable('2026-03-15T04:35:00+00:00'),
    );

    $publicado = publicadoEnAttendance('kronoqr_pattern_detection.prom');

    expect($publicado)
        ->toContain('# TYPE pattern_detection_last_run_timestamp_seconds gauge')
        ->toContain('pattern_detection_last_run_timestamp_seconds '.strtotime('2026-03-15T04:35:00+00:00'))
        ->toContain('# TYPE pattern_detection_last_failures gauge')
        ->toContain('pattern_detection_last_failures 2');

    // Ni una etiqueta (regla dura 21): un indicio habla de dos personas
    // concretas, y una serie por persona seria un registro de quien es
    // sospechoso. Con `str_contains` y no con `->not->toContain()`: sobre una
    // expectativa de `string|null` el analisis estatico no resuelve `->not`
    // (PHPStan 9), y una asercion que no compila no protege nada.
    expect(str_contains($publicado, 'pattern_detection_last_failures{'))->toBeFalse();
})->group('RF-PR-06');

it('no escribe el fichero de la deteccion de patrones con el colector apagado', function (): void {
    Config::set('observability.metrics.enabled', false);

    (new TextfilePatternDetectionMetrics)->scanCompleted(0, new DateTimeImmutable('2026-03-15T04:35:00+00:00'));

    expect(ficheroDeAttendance('kronoqr_pattern_detection.prom'))->not->toBeFile();
})->group('RF-PR-06');
