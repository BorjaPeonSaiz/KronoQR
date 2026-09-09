<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use RuntimeException;
use Tests\Architecture\SettingsSurfaceTest;
use Tests\Architecture\TestDiscoveryTest;

/**
 * Por donde se cambia cada clave de `installation_settings` (RF-PD-01).
 *
 * POR QUE ESTA CLASE EXISTE. Igual que {@see ClientDocs}, para que las pruebas
 * de {@see SettingsSurfaceTest} no lleven bucles ni
 * condicionales: el recorrido del arbol del panel, la deteccion de la escritura
 * y el troceado de la tabla de la guia viven aqui, y cada prueba se queda con
 * una lista y una aseveracion.
 *
 * EL ARBOL SE RECORRE CON `scandir` Y NO CON `RecursiveDirectoryIterator`. Sobre
 * el *bind mount* de Docker Desktop el iterador pierde ficheros sin avisar —lo
 * sufrio la suite Feature entera, ver {@see TestDiscoveryTest}—
 * y aqui un fichero perdido no se veria: la vista que escribe la clave se
 * declararia ausente y la prueba se pondria roja por el sistema de ficheros. Con
 * `scandir` recursivo no pasa.
 */
final class SettingsSurface
{
    /** Donde viven las pantallas del panel. */
    public const string PANEL_FEATURES = 'frontend-admin/src/features';

    /** El registro de rutas del panel: una vista sin ruta no es una via de cambio. */
    public const string PANEL_ROUTER = 'frontend-admin/src/router/index.ts';

    /**
     * El asistente de puesta en marcha, que NO cuenta como via de cambio.
     *
     * Escribe tres claves —el nombre visible y los dos idiomas— pero es de un
     * solo uso: se cierra con `POST /api/v1/setup/complete` y no vuelve a
     * abrirse. Una instalacion en marcha que quiera cambiar su idioma por
     * defecto no puede volver ahi, asi que darlo por bueno dejaria esas claves
     * sin pantalla durante toda la vida del producto, que es exactamente el
     * agujero que estas pruebas cierran.
     */
    private const string WIZARD = self::PANEL_FEATURES.'/onboarding/';

    /**
     * La pantalla por la que se cambia cada clave, y su ruta en el panel.
     *
     * Es el PACTO, escrito una sola vez: `BrandingView` (tarea 5.8) lleva la
     * marca y `OperationalSettingsView` los umbrales operativos y los idiomas.
     * Una clave nueva obliga a decidir aqui por donde se cambia antes de que la
     * suite pase, que es justo el momento en que esa decision cuesta poco.
     *
     * @var array<string, array{view: string, route: string}>
     */
    private const array SCREENS = [
        'ATTENDANCE_MAX_SHIFT_HOURS' => ['view' => 'OperationalSettingsView.vue', 'route' => '/settings'],
        'ATTENDANCE_DEBOUNCE_SECONDS' => ['view' => 'OperationalSettingsView.vue', 'route' => '/settings'],
        'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES' => ['view' => 'OperationalSettingsView.vue', 'route' => '/settings'],
        'ATTENDANCE_MIN_TRANSIT_SECONDS' => ['view' => 'OperationalSettingsView.vue', 'route' => '/settings'],
        'BRANDING_APP_NAME' => ['view' => 'BrandingView.vue', 'route' => '/branding'],
        'BRANDING_LOGO_PATH' => ['view' => 'BrandingView.vue', 'route' => '/branding'],
        'BRANDING_ACCENT_COLOR' => ['view' => 'BrandingView.vue', 'route' => '/branding'],
        'LOCALE_DEFAULT' => ['view' => 'OperationalSettingsView.vue', 'route' => '/settings'],
        'LOCALE_AVAILABLE' => ['view' => 'OperationalSettingsView.vue', 'route' => '/settings'],
    ];

    /** La pantalla pactada para una clave, o cadena vacia si nadie la ha decidido. */
    public static function screenFor(string $key): string
    {
        return self::SCREENS[$key]['view'] ?? '';
    }

    /** La ruta del panel pactada para una clave, o cadena vacia. */
    public static function routeFor(string $key): string
    {
        return self::SCREENS[$key]['route'] ?? '';
    }

    /**
     * Las pantallas pactadas, sin repetir.
     *
     * @return list<array{view: string, route: string}>
     */
    public static function screens(): array
    {
        $screens = [];

        foreach (self::SCREENS as $screen) {
            $screens[$screen['view']] = $screen;
        }

        return array_values($screens);
    }

