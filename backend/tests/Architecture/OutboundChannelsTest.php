<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\TelemetrySender;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **EL PRODUCTO TIENE UN SOLO CANAL SALIENTE, Y ESTA EN UN SITIO**
 * (ADR-020, RF-PD-12, regla dura 16).
 *
 * ## Que afirmacion verifica, literalmente
 *
 * El apartado «Verificacion» de ADR-020 dice que *ningun canal del producto
 * envia datos al fabricante fuera del paquete de diagnostico y de la
 * telemetria*. El paquete de diagnostico no sale solo -lo genera el cliente y lo
 * envia el cliente, por el medio que quiera-, asi que **el unico codigo que
 * puede abrir una conexion hacia fuera es el de la telemetria**, que ademas
 * viene apagada.
 *
 * Esa afirmacion, sin esta prueba, se sostiene en que nadie añada un
 * `Http::post()` en un listener. Y ese es exactamente el cambio que pasa
 * desapercibido en una revision: una linea, en un fichero que hace otra cosa,
 * con una intencion razonable -avisar a un webhook, comprobar si hay version
 * nueva, mandar un correo por una API-.
 *
 * ## Y por eso lleva la etiqueta RL-17
 *
 * RL-17 dice que el fabricante **no es encargado del tratamiento en la
 * operacion ordinaria, porque no aloja ni accede a los datos**. Lo primero lo
 * decide el despliegue -cada cliente en su servidor-; lo segundo lo decide este
 * repositorio, y es lo que estas pruebas afirman: no hay ni un cliente HTTP de
 * proposito general fuera de la telemetria, tampoco en el armazon, y el unico
 * que hay viene apagado y sin destino. Sin esto, RL-17 seria una declaracion del
 * documento del cliente que nada respalda.
 *
 * ## Se lee el codigo como TEXTO
 *
 * Como el resto de las pruebas de arquitectura: hay que poder hablar de ficheros
 * que ni siquiera se cargan, y un cliente HTTP se puede usar sin importar su
 * clase -`curl_init()`, `file_get_contents('http://...')`-.
 *
 * ## Que NO afirma
 *
 * No afirma que el producto no tenga trafico saliente de ningun tipo: la base de
 * datos, Redis y el servidor de correo son conexiones salientes y son
 * infraestructura del propio cliente. Lo que afirma es que **no hay ningun
 * cliente HTTP de proposito general** fuera de la telemetria, que es por donde
 * saldrian datos hacia un tercero.
 */

/** El unico directorio de `app/` donde puede vivir un cliente HTTP saliente. */
const CANAL_SALIENTE = 'Product/Infrastructure/Telemetry/';

/**
 * Las formas de abrir una conexion HTTP saliente en PHP y en Laravel.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function clientesHttp(): array
{
    return [
        'el facade Http de Laravel' => ['el facade Http de Laravel', '/Illuminate\\\\Support\\\\Facades\\\\Http\b/'],
        'el cliente HTTP de Laravel' => ['el cliente HTTP de Laravel', '/Illuminate\\\\Http\\\\Client\\\\/'],
        'Guzzle directamente' => ['Guzzle directamente', '/GuzzleHttp\\\\/'],
        'curl' => ['curl', '/\bcurl_(init|exec|setopt)\s*\(/'],
        'un flujo HTTP con file_get_contents' => ['file_get_contents sobre una URL', "/file_get_contents\(\s*['\"]https?:/"],
        'un flujo HTTP con fopen' => ['fopen sobre una URL', "/fopen\(\s*['\"]https?:/"],
    ];
}

/**
 * La unica excepcion, y esta acotada a un fichero y a un patron.
 *
 * `ProductServiceProvider` es la **raiz de composicion** del modulo: es donde se
 * enlaza el puerto {@see TelemetrySender}
 * con su adaptador, y para construirlo tiene que nombrar el tipo de la fabrica
 * HTTP. Nombrar un tipo no es abrir una conexion, y sacarlo de ahi significaria
 * que el adaptador leyera la configuracion por su cuenta -justo lo que el resto
 * del modulo evita, regla dura 14-.
 *
 * Se exceptua **por nombre y solo para ese patron**: un `Http::post()` en el
 * proveedor seguiria fallando, y un fichero nuevo que nombrara el cliente
 * tambien.
 *
 * @return list<string>
 */
