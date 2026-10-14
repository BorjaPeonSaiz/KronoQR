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
 * Monolog—, y ese log viaja en el paquete de diagnostico.
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

it('solo la propuesta de purga se limita al resumen', function (): void {
    $resumen = array_values(array_filter(
        scheduledTaskOutputStatements(),
        static fn (string $statement): bool => str_contains($statement, 'ScheduledTaskOutput::summaryOnly('),
    ));

    // Si otra tarea necesita callar su salida, que se anada aqui con su motivo
    // en routes/console.php: lo normal es reenviarla saneada.
    expect($resumen)->toHaveCount(1)
        ->and($resumen[0])->toContain("Schedule::command('compliance:apply-retention', ['--dry-run'])");
})->group('RNF-D-02', 'RL-08');
