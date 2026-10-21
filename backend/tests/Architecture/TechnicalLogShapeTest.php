<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * LA FORMA DEL LOG TECNICO: UN IDENTIFICADOR DE EVENTO Y NUNCA EL MENSAJE DE
 * UNA EXCEPCION (tarea 10.6; V4-BE-3, L6, ADR-048, regla dura 21).
 *
 * - **El mensaje de cada apunte es un identificador** (`modulo.evento`), no una
 *   frase. El saneador del log deja pasar solo las palabras de
 *   `ErrorVocabulary`, y una frase con palabras que no estan —«difundir»,
 *   «cerrarse»— llegaba a Loki como `No se … podido …`. Los identificadores los
 *   cubre `ErrorVocabularyCoverageTest`.
 * - **Ningun `getMessage()` en el contexto de un apunte.** El mensaje de una
 *   excepcion lleva lo que el driver o la libreria quisieron poner: el host de
 *   Redis, el SQL con sus valores, la salida de Browsershot con nombres. La
 *   clase basta para buscar, y la causa esta en `error_events`, saneada.
 */

const TECHNICAL_LOG_SHAPE_LEVELS = 'debug|info|notice|warning|error|critical|alert|emergency';

/**
 * Los ficheros PHP de `app/`, `ruta relativa => codigo`.
 *
 * @return array<string, string>
 */
function technicalLogShapeFiles(): array
{
    $app = \dirname(ModuleTree::root());
    $files = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        $files[ModuleTree::relative($file, $app)] = (string) file_get_contents($file);
    }

    return $files;
}

it('escribe en el log identificadores de evento y no frases', function (): void {
    $frases = [];

    foreach (technicalLogShapeFiles() as $file => $source) {
        // Solo el logger: `$this->info()` de una orden de consola es su salida,
        // no un apunte.
        preg_match_all('/(?:logger->|Log::|Log::channel\([^)]*\)->)(?:'.TECHNICAL_LOG_SHAPE_LEVELS.")\\(\\s*'([^']*)'/", $source, $matches);

        foreach ($matches[1] as $message) {
            if (preg_match('/^[a-z][a-z0-9_]*(?:[.:][a-z0-9_]+)*$/', $message) !== 1) {
                $frases[] = $file.': '.$message;
            }
        }
    }

    expect($frases)->toBe([], "Apuntes con una frase en lugar de un identificador de evento:\n".implode("\n", $frases));
})->group('RF-PD-15', 'RS-05');

it('no escribe en el contexto de un apunte el mensaje de una excepcion', function (): void {
    $conMensaje = [];

    foreach (technicalLogShapeFiles() as $file => $source) {
        if (preg_match_all("/^\\s*'[a-z_]+' => \\\$\\w+->getMessage\\(\\),?$/m", $source, $matches) > 0) {
            $conMensaje[] = $file;
        }
    }

    expect($conMensaje)->toBe([], 'La clase de la excepcion, nunca su mensaje (L6, regla dura 21).');
})->group('RF-PD-15', 'RS-05');

it('reconoce una frase y un getMessage (red de seguridad de la prueba)', function (): void {
    expect(preg_match('/^[a-z][a-z0-9_]*(?:[.:][a-z0-9_]+)*$/', 'No se ha podido difundir.'))->toBe(0)
        ->and(preg_match('/^[a-z][a-z0-9_]*(?:[.:][a-z0-9_]+)*$/', 'reporting.presence_broadcast_failed'))->toBe(1)
        ->and(preg_match("/^\\s*'[a-z_]+' => \\\$\\w+->getMessage\\(\\),?$/m", "    'reason' => \$failure->getMessage(),"))->toBe(1);
})->group('RF-PD-15');
