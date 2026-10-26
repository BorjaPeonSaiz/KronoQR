<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * **UNA SOLA REGLA DE MINUTOS** (RN-09, RN-10; bloque 19 de la 2.2.1, ADR-055 §3).
 *
 * Los minutos entre dos instantes se calculaban en cinco sitios del dominio
 * —`TimeRange`, `ComplianceShiftEntry`, `ComplianceFacts`,
 * `AnomalyDetectionPolicy` y `WorkRecordReconciliation`— con la misma resta de
 * `getTimestamp()` dividida entre sesenta, y una prueba que ataba las copias.
 * Ahora la regla vive en `Shared\Domain\ValueObject\ElapsedMinutes` y esta
 * guarda impide que vuelva a copiarse en `Domain/` o en `Application/`.
 *
 * ## Que se considera una copia
 *
 * Un fichero que llama a `getTimestamp()` **dos veces o mas** y ademas divide
 * entre 60 (`intdiv(…, 60)`, `/ 60`) o usa una constante `*_PER_MINUTE`. Es una
 * comprobacion por fichero y no por sentencia, a proposito: la copia de
 * `ComplianceFacts` restaba en una linea y dividia en otra, y una copia nueva
 * puede leer las marcas en un metodo y dividir en otro. Es deliberadamente
 * amplia: si algun dia salta sobre un fichero que no mide minutos entre
 * instantes, la salida es usar `ElapsedMinutes` o partir el fichero, no
 * relajar la guarda.
 *
 * Restar marcas para comparar o medir segundos (desfase de reloj, vida de un
 * token) sigue siendo libre mientras no se pase a minutos en el mismo fichero.
 *
 * Sobre el TEXTO del codigo, sin comentarios: los comentarios explican la
 * regla y la nombran (`AggregateBoundaryTest` aprendio lo mismo).
 */

/** El unico fichero que puede pasar de segundos a minutos. */
const SINGLE_MINUTE_RULE_HOME = 'Shared/Domain/ValueObject/ElapsedMinutes.php';

/** Las capas vigiladas, como segmento de la ruta relativa a `app/Modules/`. */
const SINGLE_MINUTE_RULE_LAYERS = ['/Domain/', '/Application/'];

function codigoSinComentariosDeLosMinutos(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= \is_array($token) ? $token[1] : $token;
    }

    return (string) preg_replace('/\s+/', ' ', $code);
}

/**
 * Si el codigo lee dos marcas o mas y ademas pasa algo a minutos.
 */
function convierteMarcasEnMinutos(string $code): bool
{
    $leeDosMarcas = preg_match_all('/->getTimestamp\(\)/', $code) >= 2;
    $pasaAMinutos = preg_match('/intdiv\([^;]*?, ?60 ?\)|\/ ?60\b|\b[A-Z_]*PER_MINUTE\b/', $code) === 1;

    return $leeDosMarcas && $pasaAMinutos;
}

it('ningun fichero de Domain ni de Application, salvo ElapsedMinutes, convierte marcas en minutos', function (): void {
    $root = ModuleTree::root();
    $vigilados = 0;
    $copias = [];

    foreach (ModuleTree::phpFilesUnder($root) as $file) {
        $relative = ModuleTree::relative($file, $root);

        if ($relative === SINGLE_MINUTE_RULE_HOME
            || ! array_any(SINGLE_MINUTE_RULE_LAYERS, static fn (string $layer): bool => str_contains($relative, $layer))) {
            continue;
        }

        $vigilados++;

        if (convierteMarcasEnMinutos(codigoSinComentariosDeLosMinutos((string) file_get_contents($file)))) {
            $copias[] = $relative;
        }
    }

    // Que «no encuentra nada» signifique algo: se ha mirado de verdad.
    expect($vigilados)->toBeGreaterThan(100)
        ->and($copias)->toBe([]);
})->group('RN-09', 'RN-10');

it('la regla vive donde dice la guarda', function (): void {
    // Si se mueve, la exencion de arriba dejaria de apuntar a nada y la guarda
    // seguiria verde sin proteger la casa de la regla.
    $file = ModuleTree::root().'/'.SINGLE_MINUTE_RULE_HOME;

    expect(is_file($file))->toBeTrue()
        ->and(convierteMarcasEnMinutos(codigoSinComentariosDeLosMinutos((string) file_get_contents($file))))->toBeTrue();
})->group('RN-09', 'RN-10');

it('el detector ve las copias de la regla y deja pasar lo que no lo es', function (string $fragmento, bool $copia): void {
    expect(convierteMarcasEnMinutos(codigoSinComentariosDeLosMinutos('<?php '.$fragmento)))->toBe($copia);
})->with([
    'en una expresion' => ['return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);', true],
    'en dos lineas' => ["\$s = \$a->getTimestamp() - \$b->getTimestamp();\nreturn \$s < 0 ? null : intdiv(\$s, 60);", true],
    'con barra' => ['return (int) (($b->getTimestamp() - $a->getTimestamp()) / 60);', true],
    'en dos metodos' => [
        "function s(\$a, \$b): int { \$x = \$a->getTimestamp(); \$y = \$b->getTimestamp(); return \$y - \$x; }\n"
        .'function m(int $s): int { return intdiv($s, 60); }',
        true,
    ],
    'con constante por minuto' => [
        "const SECONDS_PER_MINUTE = 60;\n\$s = \$to->getTimestamp() - \$from->getTimestamp();\nreturn intdiv(\$s, self::SECONDS_PER_MINUTE);",
        true,
    ],
    'con constante y sin resta en la misma sentencia' => [
        "\$in = \$a->getTimestamp();\n\$out = \$b->getTimestamp();\nreturn (\$out - \$in) / Time::SECONDS_PER_MINUTE;",
        true,
    ],
    'segundos sin minutos' => ['return $this->recordedAt->getTimestamp() - $this->occurredAt->getTimestamp();', false],
    'horas de unos minutos' => ["return sprintf('%02d:%02d', intdiv(\$this->minutes, 60), \$this->minutes % 60);", false],
    'una sola marca y minutos' => ['return intdiv($now->getTimestamp() % 3600, 60);', false],
    'dias, no minutos' => ['return intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), self::SECONDS_PER_DAY);', false],
    'en un comentario' => ["// intdiv(\$b->getTimestamp() - \$a->getTimestamp(), 60)\n\$x = 1;", false],
])->group('RN-09');
