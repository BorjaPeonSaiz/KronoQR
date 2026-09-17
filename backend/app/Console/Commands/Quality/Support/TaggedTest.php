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
}
