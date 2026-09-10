<?php

declare(strict_types=1);

use App\Http\Middleware\RecordHttpMetrics;
use App\Modules\Attendance\Infrastructure\Metrics\RedisScanMetrics;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\RedisAuthenticationMetrics;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * El catalogo de `/metrics` contra el listado del doc 02 §8.2 (tarea 3.1,
 * decision 1 de la ficha).
 *
 * ## Que se afirma y por que hace falta afirmarlo
 *
 * Una serie que se emite pero nadie expone no existe para Prometheus, y una
 * serie catalogada sin emisor produce una alerta que nunca se evalua. Ninguno de
 * los dos fallos rompe una prueba de nada: el sistema sigue funcionando, el
 * panel se queda en blanco y la alerta se queda muda. Justo lo que paso hasta
 * esta tarea con doce series que llevaban meses escribiendose en Redis sin que
 * nadie las publicara.
 *
 * Por eso la comparacion es **bidireccional y contra el documento**, no contra
 * una lista escrita a mano en esta prueba: el §8.2 es la columna literal que la
 * ficha manda respetar, y una lista copiada aqui se desincronizaria con el
 * documento igual que el codigo se desincronizo con el.
 *
 * ## Las dos exclusiones, con su motivo
 *
 * - **`kronoqr_backup_*`**: las escriben `infra/scripts/backup.sh` y
 *   `restore-drill.sh`, no la aplicacion. Tienen que seguir publicandose
 *   **cuando la aplicacion no arranca**, que es justo el dia que interesa saber
 *   si hay copia.
 * - **Las del colector *textfile***: las produce un comando programado que corre
 *   y termina, y se publican por fichero `.prom`. No se duplican en `/metrics`
 *   porque una misma serie por dos objetivos de *scrape* daria dos series con
 *   distinto `job`. La verificacion «todas las del §8.2 estan» se hace sobre los
 *   dos objetivos juntos, `kronoqr-api` y `node-exporter`.
 */

/**
 * Las series del §8.2 que NO expone la aplicacion por `/metrics`, con quien las
 * publica en su lugar.
 *
 * Lista explicita y no derivada de los adaptadores `Textfile*Metrics`: lo que
 * importa aqui es la **decision** de por que ruta sale cada serie, y esa
 * decision se toma una vez y se escribe. Si alguien mueve una serie de un
 * soporte al otro, tiene que tocar esta lista — que es exactamente el momento en
 * el que conviene pensarlo.
 *
 * @return list<string>
 */
function textfileSeries(): array
{
    return [
        // Reporting\Infrastructure\Metrics\TextfilePresenceMetrics
        'open_shifts_current',
        'websocket_connections_active',
        // Compliance\Infrastructure\Metrics\TextfileIncidentMetrics
        'incidents_open',
        'incidents_metrics_timestamp_seconds',
        // Attendance\Infrastructure\Metrics\TextfileProjectionMetrics
        'projection_divergence_total',
        'projection_reconciliation_last_run_timestamp_seconds',
        // Compliance\Infrastructure\Metrics\TextfileAuditMetrics
        'audit_chain_verification_failures_total',
        'audit_chain_last_verification_timestamp_seconds',
        'audit_chain_last_verification_result',
        'audit_chain_rows_verified',
        'audit_chain_unknown_actions',
        'audit_log_partition_ready',
        'audit_log_partition_check_timestamp_seconds',
        // Identity\Infrastructure\Metrics\TextfileCredentialMetrics
        'employees_without_delivered_credential',
        'credentials_pending_print',
        'credentials_pending_reprint',
        'credentials_active_unknown_key',
        // Reporting\Infrastructure\Metrics\TextfileAdoptionMetrics (tarea 3.1)
        'workdays_complete_ratio',
    ];
}

/**
 * El bloque de codigo del §8.2, ya troceado en `nombre => ['type' => …, 'labels' => …]`.
 *
 * @return array<string, array{type: string, labels: list<string>}>
 */
function documentedSeries(): array
{
    $document = str_replace("\r\n", "\n", Repo::contents('docs/02-stack-tecnologico-y-plan-implementacion.md'));

    $section = strstr($document, '### 8.2 Métricas expuestas');

    expect($section)->toBeString('El §8.2 del doc 02 ha cambiado de titulo: esta prueba lo localiza por el encabezado.');

    preg_match('/```\n(.*?)\n```/s', (string) $section, $block);

    $listing = $block[1] ?? null;

    expect($listing)->toBeString('El §8.2 ya no lleva el bloque de codigo con el listado literal de series.');

    preg_match_all(
        '/^([a-z][a-z0-9_]*)(?:\{([a-z0-9_,]*)\})?[ \t]+(counter|gauge|histogram)$/m',
        (string) $listing,
        $matches,
        PREG_SET_ORDER,
    );

    $series = [];

    foreach ($matches as $match) {
        $series[$match[1]] = [
            'type' => $match[3],
            'labels' => $match[2] === '' ? [] : explode(',', $match[2]),
        ];
    }

    return $series;
}

