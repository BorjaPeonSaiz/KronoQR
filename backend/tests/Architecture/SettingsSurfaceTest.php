<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SettingKey;
use Tests\Architecture\Support\ClientDocs;
use Tests\Architecture\Support\SettingsSurface;

/*
 * Cada clave de `installation_settings` tiene una via de cambio, y la guia dice
 * cual (RF-PD-01, RF-PD-02).
 *
 * POR QUE ESTE FICHERO EXISTE. `ClientDocumentationTest` ya exige que la guia
 * EXPLIQUE cada clave del catalogo, y con eso solo se demostraba que el cliente
 * sabe lo que significa `ATTENDANCE_DEBOUNCE_SECONDS`. No que pueda cambiarlo.
 * En la revision del cierre de la Fase 5 se vio que seis de las nueve claves
 * —las cuatro `ATTENDANCE_*` y las dos `LOCALE_*`— estaban documentadas, tenian
 * endpoint, tenian auditoria y **no tenian pantalla**: la unica forma de
 * tocarlas era un `curl` con un token de administrador. La guia, mientras tanto,
 * decia «se editan desde el panel».
 *
 * Es el fallo que ninguna prueba de las que habia podia ver, porque cada mitad
 * estaba bien: el endpoint tiene su feature y su autorizacion negativa, el
 * catalogo tiene su unitaria, la guia tiene su prueba de documentacion. Lo que
 * faltaba era la costura, y una costura solo la comprueba algo que mire los dos
 * lados a la vez. Eso es lo que hay aqui: el enum de PHP contra el arbol del
 * panel y contra la tabla de la guia.
 *
 * Y POR QUE NO ESTA EN `ClientDocumentationTest`. Porque lo que se afirma no es
 * una propiedad de la documentacion sino del PRODUCTO —que una propiedad
 * configurable se puede configurar—, y porque aquel fichero pasa ya de las
 * setenta pruebas. El ayudante {@see SettingsSurface} lleva el recorrido del
 * arbol, igual que `ClientDocs` lleva el de las guias.
 *
 * ADR-017 Y REGLA DURA 13. Nada especifico de un cliente vive en el codigo: la
 * marca, los umbrales operativos y los idiomas son configuracion. Una clave de
 * configuracion que solo se cambia por API es, para el cliente que compra el
 * producto, una que hay que pedirle al fabricante.
 */

/*
 * Las nueve claves, una prueba por clave.
 *
 * Un dataset y no un bucle porque el nombre de la clave tiene que salir en el
 * informe: «(ATTENDANCE_DEBOUNCE_SECONDS)» dice cual falta sin abrir nada, y un
 * bucle diria solo que una de nueve fallo. La lista sale de `SettingKey::cases()`
 * y no de un literal: una clave nueva entra sola.
 */
dataset('las claves de installation_settings', array_combine(
    array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases()),
    array_map(static fn (SettingKey $key): array => [$key], SettingKey::cases()),
));

/*
 * La referencia de configuracion en las dos lenguas.
 *
 * Nombre propio y no el de `ClientDocumentationTest`: los datasets de Pest se
 * registran por nombre en un ambito global, y dos con el mismo nombre se pisan.
 */
dataset('el catalogo de configuracion en las dos lenguas', [
    'es' => ['docs/cliente/configuracion.md'],
    'en' => ['docs/cliente/en/configuration.md'],
]);

it('deja cambiar desde el panel cada clave de installation_settings', function (SettingKey $key): void {
    // LA COMPROBACION QUE FALTABA. No basta con que el endpoint acepte la clave:
    // el cliente compra un producto que se configura solo (ADR-017), y una clave
    // sin pantalla es una clave que hay que pedir por telefono.
    //
    // El asistente de puesta en marcha no cuenta —ver `SettingsSurface::WIZARD`—
    // porque es de un solo uso: escribe tres de estas claves y despues se cierra
    // para siempre.
    $writers = SettingsSurface::writerViews($key->value);

    expect($writers)->not->toBe([], 'Ninguna vista de '.SettingsSurface::PANEL_FEATURES
        .' escribe '.$key->value.'. Se documenta, tiene endpoint y deja asiento, pero no hay '
        .'pantalla que la cambie: la pactada es '.SettingsSurface::screenFor($key->value).'.');

    // Y ademas es LA pactada. Que la escriba otra pantalla cualquiera dejaria la
    // guia mintiendo, que es la mitad de esta prueba que mira al cliente.
    $screen = SettingsSurface::screenFor($key->value);
    $expected = array_filter($writers, static fn (string $view): bool => str_ends_with($view, '/'.$screen));

    expect($expected)->not->toBe([], $key->value.' se escribe desde '.implode(', ', $writers)
        .', pero la pantalla pactada es '.$screen.' y la guia remite a ella.');
})->with('las claves de installation_settings')->group('RF-PD-01', 'RF-PD-02');

