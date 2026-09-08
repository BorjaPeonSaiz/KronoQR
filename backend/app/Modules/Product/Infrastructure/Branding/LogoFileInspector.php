<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Branding;

use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Product\Domain\ValueObject\LogoInspection;
use App\Modules\Product\Domain\ValueObject\LogoRejection;
use App\Modules\Shared\Domain\ValueObject\LogoFormat;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use Throwable;

/**
 * **El unico sitio donde se decide si un fichero sirve como logotipo**
 * (RF-PD-08, tarea 5.8, regla dura 13).
 *
 * ## Uno solo, y esa es toda la idea
 *
 * Lo usan los dos extremos: `PATCH /api/v1/settings` al GUARDAR la ruta —donde
 * un rechazo es un `422` con una persona delante— y `LocalBrandingLogoReader` al
 * LEERLA para dibujar —donde un rechazo es «sigue sin logotipo»—. Con dos
 * comprobaciones distintas, el panel aceptaria un fichero que el endpoint
 * publico rechaza y nadie sabria por que la cabecera sale vacia.
 *
 * ## Que se comprueba, en este orden, y por que este
 *
 * 1. **Ruta absoluta y sin dos puntos seguidos**, sobre la cadena. Antes de
 *    tocar el disco, porque el mensaje util es «la ruta esta mal escrita» y no
 *    «no existe».
 * 2. **Dentro del directorio de marca**, sobre la ruta resuelta. Es la
 *    comprobacion que sostiene que `GET /api/v1/branding/logo` sea publico: sin
 *    ella, ese endpoint seria una lectura de cualquier fichero del servidor a la
 *    que le basta con un `PATCH` de administrador. Se hace sobre la ruta
 *    RESUELTA y no sobre la cadena porque un enlace simbolico colocado dentro
 *    del directorio la burlaria.
 * 3. **Existe y se puede leer.**
 * 4. **Tamano**, con `filesize()` ANTES de leer. Un fichero de 8 MB no se carga
 *    en memoria para descubrir que es demasiado grande.
 * 5. **Formato por CONTENIDO**, nunca por extension. Un `.png` que dentro es
 *    otra cosa se serviria con `Content-Type: image/png` a los navegadores de
 *    todo el hotel.
 * 6. **Pixeles de lado** del PNG, leidos del IHDR. Sin descodificar la imagen:
 *    los primeros bytes de la cabecera bastan, y `getimagesize()` sobre un
 *    fichero manipulado hace bastante mas trabajo del necesario.
 *
 * ## Nunca lanza
 *
 * Ni por I/O ni por nada. Un disco que desaparece a mitad de la lectura devuelve
 * {@see LogoRejection::UNREADABLE}, no una excepcion: esta clase esta en el
 * camino de la tarjeta impresa y del informe sellado, y ahi una excepcion deja a
 * un cliente sin documentos por una imagen.
 *
 * ## Sin estado y sin cache
 *
 * Quien memoriza es el adaptador de lectura, por peticion. Aqui no, porque el
 * otro consumidor —la validacion del `PATCH`— necesita mirar el disco de verdad
 * justo en ese momento.
 */
