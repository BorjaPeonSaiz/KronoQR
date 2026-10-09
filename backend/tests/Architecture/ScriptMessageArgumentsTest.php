<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;
use Tests\Architecture\Support\ShellMessageCalls;

/*
 * Cada mensaje de los scripts de operacion recibe exactamente los argumentos
 * que pide su plantilla (V3-PL-04, verificacion final de la 2.2.0).
 *
 * DE DONDE SALE. `doctor.sh` y `update.sh` imprimian la orden que repara la
 * clave del WAL con DOS `%s` en la plantilla y UN argumento en la llamada:
 * `printf` rellena lo que falta con la cadena vacia y la orden salia como
 * `--write-env ` sin fichero. Lo mismo en `d_w_cert_unknown`
 * («`openssl x509 ... -in `» sin certificado). Es un fallo que ni ShellCheck ni
 * shfmt ven (el formato es una variable del catalogo) y que solo aparece el dia
 * que alguien tiene el problema y copia la orden. Al reves es igual de malo:
 * con MAS argumentos que huecos, `printf` reutiliza la plantilla y el mensaje
 * sale repetido.
 *
 * QUE SE COMPRUEBA:
 *   · cada clave tiene la MISMA secuencia de huecos en espanol y en ingles;
 *   · cada llamada `kq_format CLAVE ...` y `kq_msg CLAVE ...` con la clave
 *     escrita tal cual pasa tantos argumentos como huecos tiene la plantilla;
 *   · cada `kq_text CLAVE` (que no formatea) nombra una plantilla sin huecos;
 *   · la clave existe en el catalogo.
 *
 * Las llamadas con la clave en una variable (`kq_format "${key}" "$@"`, los
 * envoltorios) o con un numero variable de argumentos (`"$@"`, `"${arr[@]}"`)
 * no se pueden contar sin ejecutar y se dejan fuera; hoy solo son los
 * envoltorios de los propios catalogos.
 *
 * El catalogo se lee CARGANDO los ficheros con bash, no con una expresion
 * regular sobre su texto: los mensajes son cadenas de varias lineas con
 * comillas escapadas, y lo que importa es lo que bash acaba guardando.
 */

/**
 * Plantillas de los tres catalogos: clave => [es, en].
 *
 * @return array<string, array{0: string, 1: string}>
 */
function scriptMessageArgumentsCatalog(): array
{
    $lib = Repo::file('infra/scripts/lib');
    $script = 'set -euo pipefail; '
        .'. '.escapeshellarg($lib.'/messages.sh').'; '
        .'. '.escapeshellarg($lib.'/messages-update.sh').'; '
        .'. '.escapeshellarg($lib.'/messages-doctor.sh').'; '
        .'for k in "${!KQ_MSG_ES[@]}" "${!KQ_MSG_EN[@]}"; do '
        .'printf "%s\0%s\0%s\0" "$k" "${KQ_MSG_ES[$k]-}" "${KQ_MSG_EN[$k]-}"; done';

    $process = new Process(['bash', '-c', $script], timeout: 30.0);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $parts = explode("\0", $process->getOutput());
    $catalog = [];
    for ($i = 0; $i + 2 < \count($parts); $i += 3) {
        $catalog[$parts[$i]] = [$parts[$i + 1], $parts[$i + 2]];
    }

    return $catalog;
}

/**
 * Los huecos de una plantilla de printf, en orden (`%%` no es un hueco).
 *
 * @return list<string>
 */
function scriptMessageArgumentsPlaceholders(string $template): array
{
    preg_match_all('/%(?:%|[-+ 0#]*\d*(?:\.\d+)?([a-zA-Z]))/', $template, $matches);

    return array_values(array_filter($matches[1], static fn (string $type): bool => $type !== ''));
}

it('tiene los mismos huecos en espanol y en ingles en cada mensaje de los scripts', function (): void {
    $distintos = [];
    foreach (scriptMessageArgumentsCatalog() as $key => [$es, $en]) {
        if (scriptMessageArgumentsPlaceholders($es) !== scriptMessageArgumentsPlaceholders($en)) {
            $distintos[] = $key.' (es: '.implode(',', scriptMessageArgumentsPlaceholders($es))
                .' · en: '.implode(',', scriptMessageArgumentsPlaceholders($en)).')';
        }
    }

    expect($distintos)->toBe([], "Mensajes cuyo numero o tipo de huecos cambia entre idiomas:\n".implode("\n", $distintos));
})->group('RF-PD-02', 'RF-PD-10', 'RF-PD-13');

it('pasa a cada mensaje tantos argumentos como huecos tiene su plantilla', function (): void {
    $catalog = scriptMessageArgumentsCatalog();
    $root = Repo::file('infra/scripts');
    $calls = ShellMessageCalls::in([...glob($root.'/*.sh') ?: [], ...glob($root.'/lib/*.sh') ?: []], Repo::file(''));

    // Si el analizador dejara de encontrar llamadas, la prueba pasaria sin mirar nada.
    expect(\count($calls))->toBeGreaterThan(500);

    $fallos = [];
    foreach ($calls as [$where, $function, $key, $arguments]) {
        if (! isset($catalog[$key])) {
            $fallos[] = $where.' '.$function.' '.$key.': la clave no existe en ningun catalogo';

            continue;
        }
        $expected = \count(scriptMessageArgumentsPlaceholders($catalog[$key][0]));

        if ($function === 'kq_text') {
            if ($expected !== 0) {
                $fallos[] = $where.' kq_text '.$key.': la plantilla tiene '.$expected
                    .' huecos y kq_text no los rellena (usa kq_format)';
            }

            continue;
        }
        if ($arguments !== null && $arguments !== $expected) {
            $fallos[] = $where.' '.$function.' '.$key.': '.$arguments.' argumentos para '.$expected.' huecos';
        }
    }

    expect($fallos)->toBe([], "Llamadas cuyo numero de argumentos no cuadra con su plantilla:\n".implode("\n", $fallos));
})->group('RF-PD-02', 'RF-PD-10', 'RF-PD-13');

it('cuenta bien los argumentos de una llamada partida en lineas y con expansiones anidadas', function (): void {
    // El analizador de arriba es parte de la prueba: si cuenta mal, la prueba
    // miente. Los casos son los que aparecen en los scripts.
    $script = <<<'SH'
        x="$(kq_format clave_a "${uno}" "$(kq_format otra "b)" "c")" 'd e')"
        kq_msg clave_b \
          "${A}" "${B:-$(kq_text no_value)}" >&2
        err "$(kq_format clave_c "$@")"
        SH;

    $first = ShellMessageCalls::words($script, (int) strpos($script, 'clave_a'));
    $second = ShellMessageCalls::words($script, (int) strpos($script, 'clave_b'));
    $third = ShellMessageCalls::words($script, (int) strpos($script, 'clave_c'));

    expect($first)->toBe(['clave_a', '"${uno}"', '"$(kq_format otra "b)" "c")"', "'d e'"])
        ->and($second)->toBe(['clave_b', '"${A}"', '"${B:-$(kq_text no_value)}"'])
        ->and($third)->toBe(['clave_c', '"$@"'])
        ->and(scriptMessageArgumentsPlaceholders('a %s b %d c %% d %s'))->toBe(['s', 'd', 's']);
})->group('RF-PD-13');
