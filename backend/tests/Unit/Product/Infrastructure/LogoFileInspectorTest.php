<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\LogoRejection;
use App\Modules\Product\Infrastructure\Branding\LogoFileInspector;
use App\Modules\Shared\Domain\ValueObject\LogoFormat;

/*
 * La comprobacion del fichero de logotipo (RF-PD-08, tarea 5.8).
 *
 * SIN BASE DE DATOS. Toca el disco —es lo suyo— pero solo un directorio temporal
 * propio de cada prueba, creado y borrado aqui.
 *
 * POR QUE ESTA PRUEBA IMPORTA MAS DE LO QUE PARECE. Esta clase es la guarda de un
 * endpoint PUBLICO: `GET /api/v1/branding/logo` sirve lo que ella acepta, asi que
 * un fallo suyo no es «sale mal el logotipo», es una lectura de cualquier fichero
 * del servidor a la que le basta con un `PATCH` de administrador.
 *
 * Y LA OTRA MITAD: los mensajes. Quien recibe el rechazo es personal de IT de un
 * hotel que no conoce el sistema, asi que cada motivo tiene nombre propio para
 * que el `422` pueda decir que hacer y no solo que ha fallado.
 */

/** Un PNG de 1x1 de verdad, con su IHDR. */
function pngDeUnPixel(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );
}

/**
 * Un PNG con el ancho y el alto que se le digan, escritos en su IHDR.
 *
 * No se genera una imagen de verdad: al inspector solo le importan los primeros
 * 24 bytes, y fabricar un PNG de 4000x4000 real seria pedirle a la suite que
 * gaste memoria para comprobar una cuenta de enteros.
 */
function pngDeTamano(int $width, int $height): string
{
    $png = pngDeUnPixel();

    return substr($png, 0, 16).pack('NN', $width, $height).substr($png, 24);
}

/** Un directorio de marca recien creado, propio de esta prueba. */
function raizDeMarca(): string
{
    $root = sys_get_temp_dir().'/kronoqr-branding-'.bin2hex(random_bytes(6));

    mkdir($root, 0o755, true);

    return $root;
}

function inspectorSobre(string $root, int $maxBytes = 524288, int $maxDimension = 2048): LogoFileInspector
{
    return new LogoFileInspector($root, $maxBytes, $maxDimension);
}

it('acepta un PNG que vive dentro del directorio de marca', function (): void {
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeUnPixel());

    $inspection = inspectorSobre($root)->inspect($root.'/logo.png');

    expect($inspection->isAccepted())->toBeTrue()
        ->and($inspection->image?->format)->toBe(LogoFormat::PNG);
})->group('RF-PD-08');

it('acepta un SVG, un SVG con declaracion XML y un SVG con marca de orden de bytes', function (string $contenido): void {
    // Las tres formas salen de herramientas de diseño reales. Si solo se
    // aceptara la primera, el cliente que exporte desde Illustrator veria «el
    // fichero no es un PNG ni un SVG» sobre un SVG perfectamente valido.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.svg', $contenido);

    $inspection = inspectorSobre($root)->inspect($root.'/logo.svg');

    expect($inspection->isAccepted())->toBeTrue()
        ->and($inspection->image?->format)->toBe(LogoFormat::SVG);
})->with([
    'a pelo' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
    'con declaracion XML' => ['<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"></svg>'],
    'con espacios y saltos delante' => ["\n  <svg xmlns=\"http://www.w3.org/2000/svg\"></svg>"],
    'con marca de orden de bytes' => ["\xEF\xBB\xBF<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>"],
    'con declaracion XML y BOM' => ["\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<svg></svg>"],
])->group('RF-PD-08');

it('rechaza una ruta relativa, que se resolveria contra el directorio del proceso', function (): void {
    $root = raizDeMarca();

    expect(inspectorSobre($root)->inspect('logo.png')->rejection())
        ->toBe(LogoRejection::NOT_ABSOLUTE);
})->group('RF-PD-08', 'RS-03');

it('rechaza una ruta con salto a directorio superior antes de resolverla', function (): void {
    // Sobre la cadena y no sobre el `realpath`: una ruta con salto que hoy
    // resolviera dentro del directorio se aceptaria, y dejaria de hacerlo sin
    // aviso el dia que el directorio cambie de sitio.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeUnPixel());

    expect(inspectorSobre($root)->inspect($root.'/../'.basename($root).'/logo.png')->rejection())
        ->toBe(LogoRejection::TRAVERSAL);
})->group('RF-PD-08', 'RS-03');

