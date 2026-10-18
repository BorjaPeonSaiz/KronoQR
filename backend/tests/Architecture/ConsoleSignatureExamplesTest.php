<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * Ningun ejemplo vuelve a enseñar la firma por consola de la 2.2.1 (2.3.0
 * bloque 3, doc 01 §7.2 «Firmas por consola»).
 *
 * Desde la 2.3.0 la cuenta que firma se nombra con `--responsible=<correo o
 * uuid>`: `credentials:deliver --by=` se rechaza y `--responsible=<id>` sale
 * con 2. Un runbook, una guia o un mensaje de consola que siga enseñando la
 * forma antigua manda al hotel a una orden que falla, en el peor momento. Se
 * recorre el codigo de produccion, los textos, la documentacion (menos las
 * verificaciones, que citan lo que habia) y la infraestructura.
 *
 * Con `scandir` y no `RecursiveDirectoryIterator`, por lo mismo que
 * `BladeTemplatesTest`: sobre el bind mount de Docker Desktop el iterador
 * pierde ficheros sin avisar y la prueba pasaria en verde sin mirarlos.
 */

/** Directorios que no se recorren: dependencias, generados y lo que cita la historia. */
const CONSOLE_SIGNATURE_EXAMPLES_SKIP = ['vendor', 'node_modules', 'storage', '.git', 'verificacion'];

/**
 * @return list<string> rutas relativas a la raiz del repositorio
 */
function consoleSignatureExamplesFiles(string $relative): array
{
    $directory = Repo::root().'/'.$relative;
    $entries = scandir($directory);
    $files = [];

    foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
        $path = $relative.'/'.$entry;

        if (is_dir(Repo::root().'/'.$path)) {
            if (! in_array($entry, CONSOLE_SIGNATURE_EXAMPLES_SKIP, true)) {
                $files = [...$files, ...consoleSignatureExamplesFiles($path)];
            }

            continue;
        }

        if (preg_match('/\.(php|md|sh|ya?ml|txt|example)$/', $entry) === 1) {
            $files[] = $path;
        }
    }

    return $files;
}

it('ningun ejemplo de credentials:deliver usa --by ni ningun --responsible es un id', function (): void {
    $patterns = [
        // La entrega de la 2.2.1 (`--by=`), en cualquier linea que nombre la orden.
        '/credentials:deliver[^\n]*--by=/',
        // El id interno como responsable, en un ejemplo.
        '/--responsible=<id/',
    ];

    $offenders = [];

    foreach (['backend/app', 'backend/lang', 'backend/routes', 'docs', 'infra'] as $root) {
        foreach (consoleSignatureExamplesFiles($root) as $file) {
            $contents = (string) file_get_contents(Repo::root().'/'.$file);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = $file.' ('.$pattern.')';
                }
            }
        }
    }

    expect($offenders)->toBe([], 'Ejemplos con la firma por consola de la 2.2.1: '.implode(', ', $offenders));
})->group('RL-04', 'RF-QR-06');
