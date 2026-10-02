<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\MetricsCollector;
use App\Modules\Product\Infrastructure\Metrics\RedisComplianceProfileMetrics;
use App\Modules\Product\Infrastructure\Metrics\RedisLicenseMetrics;
use App\Modules\Product\Infrastructure\Metrics\RedisSettingsMetrics;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Infrastructure\Metrics\RedisGeneratedFileMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;

/*
 * Resto de la 3.1: `installation_setting_changes_total`,
 * `compliance_profile_changes_total` y `license_limit_exceeded_total` no
 * viajaban en el paquete de diagnostico desde la 5.5 (doc 02 §8.2, RF-PD-09,
 * ADR-020).
 *
 * **Por que Integration y no un doble.** Igual que
 * `Integration/Shared/MetricsExpositionTest.php`: lo que fallaba solo aparece
 * contra el cliente de Redis real. `installation_setting_changes_total`,
 * `compliance_profile_changes_total` y `license_limit_exceeded_total` viven
 * como claves sueltas (`INCRBY` sobre `...:etiqueta=valor`, una sola
 * etiqueta), y `MetricsCollector::labelled()` las alcanzaba con
 * `command('SCAN', [$cursor, 'MATCH', $pattern, 'COUNT', $count])`: cinco
 * argumentos posicionales que `phpredis` no acepta —su `scan()` real es
 * `scan(&$iterator, $pattern, $count, $type)`, cuatro como mucho—. La llamada
 * lanzaba `ArgumentCountError`, el `try` que protege una serie individual lo
 * atrapaba en silencio, y la seccion `metrics` seguia saliendo sin las tres
 * series y sin ningun aviso. Un doble que no ejecute el `SCAN` de verdad
 * habria dejado pasar exactamente este fallo, otra vez, con la prueba en
 * verde.
 *
 * **Por incremento, no por valor absoluto.** Estas tres claves las escribe
 * CUALQUIER peticion HTTP que cambie un ajuste, un perfil de cumplimiento o
 * de alta a alguien por encima del plan, en la instalacion de desarrollo
 * entera —no hay base de datos de pruebas dedicada para Redis, la misma nota
 * que ya deja `MetricsExpositionTest`—. Afirmar un valor exacto seria fragil
 * mientras otra suite corre en paralelo; afirmar el INCREMENTO que provoca
 * esta prueba es correcto pase lo que pase alrededor.
 *
 * **Sin base de datos**: estas tres series no pasan por PostgreSQL.
 */

/**
 * Las claves logicas que esta prueba toca (SIN el prefijo global: los
 * comandos van por `Connection::command()`, que lo antepone solo, igual que
 * al escribir).
 *
 * @return list<string>
 */
function metricsCollectorKeysUnderTest(): array
{
    return [
        RedisSettingsMetrics::CHANGES_TOTAL.':affects_worked_hours=true',
        RedisSettingsMetrics::CHANGES_TOTAL.':affects_worked_hours=false',
        RedisComplianceProfileMetrics::CHANGES_TOTAL.':effect=retention',
        RedisLicenseMetrics::LIMIT_EXCEEDED_TOTAL.':limit=max_employees',
    ];
}

/**
 * El valor actual de una clave suelta, o 0 si no existe todavia.
 */
function metricsCollectorCurrentValue(string $logicalKey): int
{
    $raw = app(Redis::class)->connection()->command('GET', [$logicalKey]);

    return is_numeric($raw) ? (int) $raw : 0;
}

/**
 * El valor de una etiqueta dentro de la seccion `metrics` del paquete, con el
 * tipo ya afirmado: {@see DiagnosticsCollector::collect()} devuelve
 * `array<array-key, mixed>` a proposito —cada seccion tiene su propia
 * forma— y este es el unico sitio de la prueba que da por hecho la de esta.
 *
 * @param  array<array-key, mixed>  $section
 */
function metricsCollectorLabel(array $section, string $series, string $label): int
{
    /** @var array<string, int> $values */
    $values = $section[$series];

    return $values[$label];
}

it('el paquete de diagnostico contiene las tres series de claves sueltas cuando existen en Redis', function (): void {
    $before = [];

    foreach (metricsCollectorKeysUnderTest() as $key) {
        $before[$key] = metricsCollectorCurrentValue($key);
    }

    (new RedisSettingsMetrics(app(Redis::class)))->settingsChanged(true, 2);
    (new RedisSettingsMetrics(app(Redis::class)))->settingsChanged(false, 1);
    // Solo `effect=retention`: `$changes` en cero deja `any` sin incrementar.
    (new RedisComplianceProfileMetrics(app(Redis::class)))->profileChanged(0, 0, 0, 1);
    (new RedisLicenseMetrics(app(Redis::class)))->limitExceeded(PlanLimit::Employees);

    $section = app(MetricsCollector::class)->collect(DiagnosticsOptions::anonymized());

    $trueKey = RedisSettingsMetrics::CHANGES_TOTAL.':affects_worked_hours=true';
    $falseKey = RedisSettingsMetrics::CHANGES_TOTAL.':affects_worked_hours=false';
    $retentionKey = RedisComplianceProfileMetrics::CHANGES_TOTAL.':effect=retention';
    $employeesKey = RedisLicenseMetrics::LIMIT_EXCEEDED_TOTAL.':limit=max_employees';

    expect($section)
        ->toHaveKey('installation_setting_changes_total')
        ->toHaveKey('compliance_profile_changes_total')
        ->toHaveKey('license_limit_exceeded_total');

    expect(metricsCollectorLabel($section, 'installation_setting_changes_total', 'affects_worked_hours=true'))
        ->toBe($before[$trueKey] + 2)
        ->and(metricsCollectorLabel($section, 'installation_setting_changes_total', 'affects_worked_hours=false'))
        ->toBe($before[$falseKey] + 1)
        ->and(metricsCollectorLabel($section, 'compliance_profile_changes_total', 'effect=retention'))
        ->toBe($before[$retentionKey] + 1)
        ->and(metricsCollectorLabel($section, 'license_limit_exceeded_total', 'limit=max_employees'))
        ->toBe($before[$employeesKey] + 1);
})->group('RF-PD-09', 'ADR-020');

