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
        /*
         * El exportador OTLP de OpenTelemetry (tarea 3.1). Abre una conexion
         * saliente de verdad —hacia el Tempo del propio cliente— y no la ve
         * ninguno de los patrones de arriba: el SDK trae su propia fabrica de
         * transporte y descubre el cliente HTTP por `psr/http-client-discovery`,
         * asi que un `Http::post()` no aparece por ninguna parte.
         *
         * Sin esta fila, cualquier fichero podia estrenar un exportador OTLP
         * —de logs, de metricas, hacia donde fuera— sin que esta prueba dijera
         * nada, que es justo la clase de canal que ADR-020 vigila.
         */
        'el exportador OTLP de OpenTelemetry' => ['el exportador OTLP de OpenTelemetry', '/OpenTelemetry\\\\Contrib\\\\Otlp\b/'],
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

/**
 * La segunda excepcion, tambien acotada a un fichero y a un patron (tarea 3.1).
 *
 * `HttpLokiTransport` empuja el log tecnico a **Loki, que vive en el servidor del
 * cliente** (doc 02 §1.4: «Observabilidad (en el mismo servidor)»; de serie
 * `LOKI_URL` vacia; con el perfil `observability`, `http://loki:3100`, que no sale del `docker compose`). Es la misma
 * clase de conexion saliente que PostgreSQL, Redis o el servidor de correo, y el
 * docblock de arriba ya la declara fuera de lo que esta prueba afirma: lo que se
 * prohibe es un canal **hacia el fabricante**, no que el producto hable con la
 * infraestructura del propio cliente.
 *
 * Se descarto la alternativa —un agente de recoleccion como Alloy o el driver de
 * Docker para Loki— precisamente por seguridad: los dos exigen el socket de
 * Docker dentro de un contenedor con privilegios (decision 8 de la ficha 3.1).
 *
 * La excepcion es de UN fichero por patron, comparado por **ruta relativa
 * completa**: un `Http::post()` en cualquier otro sitio del armazon sigue
 * rompiendo la prueba, y `LoggingServiceProvider` —su raiz de composicion—
 * enlaza el adaptador por clase justo para no tener que nombrar la fabrica HTTP.
 *
 * La segunda excepcion, del mismo tipo, es el exportador OTLP de trazas
 * (tarea 3.1): tambien apunta a infraestructura del propio cliente (Tempo) y
 * tambien viene sin destino de serie.
 *
 * @return list<string>
 */
function canalDeObservabilidad(string $descripcion): array
{
    return match ($descripcion) {
        'el cliente HTTP de Laravel' => ['Support/Observability/Logging/HttpLokiTransport.php'],
        /*
         * `TracerFactory` es la raiz de composicion del SDK de trazas: el unico
         * sitio que nombra el transporte y el exportador OTLP, y el unico que
         * conoce el destino —el Tempo del propio cliente, `OTEL_EXPORTER_OTLP_ENDPOINT`,
         * vacio de serie—. Mismo argumento que `HttpLokiTransport`: es
         * infraestructura del cliente, no un canal hacia el fabricante.
         *
         * Un exportador OTLP en cualquier otro fichero sigue rompiendo la prueba.
         */
        'el exportador OTLP de OpenTelemetry' => ['Support/Observability/Tracing/TracerFactory.php'],
        default => [],
    };
}

it('tampoco lo hace el armazon de la aplicacion, fuera de los modulos', function (string $descripcion, string $patron): void {
    // `app/Support`, `app/Http`, `app/Providers`, `app/Console`: todo lo que no
    // es un modulo. Un `Http::get()` en un middleware seria igual de saliente.
    $offenders = [];
    $permitidos = canalDeObservabilidad($descripcion);
    $raiz = Repo::file('backend/app');

    // `scandir` y no `RecursiveDirectoryIterator`: sobre el bind mount de Docker
    // Desktop el iterador pierde ficheros en silencio, y un fichero que esta
    // regla no ve es un canal saliente que nadie denuncia. Ver `ModuleTree`.
    foreach (['Support', 'Http', 'Providers', 'Console', 'Models', 'Exceptions'] as $directory) {
        foreach (ModuleTree::phpFilesUnder(Repo::file('backend/app/'.$directory)) as $file) {
            /*
             * RUTA RELATIVA COMPLETA, nunca `basename()`. Un nombre de fichero no
             * identifica un fichero: con `basename`, un `TracerFactory.php` nuevo
             * en cualquier otro directorio del armazon heredaba la excepcion de
             * un canal saliente sin que nadie lo decidiera, que es exactamente el
             * agujero silencioso que estas pruebas existen para no tener.
             */
            $relative = ModuleTree::relative($file, $raiz);

            if (in_array($relative, $permitidos, true)) {
                continue;
            }

            if (preg_match($patron, (string) file_get_contents($file)) === 1) {
                $offenders[] = 'app/'.$relative;
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