it('no toma por via de cambio una clave nombrada en un comentario', function (): void {
    // EL GUARDA DEL GUARDA. Si la deteccion se conformara con encontrar el nombre
    // de la clave en el fichero, la prueba de arriba estaria en verde desde antes
    // de que existiera ninguna pantalla: media docena de vistas citan
    // `LOCALE_AVAILABLE` en un comentario para explicar de donde salen los
    // idiomas. Aqui se fija la diferencia entre nombrar y escribir.
    $mention = "// Un boton por cada idioma ACTIVO de la instalacion (`LOCALE_AVAILABLE`).\n";
    $documented = "/** La forma que exige el contrato para BRANDING_ACCENT_COLOR. */\n";
    $comparison = "if (value === current['LOCALE_DEFAULT']) { return }\n";

    $assignment = "changes['LOCALE_DEFAULT'] = defaultLocale.value\n";
    $fieldMap = "const fields = { 'settings.BRANDING_APP_NAME': t('branding.fields.appName') }\n";
    $body = "await updateInstallationSettings({ 'ATTENDANCE_DEBOUNCE_SECONDS': seconds })\n";

    expect(SettingsSurface::writes($mention, 'LOCALE_AVAILABLE'))->toBeFalse('Una mencion en un comentario no es una via de cambio.')
        ->and(SettingsSurface::writes($documented, 'BRANDING_ACCENT_COLOR'))->toBeFalse('Un docblock no es una via de cambio.')
        ->and(SettingsSurface::writes($comparison, 'LOCALE_DEFAULT'))->toBeFalse('Leer el valor actual para compararlo no es escribirlo.')
        ->and(SettingsSurface::writes($assignment, 'LOCALE_DEFAULT'))->toBeTrue('El cuerpo del PATCH se arma asi.')
        ->and(SettingsSurface::writes($fieldMap, 'BRANDING_APP_NAME'))->toBeTrue('El mapa de campos del 422 solo lleva lo que se envia.')
        ->and(SettingsSurface::writes($body, 'ATTENDANCE_DEBOUNCE_SECONDS'))->toBeTrue('La clave dentro del objeto que se envia.');
})->group('RF-PD-01');

it('enruta las dos pantallas por las que se cambia la configuracion', function (): void {
    // Una vista que no cuelga de ninguna ruta no es una via de cambio: no hay
    // forma de llegar a ella desde el panel. Se comprueban el fichero y la ruta
    // porque son las dos mitades de «se puede llegar».
    $router = ClientDocs::contents(SettingsSurface::PANEL_ROUTER);

    $missing = [];

    foreach (SettingsSurface::screens() as $screen) {
        if (! ClientDocs::exists(SettingsSurface::PANEL_FEATURES.'/settings/'.$screen['view'])) {
            $missing[] = $screen['view'].' (el fichero no existe)';

            continue;
        }

        if (! str_contains($router, "path: '".ltrim($screen['route'], '/')."'")) {
            $missing[] = $screen['view'].' (sin ruta '.$screen['route'].' en el router)';
        }
    }

    expect($missing)->toBe([], 'Pantalla(s) de configuracion sin via de acceso: '.implode(', ', $missing));
})->group('RF-PD-01', 'RF-PD-02');

it('dice en la guia por donde se cambia cada clave de installation_settings', function (string $guide, SettingKey $key): void {
    // RF-PD-02: el IT del cliente opera «siguiendo una guia, sin intervencion del
    // fabricante». Explicar que hace la clave no basta si no dice donde se toca,
    // y la ruta del panel es lo unico que no cambia con la traduccion ni con el
    // nombre que lleve el menu.
    $row = SettingsSurface::settingsCatalogueRow($guide, $key->value);

    expect($row)->not->toBe('', $key->value.' no tiene fila en el catalogo del apartado 6.0 de '.$guide.'.');

    $route = SettingsSurface::routeFor($key->value);

    expect(str_contains($row, $route))->toBeTrue(
        'La fila de '.$key->value.' en el apartado 6.0 de '.$guide.' no dice por donde se edita: '
        .'se espera la ruta '.$route.' del panel y la fila es «'.$row.'».'
    );
})->with('el catalogo de configuracion en las dos lenguas')
    ->with('las claves de installation_settings')
    ->group('RF-PD-01', 'RF-PD-02');
