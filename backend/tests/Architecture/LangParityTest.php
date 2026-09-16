<?php

declare(strict_types=1);

/*
 * **Los textos del servidor existen en los dos idiomas, clave por clave**
 * (**RF-KI-05**, doc 01 §6.5).
 *
 * ## Que se rompe cuando esto no se comprueba
 *
 * El producto se vende con «mínimo español e inglés» y el idioma se resuelve por
 * instalación (`LOCALE_DEFAULT`), no por pantalla: un hotel entero puede estar
 * operando en inglés. Cuando una clave existe solo en español, Laravel **no
 * falla**: devuelve la propia clave. El cliente inglés no ve un error, ve
 * `kiosk.health.reason.battery_low` impreso en la salida de `kiosk:health` o en
 * una respuesta de la API, y no tiene forma de saber que eso era un texto.
 *
 * Es justo el fallo que se cuela al añadir una razón, un aviso o una sonda: se
 * escribe el texto español mientras se implementa y la traducción se deja «para
 * luego». La tarea 3.3 añade claves a `kiosk.php` y a `doctor.php`, y esta
 * prueba es lo que las ata.
 *
 * ## Por que es de arquitectura y no de feature
 *
 * Porque no se comprueba lo que hace un endpoint sino una **invariante del
 * árbol**: todo fichero de `lang/es/` tiene su par en `lang/en/` con las mismas
 * claves. No hace falta base de datos, ni framework, ni HTTP —esta suite corre
 * sobre PHPUnit puro— y se ejecuta en segundos sobre los 17 ficheros.
 *
 * ## Se leen los ficheros, no `__()`
 *
 * Lo que se vigila es el CONTENIDO del fichero. Un traductor con idioma de
 * respaldo devolvería el texto español para la clave que falta en inglés, que es
 * exactamente el fallo que se busca: preguntarle a él sería preguntarle al
 * acusado.
 *
 * ## Aplanadas, porque los ficheros anidan
 *
 * `doctor.php` y `kiosk.php` agrupan por familia (`health.reason.silent`). Una
 * comparación de claves de primer nivel daría por buenos dos ficheros con las
 * mismas ocho familias y treinta textos de diferencia dentro.
 *
 * ## Deuda: ninguna, y se deja dicho
 *
 * Al escribirla (16-09-2026) los 17 pares estaban completos y sin textos vacíos,
 * así que **no hay ninguna exclusión**. Si algún día hiciera falta una, va con su
 * motivo escrito al lado y no como un `skip` mudo: una exclusión sin motivo es
 * una clave sin traducir con permiso.
 */

/**
 * Ficheros de `lang/<locale>/`, por nombre.
 *
 * @return list<string>
 */
function ficherosDeIdioma(string $locale): array
{
    $files = glob(\dirname(__DIR__, 2).'/lang/'.$locale.'/*.php');

    return array_map(basename(...), $files === false ? [] : $files);
}

/**
 * Las claves de un fichero de traducción, aplanadas con punto.
 *
 * Un array vacío se trata como hoja: `[]` es un valor legítimo —una lista de
 * avisos sin ninguno— y descender en él no daría ninguna clave, de modo que el
 * fichero que lo tuviera solo en un idioma pasaría inadvertido.
 *
 * @param  array<array-key, mixed>  $translations
 * @return array<string, mixed>
 */
function clavesAplanadas(array $translations, string $prefix = ''): array
{
    $flat = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        $flat = \is_array($value) && $value !== []
            ? [...$flat, ...clavesAplanadas($value, $path)]
            : [...$flat, $path => $value];
    }

    return $flat;
}

/**
 * El contenido de un fichero de traducción, aplanado.
 *
 * @return array<string, mixed>
 */
function traduccionesDe(string $locale, string $file): array
{
    $path = \dirname(__DIR__, 2).'/lang/'.$locale.'/'.$file;

    expect(is_file($path))->toBeTrue('No existe lang/'.$locale.'/'.$file);

    /** @var mixed $translations */
    $translations = require $path;

    expect($translations)->toBeArray('lang/'.$locale.'/'.$file.' no devuelve un array.');

    /** @var array<array-key, mixed> $translations */
    return clavesAplanadas($translations);
}

/**
 * Los textos de un fichero que están en blanco.
 *
 * @return list<string>
 */
function textosEnBlancoDe(string $locale, string $file): array
{
    return array_keys(array_filter(
        traduccionesDe($locale, $file),
        static fn (mixed $text): bool => \is_string($text) && trim($text) === '',
    ));
}

// --- Los ficheros ------------------------------------------------------------

it('tiene pareja inglesa para cada fichero de textos en español', function (): void {
    // Un fichero nuevo solo en español es el caso mas silencioso de todos: no
    // falta una clave, falta el idioma entero, y quien lo añadió no vuelve a
    // mirarlo.
    expect(ficherosDeIdioma('en'))->toBe(ficherosDeIdioma('es'));
})->group('RF-KI-05');

// --- Las claves, fichero a fichero ------------------------------------------

it('traduce al ingles todas las claves del fichero español, y ni una de mas', function (string $file): void {
    // Las dos direcciones. Sobran también importa: una clave que quedó solo en
    // inglés es texto muerto que alguien mantendrá durante años creyendo que se
    // usa, o el rastro de un renombrado a medias en español.
    $es = array_keys(traduccionesDe('es', $file));
    $en = array_keys(traduccionesDe('en', $file));

    sort($es);
    sort($en);

    expect($en)->toBe($es, 'Las claves de lang/en/'.$file.' no coinciden con las de lang/es/'.$file.'.');
})->with(ficherosDeIdioma('es'))->group('RF-KI-05');

// --- Los textos --------------------------------------------------------------

it('no deja ningun texto en blanco en el fichero español', function (string $file): void {
    // Una cadena vacía pasa cualquier comprobación de claves y en pantalla es un
    // hueco: el aviso que no dice nada, el botón sin etiqueta.
    expect(textosEnBlancoDe('es', $file))->toBe([]);
})->with(ficherosDeIdioma('es'))->group('RF-KI-05');

it('no deja ningun texto en blanco en el fichero ingles', function (string $file): void {
    // Separado del español a propósito: el hueco en inglés es el que nadie ve
    // aquí, porque el desarrollo se hace en español.
    expect(textosEnBlancoDe('en', $file))->toBe([]);
})->with(ficherosDeIdioma('es'))->group('RF-KI-05');
