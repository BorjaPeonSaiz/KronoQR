<?php

declare(strict_types=1);

use App\Support\Scheduling\SchedulerHeartbeat;
use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\AlertRules;
use Tests\Architecture\Support\Repo;

/*
 * LOS PROCESOS DE FONDO TIENEN `healthcheck` (bloque 9 de la 2.3.0: O1,
 * V21-4-DV-4).
 *
 * `horizon`, `scheduler` y `reverb` comparten la imagen PHP, que declara «sin
 * HEALTHCHECK a proposito»: cada rol se comprueba distinto y la sonda se
 * declara por servicio en el compose. Hasta la 2.2.1 no se declaraba ninguna, y
 * un proceso vivo que ha dejado de hacer su trabajo (Horizon sin maestro, el
 * bucle del planificador colgado) figuraba `running` para siempre.
 *
 * Lo que se ata aqui, en desarrollo y en produccion:
 *   · que cada servicio tenga su sonda y sea LA correcta (no un `true` que
 *     siempre pasa);
 *   · que el fichero del latido del planificador sea el mismo en el compose, en
 *     la clase que lo escribe y en el entrypoint que lo siembra;
 *   · que ninguna sonda haga depender el arranque del fichaje de otro servicio
 *     (regla dura 19): nada en `depends_on` espera a estos tres;
 *   · y que Loki, que no puede tener sonda propia (imagen sin shell), la tenga
 *     desde Blackbox.
 */

/**
 * @return array<string, array{0: string}>
 */
function composesConProcesosDeFondo(): array
{
    return [
        'desarrollo' => ['infra/compose.dev.yaml'],
        'produccion' => ['infra/compose.prod.yaml'],
    ];
}

/**
 * @return array<string, mixed>
 */
function serviciosDe(string $compose): array
{
    /** @var array{services: array<string, array<string, mixed>>} $parsed */
    $parsed = Yaml::parse(Repo::contents($compose));

    return $parsed['services'];
}

/**
 * @param  array<string, mixed>  $servicio
 */
function ordenDeLaSonda(array $servicio): string
{
    /** @var array{test?: list<string>} $sonda */
    $sonda = $servicio['healthcheck'] ?? [];

    return implode(' ', \array_slice($sonda['test'] ?? [], 1));
}

it('comprueba Horizon con horizon:status, que falla sin maestro registrado o en pausa', function (string $compose): void {
    $horizon = serviciosDe($compose)['horizon'];

    expect(ordenDeLaSonda($horizon))->toContain('artisan horizon:status');
    // Un intervalo que cabe cuatro veces en la espera de update.sh (KQ_WAIT_WORKERS = 90).
    expect($horizon['healthcheck']['interval'])->toBe('20s');
    expect($horizon['healthcheck']['retries'])->toBeGreaterThanOrEqual(3);
})->with(composesConProcesosDeFondo())->group('RNF-D-01', 'RF-PR-04');

it('comprueba Reverb con su ruta /up, detras de REVERB_SERVER_PATH', function (string $compose): void {
    $orden = ordenDeLaSonda(serviciosDe($compose)['reverb']);

    expect($orden)
        ->toContain('127.0.0.1:8080')
        ->toContain('REVERB_SERVER_PATH')
        ->toContain('/up');
})->with(composesConProcesosDeFondo())->group('RNF-D-01', 'RF-PR-04');

it('comprueba el planificador por la edad del fichero de latido que escribe SchedulerHeartbeat', function (string $compose): void {
    $orden = ordenDeLaSonda(serviciosDe($compose)['scheduler']);

    expect($orden)->toContain(SchedulerHeartbeat::PATH)->toContain('-mmin -3');

    // El entrypoint siembra el latido al arrancar: sin el, el servicio no seria sano hasta su
    // primer minuto de bucle y `update.sh` esperaria de mas.
    expect(Repo::contents('infra/docker/php/entrypoint.sh'))->toContain('touch '.SchedulerHeartbeat::PATH);

    // Y la tarea programada que lo renueva cada minuto existe.
    $consola = Repo::contents('backend/routes/console.php');
    expect($consola)
        ->toContain('Schedule::call(new SchedulerHeartbeat)')
        ->toContain("->name('scheduler:heartbeat')")
        ->toContain('->everyMinute()');
})->with(composesConProcesosDeFondo())->group('RNF-D-01', 'RF-PR-04');