function raizDeComposicion(string $descripcion): array
{
    return $descripcion === 'el cliente HTTP de Laravel' ? ['Product/ProductServiceProvider.php'] : [];
}

it('ningun fichero de un modulo abre una conexion saliente fuera de la telemetria', function (string $descripcion, string $patron): void {
    $offenders = [];
    $permitidos = raizDeComposicion($descripcion);

    foreach (ModuleTree::filesIn('') as $file) {
        $relative = ModuleTree::relative($file);

        if (str_starts_with($relative, CANAL_SALIENTE) || in_array($relative, $permitidos, true)) {
            continue;
        }

        if (preg_match($patron, (string) file_get_contents($file)) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'Estos ficheros usan '.$descripcion.' fuera de `'.CANAL_SALIENTE.'`: '.implode(', ', $offenders)
        .'. ADR-020 afirma que ningun canal del producto envia datos al fabricante fuera del paquete de '
        .'diagnostico y de la telemetria, y esa afirmacion se sostiene en que el unico cliente HTTP '
        .'saliente viva en un solo sitio, apagado de serie.'
    );
})->with(clientesHttp())->group('RF-PD-12', 'RF-PD-11', 'RL-17');

it('tampoco lo hace el armazon de la aplicacion, fuera de los modulos', function (string $descripcion, string $patron): void {
    // `app/Support`, `app/Http`, `app/Providers`, `app/Console`: todo lo que no
    // es un modulo. Un `Http::get()` en un middleware seria igual de saliente.
    $offenders = [];

    // `scandir` y no `RecursiveDirectoryIterator`: sobre el bind mount de Docker
    // Desktop el iterador pierde ficheros en silencio, y un fichero que esta
    // regla no ve es un canal saliente que nadie denuncia. Ver `ModuleTree`.
    foreach (['Support', 'Http', 'Providers', 'Console', 'Models', 'Exceptions'] as $directory) {
        foreach (ModuleTree::phpFilesUnder(Repo::file('backend/app/'.$directory)) as $file) {
            if (preg_match($patron, (string) file_get_contents($file)) === 1) {
                $offenders[] = 'app/'.$directory.'/'.basename($file);
            }
        }
    }

    expect($offenders)->toBe([], 'Estos ficheros del armazon usan '.$descripcion.': '.implode(', ', $offenders));
})->with(clientesHttp())->group('RF-PD-12', 'RL-17');

it('el unico canal saliente esta apagado de serie', function (): void {
    // La otra mitad de la garantia: que el canal exista en un solo sitio no
    // valdria de nada si viniera encendido. El valor por defecto ES el producto
    // (RF-PD-12, Anexo B).
    $env = Repo::contents('.env.example');

    expect($env)->toContain('TELEMETRY_ENABLED=false')
        // Y sin destino: aunque alguien pusiera la variable a `true`, no hay a
        // donde enviar. El producto no trae ningun destino escrito.
        ->and($env)->toContain("TELEMETRY_ENDPOINT=\n");

    $config = Repo::contents('backend/config/product.php');

    expect($config)->toContain("env('TELEMETRY_ENABLED', false)")
        ->and($config)->toContain("env('TELEMETRY_ENDPOINT', '')");
})->group('RF-PD-12', 'RL-17');

it('la raiz de composicion nombra el cliente HTTP, pero no lo usa', function (): void {
    // La contrapartida de la excepcion de `raizDeComposicion()`: el proveedor
    // puede NOMBRAR la fabrica para enlazar el adaptador, y nada mas. Si algun
    // dia alguien pusiera ahi una peticion -un ping de arranque, una
    // comprobacion de version-, esto lo dice.
    $source = (string) file_get_contents(ModuleTree::root().'/Product/ProductServiceProvider.php');

    expect($source)->toContain('Illuminate\Http\Client\Factory as HttpClient');

    foreach (['->post(', '->get(', '->send(', '->patch(', '->put(', '->head('] as $llamada) {
        expect(str_contains($source, 'HttpClient'.$llamada))->toBeFalse();
        expect(str_contains($source, '$http'.$llamada))->toBeFalse();
    }
})->group('RF-PD-12', 'RL-17');