    /**
     * Las vistas del panel que escriben una clave, en rutas relativas a la raiz.
     *
     * Fuera queda el asistente, por lo que dice {@see WIZARD}.
     *
     * @return list<string>
     */
    public static function writerViews(string $key): array
    {
        $writers = [];

        foreach (self::panelViews() as $view) {
            if (self::writes(ClientDocs::contents($view), $key)) {
                $writers[] = $view;
            }
        }

        return $writers;
    }

    /**
     * Si un fuente `.vue` ESCRIBE esa clave, no si la nombra.
     *
     * La diferencia es el punto entero de esta comprobacion: media docena de
     * ficheros del panel citan `LOCALE_AVAILABLE` en un comentario para explicar
     * de donde salen los idiomas, y ninguno de ellos deja cambiarlo. Se acusa
     * escritura solo en dos formas, que son las dos que el panel usa de verdad:
     *
     *   - `changes['CLAVE'] = valor` — el cuerpo del `PATCH /api/v1/settings`.
     *   - `'CLAVE':` o `'settings.CLAVE':` — la clave dentro de un objeto: el
     *     cuerpo escrito de una vez, o el mapa que traduce el `422` del servidor
     *     al campo del formulario, que solo se escribe para lo que se envia.
     *
     * Una mencion en prosa no tiene ninguna de las dos formas, y por eso el
     * comentario de `CredentialBoardView` no convierte esa pantalla en una via
     * para cambiar los idiomas de la instalacion.
     */
    public static function writes(string $source, string $key): bool
    {
        $quoted = preg_quote($key, '/');

        // `[^=]` al final descarta una comparacion (`===`): comparar el valor
        // actual con el nuevo no es escribirlo.
        $assignment = '/\[\s*\''.$quoted.'\'\s*\]\s*=[^=]/';
        $inObject = '/\'(?:settings\.)?'.$quoted.'\'\s*:/';

        return preg_match($assignment, $source) === 1 || preg_match($inObject, $source) === 1;
    }

    /**
     * Todas las vistas `.vue` del panel salvo las del asistente, ordenadas.
     *
     * @return list<string>
     */
    public static function panelViews(): array
    {
        $views = [];

        foreach (self::tree(self::PANEL_FEATURES) as $file) {
            if (! str_ends_with($file, '.vue') || str_starts_with($file, self::WIZARD)) {
                continue;
            }

            $views[] = $file;
        }

        sort($views);

        return $views;
    }

    /**
     * La fila de la tabla del apartado 6.0 de la guia que habla de una clave.
     *
     * Cadena vacia si no hay ninguna, para que la prueba lo diga con su propio
     * mensaje en lugar de reventar. Se acota al apartado —de `### 6.0` a la
     * cabecera siguiente— a proposito: la clave aparece tambien en la seccion 2
     * y en la referencia del `.env`, y lo que se comprueba aqui es el CATALOGO,
     * que es la tabla que el lector usa para saber donde se toca cada cosa.
     */
    public static function settingsCatalogueRow(string $guide, string $key): string
    {
        foreach (explode("\n", self::settingsCatalogue($guide)) as $line) {
            if (str_starts_with(trim($line), '|') && str_contains($line, '`'.$key.'`')) {
                return trim($line);
            }
        }

        return '';
    }

    /** El apartado «6.0» de la guia de configuracion, hasta la cabecera siguiente. */
    public static function settingsCatalogue(string $guide): string
    {
        $content = ClientDocs::contents($guide);
        $start = mb_strpos($content, "\n### 6.0");

        if ($start === false) {
            throw new RuntimeException(
                $guide.' no tiene el apartado "### 6.0" con el catalogo de installation_settings.'
            );
        }

        $section = mb_substr($content, $start + 1);
        $end = mb_strpos($section, "\n### ", 1);

        return $end === false ? $section : mb_substr($section, 0, $end);
    }

    /**
     * Los ficheros de un directorio del repositorio, recursivo y relativos a la raiz.
     *
     * @return list<string>
     */
    private static function tree(string $directory): array
    {
        $absolute = Repo::file($directory);

        if (! is_dir($absolute)) {
            throw new RuntimeException($directory.' no existe en el repositorio.');
        }

        $files = [];

        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = $directory.'/'.$entry;

            $files = is_dir($absolute.'/'.$entry)
                ? [...$files, ...self::tree($relative)]
                : [...$files, $relative];
        }

        return $files;
    }
}
