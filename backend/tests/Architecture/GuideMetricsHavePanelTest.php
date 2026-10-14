<?php

declare(strict_types=1);

use Tests\Architecture\Support\Doc82Series;
use Tests\Architecture\Support\Repo;

/*
 * TODA METRICA QUE UNA GUIA NOMBRA TIENE UN PANEL (V4-DV-3, RF-PD-13, RNF-D-01).
 *
 * Los runbooks y las guias del cliente mandan a quien recibe una alerta a
 * «mirar la serie X». Si X no sale en ningun cuadro de Grafana, esa instruccion
 * es un acertijo a las 06:30: hay que abrir Explore, escribir la consulta de
 * memoria y adivinar las etiquetas. El caso que motivo esta prueba:
 * `alertmanager_notifications_failed_total` la citaba el runbook de entrega de
 * alertas, la vigilaba una alerta y no salia en ningun cuadro, justo cuando el
 * correo —el canal por el que te enterarias— es lo que esta roto.
 *
 * ## Que es «metrica citada en una guia»
 *
 * Un nombre entre comillas invertidas, en `docs/runbooks/` o `docs/cliente/`,
 * que sea una serie conocida: las del §8.2 del doc 02 mas las externas que
 * declara `Doc82Series::EXTERNAL`. Se lee del documento y de los ficheros, no de
 * una lista copiada aqui.
 *
 * ## Deuda declarada, con trinquete
 *
 * Hoy hay series citadas que todavia no tienen panel (copia y WAL, conciliacion
 * del registro, ficheros generados, almacen de metricas: V4-DV-2). Se listan
 * abajo, una a una. La prueba falla en los dos sentidos: por una serie nueva
 * citada sin panel y sin estar en la lista, y por una serie de la lista que ya
 * tiene panel o ya no se cita (hay que quitarla). La deuda solo puede bajar.
 */

/**
 * Las series que las guias citan y que aun no tienen panel.
 *
 * @var list<string>
 */
$guiasSinPanel = [
    'audit_chain_last_verification_timestamp_seconds',
    'audit_chain_unknown_actions',
    'generated_files_missing_total',
    'generated_files_orphans_removed_total',
    'generated_files_overdue',
    'generated_files_refused_total',
    'generated_files_remove_failed_total',
    'kiosk_discarded_scans_total',
    'kiosk_token_rotations_total',
    'kronoqr_auth_attempts_total',
    'kronoqr_management_account_changes_total',
    'kronoqr_metrics_store_up',
    'kronoqr_wal_archive_failing',
    'kronoqr_wal_last_archived_age_seconds',
    'kronoqr_wal_replication_slots_inactive',
    'kronoqr_wal_unarchived_segments',
    'projection_reconciliation_last_run_timestamp_seconds',
    'scan_batch_items_not_processed_total',
    'scan_processing_duration_seconds',
    'work_record_reconciliation_discrepancies',
    'work_record_reconciliation_last_run_timestamp_seconds',
    'work_record_reconciliation_reviewed_purges',
];

/**
 * Las series que citan las guias, con las guias que las citan.
 *
 * @return array<string, list<string>>
 */
$seriesCitadasEnGuias = static function (): array {
    // `up` no cuenta: las guias lo citan al transcribir el umbral de una alerta
    // (`up{job="alertmanager"} == 0`), no como serie que haya que mirar.
    $conocidas = array_flip(array_diff(array_merge(Doc82Series::names(), Doc82Series::EXTERNAL), ['up']));

    $ficheros = array_merge(
        glob(Repo::file('docs/runbooks').'/*.md') ?: [],
        glob(Repo::file('docs/cliente').'/*.md') ?: [],
        glob(Repo::file('docs/cliente/en').'/*.md') ?: [],
    );
    sort($ficheros);

    $citadas = [];

    foreach ($ficheros as $fichero) {
        // Cada tramo entre comillas invertidas se descompone en palabras: asi se ven
        // tambien `nombre{etiqueta}`, `increase(nombre[5m])` y las guias en ingles.
        preg_match_all('/`([^`\n]+)`/', (string) file_get_contents($fichero), $tramos);
        $guia = basename(\dirname($fichero)).'/'.basename($fichero);

        foreach ($tramos[1] as $tramo) {
            preg_match_all('/(?<![a-z0-9_])[a-z][a-z0-9_]*/', $tramo, $palabras);

            foreach ($palabras[0] as $nombre) {
                if (isset($conocidas[$nombre]) && ! in_array($guia, $citadas[$nombre] ?? [], true)) {
                    $citadas[$nombre][] = $guia;
                }
            }
        }
    }

    ksort($citadas);

    return $citadas;
};

