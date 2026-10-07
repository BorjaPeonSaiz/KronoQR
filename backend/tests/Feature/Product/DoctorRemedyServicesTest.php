<?php

declare(strict_types=1);

use Tests\Architecture\Support\ComposeEnvironment;

/*
 * Los remedios de `product:doctor` nombran servicios que existen (RF-PD-13;
 * R4-DV-02 de la reverificacion 2.2.0).
 *
 * El doctor es lo que lee el informatico del hotel cuando algo va mal, y su
 * remedio se copia y se pega tal cual: el fabricante no puede entrar a
 * corregirlo (ADR-016). Durante la 2.2.0 el remedio de la cola parada decia
 * `docker compose restart worker`, pero el servicio se llama `horizon`: el
 * comando respondia «no such service» justo en el momento de la incidencia.
 *
 * La prueba recorre TODOS los textos de los dos idiomas, saca cada
 * `docker compose <verbo> … <servicio>` y comprueba que el servicio esta
 * declarado en el compose de produccion, el que recibe el cliente. Un servicio
 * renombrado en el compose sin tocar los textos —o al reves— la pone en rojo.
 */

const DOCTOR_REMEDY_SERVICES_COMPOSE = 'infra/compose.prod.yaml';

/** Verbos que aceptan una LISTA de servicios tras las opciones. */
const DOCTOR_REMEDY_SERVICES_LIST_VERBS = [
    'ps', 'restart', 'logs', 'up', 'start', 'stop', 'pull', 'create', 'rm', 'kill', 'pause', 'unpause', 'top',
];

/** Verbos cuyo primer argumento es UN servicio y el resto es la orden. */
const DOCTOR_REMEDY_SERVICES_SINGLE_VERBS = ['exec', 'run'];

/** Verbos sin servicio que tambien pueden aparecer. */
const DOCTOR_REMEDY_SERVICES_NO_SERVICE_VERBS = ['down', 'config', 'images', 'version', 'ls'];

/** Opciones que consumen el token siguiente cuando no llevan `=`. */
const DOCTOR_REMEDY_SERVICES_VALUED_OPTIONS = [
    '-f', '--file', '-p', '--project-name', '--env-file', '--profile',
    '-u', '--user', '-w', '--workdir', '-e', '--env', '--entrypoint', '--name',
    '-n', '--tail', '--since', '--until', '-t', '--timeout',
];

/**
 * Todas las cadenas de un fichero de idioma, aplanadas.
 *
 * @return list<string>
 */
function doctorRemedyServicesStrings(string $locale): array
{
    $texts = (static fn (string $file): mixed => require $file)(base_path("lang/{$locale}/doctor.php"));
    $strings = [];

    if (! is_array($texts)) {
        throw new RuntimeException("lang/{$locale}/doctor.php no devuelve un array.");
    }

    array_walk_recursive($texts, static function (mixed $value) use (&$strings): void {
        if (is_string($value)) {
            $strings[] = $value;
        }
    });

    return $strings;
}

/** Un token sin la puntuacion de la frase que lo rodea. */
function doctorRemedyServicesBare(string $token): string
{
    return rtrim($token, '`.,;:)\'"');
}

/**
 * Avanza el indice sobre las opciones (`-d`, `--tail=100`, `-u root`).
 *
 * @param  list<string>  $tokens
 */
function doctorRemedyServicesSkipOptions(array $tokens, int $index): int
{
    while (isset($tokens[$index]) && str_starts_with($tokens[$index], '-')) {
        $option = $tokens[$index];
        $index++;

        if (! str_contains($option, '=') && in_array($option, DOCTOR_REMEDY_SERVICES_VALUED_OPTIONS, true)) {
            $index++;
        }
    }

    return $index;
}

/**
 * Los servicios a partir de `$index`: uno solo para `exec`/`run`; para el resto,
 * hasta el primer token que no es un nombre o que cierra la frase.
 *
 * @param  list<string>  $tokens
 * @return list<string>
 */
function doctorRemedyServicesNames(array $tokens, int $index, bool $isList): array
{
    $services = [];

    while (isset($tokens[$index])) {
        $name = doctorRemedyServicesBare($tokens[$index]);

        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            break;
        }

        $services[] = $name;

        if (! $isList || $name !== $tokens[$index]) {
            break;
        }

        $index++;
    }

    return $services;
}

