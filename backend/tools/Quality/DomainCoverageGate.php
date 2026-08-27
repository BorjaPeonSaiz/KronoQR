<?php

declare(strict_types=1);

namespace KronoQR\Tools\Quality;

use RuntimeException;
use SimpleXMLElement;

/**
 * Umbral de cobertura acotado a una parte del arbol — doc 02 §9.2, RNF-M-01.
 *
 * El requisito son DOS umbrales: dominio >= 90 % y global >= 75 %. El `--min` de
 * Pest es uno solo y global, asi que con el 75 puesto la mitad que de verdad
 * importa —`Modules/*&#47;Domain`, donde un `>` en lugar de un `>=` produce minutos
 * incorrectos en la nomina de alguien— no la comprobaba nadie: bastaba con que
 * el resto del codigo compensara.
 *
 * Esto lee el informe Clover que ya genera la misma ejecucion, se queda con los
 * ficheros que casan con el patron y aplica su propio minimo. No vuelve a
 * ejecutar la suite.
 */
final class DomainCoverageGate
{
    /**
     * @param  list<string>  $patterns  Patrones `fnmatch` sobre la ruta relativa a backend/.
     */
    public function __construct(
        private readonly array $patterns,
        private readonly float $minimum,
    ) {}

    public function measure(string $cloverPath): CoverageResult
    {
        $report = @simplexml_load_file($cloverPath);

        if ($report === false) {
            throw new RuntimeException('No se ha podido leer el informe de cobertura '.$cloverPath.'.');
        }

        $statements = 0;
        $covered = 0;
        $files = [];

        foreach ($this->filesIn($report) as $file) {
            $name = $this->relative((string) $file['name']);

            if (! $this->matches($name)) {
                continue;
            }

            $metrics = $file->metrics;

            if (! $metrics instanceof SimpleXMLElement) {
                continue;
            }

            $fileStatements = (int) $metrics['statements'];
            $fileCovered = (int) $metrics['coveredstatements'];

            $statements += $fileStatements;
            $covered += $fileCovered;
            $files[$name] = self::percentage($fileCovered, $fileStatements);
        }

        ksort($files);

        return new CoverageResult($covered, $statements, self::percentage($covered, $statements), $this->minimum, $files);
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function filesIn(SimpleXMLElement $report): array
    {
        $files = [];

        foreach ($report->xpath('//file') ?? [] as $file) {
            $files[] = $file;
        }

        return $files;
    }

    private function matches(string $name): bool
    {
        foreach ($this->patterns as $pattern) {
            if (fnmatch($pattern, $name) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clover escribe rutas absolutas, que dentro del contenedor y en la CI son
     * distintas. Se compara siempre desde `app/`.
     */
    private function relative(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $position = strpos($normalized, '/app/');

        return $position === false ? $normalized : substr($normalized, $position + 1);
    }

    private static function percentage(int $covered, int $statements): float
    {
        return $statements === 0 ? 100.0 : round($covered / $statements * 100, 2);
    }
}
