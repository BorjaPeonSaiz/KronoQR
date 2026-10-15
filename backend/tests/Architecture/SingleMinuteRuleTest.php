<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * **UNA SOLA REGLA DE MINUTOS** (RN-09, RN-10; bloque 19 de la 2.2.1, ADR-055 §3).
 *
 * Los minutos entre dos instantes se calculaban en cinco sitios del dominio
 * —`TimeRange`, `ComplianceShiftSegment`, `ComplianceFacts`,
 * `AnomalyDetectionPolicy` y `WorkRecordReconciliation`— con la misma resta de
 * `getTimestamp()` dividida entre sesenta, y una prueba que ataba las copias.
 * Ahora la regla vive en `Shared\Domain\ValueObject\ElapsedMinutes` y esta
 * guarda impide que vuelva a copiarse: si un fichero de `app/Modules/*\/Domain`
 * resta dos `getTimestamp()` y divide entre 60, esta duplicando la regla.
 *
 * Es una comprobacion por fichero y no por expresion, a proposito: la copia de
 * `ComplianceFacts` restaba en una linea y dividia en otra. Restar marcas para
 * comparar o medir segundos (desfase de reloj, vida de un token) sigue siendo
 * libre; lo que no se puede es convertir esa resta en minutos.
 *
 * Sobre el TEXTO del codigo, sin comentarios: los comentarios de dominio
 * explican la regla y la nombran (`AggregateBoundaryTest` aprendio lo mismo).
 */

/** El unico fichero de dominio que puede pasar de segundos a minutos. */
const SINGLE_MINUTE_RULE_HOME = 'Shared/Domain/ValueObject/ElapsedMinutes.php';

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
 * Si el codigo resta dos `getTimestamp()` y ademas divide entre 60.
 */
function convierteMarcasEnMinutos(string $code): bool
{
    $restaMarcas = preg_match('/getTimestamp\(\) ?- ?[^;]*?getTimestamp\(\)/', $code) === 1;
    $divideEntreSesenta = preg_match('/intdiv\([^;]*?, ?60 ?\)|\/ ?60\b/', $code) === 1;

    return $restaMarcas && $divideEntreSesenta;
}

it('ningun fichero de dominio, salvo ElapsedMinutes, convierte una resta de marcas en minutos', function (): void {
    $root = ModuleTree::root();
    $copias = [];

    foreach (ModuleTree::phpFilesUnder($root) as $file) {
        $relative = ModuleTree::relative($file, $root);

        if (! str_contains($relative, '/Domain/') || $relative === SINGLE_MINUTE_RULE_HOME) {
            continue;
        }

        if (convierteMarcasEnMinutos(codigoSinComentariosDeLosMinutos((string) file_get_contents($file)))) {
            $copias[] = $relative;
        }
    }

    expect($copias)->toBe([]);
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
    'segundos sin minutos' => ['return $this->recordedAt->getTimestamp() - $this->occurredAt->getTimestamp();', false],
    'horas de unos minutos' => ["return sprintf('%02d:%02d', intdiv(\$this->minutes, 60), \$this->minutes % 60);", false],
    'comparar marcas' => ['return $a->getTimestamp() < $b->getTimestamp() ? intdiv($m, 60) : 0;', false],
    'en un comentario' => ["// intdiv(\$b->getTimestamp() - \$a->getTimestamp(), 60)\n\$x = 1;", false],
])->group('RN-09');