/**
 * Los servicios que nombra una linea que empieza por `docker compose`. Un verbo
 * que la prueba no conoce falla: mejor ensenarle uno nuevo que dejar pasar un
 * servicio sin mirar.
 *
 * @return list<string>
 */
function doctorRemedyServicesOfLine(string $line): array
{
    $split = preg_split('/\s+/', trim(substr($line, strlen('docker compose'))));
    $tokens = array_values(array_filter($split ?: [], static fn (string $token): bool => $token !== ''));

    $index = doctorRemedyServicesSkipOptions($tokens, 0);
    $verb = doctorRemedyServicesBare($tokens[$index] ?? '');

    if ($verb === '' || in_array($verb, DOCTOR_REMEDY_SERVICES_NO_SERVICE_VERBS, true)) {
        return [];
    }

    $isList = in_array($verb, DOCTOR_REMEDY_SERVICES_LIST_VERBS, true);

    if (! $isList && ! in_array($verb, DOCTOR_REMEDY_SERVICES_SINGLE_VERBS, true)) {
        throw new RuntimeException("Verbo de docker compose desconocido para la prueba: «{$verb}» en «{$line}».");
    }

    $services = doctorRemedyServicesNames($tokens, doctorRemedyServicesSkipOptions($tokens, $index + 1), $isList);

    if (! $isList && $services === []) {
        throw new RuntimeException("«docker compose {$verb}» sin servicio reconocible en «{$line}».");
    }

    return $services;
}

/**
 * Cada `docker compose …` de un texto como [orden, servicios que nombra].
 *
 * @return list<array{command: string, services: list<string>}>
 */
function doctorRemedyServicesCommands(string $text): array
{
    preg_match_all('/docker compose\b[^\n]*/', $text, $matches);

    return array_map(
        static fn (string $line): array => ['command' => trim($line), 'services' => doctorRemedyServicesOfLine($line)],
        $matches[0],
    );
}

it('extrae el servicio de cada forma de orden que usan los remedios', function (string $text, array $expected): void {
    $services = array_merge(...array_map(
        static fn (array $command): array => $command['services'],
        doctorRemedyServicesCommands($text),
    ));

    expect($services)->toBe($expected);
})->with([
    'la orden que fallo en la 2.2.0' => ["  docker compose restart worker\n", ['worker']],
    'logs con opcion pegada' => ['  docker compose logs --tail=100 horizon', ['horizon']],
    'logs con opcion separada' => ['docker compose logs --since 24h scheduler | grep -i backup', ['scheduler']],
    'up con varios y punto final' => ['con docker compose up -d app horizon scheduler. Detalle: x', ['app', 'horizon', 'scheduler']],
    'up entre comillas invertidas' => ['reinicia con `docker compose up -d app`.', ['app']],
    'up sin servicios' => ['recrea los contenedores (docker compose up -d)', []],
    'ps sin servicios' => ['  docker compose ps', []],
    'exec con usuario' => ['  docker compose exec -u root app chown app:app :path', ['app']],
    'exec con la orden detras' => ['  docker compose exec scheduler php artisan backup:run', ['scheduler']],
])->group('RF-PD-13');

it('cada docker compose de los remedios nombra un servicio del compose de produccion', function (string $locale): void {
    $declared = array_keys(ComposeEnvironment::services(DOCTOR_REMEDY_SERVICES_COMPOSE));
    $unknown = [];
    $named = [];

    foreach (doctorRemedyServicesStrings($locale) as $text) {
        foreach (doctorRemedyServicesCommands($text) as $command) {
            foreach ($command['services'] as $service) {
                $named[$service] = true;

                if (! in_array($service, $declared, true)) {
                    $unknown[] = "«{$service}» en «{$command['command']}»";
                }
            }
        }
    }

    expect($unknown)->toBe([], sprintf(
        'lang/%s/doctor.php nombra servicios que %s no declara: el informatico copiara una orden que '
        ."responde «no such service». Servicios declarados: %s.\n%s",
        $locale,
        DOCTOR_REMEDY_SERVICES_COMPOSE,
        implode(', ', $declared),
        implode("\n", $unknown),
    ));

    // Sin verdes vacios: el remedio de la cola y el de la base de datos tienen
    // que haberse leido, o la extraccion ha dejado de ver los comandos.
    expect($named)->toHaveKeys(['horizon', 'app', 'redis', 'postgres']);
})->with(['es', 'en'])->group('RF-PD-13');
