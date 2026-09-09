<?php

declare(strict_types=1);
use Tests\Architecture\Support\Repo;

/*
 * **LO QUE LA HOJA DEL EMPLEADO NO PUEDE DECIR** (tarea 5.11b, RL-05, ficha
 * 5.11b decision 6).
 *
 * ## Por que una prueba y no una revision
 *
 * Porque este texto se traduce, se reproduce en la guia de RRHH, se retoca
 * cuando cambia una pantalla del quiosco y lo leen personas que no conocen el
 * producto. La tentacion de escribir «tambien puedes llevar la credencial en el
 * movil» o «si olvidas el PIN te lo enviamos por correo» no aparece hoy: aparece
 * dentro de seis meses, en una correccion de una frase, hecha por quien no ha
 * leido ADR-014 ni ADR-015.
 *
 * Y el dano de esa frase no es un error de estilo: **es una promesa impresa y
 * entregada en mano a toda la plantilla** sobre algo que el producto no hace.
 *
 * ## Las tres reglas duras que vigila
 *
 * - **11 — La credencial es una tarjeta fisica** (ADR-014). No hay credencial en
 *   el movil.
 * - **20 — Cero biometria** (ADR-009). Ni huella, ni cara, ni nada.
 * - **ADR-015 — El producto no depende del correo del empleado.** El PIN se
 *   entrega en mano; **la unica frase que puede juntar el PIN con el correo es la
 *   que dice que NUNCA va por ahi**, y esa tiene que estar.
 *
 * ## Frases, no palabras sueltas
 *
 * «Movil» es legitimo —el portal se abre desde el movil, y la hoja lo dice— asi
 * que lo que se prohibe es la CONVIVENCIA de movil con credencial en una misma
 * frase. Buscar la palabra suelta obligaria a reescribir un texto correcto.
 *
 * ## No comprueba la guia
 *
 * De atar cada frase de estos ficheros a `docs/cliente/hoja-empleado.md` se
 * ocupa `ClientDocumentationTest`. Aqui se comprueba el producto: lo que dice el
 * PDF que sale del servidor del cliente.
 */

/**
 * Las frases de un idioma de la hoja, tal y como las lee el renderizador.
 *
 * Se leen del fichero y no con `__()`: esta suite corre sin framework (ver
 * `tests/Pest.php`), y ademas lo que se vigila es el CONTENIDO del fichero, no
 * lo que resuelva un traductor con su idioma de respaldo.
 *
 * @return array<string, string>
 */
function frasesDeLaHoja(string $locale): array
{
    $path = \dirname(__DIR__, 2).'/lang/'.$locale.'/instructions-sheet.php';

    expect(is_file($path))->toBeTrue('No existe lang/'.$locale.'/instructions-sheet.php');

    /** @var mixed $lines */
    $lines = require $path;

    expect($lines)->toBeArray();

    $texts = [];

    foreach ((array) $lines as $key => $line) {
        if (\is_string($key) && \is_string($line)) {
            $texts[$key] = $line;
        }
    }

    expect($texts)->not->toBeEmpty();

    return $texts;
}

/** Sin tildes y en minusculas, para que «móvil» y «movil» sean lo mismo. */
function normalizarFrase(string $line): string
{
    $lower = mb_strtolower($line, 'UTF-8');

    return strtr($lower, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
    ]);
}

it('no menciona la biometria en ningun idioma', function (string $locale): void {
    // Regla dura 20 y ADR-009: no existe y no va a existir. Una hoja que la
    // nombrara —aunque fuera para negarla— abriria la conversacion.
    foreach (frasesDeLaHoja($locale) as $key => $line) {
        $frase = normalizarFrase($line);

        foreach (['biometr', 'huella', 'fingerprint', 'face id', 'reconocimiento facial'] as $prohibida) {
            expect(str_contains($frase, $prohibida))
                ->toBeFalse('La frase «'.$key.'» de '.$locale.' menciona «'.$prohibida.'».');
        }
    }
})->with(['es', 'en'])->group('RL-05', 'RF-QR-06');

