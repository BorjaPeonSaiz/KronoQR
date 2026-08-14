<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/requisitos.yaml: la fuente legible por maquina del Anexo A del doc 01.
 *
 * El fichero es la unica entrada de `--check`, asi que se valida entero al
 * cargarlo y se falla con la linea concreta. Lo que aqui se cuela sin ruido deja
 * de bloquear despues sin ruido: un `RF-ID-04..09` sin expandir seria un
 * requisito con nombre imposible que ninguna prueba puede etiquetar, y seis
 * requisitos reales sin vigilancia.
 */
final readonly class RequirementCatalog
{
    /**
     * @param  list<Requirement>  $requirements
     */
    private function __construct(public array $requirements) {}

    public static function fromFile(string $file): self
    {
        if (! is_file($file)) {
            throw new RuntimeException(
                'No se encuentra el catalogo de requisitos en '.$file.'. '
                .'Dentro del contenedor llega por el montaje ../docs de infra/compose.dev.yaml.'
            );
        }

        $parsed = Yaml::parseFile($file);

        if (! is_array($parsed) || $parsed === []) {
            throw new RuntimeException('El catalogo '.$file.' no es una lista de requisitos.');
        }

        $requirements = [];
        $seen = [];

        foreach (array_values($parsed) as $index => $entry) {
            $requirement = self::read($entry, $index + 1);

            if (isset($seen[$requirement->id])) {
                throw new RuntimeException('El catalogo repite el requisito '.$requirement->id.'.');
            }

            $seen[$requirement->id] = true;
            $requirements[] = $requirement;
        }

        return new self($requirements);
    }

    /**
     * Fase de cada requisito, indexada por identificador.
     *
     * @return array<string, int>
     */
    public function phases(): array
    {
        $phases = [];

        foreach ($this->requirements as $requirement) {
            $phases[$requirement->id] = $requirement->phase;
        }

        return $phases;
    }

    /**
     * Requisitos cuya fase ya se ha ejecutado: el alcance del bloqueo (§9.6).
     *
     * @return list<Requirement>
     */
    public function inScope(PhaseOrder $order, int $currentPhase): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (Requirement $requirement): bool => $order->isExecutedBy($requirement->phase, $currentPhase),
        ));
    }

    private static function read(mixed $entry, int $position): Requirement
    {
        if (! is_array($entry)) {
            throw new RuntimeException('La entrada '.$position.' del catalogo no es un mapa con id, fase y titulo.');
        }

        $id = $entry['id'] ?? null;
        $phase = $entry['fase'] ?? null;
        $title = $entry['titulo'] ?? null;

        if (! is_string($id) || preg_match(RequirementRange::IDENTIFIER, $id) !== 1) {
            throw new RuntimeException(
                'La entrada '.$position.' del catalogo no tiene un identificador expandido: '
                .var_export($id, true).'. Los rangos del Anexo A se escriben aqui uno a uno.'
            );
        }

        if (! is_int($phase)) {
            throw new RuntimeException('El requisito '.$id.' no declara su fase como numero entero.');
        }

        if (! is_string($title) || trim($title) === '') {
            throw new RuntimeException('El requisito '.$id.' no tiene enunciado.');
        }

        return new Requirement($id, $phase, $title);
    }
}
