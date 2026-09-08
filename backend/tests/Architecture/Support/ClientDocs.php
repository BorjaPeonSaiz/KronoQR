<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Lectura de la documentacion que viaja en el paquete de entrega (docs/cliente).
 *
 * POR QUE ESTA CLASE EXISTE. Las pruebas de `ClientDocumentationTest` no pueden
 * llevar bucles ni condicionales: un `if` dentro de una prueba es una rama que
 * nadie prueba, y un bucle que recorre ficheros esconde el caso que fallo. Todo
 * el recorrido del arbol, el troceado de los bloques de codigo y la resolucion
 * de los enlaces vive aqui, y cada prueba se queda con una lista y una
 * aseveracion.
 *
 * NORMALIZACION DE FIN DE LINEA. Todo lo que se lee pasa por {@see contents()},
 * que convierte CRLF en LF. Las guias las escriben personas distintas en
 * sistemas distintos; comparar la guia espanola con la inglesa y que difieran
 * por un retorno de carro seria un fallo intermitente segun quien tocara el
 * fichero la ultima vez, y un fallo asi se acaba ignorando.
 */
final class ClientDocs
{
    /** El directorio de guias que `package.sh` copia entero en el paquete. */
    public const string ROOT = 'docs/cliente';

    /** Contenido de un fichero del repositorio, con los finales de linea normalizados. */
    public static function contents(string $relative): string
    {
        return str_replace("\r\n", "\n", Repo::contents($relative));
    }

    public static function exists(string $relative): bool
    {
        return is_file(Repo::file($relative));
    }