it('no confunde una serie de claves sueltas con la de otra', function (): void {
    // Regresion del bug real: sin desprefijar antes de pedir el valor, `GET`
    // pide una clave que no existe (el fisico lleva el prefijo global DOS
    // veces) y la serie sale vacia aunque el `SCAN` si encontrara la clave.
    $employeesKey = RedisLicenseMetrics::LIMIT_EXCEEDED_TOTAL.':limit=max_employees';
    $before = metricsCollectorCurrentValue($employeesKey);

    (new RedisLicenseMetrics(app(Redis::class)))->limitExceeded(PlanLimit::Employees);

    $section = app(MetricsCollector::class)->collect(DiagnosticsOptions::anonymized());

    expect(metricsCollectorLabel($section, 'license_limit_exceeded_total', 'limit=max_employees'))
        ->toBe($before + 1);
})->group('RF-PD-09', 'ADR-020');

it('el SCAN encuentra una combinacion de etiquetas que ningun otro proceso escribe', function (): void {
    // Etiqueta que ningun catalogo de `PlanLimit` produce en produccion: aisla
    // esta prueba de lo que otra suite pueda estar escribiendo en paralelo
    // sobre las combinaciones reales, y comprueba el mecanismo de `SCAN` en
    // si mismo (no solo el reparto por encima de una clave que ya exista).
    $ghostKey = RedisLicenseMetrics::LIMIT_EXCEEDED_TOTAL.':limit=__ghost_de_prueba__';

    try {
        app(Redis::class)->connection()->command('INCRBY', [$ghostKey, 7]);

        $section = app(MetricsCollector::class)->collect(DiagnosticsOptions::anonymized());

        expect(metricsCollectorLabel($section, 'license_limit_exceeded_total', 'limit=__ghost_de_prueba__'))
            ->toBe(7);
    } finally {
        app(Redis::class)->connection()->command('DEL', [$ghostKey]);
    }
})->group('RF-PD-09', 'ADR-020');

/**
 * El valor de un campo de un hash de metricas, o 0 si no existe todavia.
 */
function metricsCollectorHashValue(string $logicalKey, string $field): int
{
    $raw = app(Redis::class)->connection()->command('HGET', [$logicalKey, $field]);

    return is_numeric($raw) ? (int) $raw : 0;
}

it('el paquete lleva las cuatro series de ficheros generados, solo con la etiqueta class', function (): void {
    // ADR-045 (C5, C10). Soporte necesita estas cifras para contestar «la
    // exportacion no se descarga» o «suena la alerta de fichero desaparecido»
    // sin pedir una segunda ronda; y no pueden llevar mas que la clase: nunca
    // un uuid, un nombre de fichero ni una ruta (regla dura 21).
    $metrics = new RedisGeneratedFileMetrics(app(Redis::class));
    $field = 'class='.GeneratedFileClass::Diagnostics->value;
    $overdueField = 'class='.GeneratedFileClass::LegalExportConsole->value;

    $before = [
        'orphans' => metricsCollectorHashValue(RedisGeneratedFileMetrics::ORPHANS_REMOVED_TOTAL, $field),
        'refused' => metricsCollectorHashValue(RedisGeneratedFileMetrics::REFUSED_TOTAL, $field),
        'missing' => metricsCollectorHashValue(RedisGeneratedFileMetrics::MISSING_TOTAL, $field),
    ];
    $previousOverdue = app(Redis::class)->connection()->command('HGET', [RedisGeneratedFileMetrics::OVERDUE, $overdueField]);

    try {
        $metrics->orphanRemoved(GeneratedFileClass::Diagnostics);
        $metrics->refused(GeneratedFileClass::Diagnostics);
        $metrics->missing(GeneratedFileClass::Diagnostics);
        $metrics->overdue(GeneratedFileClass::LegalExportConsole, 3);

        $section = app(MetricsCollector::class)->collect(DiagnosticsOptions::anonymized());

        expect(metricsCollectorLabel($section, 'generated_files_orphans_removed_total', $field))->toBe($before['orphans'] + 1)
            ->and(metricsCollectorLabel($section, 'generated_files_refused_total', $field))->toBe($before['refused'] + 1)
            ->and(metricsCollectorLabel($section, 'generated_files_missing_total', $field))->toBe($before['missing'] + 1)
            ->and(metricsCollectorLabel($section, 'generated_files_overdue', $overdueField))->toBe(3);

        $classes = array_map(static fn (GeneratedFileClass $class): string => 'class='.$class->value, GeneratedFileClass::cases());

        foreach (['generated_files_orphans_removed_total', 'generated_files_refused_total', 'generated_files_missing_total', 'generated_files_overdue'] as $series) {
            /** @var array<string, int> $values */
            $values = $section[$series];

            expect(array_diff(array_keys($values), $classes))
                ->toBe([], $series.' lleva una etiqueta que no es una clase del catalogo cerrado.');
        }
    } finally {
        if (is_numeric($previousOverdue)) {
            app(Redis::class)->connection()->command('HSET', [RedisGeneratedFileMetrics::OVERDUE, $overdueField, (int) $previousOverdue]);
        } else {
            app(Redis::class)->connection()->command('HDEL', [RedisGeneratedFileMetrics::OVERDUE, $overdueField]);
        }
    }
})->group('RF-PD-09', 'ADR-020', 'RL-15');
