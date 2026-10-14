<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use App\Modules\Product\Application\Port\StagedDiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use RuntimeException;
use Throwable;

/**
 * Escribe el paquete en el disco de la instalacion (`product:diagnostics`).
 *
 * ## Permisos `0600`, y el directorio `0700`
 *
 * El paquete anonimizado no lleva datos personales, pero **el que se pide con
 * `--with-personal-data` si**, y los dos acaban en el mismo directorio. Un
 * fichero de diagnostico legible por cualquier cuenta del servidor seria una
 * copia de la plantilla al alcance de quien pase por ahi. Se escribe cerrado
 * siempre: distinguir por contenido seria una decision que algun dia se toma
 * mal.
 *
 * ## Falla ruidosamente, y con la ruta en el mensaje
 *
 * Al contrario que los recolectores, que degradan. Aqui no hay nada que
 * degradar: si el fichero no se puede escribir, no hay paquete, y quien ejecuto
 * el comando tiene que saber **que directorio** arreglar. El mensaje lleva la
 * ruta y el motivo, que es lo que necesita quien esta delante de la terminal.
 */
final readonly class JsonDiagnosticsBundleWriter implements DiagnosticsBundleWriter
{
    public function __construct(private string $defaultDirectory) {}

    public function write(DiagnosticsBundle $bundle, ?string $path = null): string
    {
        return $this->publish($this->stage($bundle, $path));
    }

    /**
     * ## El temporal casa con el patron de los paquetes, a proposito
     *
     * `kronoqr-diagnostics-staging-<aleatorio>.json`, en el mismo directorio que
     * el destino —el renombrado es atomico solo dentro del mismo sistema de
     * ficheros—. Si el proceso muere antes de publicarlo, la purga por
     * antiguedad lo barre como a cualquier paquete (ADR-045 §g): no queda un
     * fichero `0600` olvidado para siempre.
     */
    public function stage(DiagnosticsBundle $bundle, ?string $path = null): StagedDiagnosticsBundle
    {
        $target = $path ?? rtrim($this->defaultDirectory, '/').'/'.$bundle->manifest->fileName();

        // Con `--output=carpeta/` se compone el nombre; con `--output=fichero.json`
        // se respeta. Quien pasa un directorio casi siempre quiere lo primero.
        if (is_dir($target)) {
            $target = rtrim($target, '/').'/'.$bundle->manifest->fileName();
        }

        $directory = \dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio del paquete de diagnostico: '.$directory);
        }

        $temporary = $directory.'/kronoqr-diagnostics-staging-'.bin2hex(random_bytes(6)).'.json';
        $previous = umask(0o077);

        try {
            $written = @file_put_contents($temporary, $bundle->toJson());
        } finally {
            umask($previous);
        }

        if ($written === false) {
            throw new RuntimeException(
                'No se pudo escribir el paquete de diagnostico en '.$target
                .'. Comprueba que el directorio existe y que el usuario de la aplicacion puede escribir en el.'
            );
        }

        @chmod($temporary, 0o600);

        return new StagedDiagnosticsBundle($temporary, $target);
    }

    public function publish(StagedDiagnosticsBundle $staged): string
    {
        if (! @rename($staged->temporary, $staged->target)) {
            throw new RuntimeException(
                'No se pudo escribir el paquete de diagnostico en '.$staged->target
                .'. Comprueba que el directorio existe y que el usuario de la aplicacion puede escribir en el.'
            );
        }

        return (string) (realpath($staged->target) ?: $staged->target);
    }

    public function discard(StagedDiagnosticsBundle $staged): void
    {
        if (is_file($staged->temporary)) {
            @unlink($staged->temporary);
        }
    }

    public function read(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
