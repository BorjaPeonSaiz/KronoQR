<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Resultado de recorrer la suite: lo que se ha encontrado y, sobre todo, lo que
 * NO se ha podido mirar.
 *
 * Los directorios ausentes y las etiquetas mal escritas viajan con el resultado
 * porque un extractor que devuelve «0 pruebas» sin decir si es que no hay
 * ninguna o que no ha podido verlas da una garantia que no presta.
 */
final readonly class TagScan
{
    /**
     * @param  list<TaggedTest>  $tests
     * @param  array<string, list<string>>  $missingRoots  Herramienta -> directorios que no existen.
     * @param  array<string, int>  $scannedFiles  Herramienta -> ficheros leidos.
     * @param  list<string>  $malformed  Etiquetas con forma de requisito que no lo son.
     */
    public function __construct(
        public array $tests,
        public array $missingRoots,
        public array $scannedFiles,
        public array $malformed,
    ) {}

    /**
     * @return list<TaggedTest>
     */
    public function by(string $tool): array
    {
        return array_values(array_filter(
            $this->tests,
            static fn (TaggedTest $test): bool => $test->tool === $tool,
        ));
    }

    /**
     * Pruebas que cubren cada requisito, indexadas por identificador.
     *
     * @return array<string, list<TaggedTest>>
     */
    public function byRequirement(): array
    {
        $index = [];

        foreach ($this->tests as $test) {
            foreach ($test->requirements as $requirement) {
                $index[$requirement][] = $test;
            }
        }

        ksort($index);

        return $index;
    }
}