/**
 * Las del §8.2 que le tocan a `/metrics`: todas menos las de respaldo y las de
 * fichero.
 *
 * @return array<string, array{type: string, labels: list<string>}>
 */
function seriesForTheEndpoint(): array
{
    return array_filter(
        documentedSeries(),
        static fn (string $name): bool => ! str_starts_with($name, 'kronoqr_backup_')
            && ! \in_array($name, textfileSeries(), true),
        ARRAY_FILTER_USE_KEY,
    );
}

it('el §8.2 sigue teniendo un listado de series que se puede leer', function (): void {
    // Red de seguridad de la propia prueba: si el bloque cambia de forma y deja
    // de parsearse, las tres comparaciones de abajo pasarian comparando dos
    // conjuntos vacios.
    //
    // Cota inferior y no un numero exacto: lo que esta prueba vigila es que el
    // bloque SE PARSEA, no cuantas series tiene el documento. Con un numero
    // exacto, añadir una serie al §8.2 pondria en rojo esta prueba ademas de las
    // dos que de verdad describen el hueco, y la primera en fallar seria la que
    // menos dice.
    expect(\count(documentedSeries()))->toBeGreaterThan(40)
        ->and(\count(seriesForTheEndpoint()))->toBeGreaterThan(20);
})->group('RQ-06');

it('cataloga exactamente las series del §8.2 que no salen por fichero ni del respaldo', function (): void {
    $documented = array_keys(seriesForTheEndpoint());
    $catalogued = MetricCatalogue::names();

    sort($documented);
    sort($catalogued);

    expect($catalogued)->toBe($documented);
})->group('RQ-06', 'RF-IN-08');

it('declara el mismo tipo y las mismas etiquetas que el documento', function (): void {
    $documented = seriesForTheEndpoint();

    $catalogued = [];

    foreach (MetricCatalogue::all() as $definition) {
        $catalogued[$definition->name] = [
            'type' => $definition->type->value,
            'labels' => $definition->labels,
        ];
    }

    ksort($catalogued);
    ksort($documented);

    // El ORDEN de las etiquetas tambien: es el orden en el que el adaptador las
    // concatena en Redis (`site=1,department=Cocina`) y el que el lector usa
    // para descomponerlas. Invertirlo aqui dejaria la serie ilegible sin que
    // fallara nada mas.
    expect($catalogued)->toBe($documented);
})->group('RQ-06');

it('todas las series catalogadas llevan un HELP que explica para que sirven', function (): void {
    $withoutHelp = array_values(array_filter(
        MetricCatalogue::all(),
        // Cuarenta caracteres: el minimo para que sea una frase y no el nombre
        // de la serie repetido. Un `# HELP` que no aporta nada es peor que
        // ninguno, porque ocupa el sitio donde alguien buscaria la explicacion.
        static fn (MetricDefinition $definition): bool => mb_strlen($definition->help) < 40,
    ));

    expect($withoutHelp)->toBe([]);
})->group('RQ-06');

it('todos los adaptadores de metricas escriben bajo el mismo prefijo', function (): void {
    // La invariante de la que depende el lector entero: `/metrics` recoge las
    // series por prefijo comun. Un adaptador que se separe deja de publicarse
    // sin romper ninguna prueba suya.
    $offenders = [];

    foreach (metricAdapters() as $class) {
        $constants = (new ReflectionClass($class))->getConstants();

        foreach ($constants as $name => $value) {
            if (! \is_string($value) || ! str_contains($value, 'metrics:')) {
                continue;
            }

            if (! str_starts_with($value, MetricCatalogue::KEY_PREFIX)) {
                $offenders[] = $class.'::'.$name.' = '.$value;
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RQ-06');

it('el prefijo del catalogo es el que declaran los adaptadores', function (): void {
    expect(MetricCatalogue::KEY_PREFIX)
        ->toBe('kronoqr:metrics:')
        ->and(RedisScanMetrics::KEY_PREFIX)
        ->toBe(MetricCatalogue::KEY_PREFIX)
        ->and(RedisAuthenticationMetrics::KEY_PREFIX)
        ->toBe(MetricCatalogue::KEY_PREFIX)
        ->and(RecordHttpMetrics::KEY_PREFIX)
        ->toBe(MetricCatalogue::KEY_PREFIX);
})->group('RQ-06');

/**
 * Las clases que escriben una serie en Redis: los adaptadores `Redis*Metrics` de
 * los modulos mas el middleware del borde HTTP, que no es de ningun modulo.
 *
 * @return list<class-string>
 */
function metricAdapters(): array
{
    $classes = [RecordHttpMetrics::class];

    foreach (ModuleTree::filesIn('') as $file) {
        $relative = ModuleTree::relative($file);

        if (preg_match('#^[A-Za-z]+/Infrastructure/Metrics/Redis[A-Za-z]+\.php$#', $relative) !== 1) {
            continue;
        }

        /** @var class-string $class */
        $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}
