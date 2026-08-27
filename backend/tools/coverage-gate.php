<?php

declare(strict_types=1);

/*
 * Umbral de cobertura del DOMINIO — RNF-M-01, doc 02 §9.2.
 *
 *     php tools/coverage-gate.php <clover.xml> <minimo> <patron> [patron...]
 *
 * Se invoca desde `make coverage`, sobre el informe Clover que la misma
 * ejecucion de Pest acaba de escribir: no vuelve a correr la suite.
 *
 * Existe porque `--min` de Pest es un umbral global y el requisito son dos.
 * Con solo el global, el 90 % del dominio lo podia «pagar» cualquier otra parte
 * del arbol, y la puerta figuraba en la CI sin comprobar lo que dice comprobar.
 */

use KronoQR\Tools\Quality\DomainCoverageGate;

require __DIR__.'/../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = array_slice($argv, 1);

if (count($arguments) < 3) {
    fwrite(STDERR, 'Uso: php tools/coverage-gate.php <clover.xml> <minimo> <patron> [patron...]'.PHP_EOL);

    exit(2);
}

$clover = $arguments[0];
$minimum = (float) $arguments[1];
$patterns = array_slice($arguments, 2);

$result = (new DomainCoverageGate($patterns, $minimum))->measure($clover);

fwrite(STDOUT, sprintf(
    '[coverage-gate] %s: %.2f %% (%d de %d sentencias), umbral %.0f %%.%s',
    implode(', ', $patterns),
    $result->percentage,
    $result->coveredStatements,
    $result->statements,
    $minimum,
    PHP_EOL,
));

if ($result->statements === 0) {
    fwrite(STDERR, '[coverage-gate] Ningun fichero casa con el patron: no hay nada que medir.'.PHP_EOL);

    exit(1);
}

if ($result->passes()) {
    exit(0);
}

foreach ($result->below() as $file => $percentage) {
    fwrite(STDERR, sprintf('  %6.2f %%  %s%s', $percentage, $file, PHP_EOL));
}

fwrite(STDERR, '[coverage-gate] La cobertura del dominio no llega al umbral (RNF-M-01).'.PHP_EOL);

exit(1);
