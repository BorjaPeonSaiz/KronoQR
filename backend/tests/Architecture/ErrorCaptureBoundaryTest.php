<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * **LA CAPTACION DE ERRORES TIENE UNA PUERTA, Y NO PUEDE VOLVER A DECIDIR POR
 * EL NOMBRE DE LA CARPETA** (RF-PD-15, tarea 5.12, decisiones 1 y 2).
 *
 * ## Lo que cambio, y por que esta prueba cambio con ello
 *
 * La primera version del enganche descartaba del historico todo lo que viviera
 * en `App\Modules\*\Domain\Exception\` y `App\Modules\*\Application\Exception\`,
 * y esta prueba defendia la premisa de la que dependia: que ahi solo hubiera
 * excepciones. La revision demostro que la premisa era cierta y la REGLA era
 * falsa: **50 de las 96 excepciones de esos dos espacios no tienen `render` en
 * `bootstrap/app.php`** —`AuditPayloadIsNotCanonical`, `InstantIsNotUtc`,
 * `PairingCodeSpaceExhausted`…—, producen un `500` de verdad, y quedaban fuera
 * del panel de errores precisamente por estar bien colocadas.
 *
 * La regla nueva pregunta al manejador que estado responderia (decision 1). Lo
 * que esta prueba defiende ahora es que **nadie la vuelva a sustituir por el
 * atajo**: es el tipo de cambio que parece una optimizacion razonable —«esto se
 * puede decidir sin renderizar»— y que apaga en silencio la mitad del histórico.
 * El comportamiento de la regla —que un `409` traducido no entra y que una
 * excepcion de dominio SIN traduccion si— se prueba en `ErrorCaptureTest`, que
 * es donde hay una aplicacion que renderice.
 *
 * ## Y que solo dos modulos toquen el historico
 *
 * `ErrorEventSink` es un puerto de `Shared` que puede ver cualquier modulo. Si
 * `Attendance` empezara a reportar errores a mano, habria **dos caminos de
 * captacion**: el del manejador de excepciones —que sanea, agrupa y decide el
 * origen— y uno paralelo escrito por quien pasaba por ahi, con su propia idea de
 * que meter en `context`. La regla dura 21 dejaria de ser una propiedad del
 * sistema para pasar a ser una costumbre de cada autor.
 *
 * Solo dos modulos lo alcanzan, y por motivos distintos: **`Product`**, que es
 * quien tiene la tabla, el saneado, la captacion de servidor y el endpoint de los
 * clientes web; y **`Kiosk`**, que recibe los errores de la tablet dentro del
 * latido y no puede importar `Product` (doc 02 §1.6, Deptrac). Cualquier otro
 * modulo que quiera contar algo tiene la via normal: lanzar.
 *
 * Se lee el codigo como TEXTO, como el resto de las pruebas de arquitectura: hay
 * que poder hablar de ficheros que ni siquiera se cargan.
 */

/** El puerto vive aqui y este fichero es, obviamente, el que mas lo nombra. */
const PUERTO_DEL_HISTORICO = 'Shared/Application/Port/ErrorEventSink.php';

/** El unico directorio donde vive la captacion desde el servidor. */
const DIRECTORIO_DE_CAPTACION = 'Product/Infrastructure/Capture/';

/**
 * Ficheros de `app/Modules/` que nombran el puerto del historico, sin contar el
 * propio puerto.
 *
 * @return list<string> Rutas relativas a `app/Modules/`.
 */
function ficherosQueTocanElHistorico(): array
{
    $found = [];

    foreach (ModuleTree::filesIn('') as $file) {
        $relative = ModuleTree::relative($file);

        if ($relative === PUERTO_DEL_HISTORICO) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), 'ErrorEventSink')) {
            $found[] = $relative;
        }
    }

    return $found;
}

/**
 * Los ficheros del directorio de captacion, por su ruta relativa.
 *
 * @return list<string>
 */
function ficherosDeLaCaptacion(): array
{
    return array_map(
        static fn (string $file): string => ModuleTree::relative($file),
        ModuleTree::filesIn(DIRECTORIO_DE_CAPTACION),
    );
}

it('no vuelve a decidir que es un error por el espacio de nombres de la excepcion', function (): void {
    /*
     * El atajo que la revision retiro, escrito como se escribiria otra vez: una
     * expresion regular sobre `Domain\Exception` o `Application\Exception` en el
     * codigo de la captacion. Si alguien la reintroduce, esta prueba lo dice
     * ANTES de que cincuenta clases de excepcion desaparezcan del panel sin que
     * nadie lo note —porque el sintoma de este fallo es una tabla mas limpia, que
     * es exactamente lo que parece una mejora—.
     *
     * Se busca en el codigo, no en los comentarios: el docblock de
     * `ServerErrorReporter` cuenta la historia y tiene que poder nombrarlos.
     */
    $reincidentes = array_values(array_filter(
        ficherosDeLaCaptacion(),
        static function (string $file): bool {
            $source = (string) file_get_contents(ModuleTree::root().'/'.$file);

            // Fuera los bloques de documentacion y las lineas de comentario.
            $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source) ?? $source;

            return preg_match('/(Domain|Application)\\\\+Exception/', $code) === 1;
        },
    ));

    expect($reincidentes)->toBe([]);
})->group('RF-PD-15');

it('no deja que ningun modulo salvo Product y Kiosk toque el historico de errores', function (): void {
    /*
     * Los tres modulos que pueden nombrarlo, y por que cada uno:
     *
     * - `Product`, que tiene la tabla, el saneado, la captacion de servidor y el
     *   endpoint de los clientes web.
     * - `Kiosk`, que recibe los errores de la tablet dentro del latido y no puede
     *   importar `Product` (doc 02 §1.6, Deptrac).
     * - `Shared`, que es donde vive el propio puerto y el objeto de valor que
     *   viaja por el; ahi las menciones son declaraciones y documentacion. Que no
     *   aparezca ninguna IMPLEMENTACION en `Shared` lo fija la ultima prueba de
     *   este fichero, que es la mitad que de verdad importaria.
     */
    $intrusos = array_values(array_filter(
        ficherosQueTocanElHistorico(),
        static fn (string $file): bool => ! str_starts_with($file, 'Product/')
            && ! str_starts_with($file, 'Kiosk/')
            && ! str_starts_with($file, 'Shared/'),
    ));

    expect($intrusos)->toBe([]);
})->group('RF-PD-15', 'RL-19');

it('mantiene la captacion del servidor en un solo directorio', function (): void {
    // Dentro de `Product`, la escritura del historico y su consulta son de la otra
    // mitad de la tarea; lo que esta prueba acota es la CAPTACION. Fuera del
    // directorio solo puede nombrarla la raiz de composicion, que es la que la
    // construye y la engancha al manejador de excepciones.
    $fuera = array_values(array_filter(
        ModuleTree::filesIn(''),
        static function (string $file): bool {
            $relative = ModuleTree::relative($file);

            if (str_starts_with($relative, DIRECTORIO_DE_CAPTACION) || $relative === 'Product/ProductServiceProvider.php') {
                return false;
            }

            return str_contains((string) file_get_contents($file), 'Infrastructure\\Capture\\');
        },
    ));

    expect(ficherosDeLaCaptacion())->not->toBe([])
        ->and($fuera)->toBe([]);
})->group('RF-PD-15');

it('solo implementa el puerto del historico dentro de Product', function (): void {
    $implementaciones = array_values(array_filter(
        ficherosQueTocanElHistorico(),
        static fn (string $file): bool => str_contains(
            (string) file_get_contents(ModuleTree::root().'/'.$file),
            'implements ErrorEventSink',
        ),
    ));

    expect($implementaciones)->not->toBe([])
        ->and(array_filter($implementaciones, static fn (string $file): bool => ! str_starts_with($file, 'Product/')))
        ->toBe([]);
})->group('RF-PD-15');
