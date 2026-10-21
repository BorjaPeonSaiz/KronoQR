<?php

declare(strict_types=1);

use App\Support\Scheduling\ScheduledTaskOutput;
use Tests\Architecture\Support\Repo;

/*
 * **TODA TAREA EN SEGUNDO PLANO DECLARA SU SALIDA, Y LA DECLARA CON EL
 * AYUDANTE** (V4-BE-1, RNF-D-02, regla dura 21).
 *
 * Por defecto Laravel tira a `/dev/null` la salida de una tarea programada, y
 * con `runInBackground()` tambien la del proceso que la cierra. Una copia que
 * fallaba con un mensaje claro de `pg_dump` no dejaba ni una linea en el log del
 * contenedor. {@see ScheduledTaskOutput} lo arregla, pero solo si cada tarea
 * pasa por el: una tarea nueva copiada de la documentacion de Laravel volveria
 * al silencio sin que nada fallara.
 *
 * Y con el ayudante y no a mano: `sendOutputTo('/proc/1/fd/1')` llevaria la
 * salida al log del contenedor **sin sanear** —el contenedor no pasa por
 * Monolog—, y de ahi a Loki, donde no hay borrado selectivo.
 *
 * Se lee `routes/console.php` con el tokenizador (los comentarios no cuentan)
 * porque esta suite corre sin framework. La comprobacion sobre las tareas que
 * Laravel registra de verdad la hace
 * `tests/Feature/Observability/ScheduledTaskOutputReachesTheContainerLogTest.php`.
 */

/**
 * Las sentencias de codigo del planificador, sin comentarios y con los espacios
 * colapsados.
 *
 * @return list<string>
 */
function scheduledTaskOutputStatements(): array
{
    $code = '';

    foreach (token_get_all(Repo::contents('backend/routes/console.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    $statements = array_map(
        static fn (string $statement): string => trim((string) preg_replace('/\s+/', ' ', $statement)),
        explode(';', $code),
    );

    return array_values(array_filter($statements, static fn (string $statement): bool => $statement !== ''));
}

it('envuelve en ScheduledTaskOutput cada tarea con runInBackground()', function (): void {
    $background = array_values(array_filter(
        scheduledTaskOutputStatements(),
        static fn (string $statement): bool => str_contains($statement, 'runInBackground'),
    ));

    // El recorrido no ha sido en vacio: el planificador tiene una veintena.
    expect(count($background))->toBeGreaterThan(10);

    $sinAyudante = array_filter(
        $background,
        static fn (string $statement): bool => preg_match(
            '/^(\$\w+ = )?ScheduledTaskOutput::(toLog|summaryOnly)\( ?Schedule::command\(.*->runInBackground\(\) ?\)$/',
            $statement,
        ) !== 1,
    );

    expect(array_values($sinAyudante))->toBe([], "Estas tareas en segundo plano tiran su salida a /dev/null.\n"
        ."Envuelvelas en ScheduledTaskOutput::toLog(...) (o summaryOnly(...) si su salida es informacion del registro del cliente):\n"
        .implode("\n", $sinAyudante));
})->group('RNF-D-02', 'RL-08');

it('envuelve tambien cada tarea en primer plano: Laravel tira igual su salida a /dev/null', function (): void {
    // `reporting:presence-metrics` y `compliance:incident-metrics` corren en
    // primer plano y su salida —el aviso de un fallo incluido— se perdia igual
    // (V4-BE-1). Lo que no sea un `Schedule::command()` (el latido es un
    // `Schedule::call()` sin salida) queda fuera.
    $commands = array_values(array_filter(
        scheduledTaskOutputStatements(),
        static fn (string $statement): bool => str_contains($statement, 'Schedule::command('),
    ));

    expect(count($commands))->toBeGreaterThan(20);

    $sinAyudante = array_filter(
        $commands,
        static fn (string $statement): bool => preg_match(
            '/^(\$\w+ = )?ScheduledTaskOutput::(toLog|summaryOnly)\( ?Schedule::command\(.*\)$/',
            $statement,
        ) !== 1,
    );

    expect(array_values($sinAyudante))->toBe([], "Estas tareas tiran su salida a /dev/null:\n".implode("\n", $sinAyudante));
})->group('RNF-D-02', 'RL-08');

it('nadie declara la salida de una tarea a mano', function (string $metodo): void {
    $aMano = array_filter(
        scheduledTaskOutputStatements(),
        static fn (string $statement): bool => str_contains($statement, '->'.$metodo.'('),
    );

    expect(array_values($aMano))->toBe([], $metodo.'() saca la salida de la tarea sin pasar por el saneador de Monolog '
        .'(regla dura 21). Usa ScheduledTaskOutput.');
})->with([
    'sendOutputTo',
    'appendOutputTo',
    'storeOutput',
    'emailOutputTo',
    'emailWrittenOutputTo',
    'emailOutputOnFailure',
    'thenWithOutput',
    'onSuccessWithOutput',
    'onFailureWithOutput',
])->group('RNF-D-02', 'RL-08');

/**
 * Las unicas tareas que se limitan al resumen, con su motivo. Todas habian
 * decidido antes de V4-BE-1 no dejar su salida en el log (`routes/console.php`
 * lo dice en el comentario de cada una). Lo normal es reenviarla saneada: una
 * tarea nueva que necesite callar la suya se anade aqui con su motivo.
 *
 * Clave: el principio de la llamada en `routes/console.php`, sin espacios de
 * mas. Valor: por que.
 */
const SCHEDULED_TASK_OUTPUT_SUMMARY_ONLY = [
    "Schedule::command('compliance:apply-retention', ['--dry-run'])" => 'recuentos de filas por tabla del registro horario del cliente',
    "Schedule::command('compliance:reconcile-work-record')" => 'UUID de shift_entries e #id de audit_log de cada discrepancia',
    "Schedule::command('compliance:reconcile-work-record', ['--full'])" => 'lo mismo, sobre el registro entero',
    "Schedule::command('reporting:absence-metrics')" => 'datos de ausencias de la plantilla; la regla dura 21 gobierna el habito',
];

it('solo las tareas de la lista se limitan al resumen, y todas ellas lo hacen', function (): void {
    $resumen = array_values(array_filter(
        scheduledTaskOutputStatements(),
        static fn (string $statement): bool => str_contains($statement, 'ScheduledTaskOutput::summaryOnly('),
    ));

    $tareas = array_map(
        static fn (string $statement): string => preg_match('/Schedule::command\((?:[^()]|\([^()]*\))*\)/', $statement, $m) === 1 ? $m[0] : $statement,
        $resumen,
    );

    $esperadas = array_keys(SCHEDULED_TASK_OUTPUT_SUMMARY_ONLY);
    sort($tareas);
    sort($esperadas);

    expect($tareas)->toBe($esperadas, 'summaryOnly() calla la salida de una tarea: cada una tiene que estar en '
        .'SCHEDULED_TASK_OUTPUT_SUMMARY_ONLY con su motivo, y cada una de la lista tiene que usarlo.');
})->group('RNF-D-02', 'RL-08');