it('no ofrece la credencial en el movil en ninguna frase', function (string $locale): void {
    // Regla dura 11 y ADR-014: la credencial es una tarjeta fisica impresa. La
    // palabra «movil» sola es legitima —el portal se abre desde el movil— asi
    // que lo que se prohibe es que aparezca en la MISMA frase que la credencial.
    $movil = ['movil', 'telefono', 'mobile', 'phone', 'smartphone'];
    $credencial = ['credencial', 'tarjeta', 'codigo qr', 'credential', 'card', 'qr code'];

    foreach (frasesDeLaHoja($locale) as $key => $line) {
        $frase = normalizarFrase($line);

        $hablaDelMovil = array_filter($movil, static fn (string $w): bool => str_contains($frase, $w)) !== [];
        $hablaDeLaTarjeta = array_filter($credencial, static fn (string $w): bool => str_contains($frase, $w)) !== [];

        expect($hablaDelMovil && $hablaDeLaTarjeta)
            ->toBeFalse('La frase «'.$key.'» de '.$locale.' junta el movil con la credencial.');
    }
})->with(['es', 'en'])->group('RL-05', 'RF-QR-06');

it('solo junta el PIN con el correo para decir que nunca va por ahi', function (string $locale): void {
    // ADR-015. La frase que lo niega tiene que estar —es la que evita que alguien
    // se quede esperando un correo— y ninguna otra puede juntar las dos cosas.
    $correo = ['correo', 'email', 'e-mail', 'mail'];
    $negacion = ['nunca', 'never', 'jamas'];

    $lasDosCosas = 0;

    foreach (frasesDeLaHoja($locale) as $key => $line) {
        $frase = normalizarFrase($line);

        if (! str_contains($frase, 'pin')) {
            continue;
        }

        $hablaDelCorreo = array_filter($correo, static fn (string $w): bool => str_contains($frase, $w)) !== [];

        if (! $hablaDelCorreo) {
            continue;
        }

        $lasDosCosas++;

        $loNiega = array_filter($negacion, static fn (string $w): bool => str_contains($frase, $w)) !== [];

        expect($loNiega)
            ->toBeTrue('La frase «'.$key.'» de '.$locale.' junta el PIN con el correo sin negarlo.');
    }

    // Y la negacion tiene que existir: si desapareciera, este caso pasaria en
    // verde sin comprobar nada.
    expect($lasDosCosas)->toBe(1, 'Falta en '.$locale.' la frase que dice que el PIN nunca va por correo.');
})->with(['es', 'en'])->group('RL-05', 'RF-ID-09');

it('dice lo mismo en los dos idiomas: las mismas claves y ninguna vacia', function (): void {
    // La plantilla dibuja por clave. Una clave que exista en castellano y no en
    // ingles saldria como un hueco en el papel, y nadie lo veria hasta tener la
    // hoja impresa delante.
    $es = frasesDeLaHoja('es');
    $en = frasesDeLaHoja('en');

    expect(array_keys($en))->toBe(array_keys($es));

    foreach ([...array_values($es), ...array_values($en)] as $line) {
        expect(trim($line))->not->toBe('');
    }
})->group('RL-05');

it('mantiene los dos marcadores que sustituye el renderizador', function (): void {
    // `:app_name` y `:portal_url` son el contrato entre el fichero de textos y
    // `BrowsershotInstructionsSheetRenderer`. Si alguien renombrara uno, la hoja
    // saldria con el marcador impreso.
    foreach (['es', 'en'] as $locale) {
        $texto = implode(' ', frasesDeLaHoja($locale));

        expect($texto)->toContain(':app_name');

        // `:portal_url` NO tiene por que aparecer: la direccion la pinta la
        // plantilla en su propio parrafo, en cuerpo grande. Lo que no puede
        // haber es un marcador que nadie sustituya.
        foreach (frasesDeLaHoja($locale) as $key => $line) {
            preg_match_all('/:[a-z_]+/', $line, $marcadores);

            foreach ($marcadores[0] as $marcador) {
                expect(\in_array($marcador, [':app_name', ':portal_url'], true))
                    ->toBeTrue('La frase «'.$key.'» de '.$locale.' usa el marcador '.$marcador.', que nadie sustituye.');
            }
        }
    }
})->group('RL-05');

/*
 * **LA HOJA CITA AL QUIOSCO, Y ESO TIENE QUE SEGUIR SIENDO CIERTO** (tarea
 * 5.11b, segunda vuelta de la revision; resto anotado en HANDOFF → «5.11b»).
 *
 * El docblock de los dos ficheros de la hoja (lang/es y lang/en) promete que las
 * confirmaciones descritas son las que muestra la tablet, y hasta aqui no lo
 * verificaba nadie. Una promesa que solo esta escrita en un comentario dura lo
 * que tarde alguien en retocar un texto de la PWA.
 *
 * **Por que importa mas que una coincidencia de estilo.** La hoja se imprime y
 * se entrega en mano; no se actualiza sola. Si la tablet pasa a decir «Registro
 * pendiente» y el papel sigue diciendo «Pendiente de validar», quien lo lee
 * concluye que ha visto una pantalla distinta de la que la hoja describe —y en
 * ese momento la reaccion correcta, que es NO repetir el fichaje, deja de estar
 * escrita en ningun sitio.
 *
 * **Se exige la cita literal ENTRE COMILLAS**, no que la frase «hable de» lo
 * mismo: comillas angulares en castellano y rectas en ingles, que es como estan
 * escritos los dos ficheros. Citar es la unica forma de que quien tiene el papel
 * delante reconozca lo que ve en la pantalla.
 */