/**
 * Si alguna consulta usa la serie o, siendo un histograma, sus derivadas
 * `_bucket`, `_sum` o `_count`.
 */
$tienePanel = static function (string $serie, string $consultas): bool {
    $alternativas = preg_quote($serie, '/').'(?:_bucket|_sum|_count)?';

    return preg_match('/(?<![a-z0-9_])'.$alternativas.'(?![a-z0-9_])/', $consultas) === 1;
};

/**
 * Todas las consultas PromQL de todos los cuadros, en un solo texto.
 */
$consultasDeLosCuadros = static function (): string {
    $consultas = [];

    $recoger = static function (mixed $nodo) use (&$recoger, &$consultas): void {
        if (! is_array($nodo)) {
            return;
        }

        foreach ($nodo as $clave => $valor) {
            if ($clave === 'expr' && is_string($valor)) {
                $consultas[] = $valor;
            }
            $recoger($valor);
        }
    };

    $cuadros = glob(Repo::file('infra/observability/grafana/dashboards/KronoQR').'/*.json') ?: [];
    expect($cuadros)->not->toBeEmpty('No hay cuadros de Grafana que mirar.');

    foreach ($cuadros as $cuadro) {
        $recoger(json_decode((string) file_get_contents($cuadro), true));
    }

    return implode("\n", $consultas);
};

it('lee las series que citan las guias (red de seguridad de la prueba)', function () use ($seriesCitadasEnGuias): void {
    // Si el formato de las guias o el §8.2 cambian y el lector deja de ver series,
    // las demas comprobaciones pasarian comparando conjuntos vacios.
    expect(count($seriesCitadasEnGuias()))->toBeGreaterThan(30);
})->group('RF-PD-13', 'RNF-D-01');

it('tiene un panel para cada serie que una guia nombra, salvo la deuda declarada', function () use ($seriesCitadasEnGuias, $consultasDeLosCuadros, $guiasSinPanel, $tienePanel): void {
    $consultas = $consultasDeLosCuadros();

    $sinPanel = [];

    foreach ($seriesCitadasEnGuias() as $serie => $guias) {
        if (! $tienePanel($serie, $consultas)) {
            $sinPanel[$serie] = implode(', ', $guias);
        }
    }

    $nuevas = array_diff_key($sinPanel, array_flip($guiasSinPanel));

    expect($nuevas)->toBe(
        [],
        'Estas series las cita una guia y ningun cuadro las grafica: anade un panel en infra/observability/grafana/dashboards/KronoQR/ '
        .'(y la serie a Doc82Series::EXTERNAL si no la emite la aplicacion).',
    );
})->group('RF-PD-13', 'RNF-D-01');

it('no deja en la deuda declarada series que ya tienen panel o que ninguna guia cita', function () use ($seriesCitadasEnGuias, $consultasDeLosCuadros, $guiasSinPanel, $tienePanel): void {
    $consultas = $consultasDeLosCuadros();
    $citadas = $seriesCitadasEnGuias();

    $obsoletas = [];

    foreach ($guiasSinPanel as $serie) {
        if (! isset($citadas[$serie])) {
            $obsoletas[] = $serie.' (ya no la cita ninguna guia)';
        } elseif ($tienePanel($serie, $consultas)) {
            $obsoletas[] = $serie.' (ya tiene panel)';
        }
    }

    expect($obsoletas)->toBe([], 'Quitalas de la lista $guiasSinPanel: la deuda solo puede bajar.');
})->group('RF-PD-13', 'RNF-D-01');

it('grafica la entrega fallida de alertas, que vigila EntregaDeAlertasFallando', function () use ($consultasDeLosCuadros): void {
    // El caso que motivo la prueba, fijado aparte: el panel sale en el cuadro de
    // salud de la API y no depende del canal que puede estar roto.
    expect($consultasDeLosCuadros())->toContain('alertmanager_notifications_failed_total');
    expect(Repo::contents('docs/runbooks/entrega-de-alertas.md'))->toContain('alertmanager_notifications_failed_total');
})->group('RF-PD-13', 'RNF-D-01');