    /**
     * Todos los `.md` de `docs/cliente`, recursivo, en rutas relativas a la raiz.
     *
     * Con `$subdirectory` se acota a una parte del paquete —`en`, por ejemplo—
     * sin que la prueba tenga que filtrar nada.
     *
     * @return list<string>
     */
    public static function markdownFiles(string $subdirectory = ''): array
    {
        $directory = self::ROOT.($subdirectory === '' ? '' : '/'.$subdirectory);

        if (! is_dir(Repo::file($directory))) {
            throw new RuntimeException($directory.' no existe en el repositorio.');
        }

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Repo::file($directory), FilesystemIterator::SKIP_DOTS),
        );

        $root = str_replace(DIRECTORY_SEPARATOR, '/', Repo::file($directory));
        $files = [];

        foreach ($tree as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }

            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            $files[] = $directory.substr($path, strlen($root));
        }

        sort($files);

        return $files;
    }

    /**
     * Los nombres de variable declarados en `.env.example`.
     *
     * Se aceptan las lineas comentadas (almohadilla y nombre) porque el fichero
     * declara asi las que solo se activan en algunos despliegues: siguen siendo
     * parametros que el IT del cliente puede necesitar, y son justo las que
     * nadie documenta.
     *
     * @return list<string>
     */
    public static function environmentKeys(): array
    {
        // El `=` tiene que ir seguido de un valor sin espacios, de un comentario o
        // del fin de linea: asi una frase de comentario que cita `NOMBRE=valor` en
        // mitad de la prosa no se toma por una declaracion.
        preg_match_all('/^#? ?([A-Z][A-Z0-9_]+)=\S*[ \t]*(?:#.*)?$/m', self::contents('.env.example'), $matches);

        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    /**
     * De una lista de nombres, los que el documento NO cita entre comillas inversas.
     *
     * Entre comillas inversas y no sueltos a proposito: es como se escribe un
     * identificador tecnico en estas guias, y ademas evita que `BACKUP_DB_USERNAME`
     * de por documentada a `BACKUP_DB_USER` por ser prefijo suyo.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function namesMissingFrom(array $names, string $relative): array
    {
        $content = self::contents($relative);
        $missing = [];

        foreach ($names as $name) {
            if (! str_contains($content, '`'.$name.'`')) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * De una lista de literales, los que el documento no menciona tal cual.
     *
     * @param  list<string>  $literals
     * @return list<string>
     */
    public static function literalsMissingFrom(array $literals, string $relative): array
    {
        $content = self::contents($relative);
        $missing = [];

        foreach ($literals as $literal) {
            if (! str_contains($content, $literal)) {
                $missing[] = $literal;
            }
        }

        return $missing;
    }

    /**
     * Las lineas ejecutables de todos los bloques bash de un documento, ordenadas.
     *
     * Fuera quedan los comentarios y las lineas en blanco: son lo unico que la
     * traduccion puede cambiar. Lo que no puede cambiar es el comando, y esta
     * lista es contra lo que se compara la guia inglesa con la espanola.
     *
     * Se ordena porque una traduccion puede reordenar parrafos —y con ellos los
     * bloques— sin que ningun comando cambie. Lo que importa es el CONJUNTO de
     * ordenes que se le piden al cliente, no en que parrafo aparecen.
     *
     * @return list<string>
     */
    public static function bashLines(string $relative): array
    {
        preg_match_all('/^```bash[^\n]*\n(.*?)^```/ms', self::contents($relative), $blocks);

        $lines = [];

        foreach ($blocks[1] as $block) {
            foreach (explode("\n", $block) as $line) {
                $trimmed = rtrim($line);

                if ($trimmed === '' || str_starts_with(ltrim($trimmed), '#')) {
                    continue;
                }

                $lines[] = $trimmed;
            }
        }

        sort($lines);

        return $lines;
    }

    /**
     * Cuantas cabeceras de segundo y tercer nivel tiene el documento.
     *
     * Se quitan antes los bloques de codigo: un comentario de shell a columna
     * cero se parece demasiado a una cabecera y contaria apartados que no
     * existen.
     */
    public static function headingCount(string $relative): int
    {
        $withoutCode = (string) preg_replace('/^```.*?^```/ms', '', self::contents($relative));

        // `preg_match_all` devuelve `false` si el patron no compila; el de aqui
        // es literal y compila siempre, pero PHPStan no puede saberlo y un cero
        // es la lectura correcta de «no se ha podido contar ninguna».
        return (int) preg_match_all('/^#{2,3} /m', $withoutCode);
    }

    /**
     * Las rutas de imagen enlazadas desde un documento, solo las relativas.
     *
     * Las absolutas y las `http` quedan fuera a proposito, igual que en
     * `check-package-links.sh`: un enlace a la web del fabricante es legitimo;
     * una ruta relativa que no viaja en el paquete es un hueco en la pagina.
     *
     * @return list<string>
     */
    public static function imageTargets(string $relative): array
    {
        preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', self::contents($relative), $matches);

        return self::onlyRelative($matches[1]);
    }

    /**
     * Las rutas `.md` enlazadas desde un documento, solo las relativas.
     *
     * @return list<string>
     */
    public static function documentTargets(string $relative): array
    {
        preg_match_all('/\]\(([^)\s]+\.md[^)\s]*)\)/', self::contents($relative), $matches);

        return self::onlyRelative($matches[1]);
    }

    /**
     * Las imagenes enlazadas que no existen, en varios documentos a la vez.
     *
     * @param  list<string>  $documents
     * @return list<string>
     */
    public static function brokenImages(array $documents): array
    {
        $broken = [];

        foreach ($documents as $document) {
            $broken = [...$broken, ...self::brokenTargets($document, self::imageTargets($document))];
        }

        return $broken;
    }

    /**
     * Los enlaces a `.md` que no resuelven, en varios documentos a la vez.
     *
     * @param  list<string>  $documents
     * @return list<string>
     */
    public static function brokenDocumentLinks(array $documents): array
    {
        $broken = [];

        foreach ($documents as $document) {
            $broken = [...$broken, ...self::brokenTargets($document, self::documentTargets($document))];
        }

        return $broken;
    }

    /**
     * Los destinos que NO resuelven, descritos como los enumera el script del paquete.
     *
     * @param  list<string>  $targets
     * @return list<string>
     */
    private static function brokenTargets(string $relative, array $targets): array
    {
        $base = \dirname(Repo::file($relative));
        $broken = [];

        foreach ($targets as $target) {
            if (! file_exists($base.'/'.$target)) {
                $broken[] = $relative.' -> '.$target;
            }
        }

        return $broken;
    }

    /**
     * El texto que va desde una cabecera hasta el final del documento.
     *
     * Cadena vacia si la cabecera no esta, para que la prueba lo diga con su
     * propio mensaje en vez de reventar con una excepcion de indice.
     */
    public static function section(string $relative, string $heading): string
    {
        $content = self::contents($relative);
        $position = mb_strpos($content, $heading);

        return $position === false ? '' : mb_substr($content, $position);
    }

    /**
     * Los nombres de variable de la primera columna de las tablas de un texto.
     *
     * Solo cuenta la celda que ABRE la fila, y solo si el nombre va entre
     * comillas inversas. Asi la prosa del apartado —que cita las mismas
     * variables en mitad de una frase— no entra en la comprobacion: lo que se
     * verifica es la tabla de referencia, que es lo que el lector usa como
     * catalogo.
     *
     * @return list<string>
     */
    public static function tableKeys(string $section): array
    {
        preg_match_all('/^\|\s*`([A-Z][A-Z0-9_]+)`/m', $section, $matches);

        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    /** La serie `mayor.menor` de un SemVer, o cadena vacia si no lo es. */
    public static function minorSeries(string $version): string
    {
        preg_match('/^(\d+\.\d+)/', trim($version), $matches);

        return $matches[1] ?? '';
    }

    /**
     * Asignaciones con pinta de secreto de verdad, en varios documentos a la vez.
     *
     * Se devuelve el documento y el NOMBRE de la variable, nunca el valor: si
     * algun dia esta prueba se pone roja, su mensaje se pega en un tique o en un
     * log de la CI, y lo ultimo que debe hacer una prueba que caza secretos es
     * publicar el que ha cazado.
     *
     * @param  list<string>  $documents
     * @return list<string>
     */
    public static function secretLikeAssignments(array $documents): array
    {
        $findings = [];

        foreach ($documents as $document) {
            preg_match_all(
                '/^[ \t]*([A-Z][A-Z0-9_]*(?:KEY|SECRET|PASSWORD|TOKEN)[A-Z0-9_]*)=[^\s]{16,}/m',
                self::contents($document),
                $matches,
            );

            foreach (array_unique($matches[1]) as $variable) {
                $findings[] = $document.' -> '.$variable;
            }
        }

        return $findings;
    }

    /**
     * Los runbooks, que tambien viajan en el paquete (`docs/runbooks`).
     *
     * @return list<string>
     */
    public static function runbookFiles(): array
    {
        $files = [];

        foreach (glob(Repo::file('docs/runbooks').'/*.md') ?: [] as $path) {
            $files[] = 'docs/runbooks/'.basename($path);
        }

        sort($files);

        return $files;
    }

    /**
     * Los comandos `artisan espacio:nombre` que citan varios documentos.
     *
     * @param  list<string>  $documents
     * @return list<string>
     */
    public static function citedArtisanCommands(array $documents): array
    {
        $commands = [];

        foreach ($documents as $document) {
            preg_match_all('/\bartisan ([a-z0-9]+:[a-z0-9:_-]+)/', self::contents($document), $matches);
            $commands = [...$commands, ...$matches[1]];
        }

        $commands = array_values(array_unique($commands));
        sort($commands);

        return $commands;
    }

    /**
     * Los comandos que declara el arbol: cada `$signature` de `backend/app` y cada
     * `Artisan::command()` de `routes/console.php`.
     *
     * Se lee el arbol y no `Artisan::all()` porque la suite Architecture corre
     * sobre PHPUnit puro, sin arrancar el framework.
     *
     * @return list<string>
     */
    public static function declaredArtisanCommands(): array
    {
        $commands = [];

        foreach (self::phpFiles('backend/app') as $file) {
            preg_match_all('/\\$signature = \'([a-z0-9]+:[a-z0-9:_-]+)/', self::contents($file), $matches);
            $commands = [...$commands, ...$matches[1]];
        }

        preg_match_all('/Artisan::command\\(\'([a-z0-9]+:[a-z0-9:_-]+)/', self::contents('backend/routes/console.php'), $matches);
        $commands = [...$commands, ...$matches[1]];

        $commands = array_values(array_unique($commands));
        sort($commands);

        return $commands;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Repo::file($directory), FilesystemIterator::SKIP_DOTS),
        );
        $root = str_replace(DIRECTORY_SEPARATOR, '/', Repo::file($directory));
        $files = [];

        foreach ($tree as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $directory.substr(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), strlen($root));
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $targets
     * @return list<string>
     */
    private static function onlyRelative(array $targets): array
    {
        $relatives = [];

        foreach ($targets as $target) {
            if (str_starts_with($target, 'http') || str_starts_with($target, '/')) {
                continue;
            }

            $withoutAnchor = (string) preg_replace('/[#?].*$/', '', $target);

            if ($withoutAnchor === '') {
                continue;
            }

            $relatives[] = $withoutAnchor;
        }

        return array_values(array_unique($relatives));
    }
}
