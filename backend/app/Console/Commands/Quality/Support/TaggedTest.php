<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Una prueba que declara que requisitos cubre (§9.6).
 */
final readonly class TaggedTest
{
    /**
     * @param  string  $tool  `pest`, `playwright` o `k6`.
     * @param  string  $name  Nombre de la prueba; en k6, la clave del escenario.
     * @param  list<string>  $requirements  Identificadores citados en la etiqueta.
     * @param  bool  $conditional  La prueba lleva un salto condicionado al
     *                             entorno (`->skip(! hayChromium(), '…')`): se
     *                             ejecuta y verifica, pero solo donde la
     *                             herramienta que necesita esta instalada.
     */
    public function __construct(
        public string $tool,
        public string $file,
        public int $line,
        public string $name,
        public array $requirements,
        public bool $conditional = false,
    ) {}

    public function reference(): string
    {
        return $this->file.':'.$this->line;
    }

    /**
     * ¿Es una prueba condicionada al entorno de un fichero que la CI omite
     * siempre (`quality.skipped_in_ci`)? Solo las condicionadas: las demas
     * pruebas del mismo fichero se ejecutan en la CI y cubren lo que dicen.
     *
     * Aqui y no en el informe para que `qa:traceability` y la puerta de nivel
     * (`TestLevelGateTest`) decidan lo mismo: si solo la aplicara el informe, la
     * puerta contaria como nivel cubierto una prueba que la CI no ejecuta nunca.
     *
     * Se compara por el FINAL de la ruta porque la prueba llega relativa al
     * padre de `base_path()` —`html/tests/…` en el contenedor, `backend/tests/…`
     * en la CI— y la configuracion no puede depender de donde se ejecute.
     *
     * @param  list<string>  $skippedInCi  Ficheros de Pest relativos a backend/.
     */
    public function isAlwaysSkippedInCi(array $skippedInCi): bool
    {
        if (! $this->conditional || $this->tool !== 'pest') {
            return false;
        }

        foreach ($skippedInCi as $path) {
            if (str_ends_with('/'.$this->file, '/'.ltrim($path, '/'))) {
                return true;
            }
        }

        return false;
    }
}