it('no hace que ningun servicio espere a que Horizon, el planificador o Reverb esten sanos (regla dura 19)', function (string $compose): void {
    foreach (serviciosDe($compose) as $nombre => $servicio) {
        /** @var array<string, array{condition?: string}|string> $dependencias */
        $dependencias = \is_array($servicio['depends_on'] ?? null) ? $servicio['depends_on'] : [];

        foreach (['horizon', 'scheduler', 'reverb'] as $proceso) {
            expect(\array_key_exists($proceso, $dependencias))->toBeFalse(
                $compose.': el servicio '.$nombre.' depende de '.$proceso.'. El fichaje no puede esperar a un proceso de fondo.'
            );
        }
    }
})->with(composesConProcesosDeFondo())->group('RNF-D-01', 'RF-AT-10');

it('sondea Loki desde Blackbox porque su imagen no trae shell', function (): void {
    $prometheus = Repo::contents('infra/observability/prometheus/prometheus.yml.template');
    $blackbox = Repo::contents('infra/observability/blackbox/blackbox.yml');

    expect($prometheus)
        ->toContain('job_name: kronoqr-loki')
        ->toContain('module: [http_2xx_internal]')
        ->toContain('http://loki:3100/ready');
    expect($blackbox)->toContain('http_2xx_internal:');

    // El modulo en texto plano es una excepcion acotada (revision del bloque 9): un 200 y
    // ninguna redireccion; y el UNICO job que lo usa es el de Loki, contra su `/ready`.
    $modulos = Yaml::parse($blackbox)['modules'];
    expect($modulos['http_2xx_internal']['http']['valid_status_codes'] ?? null)->toBe([200])
        ->and($modulos['http_2xx_internal']['http']['no_follow_redirects'] ?? null)->toBeTrue();

    $trabajos = Yaml::parse((string) preg_replace('/^@@[A-Z_]+@@$/m', '', $prometheus))['scrape_configs'];
    $conModuloInterno = array_values(array_filter(
        $trabajos,
        static fn (array $trabajo): bool => ($trabajo['params']['module'] ?? []) === ['http_2xx_internal'],
    ));
    expect($conModuloInterno)->toHaveCount(1)
        ->and($conModuloInterno[0]['job_name'])->toBe('kronoqr-loki')
        ->and($conModuloInterno[0]['static_configs'][0]['targets'])->toBe(['http://loki:3100/ready']);

    // Y la alerta de esa sonda existe y apunta a su runbook.

    $regla = AlertRules::named('LokiSinRespuesta');
    expect($regla)->not->toBeNull();
    expect($regla['expr'] ?? '')->toContain('kronoqr-loki');
    expect($regla['runbook'] ?? '')->toBe('docs/runbooks/loki-sin-respuesta.md');
})->group('RNF-D-01', 'RF-PR-04');

it('deja sin healthcheck la guarda de retencion, que es de un solo uso, y lo dice', function (): void {
    // V21-4-DV-4 pedia sonda para el servicio nuevo de la 2.2.1. Una sonda sobre un contenedor
    // que termina y se queda `exited` no tiene sentido (`healthy` nunca llega a evaluarse
    // despues de salir). Lo que protege de verdad es `service_completed_successfully` en
    // Loki y Tempo, y `doctor.sh` no lo cuenta como averia. Esta prueba fija la decision.
    foreach (composesConProcesosDeFondo() as [$compose]) {
        $servicios = serviciosDe($compose);

        expect($servicios['retention-guard']['restart'] ?? null)->toBe('no');
        expect(isset($servicios['retention-guard']['healthcheck']))->toBeFalse();

        foreach (['loki', 'tempo'] as $dependiente) {
            expect($servicios[$dependiente]['depends_on']['retention-guard']['condition'] ?? null)
                ->toBe('service_completed_successfully');
        }
    }
})->group('RNF-D-01', 'RL-11');
