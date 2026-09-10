<?php

declare(strict_types=1);

use App\Modules\Attendance\Infrastructure\Metrics\TextfileIncidentDetectionMetrics;
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
        at: new DateTimeImmutable('2026-03-15T03:50:00+00:00'),
    );

    $publicado = publicadoEnAttendance('kronoqr_projection.prom');

    expect($publicado)
        ->toContain('# TYPE projection_reconciliation_last_failures gauge')
        ->toContain('projection_reconciliation_last_failures 1')
        ->toContain('projection_reconciliation_last_corrections 2')
        ->toContain('projection_reconciliation_work_days_inspected 214')
        ->toContain('projection_divergence_total 3')
        ->toContain('projection_reconciliation_last_run_timestamp_seconds '.strtotime('2026-03-15T03:50:00+00:00'));
})->group('RF-PR-02', 'RN-06');

it('vuelve a cero los fallos en cuanto una pasada sale limpia', function (): void {
    // Es un `gauge` de la ultima pasada y no un contador: lo que se pregunta es
    // «¿la de anoche dejo algo sin hacer?». Si acumulara, la alerta `> 0` seguiria
    // sonando eternamente por un fallo de hace un mes ya resuelto.
    $metricas = new TextfileProjectionMetrics;

    $metricas->reconciliationCompleted(1, 1, 0, 1, new DateTimeImmutable('2026-03-15T03:50:00+00:00'));
    $metricas->reconciliationCompleted(1, 0, 0, 0, new DateTimeImmutable('2026-03-16T03:50:00+00:00'));

    $publicado = publicadoEnAttendance('kronoqr_projection.prom');

    expect($publicado)
        ->toContain('projection_reconciliation_last_failures 0')
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
