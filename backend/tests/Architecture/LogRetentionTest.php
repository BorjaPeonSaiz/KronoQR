<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * **LA RETENCION DEL LOG TECNICO ES UNA CIFRA Y ESTA EN DOS SITIOS** (RL-11,
 * doc 02 §8.2.1, decision 11 de la ficha 3.1).
 *
 * ## Por que existe esta prueba
 *
 * El §8.2.1 fija 90 dias para el log tecnico, y esos 90 dias se escriben dos
 * veces: en `TECHNICAL_LOG_RETENTION_DAYS` —que rige la purga de `error_events`
 * y documenta la del log— y en `loki.yaml`, que **no acepta dias ni
 * expresiones** y solo entiende horas. Dos sitios, dos formatos y ninguna
 * herramienta que los relacione es la receta exacta para que alguien suba la
 * variable a 180 dias, se quede tan tranquilo y Loki siga tirando los datos a
 * los 90 — sin que nada falle y sin que nadie se entere hasta que haga falta el
 * historico.
 *
 * Lo que se afirma es la igualdad: **las horas de Loki son los dias de la
 * configuracion por 24**. Si alguien cambia uno, esta prueba le recuerda el
 * otro, y `configuracion.md` dice lo mismo con palabras.
 *
 * ## Y las trazas de Tempo, que son la TERCERA copia de la misma cifra
 *
 * Desde la tarea 3.1 el registro tecnico no son dos almacenes sino tres: el log
 * en Loki, el historico de errores en `error_events` y las **trazas en Tempo**.
 * Los tres valen 90 dias porque lo dice el §8.2.1, y el tercero lo escribe otro
 * fichero YAML estatico (`compactor.compaction.block_retention`) que tampoco
 * acepta dias. Sin esta afirmacion, subir `TECHNICAL_LOG_RETENTION_DAYS` a 180
 * dejaba el log de una incidencia sin la traza que lo acompaña, que es
 * exactamente la mitad que hace falta cuando alguien pregunta por su fichaje de
 * hace cuatro meses.
 *
 * ## Y `max_query_lookback` tambien, que es la mitad que se olvida
 *
 * `retention_period` decide cuando se BORRAN los datos; `max_query_lookback`
 * decide hasta donde puede PREGUNTAR una consulta. Con el segundo mas corto que
 * el primero, los datos siguen ahi y Grafana no los enseña: una retencion de 90
 * dias que en la practica son 30, sin ningun sintoma salvo un panel vacio.
 *
 * ## Se lee el fichero de configuracion como TEXTO
 *
 * Como el resto de la suite de arquitectura, que corre sobre PHPUnit puro y sin
 * arrancar Laravel: `config/compliance.php` llama a `env()`, que fuera del
 * framework no existe. Lo que interesa ademas es el **valor de serie** —el que
 * tendra la instalacion que no toque nada—, y ese esta en el propio `env()`.
 */

/** El plazo de serie del log tecnico, leido de `config/compliance.php`. */
function diasDeRetencionTecnica(): int
{
    $config = Repo::contents('backend/config/compliance.php');

    expect($config)->toContain('technical_log_days');

    $encontrado = preg_match(
        "/'technical_log_days'\s*=>\s*\(int\)\s*env\(\s*'TECHNICAL_LOG_RETENTION_DAYS'\s*,\s*(\d+)\s*\)/",
        $config,
        $matches,
    );

    expect($encontrado)->toBe(
        1,
        "No se encuentra el valor de serie de 'technical_log_days' en backend/config/compliance.php. "
        .'Si la clave cambio de forma, hay que actualizar esta prueba: sin ella, la retencion de Loki y la '
        .'de la aplicacion pueden separarse sin que nada falle (RL-11).'
    );

    return (int) ($matches[1] ?? 0);
}

/**
 * Los limites de Loki, ya interpretados.
 *
 * @return array<string, mixed>
 */
function limitesDeLoki(): array
{
    /** @var array{limits_config?: array<string, mixed>} $documento */
    $documento = Yaml::parse(Repo::contents('infra/observability/loki/loki.yaml'));

    $limites = $documento['limits_config'] ?? null;

    expect($limites)->toBeArray('infra/observability/loki/loki.yaml no declara `limits_config`.');
    assert(is_array($limites));

    return $limites;
}

it('la retencion de Loki es la del log tecnico expresada en horas', function (string $clave): void {
    $dias = diasDeRetencionTecnica();
    $esperado = ($dias * 24).'h';

    expect(limitesDeLoki()[$clave] ?? null)->toBe(
        $esperado,
        'infra/observability/loki/loki.yaml declara `'.$clave.'` distinto de '.$esperado.', que es lo que '
        .'valen los '.$dias.' dias de TECHNICAL_LOG_RETENTION_DAYS (RL-11, doc 02 §8.2.1). Las dos cifras '
        .'son la misma politica escrita en dos formatos: si una cambia, la otra tambien.'
    );
})->with(['retention_period', 'max_query_lookback'])->group('RL-11');

it('la retencion de las trazas de Tempo es la misma que la del log tecnico', function (): void {
    $dias = diasDeRetencionTecnica();
    $esperado = ($dias * 24).'h';

    /** @var array{compactor?: array{compaction?: array<string, mixed>}} $documento */
    $documento = Yaml::parse(Repo::contents('infra/observability/tempo/tempo.yaml'));

    expect($documento['compactor']['compaction']['block_retention'] ?? null)->toBe(
        $esperado,
        'infra/observability/tempo/tempo.yaml declara `compactor.compaction.block_retention` distinto de '
        .$esperado.', que es lo que valen los '.$dias.' dias de TECHNICAL_LOG_RETENTION_DAYS (RL-11, doc 02 '
        .'§8.2.1). Un log que se conserva mas que su traza deja media incidencia sin poder reconstruir.'
    );
})->group('RL-11');

it('la purga de Loki esta encendida, que es lo que hace real la retencion', function (): void {
    // `retention_period` sin compactador con `retention_enabled` es una
    // declaracion de intenciones: Loki guarda los datos para siempre y el numero
    // no hace nada. Es el fallo silencioso de esta configuracion.
    /** @var array{compactor?: array<string, mixed>} $documento */
    $documento = Yaml::parse(Repo::contents('infra/observability/loki/loki.yaml'));

    expect($documento['compactor']['retention_enabled'] ?? null)->toBeTrue(
        'infra/observability/loki/loki.yaml fija una retencion que nadie aplica: falta '
        .'`compactor.retention_enabled: true` (RL-11).'
    );
})->group('RL-11');

it('el mismo plazo rige el historico de errores, y por eso son dos variables', function (): void {
    /*
     * `error_events` y el log tecnico valen los dos 90 dias de serie porque lo
     * dice el §8.2.1, **no porque sean el mismo plazo**: son dos almacenes
     * distintos y un cliente puede querer conservar uno mas que el otro.
     *
     * Lo que esta prueba impide es que alguien los funda en una sola variable
     * «porque valen lo mismo». Que las dos existan es la decision.
     */
    $config = Repo::contents('backend/config/compliance.php');

    expect($config)->toContain("env('TECHNICAL_LOG_RETENTION_DAYS'")
        ->and($config)->toContain("env('ERROR_HISTORY_RETENTION_DAYS'");
})->group('RL-11', 'RF-PD-15');
