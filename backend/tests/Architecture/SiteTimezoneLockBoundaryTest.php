<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * **SOLO DOS CASOS DE USO TOCAN LA ZONA HORARIA DEL CENTRO** (RN-23, ADR-056).
 *
 * `Site::relocateTo()` se fia de que quien llama le diga si hay registro
 * horario, y eso solo es verdad si se pregunto con la fila del centro
 * bloqueada: lo hace `UpdateSiteHandler`. `Site::correctTimezone()` no mira el
 * bloqueo, y por eso solo puede llamarla `CorrectSiteTimezoneHandler`, que exige
 * motivo, responsable y confirmacion y deja asiento. Un tercer sitio que llamara
 * a cualquiera de las dos seria una puerta lateral a la regla, y nada fallaria
 * hasta que alguien comparase dos exportaciones para la Inspeccion.
 *
 * Se recorre todo `app/`, no solo los modulos: un comando del armazon o un
 * proveedor tambien serian esa puerta.
 */

/**
 * Los ficheros de `app/` que llaman al metodo, relativos a `app/`.
 *
 * @return list<string>
 */
function siteTimezoneCallersOf(string $method): array
{
    $callers = [];
    $call = '/(?:->|::)'.preg_quote($method, '/').'\s*\(/';

    foreach (siteTimezoneSources() as $file => $source) {
        // Primero el texto, que es barato; despues el codigo sin comentarios,
        // que es lo que cuenta: un docblock que nombra el metodo no lo llama.
        if (preg_match($call, $source) === 1 && preg_match($call, siteTimezoneCodeOf($source)) === 1) {
            $callers[] = $file;
        }
    }

    return $callers;
}

/**
 * Los fuentes de `app/`, leidos una vez para las tres pruebas: recorrer el
 * arbol cuesta mas que buscar en el.
 *
 * @return array<string, string>
 */
function siteTimezoneSources(): array
{
    /** @var array<string, string>|null $sources */
    static $sources = null;

    if ($sources === null) {
        $app = dirname(__DIR__, 2).'/app';
        $sources = [];

        foreach (ModuleTree::phpFilesUnder($app) as $file) {
            $sources[ModuleTree::relative($file, $app)] = (string) file_get_contents($file);
        }
    }

    return $sources;
}

/**
 * El fuente sin comentarios.
 */
function siteTimezoneCodeOf(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

it('solo UpdateSiteHandler cambia la zona con el bloqueo resuelto', function (): void {
    expect(siteTimezoneCallersOf('relocateTo'))
        ->toBe(['Modules/Workforce/Application/UseCase/UpdateSiteHandler.php']);
})->group('RN-23');

it('solo CorrectSiteTimezoneHandler corrige la zona saltandose el bloqueo', function (): void {
    expect(siteTimezoneCallersOf('correctTimezone'))
        ->toBe(['Modules/Workforce/Application/UseCase/CorrectSiteTimezoneHandler.php']);
})->group('RN-23');

it('solo CorrectSiteTimezoneHandler guarda por la puerta de la correccion, y solo el repositorio llama a la funcion', function (): void {
    // `saveTimezoneCorrection()` llama a `site_correct_timezone`, la funcion
    // `SECURITY DEFINER` que salta el *trigger* `sites_guard_timezone`
    // (bloque 2 de la 2.3.0). Un segundo sitio que la usara cambiaria la zona
    // con registro horario sin motivo ni asiento.
    expect(siteTimezoneCallersOf('saveTimezoneCorrection'))
        ->toBe(['Modules/Workforce/Application/UseCase/CorrectSiteTimezoneHandler.php']);

    // Quien la invoca: por la constante del repositorio o con un `SELECT`
    // directo. `product:doctor` la nombra en el catalogo sin llamarla.
    $funcion = [];

    foreach (siteTimezoneSources() as $file => $source) {
        $code = siteTimezoneCodeOf($source);

        if (str_contains($code, 'CORRECTION_FUNCTION') || preg_match('/SELECT\s+(?:public\.)?site_correct_timezone\s*\(/i', $code) === 1) {
            $funcion[] = $file;
        }
    }

    expect($funcion)->toBe(['Modules/Workforce/Infrastructure/Persistence/EloquentSiteRepository.php']);
})->group('RN-23');

it('solo el fichaje y el alta manual leen la zona con el candado de escritura', function (): void {
    // `timezoneForRecording()` toma `FOR KEY SHARE`, que falla en una
    // transaccion de solo lectura: llamarla desde una consulta del registro
    // romperia esa consulta. Y quien escribe un tramo sin ella vuelve a abrir la
    // carrera con el cambio de zona.
    expect(siteTimezoneCallersOf('timezoneForRecording'))->toBe([
        'Modules/Attendance/Application/UseCase/AddShiftEntryHandler.php',
        'Modules/Attendance/Application/UseCase/RegisterScanHandler.php',
    ]);
})->group('RN-23');
