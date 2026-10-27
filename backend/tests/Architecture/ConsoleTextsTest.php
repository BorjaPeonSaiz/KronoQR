<?php

declare(strict_types=1);

use Tests\Architecture\Support\ConsoleLiterals;
use Tests\Architecture\Support\ModuleTree;

/*
 * LAS ORDENES DE CONSOLA HABLAN EL IDIOMA DE LA INSTALACION (RF-KI-05, ADR-017).
 *
 * El operador del hotel lee la salida de la purga, de las credenciales, de la
 * licencia o del soporte, y el informe de purga se archiva como constancia. El
 * producto se vende con español e inglés y el idioma lo elige el cliente en el
 * panel; una orden con literales en español le habla a una instalacion inglesa
 * en un idioma que nadie eligio. `product:doctor`, `kiosk:health` y
 * `product:errors` ya leian sus textos de `lang/`; esta prueba impide que una
 * orden nueva, o una linea nueva de una existente, vuelva a escribirlos a mano.
 *
 * Y comprueba lo contrario: que cada clave que una orden pide escrita tal cual
 * existe en los dos idiomas. Laravel no falla con una clave que falta, la
 * imprime, y el operador veria `console.license_show.title`.
 *
 * QUE QUEDA FUERA, y por que:
 *
 * - `app/Console/Commands/Quality/`: `qa:traceability` y `docs:consistency`
 *   son herramientas del equipo que corren en la CI sobre la documentacion del
 *   repositorio, que esta en español; no las ejecuta el hotel.
 * - El motivo por defecto de una baja de cuenta sin `--reason` («Sin motivo
 *   declarado»): no se escribe en pantalla, se guarda en `audit_log` como
 *   motivo del asiento, y el registro legal no cambia de idioma con el panel.
 */

const CONSOLE_TEXTS_EXEMPT_DIRECTORY = 'Console/Commands/Quality/';

const CONSOLE_TEXTS_ALLOWED_LITERALS = ['Sin motivo declarado'];

/** Ademas de las ordenes, lo que escriben para que una persona lo lea. */
const CONSOLE_TEXTS_EXTRA_FILES = ['Modules/Compliance/Infrastructure/Retention/FileRetentionReportStore.php'];

/**
 * Las ordenes de Artisan de `app/` y el informe de purga, por ruta relativa.
 *
 * @return array<string, string> ruta relativa => contenido
 */
function consoleTextsSources(): array
{
    // Una sola pasada por el arbol para las tres pruebas: sobre el montaje de
    // Docker Desktop recorrer `app/` cuesta segundos.
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $root = dirname(ModuleTree::root());
    $sources = [];

    foreach (ModuleTree::phpFilesUnder($root) as $file) {
        $relative = ModuleTree::relative($file, $root);
        $code = (string) file_get_contents($file);

        $isCommand = preg_match('~\bextends\s+(?:Command|AbstractManagementAccountCommand)\b~', $code) === 1;

        if (($isCommand || in_array($relative, CONSOLE_TEXTS_EXTRA_FILES, true))
            && ! str_starts_with($relative, CONSOLE_TEXTS_EXEMPT_DIRECTORY)) {
            $sources[$relative] = $code;
        }
    }

    ksort($sources);

    return $cache = $sources;
}

it('reconoce la prosa y deja pasar claves, formatos y lineas para scripts (red de seguridad)', function (): void {
    foreach (['Licencia de KronoQR', 'Cliente: ', '%-22s contratado: %-6s', 'Usa --reissue para sustituirla.'] as $texto) {
        expect(ConsoleLiterals::isProse($texto))->toBeTrue($texto);
    }

    foreach ([
        'console.license_show.title', '%-22s %s', '  - ', 'revoked_credentials=%d employees=%d failed=%d',
        '  retention.purge_executed #', ' (SQLSTATE %s)', 'audit_chain=intact rows=%d',
    ] as $texto) {
        expect(ConsoleLiterals::isProse($texto))->toBeFalse($texto);
    }

    $codigo = <<<'PHP'
        <?php
        final class X extends Command
        {
            protected $description = 'Ayuda de artisan, que no se mira';
            public function handle(): void
            {
                $this->line('Texto a mano para el operador');
                Log::info('evento tecnico con espacios', ['clave con espacio' => 1]);
                throw new RuntimeException('Detalle tecnico de la excepcion');
            }
        }
        PHP;

    expect(ConsoleLiterals::proseIn($codigo))->toBe(['7 «Texto a mano para el operador»']);
})->group('RF-KI-05');

it('ha encontrado las ordenes que tiene que mirar', function (): void {
    $fuentes = consoleTextsSources();

    expect(count($fuentes))->toBeGreaterThan(40)
        ->and(array_keys($fuentes))->toContain(
            'Modules/Product/Infrastructure/Console/LicenseShowCommand.php',
            'Modules/Compliance/Infrastructure/Console/ApplyRetentionCommand.php',
            'Modules/Compliance/Infrastructure/Retention/FileRetentionReportStore.php',
            'Console/Commands/Backup/BackupRunCommand.php',
        );
})->group('RF-KI-05');

it('ninguna orden de consola escribe prosa a mano', function (): void {
    $literales = [];

    foreach (consoleTextsSources() as $ruta => $codigo) {
        foreach (ConsoleLiterals::proseIn($codigo, CONSOLE_TEXTS_ALLOWED_LITERALS) as $hallazgo) {
            $literales[] = $ruta.':'.$hallazgo;
        }
    }

    expect($literales)->toBe([], 'Textos de consola escritos a mano: van a lang/{es,en}/console.php');
})->group('RF-KI-05');

it('cada clave que pide una orden existe en español y en ingles', function (): void {
    $lang = dirname(ModuleTree::root(), 2).'/lang';
    $faltan = [];
    $comprobadas = 0;

    foreach (consoleTextsSources() as $ruta => $codigo) {
        foreach (ConsoleLiterals::translationKeysIn($codigo) as $clave) {
            $comprobadas++;

            foreach (['es', 'en'] as $idioma) {
                if (! ConsoleLiterals::keyExists($lang, $idioma, $clave)) {
                    $faltan[] = $ruta.': '.$idioma.' '.$clave;
                }
            }
        }
    }

    expect($comprobadas)->toBeGreaterThan(200)
        ->and($faltan)->toBe([], 'Claves de consola que no existen');
})->group('RF-KI-05');
