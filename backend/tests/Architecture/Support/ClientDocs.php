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

    /**
     * Cuantos caracteres delante de «por correo» deciden si la frase lo NIEGA.
     *
     * Corto a proposito: la negacion legitima va pegada al marcador —«y nunca
     * por correo», «never by email»—, mientras que un «si no recuerdas tu PIN,
     * te lo enviamos por correo» tiene su `no` mucho antes y no debe salvarse.
     */
    private const int NEGATION_WINDOW = 24;

    /**
     * Longitud minima de un trozo de frase de la hoja para exigirlo en la guia.
     *
     * Los marcadores parten alguna frase en cachos, y un cacho de tres letras
     * —«· » o « y »— aparece en cualquier documento: exigirlo no demostraria
     * nada y solo daria por buena una guia que no reproduce la hoja.
     */
    private const int MIN_PHRASE_LENGTH = 4;

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
     * Las variables que `.env.example` marca `[CLIENTE]`: las que rellena el IT
     * del hotel antes de instalar.
     *
     * La marca va en la linea de comentario inmediatamente anterior a la
     * declaracion —o en la misma linea—, que es la convencion que el propio
     * fichero explica en su cabecera («UNA VARIABLE VACIA NO LLEVA COMENTARIO EN
     * SU MISMA LINEA. El comentario va en la linea de encima»). La marca alcanza
     * a UNA variable: el bloque se cierra en cuanto aparece una declaracion, o
     * una `[CLIENTE]` puesta al principio de una familia se llevaria por delante
     * a las diez siguientes, que no lo son.
     *
     * @return list<string>
     */
    public static function clientOwnedEnvironmentKeys(): array
    {
        $marked = [];
        $pending = false;

        foreach (explode("\n", str_replace("\r\n", "\n", self::contents('.env.example'))) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]+)=/', $line, $match) === 1) {
                if ($pending || str_contains($line, '[CLIENTE]')) {
                    $marked[] = $match[1];
                }

                $pending = false;

                continue;
            }

            if (trim($line) === '') {
                $pending = false;

                continue;
            }

            $pending = $pending || str_contains($line, '[CLIENTE]');
        }

        $marked = array_values(array_unique($marked));
        sort($marked);

        return $marked;
    }

    /**
     * Las variables que la tabla de referencia de una guia marca `[CLIENTE]`.
     *
     * @return list<string>
     */
    public static function clientOwnedGuideKeys(string $relative): array
    {
        preg_match_all(
            '/^\| `([A-Z][A-Z0-9_]+)` \| `\[CLIENTE\]` \|/m',
            self::contents($relative),
            $matches,
        );

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
     * De una lista de literales, los que el documento SI menciona.
     *
     * El reverso de {@see literalsMissingFrom()}: aqui la lista es de lo que no
     * puede aparecer —identificadores del codigo en una guia de negocio—, y lo
     * que devuelve es lo que hay que quitar.
     *
     * @param  list<string>  $literals
     * @return list<string>
     */
    public static function literalsFoundIn(array $literals, string $relative): array
    {
        $content = self::contents($relative);
        $found = [];

        foreach ($literals as $literal) {
            if (str_contains($content, $literal)) {
                $found[] = $literal;
            }
        }

        return $found;
    }

    /**
     * De una lista de frases prohibidas, las que el documento contiene.
     *
     * La comparacion va sobre el texto PLEGADO —minusculas, sin acentos, con
     * las comillas tipograficas normalizadas y un solo espacio entre palabras—,
     * para que «Credencial en el Móvil» partido por un salto de linea cuente
     * igual que la frase de la lista. Se prohiben FRASES y no palabras sueltas
     * a proposito (decision 6 de la ficha 5.11b): «movil» es legitimo, porque el
     * portal se abre desde el movil; «tarjeta en el movil» no, porque no existe.
     *
     * @param  list<string>  $phrases
     * @return list<string>
     */
    public static function forbiddenPhrasesFoundIn(array $phrases, string $relative): array
    {
        $text = self::folded(self::contents($relative));
        $found = [];

        foreach ($phrases as $phrase) {
            if (str_contains($text, self::folded($phrase))) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    /**
     * Los sitios donde el documento da a entender que el PIN llega por correo.
     *
     * NO se puede prohibir la frase entera, porque la frase correcta la contiene:
     * `lang/es/instructions-sheet.php` dice «se entrega en mano y nunca por
     * correo», y `hoja-empleado.md` la reproduce literal (decision 2). El
     * criterio es la NEGACION PEGADA al marcador: se acusa todo «por correo» /
     * «by email» que hable del PIN salvo si en los {@see NEGATION_WINDOW}
     * caracteres inmediatamente anteriores hay una negacion. Asi «y nunca por
     * correo» pasa y «recupera tu PIN por correo» no, y tampoco se salva un «si
     * no recuerdas tu PIN, te lo enviamos por correo», cuyo `no` queda lejos.
     *
     * Se acusa solo si la FRASE habla del PIN, no una ventana de tantos
     * caracteres: una guia puede hablar de avisos por correo, y con una ventana
     * fija bastaba que la frase anterior nombrara el PIN para acusarla. La
     * unidad de sentido es la frase, y esa es la que se mira y la que se
     * devuelve en el mensaje.
     *
     * @return list<string>
     */
    public static function pinByEmailClaims(string $relative): array
    {
        $text = self::folded(self::contents($relative));

        preg_match_all('/por correo|by email/', $text, $matches, PREG_OFFSET_CAPTURE);

        $claims = [];

        foreach ($matches[0] as $match) {
            $offset = (int) $match[1];
            $sentence = self::sentenceAround($text, $offset, strlen((string) $match[0]));
            $negation = substr($text, max(0, $offset - self::NEGATION_WINDOW), min($offset, self::NEGATION_WINDOW));

            if (! str_contains($sentence, 'pin')) {
                continue;
            }

            if (preg_match('/\b(no|not|nunca|never|jamas|sin)\b/', $negation) === 1) {
                continue;
            }

            $claims[] = mb_scrub($sentence);
        }

        return $claims;
    }

    /**
     * La frase que contiene una posicion del texto, entre puntos.
     *
     * Sin newlines de por medio: el texto llega ya colapsado, asi que los
     * limites son los signos de puntuacion fuerte. Una lista o una fila de
     * tabla sin punto final se lee como una frase larga, que para lo que se
     * pregunta aqui —¿de que habla esto?— es la lectura correcta.
     */
    private static function sentenceAround(string $text, int $offset, int $length): string
    {
        $start = 0;
        preg_match_all('/[.!?]/', substr($text, 0, $offset), $before, PREG_OFFSET_CAPTURE);
        $previous = end($before[0]);

        if (is_array($previous)) {
            $start = (int) $previous[1] + 1;
        }

        $end = strlen($text);

        if (preg_match('/[.!?]/', $text, $after, PREG_OFFSET_CAPTURE, $offset + $length) === 1) {
            $end = (int) $after[0][1];
        }

        return trim(substr($text, $start, $end - $start));
    }

    /**
     * Los trozos de frase de `lang/<idioma>/instructions-sheet.php`, sin marcadores.
     *
     * La hoja la produce el producto y `hoja-empleado.md` la reproduce para que
     * RRHH sepa que entrega sin abrir el PDF (decision 2 de la ficha 5.11b). Lo
     * que se compara son los trozos ENTRE marcadores: la guia escribe «[nombre
     * de la instalacion]» donde el producto pone `:app_name`, asi que la frase
     * completa nunca coincidiria, pero cada trozo suyo si.
     *
     * Se lee con `require` porque el fichero de idioma es PHP puro que devuelve
     * un array: no hace falta arrancar el framework, y esta suite corre sin el.
     *
     * @return list<string>
     */
    public static function instructionsSheetPhrases(string $locale): array
    {
        $path = Repo::file('backend/lang/'.$locale.'/instructions-sheet.php');

        if (! is_file($path)) {
            throw new RuntimeException('backend/lang/'.$locale.'/instructions-sheet.php no existe: son los textos de la hoja del empleado.');
        }

        /** @var mixed $texts */
        $texts = require $path;

        if (! is_array($texts)) {
            throw new RuntimeException('backend/lang/'.$locale.'/instructions-sheet.php no devuelve un array plano `clave => frase`.');
        }

        $phrases = [];

        foreach ($texts as $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach (preg_split('/:app_name|:portal_url/', $value) ?: [] as $piece) {
                $trimmed = trim($piece);

                if (mb_strlen($trimmed) < self::MIN_PHRASE_LENGTH) {
                    continue;
                }

                $phrases[] = $trimmed;
            }
        }

        return $phrases;
    }

    /**
     * De una lista de frases, las que el documento NO reproduce literalmente.
     *
     * Se comparan los dos textos con los espacios colapsados: el Markdown parte
     * las frases largas en varias lineas, y eso es formato, no un texto
     * distinto. Lo que si cuenta como texto distinto es una palabra cambiada o
     * una marca de enfasis metida en mitad de la frase.
     *
     * @param  list<string>  $phrases
     * @return list<string>
     */
    public static function phrasesMissingFrom(array $phrases, string $relative): array
    {
        $content = self::collapsed(self::contents($relative));
        $missing = [];

        foreach ($phrases as $phrase) {
            if (! str_contains($content, self::collapsed($phrase))) {
                $missing[] = $phrase;
            }
        }

        return $missing;
    }

    /**
     * Los textos que el panel ensena bajo una clave del fichero de idioma.
     *
     * La clave llega con puntos (`corrections.reasons`) y se navega aqui: la
     * prueba pide «lo que el panel ensena» sin saber como esta anidado el JSON.
     * Se devuelven solo los valores de texto; si la clave no existe o no es un
     * objeto, se lanza, porque una lista vacia haria pasar la prueba sin haber
     * comprobado nada.
     *
     * @return list<string>
     */
    public static function panelTexts(string $locale, string $dottedKey): array
    {
        /** @var mixed $node */
        $node = json_decode(
            self::contents('frontend-admin/src/shared/i18n/locales/'.$locale.'.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach (explode('.', $dottedKey) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new RuntimeException($locale.'.json no tiene la clave '.$dottedKey.'.');
            }

            /** @var mixed $node */
            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new RuntimeException($locale.'.json -> '.$dottedKey.' no es un objeto de textos.');
        }

        $texts = [];

        foreach ($node as $value) {
            if (is_string($value)) {
                $texts[] = $value;
            }
        }

        return $texts;
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
     * El texto con un solo espacio entre palabras y sin espacio en los bordes.
     *
     * Es la normalizacion que hace comparable una frase del producto con la
     * misma frase escrita en un Markdown que la parte en tres lineas. El espacio
     * duro entra en la lista porque `\s` no lo cubre y es lo que deja un editor
     * al pegar texto de un PDF.
     */
    private static function collapsed(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00a0}]+/u', ' ', $text));
    }

    /**
     * El texto plegado: colapsado, en minusculas, sin acentos y sin tipografia.
     *
     * Las frases prohibidas se buscan asi porque quien las escribe no las
     * escribe como estan en la lista: en mitad de una frase, con mayuscula
     * inicial, con comillas latinas o con un guion largo. Plegar los dos lados
     * de la comparacion es lo que hace que la prohibicion no dependa de la
     * forma en que se cuele.
     */
    private static function folded(string $text): string
    {
        return strtr(mb_strtolower(self::collapsed($text)), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            '«' => '"', '»' => '"', '“' => '"', '”' => '"', '‘' => "'", '’' => "'",
            '–' => '-', '—' => '-', '·' => '.',
        ]);
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