/**
 * Los textos del quiosco, leidos del arbol como hace `ClientErrorCatalogTest`.
 *
 * Se lee el JSON del repositorio y no una copia en el backend porque la fuente
 * de verdad de lo que ve el empleado es la PWA. Si algun dia el fichero se
 * moviera, esta prueba falla por «no existe» en lugar de pasar comparando
 * contra nada.
 *
 * @return array<string, string>
 */
function textosDelQuiosco(string $locale): array
{
    /** @var mixed $decoded */
    $decoded = json_decode(
        Repo::contents('frontend-kiosk/src/shared/i18n/locales/'.$locale.'.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($decoded)->toBeArray();
    \assert(\is_array($decoded));

    $textos = [];

    // Las cuatro que la hoja cita, con su ruta completa. Se resuelven una a una
    // —y no con un recorrido generico— para que la prueba falle si alguien
    // renombra una clave, que es justo el caso que se quiere cazar.
    foreach ([
        'scan.pending.badge' => ['scan', 'pending', 'badge'],
        'scan.debounced.title' => ['scan', 'debounced', 'title'],
        'scan.rejected.title' => ['scan', 'rejected', 'title'],
        'pin.entryButton' => ['pin', 'entryButton'],
    ] as $clave => $ruta) {
        $actual = $decoded;

        foreach ($ruta as $segmento) {
            expect(\is_array($actual) && \array_key_exists($segmento, $actual))
                ->toBeTrue('El quiosco ya no tiene '.$clave.' en '.$locale.'.json.');

            \assert(\is_array($actual));

            $actual = $actual[$segmento];
        }

        expect($actual)->toBeString();
        \assert(\is_string($actual));
        expect(trim($actual))->not->toBe('');

        $textos[$clave] = $actual;
    }

    return $textos;
}

/**
 * ¿Aparece `$cita` entrecomillada dentro de `$frase`?
 *
 * Los dos ficheros de la hoja no usan las mismas comillas —angulares en
 * castellano, rectas en ingles— y forzar una sola convencion estropearia la
 * tipografia de uno de los dos idiomas. Se admiten los tres pares que un texto
 * de producto puede llevar.
 */
function citaEntreComillas(string $frase, string $cita): bool
{
    foreach ([['«', '»'], ['"', '"'], ['“', '”']] as [$abre, $cierra]) {
        if (str_contains($frase, $abre.$cita.$cierra)) {
            return true;
        }
    }

    return false;
}

it('cita literalmente los textos que muestra el quiosco', function (string $locale): void {
    $hoja = frasesDeLaHoja($locale);
    $quiosco = textosDelQuiosco($locale);

    // Clave de la hoja => clave del quiosco. `portal_body` queda fuera a
    // proposito: no describe ninguna pantalla de la tablet.
    $citas = [
        'result_pending' => 'scan.pending.badge',
        'result_debounced' => 'scan.debounced.title',
        'result_rejected' => 'scan.rejected.title',
        'no_card_body' => 'pin.entryButton',
    ];

    foreach ($citas as $claveDeLaHoja => $claveDelQuiosco) {
        expect(\array_key_exists($claveDeLaHoja, $hoja))
            ->toBeTrue('Falta la frase «'.$claveDeLaHoja.'» en '.$locale.'.');

        expect(citaEntreComillas($hoja[$claveDeLaHoja], $quiosco[$claveDelQuiosco]))
            ->toBeTrue(
                'La frase «'.$claveDeLaHoja.'» de '.$locale.' no cita entre comillas el texto del quiosco '
                .'('.$claveDelQuiosco.': «'.$quiosco[$claveDelQuiosco].'»). '
                .'Si el quiosco cambio ese texto, la hoja impresa tiene que decir lo mismo.'
            );
    }
})->with(['es', 'en'])->group('RL-05', 'RF-AT-06', 'RF-KI-04');