it('rechaza un fichero de fuera del directorio de marca aunque exista y sea un PNG', function (): void {
    // ESTA ES LA GUARDA DEL ENDPOINT PUBLICO. Sin ella, guardar una ruta desde
    // el panel bastaria para publicar cualquier fichero legible del servidor.
    $root = raizDeMarca();
    $fuera = raizDeMarca();
    file_put_contents($fuera.'/logo.png', pngDeUnPixel());

    expect(inspectorSobre($root)->inspect($fuera.'/logo.png')->rejection())
        ->toBe(LogoRejection::OUTSIDE_ROOT);
})->group('RF-PD-08', 'RS-03');

it('no deja pasar un directorio hermano cuyo nombre empieza igual', function (): void {
    // `/x/branding-de-otro` no esta dentro de `/x/branding`, aunque su ruta
    // empiece por la misma cadena. El separador final es lo que lo distingue.
    $root = raizDeMarca();
    $vecino = $root.'-de-otro';
    mkdir($vecino, 0o755, true);
    file_put_contents($vecino.'/logo.png', pngDeUnPixel());

    expect(inspectorSobre($root)->inspect($vecino.'/logo.png')->rejection())
        ->toBe(LogoRejection::OUTSIDE_ROOT);
})->group('RF-PD-08', 'RS-03');

it('rechaza una ruta que no existe', function (): void {
    $root = raizDeMarca();

    expect(inspectorSobre($root)->inspect($root.'/no-esta.png')->rejection())
        ->toBe(LogoRejection::MISSING);
})->group('RF-PD-08');

it('rechaza un directorio que ocupe el sitio del fichero', function (): void {
    $root = raizDeMarca();
    mkdir($root.'/logo.png');

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::MISSING);
})->group('RF-PD-08');

it('rechaza cualquier ruta si el directorio de marca no existe', function (): void {
    // Es el sintoma de un volumen sin montar, y el mensaje que le corresponde
    // habla justamente de eso.
    $inspector = inspectorSobre(sys_get_temp_dir().'/kronoqr-no-montado-'.bin2hex(random_bytes(4)));

    expect($inspector->inspect('/etc/hostname')->rejection())
        ->toBe(LogoRejection::OUTSIDE_ROOT);
})->group('RF-PD-08');

it('rechaza un fichero que pasa del tope de bytes', function (): void {
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeUnPixel().str_repeat('0', 4096));

    expect(inspectorSobre($root, maxBytes: 1024)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::TOO_LARGE);
})->group('RF-PD-08');

it('mira el contenido y no la extension: un texto renombrado a .png no cuela', function (): void {
    // Un `.png` que dentro es otra cosa se serviria con `Content-Type: image/png`
    // a los navegadores de todo el hotel.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', 'esto es un texto, no una imagen');

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::UNSUPPORTED_FORMAT);
})->group('RF-PD-08', 'RS-03');

it('rechaza un HTML disfrazado de SVG', function (): void {
    $root = raizDeMarca();
    file_put_contents($root.'/logo.svg', '<html><body>hola</body></html>');

    expect(inspectorSobre($root)->inspect($root.'/logo.svg')->rejection())
        ->toBe(LogoRejection::UNSUPPORTED_FORMAT);
})->group('RF-PD-08', 'RS-03');

it('rechaza un SVG con guion dentro, en mayusculas o en minusculas', function (string $contenido): void {
    // El endpoint ya lo serviria con `sandbox` y sin poder ejecutar nada, pero el
    // MISMO fichero se incrusta en los PDF, que dibuja un Chromium. La defensa se
    // hace en la puerta: un logotipo no necesita guion.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.svg', $contenido);

    expect(inspectorSobre($root)->inspect($root.'/logo.svg')->rejection())
        ->toBe(LogoRejection::ACTIVE_CONTENT);
})->with([
    'minusculas' => ['<svg><script>alert(1)</script></svg>'],
    'mayusculas' => ['<svg><SCRIPT>alert(1)</SCRIPT></svg>'],
    'con atributos por medio' => ['<svg xmlns="http://www.w3.org/2000/svg"><script type="text/ecmascript">x</script></svg>'],
])->group('RF-PD-08', 'RS-03');

it('lee el tamano del IHDR y rechaza un PNG con demasiados pixeles de lado', function (): void {
    // El peso en bytes no acota el coste de dibujarla: un PNG enorme en negro
    // pesa poco y hace que la tablet reserve cientos de megabytes al pintarlo.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeTamano(4096, 10));

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::TOO_MANY_PIXELS);
})->group('RF-PD-08');

it('mide tambien el alto, no solo el ancho', function (): void {
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeTamano(10, 4096));

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::TOO_MANY_PIXELS);
})->group('RF-PD-08');

it('acepta un PNG justo en el limite de pixeles', function (): void {
    // El limite es «hasta», no «menos de»: sin esta prueba, un cambio de `>` a
    // `>=` rechazaria un logotipo legitimo y nadie lo notaria.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeTamano(2048, 2048));

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->isAccepted())->toBeTrue();
})->group('RF-PD-08');

