<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics\Exposition;

/**
 * Una serie del catalogo: su nombre, su tipo, su `# HELP` y sus etiquetas.
 *
 * **El orden de `labels` es normativo.** Es el orden en el que el escritor las
 * concatena en Redis (`site=1,department=Cocina`) y tambien el que sale por
 * `/metrics`. Cambiarlo aqui sin cambiarlo alli deja la serie ilegible sin que
 * falle nada, y por eso hay una prueba de arquitectura que compara el catalogo
 * con el listado literal del doc 02 §8.2.
 */
final readonly class MetricDefinition
{
    /**
     * @param  list<string>  $labels  En el mismo orden en que las escribe el adaptador.
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public MetricStorage $storage,
        public string $help,
        public array $labels = [],
    ) {}

    /**
     * La clave —o el prefijo de claves— con la que la serie vive en Redis.
     */
    public function key(string $prefix): string
    {
        return $prefix.$this->name;
    }
}