final readonly class LogoFileInspector implements LogoInspector
{
    /** Firma de un PNG (ISO/IEC 15948, 8 bytes). No hay PNG que no empiece asi. */
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Lo que hay que leer de un PNG para saber su tamano: firma (8) + longitud y
     * tipo del primer trozo (8) + ancho y alto (8). El IHDR es SIEMPRE el primer
     * trozo, lo exige el estandar.
     */
    private const int PNG_HEADER_BYTES = 24;

    /** Marca de orden de bytes UTF-8, que algunos editores anteponen a un SVG. */
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Lo que convierte un SVG en algo mas que un dibujo (RS-03).
     *
     * ## Por que cuatro y no solo `<script`
     *
     * Un SVG es un DOCUMENTO, no una imagen: incrustado con `<img>` esta
     * acotado, pero abierto en una pestaña —y el endpoint del logotipo es
     * publico, asi que se puede abrir— ejecuta lo que lleve dentro. Y el MISMO
     * fichero se incrusta despues en los PDF, que dibuja un Chromium.
     *
     *   - `<script`: lo evidente.
     *   - `on...=`: los manejadores de evento (`onload`, `onclick`, `onerror`)
     *     ejecutan sin ninguna etiqueta `script` a la vista. Es el vector que se
     *     cuela cuando alguien solo busca lo evidente.
     *   - `<foreignObject`: mete HTML arbitrario —y con el, `iframe` u `object`—
     *     dentro del SVG.
     *   - `<!ENTITY`: entidades XML, que son la puerta de XXE y de la expansion
     *     recursiva que agota la memoria del proceso que parsea.
     *
     * ## Se RECHAZA, no se sanea
     *
     * Escribir un desinfectante de SVG es un proyecto entero y se equivoca en
     * silencio; rechazar se equivoca en voz alta y con una persona delante del
     * panel a la que decirle que exporte el fichero otra vez. **Un logotipo no
     * necesita nada de esto**, asi que el falso positivo cuesta una reexportacion
     * y el falso negativo cuesta un servidor.
     *
     * Insensibles a mayusculas: `<SCRIPT` y `ONLOAD=` son igual de ejecutables.
     *
     * @var list<non-empty-string>
     */
    private const array SVG_ACTIVE_CONTENT = [
        '/<script/i',
        // `\s*=` y no `=` a secas para que `on` dentro de una palabra —`iron=`,
        // un identificador que acabe en «on»— no dispare: se exige el limite de
        // palabra delante.
        '/\bon[a-z]+\s*=/i',
        '/<foreignObject/i',
        '/<!ENTITY/i',
    ];

    public function __construct(
        /** Directorio de marca, montado en el contenedor. Todo logotipo vive dentro. */
        private string $logoRoot,
        /** Tope de bytes del fichero. */
        private int $maximumBytes,
        /** Tope de pixeles de lado de un PNG. Un SVG no tiene pixeles y no se mide. */
        private int $maximumDimension,
    ) {}

    /**
     * Mira el fichero de esa ruta y dice si sirve.
     *
     * La cadena vacia **no llega aqui**: significa «el logotipo del producto» y
     * quien llama la trata antes. Si llegara, se rechaza como ruta no absoluta,
     * que es lo que es.
     */
    public function inspect(string $path): LogoInspection
    {
        try {
            return $this->examine($path);
        } catch (Throwable) {
            // Ni un fallo de I/O inesperado puede salir de aqui: ver el docblock
            // de la clase. `UNREADABLE` es lo mas honesto que se puede decir sin
            // saber que paso.
            return LogoInspection::rejected(LogoRejection::UNREADABLE);
        }
    }

    private function examine(string $path): LogoInspection
    {
        $located = $this->locate($path);

        if ($located instanceof LogoRejection) {
            return LogoInspection::rejected($located);
        }

        $bytes = $this->read($located);

        if ($bytes instanceof LogoRejection) {
            return LogoInspection::rejected($bytes);
        }

        return $this->classify($bytes);
    }

    /**
     * De la ruta configurada a una ruta resuelta que se puede abrir, o el motivo
     * por el que no.
     *
     * Aqui vive **la guarda del endpoint publico**: nada que no cuelgue del
     * directorio de marca llega a la fase siguiente.
     *
     * @return string|LogoRejection la ruta resuelta, o el motivo del rechazo
     */
    private function locate(string $path): string|LogoRejection
    {
        if (! str_starts_with($path, '/')) {
            return LogoRejection::NOT_ABSOLUTE;
        }

        // Sobre la cadena y antes de resolver: si se dejara a `realpath()`, una
        // ruta con un salto a directorio superior que resolviera dentro del
        // directorio se aceptaria, y el dia que el directorio cambie de sitio
        // dejaria de hacerlo sin aviso.
        //
        // POR SEGMENTOS Y NO POR SUBCADENA. Con `str_contains($path, '..')` un
        // fichero llamado `logo..png` —o `mi..hotel/logo.png`— se rechazaba por
        // un nombre perfectamente legitimo, y el cliente recibia un mensaje que
        // hablaba de saltos de directorio sin haber escrito ninguno. Lo que hay
        // que prohibir es el SEGMENTO `..`, que es lo unico que sube de nivel.
        if (\in_array('..', explode('/', $path), true)) {
            return LogoRejection::TRAVERSAL;
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            return LogoRejection::MISSING;
        }

        if (! $this->isInsideRoot($resolved)) {
            return LogoRejection::OUTSIDE_ROOT;
        }

        // Un directorio, un socket o un dispositivo. Para quien lo configuro es
        // lo mismo que no tener fichero.
        return is_file($resolved) ? $resolved : LogoRejection::MISSING;
    }

    /**
     * Los bytes del fichero, o el motivo por el que no se pueden leer.
     *
     * El tamano se comprueba con `filesize()` **antes** de leer: el limite existe
     * justamente para no cargar en memoria lo que no cabe en la pantalla del
     * quiosco, y leerlo para descubrirlo lo haria inutil.
     *
     * @return string|LogoRejection el contenido, o el motivo del rechazo
     */
    private function read(string $resolved): string|LogoRejection
    {
        if (! is_readable($resolved)) {
            return LogoRejection::UNREADABLE;
        }

        $size = filesize($resolved);

        if ($size === false) {
            return LogoRejection::UNREADABLE;
        }

        if ($size > $this->maximumBytes) {
            return LogoRejection::TOO_LARGE;
        }

        $bytes = file_get_contents($resolved);

        if ($bytes === false || $bytes === '') {
            return LogoRejection::UNREADABLE;
        }

        return $bytes;
    }

    /**
     * De bytes a formato, mirando SOLO el contenido.
     */
    private function classify(string $bytes): LogoInspection
    {
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return $this->inspectPng($bytes);
        }

        if ($this->looksLikeSvg($bytes)) {
            return $this->inspectSvg($bytes);
        }

        return LogoInspection::rejected(LogoRejection::UNSUPPORTED_FORMAT);
    }

    private function inspectPng(string $bytes): LogoInspection
    {
        $dimensions = $this->pngDimensions($bytes);

        if ($dimensions === null) {
            // Firma de PNG pero sin IHDR legible: el fichero esta truncado o
            // manipulado. No es un PNG, y decir «formato no admitido» es lo que
            // corresponde.
            return LogoInspection::rejected(LogoRejection::UNSUPPORTED_FORMAT);
        }

        [$width, $height] = $dimensions;

        if ($width > $this->maximumDimension || $height > $this->maximumDimension) {
            return LogoInspection::rejected(LogoRejection::TOO_MANY_PIXELS);
        }

        return LogoInspection::accepted(new LogoImage(LogoFormat::PNG, $bytes));
    }

    private function inspectSvg(string $bytes): LogoInspection
    {
        foreach (self::SVG_ACTIVE_CONTENT as $pattern) {
            if (preg_match($pattern, $bytes) === 1) {
                return LogoInspection::rejected(LogoRejection::ACTIVE_CONTENT);
            }
        }

        return LogoInspection::accepted(new LogoImage(LogoFormat::SVG, $bytes));
    }

    /**
     * Si el texto abre un elemento `svg`, saltandose lo que legitimamente puede
     * ir delante: la marca de orden de bytes, espacios, una declaracion XML y un
     * `<!DOCTYPE svg`.
     *
     * ## Por que se admite el DOCTYPE, siendo por donde entra XXE
     *
     * Justamente por eso. Las herramientas de diseño mas viejas —Illustrator,
     * Inkscape de hace unas versiones— exportan el SVG con su DOCTYPE, y sin
     * reconocerlo un fichero perfectamente legitimo se rechazaba con «no es un
     * PNG ni un SVG», que manda al cliente a buscar el problema donde no esta.
     *
     * Y peor: **la guarda de `<!ENTITY` quedaba inalcanzable**. Un fichero con
     * entidades ni siquiera llegaba a clasificarse como SVG, asi que se rechazaba
     * por el motivo equivocado y la comprobacion que existe para ese caso no se
     * ejecutaba nunca. Reconocer el DOCTYPE es lo que la pone en el camino.
     *
     * Se exige que el DOCTYPE nombre `svg`: asi un `<!DOCTYPE html` sigue siendo
     * «no es un SVG» y no entra por esta puerta.
     */
    private function looksLikeSvg(string $bytes): bool
    {
        $head = substr($bytes, 0, 1024);

        if (str_starts_with($head, self::UTF8_BOM)) {
            $head = substr($head, \strlen(self::UTF8_BOM));
        }

        $head = ltrim($head);

        if (str_starts_with($head, '<?xml')) {
            $end = strpos($head, '?>');

            if ($end === false) {
                // Declaracion XML sin cerrar dentro del primer kilobyte: no es un
                // SVG que ningun navegador vaya a dibujar.
                return false;
            }

            $head = ltrim(substr($head, $end + 2));
        }

        // `<!DOCTYPE svg ...>`, con o sin subconjunto interno. No se intenta
        // recorrerlo hasta su cierre: basta con saber que el documento se declara
        // SVG para clasificarlo, y lo que lleve dentro lo juzga
        // {@see self::SVG_ACTIVE_CONTENT} sobre el fichero entero.
        if (preg_match('/^<!DOCTYPE\s+svg\b/i', $head) === 1) {
            return true;
        }

        return str_starts_with($head, '<svg');
    }

    /**
     * Ancho y alto de un PNG, leidos del IHDR.
     *
     * El IHDR es el primer trozo del fichero y lo exige el estandar, asi que no
     * hay que recorrer nada: bytes 16 a 19 el ancho y 20 a 23 el alto, enteros
     * de 32 bits sin signo y en orden de red.
     *
     * @return array{int, int}|null
     */
    private function pngDimensions(string $bytes): ?array
    {
        if (\strlen($bytes) < self::PNG_HEADER_BYTES) {
            return null;
        }

        if (substr($bytes, 12, 4) !== 'IHDR') {
            return null;
        }

        $header = unpack('Nwidth/Nheight', substr($bytes, 16, 8));

        if ($header === false) {
            return null;
        }

        $width = $header['width'];
        $height = $header['height'];

        if (! \is_int($width) || ! \is_int($height) || $width <= 0 || $height <= 0) {
            return null;
        }

        return [$width, $height];
    }

    /**
     * Si la ruta ya resuelta cuelga del directorio de marca.
     *
     * El separador final es lo que impide que un directorio hermano cuyo nombre
     * empiece igual pase por estar dentro.
     */
    private function isInsideRoot(string $resolved): bool
    {
        $root = realpath($this->logoRoot);

        if ($root === false) {
            // El directorio de marca no existe: no hay nada que pueda estar
            // dentro de el. Es el caso de una instalacion sin el volumen montado,
            // y el motivo que se acaba contando es el correcto.
            return false;
        }

        return $resolved === $root || str_starts_with($resolved, rtrim($root, '/').'/');
    }
}