it('rechaza un PNG truncado con firma valida pero sin IHDR legible', function (): void {
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', "\x89PNG\r\n\x1a\n");

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::UNSUPPORTED_FORMAT);
})->group('RF-PD-08');

it('rechaza un fichero de cero bytes sin llegar a construir una imagen rota', function (): void {
    $root = raizDeMarca();
    touch($root.'/logo.png');

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->rejection())
        ->toBe(LogoRejection::UNREADABLE);
})->group('RF-PD-08');

it('devuelve los bytes tal cual, sin transformarlos', function (): void {
    // Lo que se sirve y lo que se incrusta en el PDF es el fichero del cliente,
    // no una version recodificada suya.
    $root = raizDeMarca();
    $bytes = pngDeUnPixel();
    file_put_contents($root.'/logo.png', $bytes);

    expect(inspectorSobre($root)->inspect($root.'/logo.png')->image?->bytes)->toBe($bytes);
})->group('RF-PD-08');

it('nunca lanza, ni con una ruta absurda', function (string $ruta): void {
    // Esta clase esta en el camino de la tarjeta impresa y del informe sellado:
    // una excepcion aqui deja a un cliente sin documentos por culpa de una imagen.
    $root = raizDeMarca();

    expect(inspectorSobre($root)->inspect($ruta)->isAccepted())->toBeFalse();
})->with([
    'vacia' => [''],
    'solo la barra' => ['/'],
    'con byte nulo' => ["/tmp/logo\0.png"],
    'larguisima' => ['/'.str_repeat('a', 5000)],
])->group('RF-PD-08');

it('rechaza un SVG con contenido activo que no es una etiqueta script', function (string $contenido): void {
    // Buscar solo `<script` deja pasar lo que de verdad se usa. Un manejador de
    // evento ejecuta sin ninguna etiqueta a la vista; `<foreignObject` mete HTML
    // arbitrario dentro del dibujo; y `<!ENTITY` es la puerta de XXE y de la
    // expansion recursiva que agota la memoria de quien parsee el fichero.
    //
    // El endpoint del logotipo es PUBLICO y el mismo fichero se incrusta despues
    // en los PDF, que dibuja un Chromium: la defensa se hace en la puerta.
    $root = raizDeMarca();
    file_put_contents($root.'/logo.svg', $contenido);

    expect(inspectorSobre($root)->inspect($root.'/logo.svg')->rejection())
        ->toBe(LogoRejection::ACTIVE_CONTENT);
})->with([
    'onload' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
    'onclick con espacios' => ['<svg><rect onclick = "alert(1)" /></svg>'],
    'ONERROR en mayusculas' => ['<svg><image ONERROR="alert(1)" /></svg>'],
    'foreignObject' => ['<svg><foreignObject><body>hola</body></foreignObject></svg>'],
    'foreignObject en mayusculas' => ['<svg><FOREIGNOBJECT/></svg>'],
    'entidad XML' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg></svg>'],
])->group('RF-PD-08', 'RS-03');

it('no confunde con un manejador de evento un atributo que solo acaba en «on»', function (): void {
    // El limite de palabra existe para que un SVG legitimo no se rechace: sin el,
    // cualquier atributo terminado en «on» —o la palabra «version»— dispararia, y
    // el cliente recibiria un mensaje sobre codigo activo por un fichero limpio.
    $root = raizDeMarca();
    file_put_contents(
        $root.'/logo.svg',
        '<svg version="1.1" xmlns="http://www.w3.org/2000/svg"><polygon points="0,0 1,1"/></svg>',
    );

    expect(inspectorSobre($root)->inspect($root.'/logo.svg')->isAccepted())->toBeTrue();
})->group('RF-PD-08');

it('acepta un fichero cuyo nombre lleva dos puntos seguidos, que no es un salto', function (): void {
    // `str_contains($path, '..')` rechazaba `logo..png` —o cualquier carpeta con
    // dos puntos en el nombre— y le decia al cliente que habia escrito un salto de
    // directorio que no habia escrito. Lo que sube de nivel es el SEGMENTO `..`.
    $root = raizDeMarca();
    file_put_contents($root.'/logo..png', pngDeUnPixel());

    expect(inspectorSobre($root)->inspect($root.'/logo..png')->isAccepted())->toBeTrue();
})->group('RF-PD-08');

it('sigue rechazando el salto de directorio cuando es un segmento de verdad', function (): void {
    $root = raizDeMarca();
    file_put_contents($root.'/logo.png', pngDeUnPixel());

    expect(inspectorSobre($root)->inspect($root.'/../'.basename($root).'/logo.png')->rejection())
        ->toBe(LogoRejection::TRAVERSAL);
})->group('RF-PD-08', 'RS-03');
