<?php

declare(strict_types=1);

/*
 * Ninguna plantilla Blade abre un sumidero de HTML sin escapar (RS-04, ADR-016;
 * H-07 de la revision interna ASVS de 2026-09).
 *
 * ## Por que existe
 *
 * `{{ $x }}` escapa; `{!! $x !!}` no. Las ocho plantillas de
 * `resources/views/pdf/` componen la tarjeta de credencial, la hoja de
 * instrucciones y el informe por periodo, y todas ellas pintan datos que **no
 * pone quien escribe la plantilla**: el nombre de un empleado importado de un
 * CSV, el nombre del centro, y sobre todo el **logotipo de marca**, que entra
 * como bytes SVG desde un fichero que sube el cliente.
 *
 * Hoy el logotipo se consume siempre por `<img src>` o `data:` en un Chromium
 * sin red (ADR-016), asi que un SVG con `<script>` dentro no se ejecuta. Esa es
 * una propiedad del CONSUMO, no de la plantilla: el dia que alguien lo incruste
 * en linea para que herede los colores —que es exactamente la razon por la que
 * se incrusta un SVG— la unica defensa que quedaria seria la lista negra de
 * `LogoFileInspector`, y una lista negra sobre XML es una carrera que se pierde.
 *
 * El frontend ya tiene su equivalente: `vue/no-v-html` como `error` explicito en
 * los cuatro `eslint.config.js`. Blade no lo tenia, y esa asimetria es el
 * hallazgo. Una convencion que no verifica una herramienta es una sugerencia.
 *
 * ## Lo que esta prueba NO afirma
 *
 * Que el contenido que llega a `{{ }}` sea seguro, ni que el SVG del cliente sea
 * inocuo. Solo que **ninguna plantilla renuncia al escapado**. Lo que inspecciona
 * el fichero de marca es `LogoFileInspector` y tiene sus propias pruebas.
 *
 * ## Por que `scandir` y no `RecursiveDirectoryIterator`
 *
 * Por lo mismo que `ModuleTree`: sobre el bind mount de Docker Desktop (NTFS ->
 * contenedor) el iterador pierde ficheros sin avisar, y una prueba de
 * arquitectura que no ve un fichero **pasa en verde**. No hay nada que denunciar
 * en una plantilla que no existe.
 */

/**
 * Todas las plantillas Blade del backend, en rutas relativas a `resources/views`.
 *
 * @return list<string>
 */
function bladeTemplates(): array
{
    $root = \dirname(__DIR__, 2).'/resources/views';

    return bladeTemplatesUnder($root, '');
}

/**
 * @return list<string>
 */
function bladeTemplatesUnder(string $directory, string $prefix): array
{
    $entries = scandir($directory);

    $templates = [];

    foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
        $path = $directory.'/'.$entry;
        $relative = $prefix === '' ? $entry : $prefix.'/'.$entry;

        $templates = [
            ...$templates,
            ...(is_dir($path)
                ? bladeTemplatesUnder($path, $relative)
                : (str_ends_with($entry, '.blade.php') ? [$relative] : [])),
        ];
    }

    sort($templates);

    return $templates;
}

/**
 * Cada uso de `{!! ... !!}`, descrito como «plantilla:linea».
 *
 * El recorrido vive fuera de la prueba por el §3.5 —un bucle con un `if` dentro
 * de un test es una rama que nadie prueba— y ademas es lo que permite que el
 * mensaje de fallo diga **donde**, que es lo primero que hace falta.
 *
 * @param  list<string>  $templates
 * @return list<string>
 */
function rawEchoesIn(array $templates): array
{
    $root = \dirname(__DIR__, 2).'/resources/views';
    $findings = [];

    foreach ($templates as $template) {
        $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($root.'/'.$template)));

        foreach ($lines as $number => $line) {
            $findings[] = str_contains($line, '{!!') ? $template.':'.($number + 1) : null;
        }
    }

    return array_values(array_filter($findings));
}

it('encuentra todas las plantillas Blade que hay que analizar', function (): void {
    // El control que impide que la prueba de abajo pase por vacio, y el que
    // avisaria del fallo del bind mount. Se nombran las ocho de `pdf/` una a una
    // en lugar de contar: una plantilla nueva no debe romper esta prueba, pero
    // que deje de verse una de las que pintan datos del cliente, si. Los
    // parciales entran igual que las plantillas completas —`{!! !!}` dentro de
    // un `@include` es exactamente el mismo sumidero—.
    expect(bladeTemplates())->toContain(
        'pdf/_credential-card-body.blade.php',
        'pdf/_credential-styles.blade.php',
        'pdf/_instructions-icons.blade.php',
        'pdf/credential-card.blade.php',
        'pdf/credential-sheet.blade.php',
        'pdf/instructions-sheet.blade.php',
        'pdf/period-report-footer.blade.php',
        'pdf/period-report.blade.php',
    );
})->group('RS-04');

it('no deja que ninguna plantilla renuncie al escapado de Blade', function (): void {
    /*
     * LA LISTA BLANCA ESTA VACIA, Y ESO ES LA AFIRMACION.
     *
     * No hay ningun uso legitimo de `{!! !!}` en este producto: lo unico que se
     * pinta en un PDF es texto de la plantilla del hotel y un logotipo que viaja
     * por `<img src>`. Si alguna vez hiciera falta uno, no se anade aqui sin
     * mas: se decide, se escribe el motivo y se dice quien sanea la entrada.
     */
    $permitidas = [];

    $crudos = rawEchoesIn(bladeTemplates());

    expect($crudos)->toBe(
        $permitidas,
        \count($crudos).' uso(s) de `{!! !!}` en plantillas Blade: '.implode(', ', $crudos)
        .'. `{{ }}` escapa y `{!! !!}` no; si el dato viene del cliente —nombre importado, '
        .'logotipo SVG— eso es un sumidero de HTML (RS-04, ADR-016).',
    );
})->group('RS-04');
