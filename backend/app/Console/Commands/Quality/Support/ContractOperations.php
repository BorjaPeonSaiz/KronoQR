<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Las operaciones del contrato (`docs/api/openapi.yaml`) y los requisitos que
 * cita el contrato entero.
 *
 * Se lee el fichero dos veces y a proposito: interpretado, para saber que cita
 * **cada operacion** en su `summary` y su `description`; en crudo, para saber
 * que cita **todo el contrato**, esquemas y respuestas incluidos. Un requisito
 * inventado en la descripcion de un esquema es la misma divergencia que en la
 * de una operacion.
 */
final readonly class ContractOperations
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'patch', 'options', 'head', 'trace'];

    /**
     * @param  list<ContractOperation>  $operations
     * @param  list<string>  $citations  Lo que cita el fichero entero.
     */
    private function __construct(public array $operations, public array $citations) {}

    public static function fromFile(string $file): self
    {
        if (! is_file($file)) {
            throw new RuntimeException(
                'No se encuentra el contrato en '.$file.'. '
                .'Dentro del contenedor llega por el montaje ../docs de infra/compose.dev.yaml.'
            );
        }

        $raw = (string) file_get_contents($file);
        $parsed = Yaml::parse($raw);

        if (! \is_array($parsed) || ! \is_array($parsed['paths'] ?? null)) {
            throw new RuntimeException('El contrato '.$file.' no declara ninguna ruta.');
        }

        $operations = [];

        foreach ($parsed['paths'] as $path => $item) {
            if (\is_array($item)) {
                array_push($operations, ...self::operationsOf((string) $path, $item));
            }
        }

        return new self($operations, RequirementCitations::in($raw));
    }

    /**
     * @param  array<mixed>  $item  Un elemento de `paths:`.
     * @return list<ContractOperation>
     */
    private static function operationsOf(string $path, array $item): array
    {
        $operations = [];

        foreach ($item as $method => $operation) {
            if (! \in_array($method, self::METHODS, true) || ! \is_array($operation)) {
                continue;
            }

            $operationId = self::text($operation['operationId'] ?? null);

            $operations[] = new ContractOperation(
                strtoupper((string) $method),
                $path,
                $operationId === '' ? '(sin operationId)' : $operationId,
                RequirementCitations::in(
                    self::text($operation['summary'] ?? null)."\n".self::text($operation['description'] ?? null)
                ),
            );
        }

        return $operations;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
