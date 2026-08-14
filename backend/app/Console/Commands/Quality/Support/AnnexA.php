<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use RuntimeException;

/**
 * Lectura del Anexo A del doc 01 —«Trazabilidad requisito -> fase»— tal y como
 * esta escrito: una tabla en prosa, con rangos, negritas y anotaciones entre
 * parentesis.
 *
 * Esta clase NO la usa el comando: `qa:traceability` lee docs/requisitos.yaml,
 * que es la version procesable. La usa la prueba que comprueba que las dos no
 * han divergido. Es la unica forma de que el YAML siga siendo el Anexo A y no
 * una copia que se quedo atras.
 *
 * Todo lo que no sabe leer lo devuelve en `uncoded()` en vez de descartarlo:
 * «§9 completo» y «Cuadrantes, vacaciones con aprobacion...» son entradas reales
 * del Anexo A que no citan ningun identificador, y quien lea el informe tiene
 * que verlas.
 */
final readonly class AnnexA
{
    private const string HEADING = '/^##\s+Anexo A\b/mu';

    private const string NEXT_HEADING = '/^##\s+/mu';

    private const string ORDER_LINE = '/Orden de ejecuci[oó]n:\s*(?<order>[^\n]+)/u';

    private const string TABLE_ROW = '/^\|\s*(?<phase>[^|]+?)\s*\|\s*(?<requirements>[^|]+?)\s*\|\s*$/mu';

    /**
     * @param  array<string, int>  $phases  Requisito -> fase en la que se construye.
     * @param  array<string, list<int>>  $multiPhase  Requisitos citados en mas de una fase.
     * @param  list<array{phase: int, text: string}>  $uncoded  Entradas sin identificador.
     * @param  list<int>  $executionOrder
     */
    private function __construct(
        public array $phases,
        public array $multiPhase,
        public array $uncoded,
        public array $executionOrder,
    ) {}

    public static function fromFile(string $file): self
    {
        if (! is_file($file)) {
            throw new RuntimeException('No se encuentra el documento de especificaciones en '.$file.'.');
        }

        return self::fromMarkdown((string) file_get_contents($file));
    }

    public static function fromMarkdown(string $markdown): self
    {
        $annex = self::section($markdown);
        $order = new PhaseOrder(self::executionOrder($annex));

        $phases = [];
        $citations = [];
        $uncoded = [];

        foreach (self::rows($annex) as $row) {
            foreach (RequirementRange::findAll($row['requirements']) as $id) {
                $citations[$id][] = $row['phase'];
            }

            $residue = RequirementRange::residue($row['requirements']);

            if ($residue !== '') {
                $uncoded[] = ['phase' => $row['phase'], 'text' => $residue];
            }
        }

        if ($citations === []) {
            throw new RuntimeException('El Anexo A no cita ningun requisito: la tabla ha cambiado de forma.');
        }

        $multiPhase = [];

        foreach ($citations as $key => $cited) {
            $id = (string) $key;

            // Un requisito citado dos veces —RF-ID-01 esta en la Fase 1 «basica»
            // y en la Fase 2 «completa»— se atribuye a la PRIMERA fase que lo
            // construye en el orden de ejecucion: es cuando empieza a ser
            // exigible que tenga prueba.
            usort($cited, static fn (int $left, int $right): int => $order->rank($left) <=> $order->rank($right));

            $phases[$id] = $cited[0];

            if (count(array_unique($cited)) > 1) {
                $multiPhase[$id] = array_values(array_unique($cited));
            }
        }

        ksort($phases);

        return new self($phases, $multiPhase, $uncoded, $order->all());
    }

    private static function section(string $markdown): string
    {
        if (preg_match(self::HEADING, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('El documento no tiene un «## Anexo A».');
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $rest = substr($markdown, $start);

        if (preg_match(self::NEXT_HEADING, $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }

    /**
     * @return list<int>
     */
    private static function executionOrder(string $annex): array
    {
        if (preg_match(self::ORDER_LINE, $annex, $match) !== 1) {
            throw new RuntimeException('El Anexo A no declara el orden de ejecucion de las fases.');
        }

        preg_match_all('/\d+/', $match['order'], $numbers);

        return array_map(intval(...), $numbers[0]);
    }

    /**
     * Filas de la tabla «Fase | Requisitos incluidos», ya sin negritas ni
     * anotaciones entre parentesis.
     *
     * @return list<array{phase: int, requirements: string}>
     */
    private static function rows(string $annex): array
    {
        preg_match_all(self::TABLE_ROW, $annex, $matches, PREG_SET_ORDER);

        $rows = [];

        foreach ($matches as $match) {
            if (preg_match('/Fase\s+(?<number>\d+)/u', $match['phase'], $phase) !== 1) {
                continue;
            }

            $requirements = str_replace('**', '', $match['requirements']);
            $requirements = (string) preg_replace('/\([^)]*\)/u', '', $requirements);

            $rows[] = ['phase' => (int) $phase['number'], 'requirements' => $requirements];
        }

        if ($rows === []) {
            throw new RuntimeException('El Anexo A no tiene ninguna fila «Fase N | requisitos».');
        }

        return $rows;
    }
}
