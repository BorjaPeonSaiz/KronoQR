<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;
use Tests\Architecture\Support\Repo;

/*
 * La lista de permitidos de `error_events.context` **dice lo mismo que los
 * clientes emiten** (RF-PD-15, decision 5, revision).
 *
 * ## El fallo que esta prueba impide que vuelva
 *
 * La primera version de `ErrorContextAllowlist` declaraba `status`, `outcome`,
 * `queue_size` y `code`: **ni una sola clave que un cliente escriba**. Nada
 * fallaba —la lista de permitidos hace lo que tiene que hacer: descartar lo que
 * no conoce—, y el resultado en la instalacion de un cliente era que el contexto
 * de todo error de quiosco y de web salia vacio, los errores llegaban sin
 * mensaje y **todos los `web.vue_error` colapsaban en una unica fila**, porque
 * la huella se calcula sobre el mensaje.
 *
 * Es el peor modo de fallo posible en este historico: no rompe nada, degrada en
 * silencio, y solo se ve mirando la tabla de un cliente.
 *
 * ## Como se extraen las claves
 *
 * De los ficheros de los clientes, no de una segunda lista escrita a mano —eso
 * seria dos listas que tienen que decir lo mismo, que es el problema que esta
 * prueba existe para evitar—. Se buscan las llamadas a las funciones que
 * reportan (`report(`, `onDiagnostic`, `onBlocked`, `onDenied`, `onError`) y se
 * leen las claves del objeto literal que llevan al lado.
 *
 * **La extraccion es deliberadamente conservadora**: si una llamada pasa una
 * variable en vez de un literal (`reporter.report(CODIGO, context)`), no se
 * extrae nada de ella. Eso puede dejar pasar una clave nueva, pero **nunca
 * produce un falso positivo**, que es lo que convertiria esta prueba en ruido
 * que alguien acaba silenciando.
 *
 * ## `message` es la excepcion, y esta documentada
 *
 * Los tres reporters mandan el texto del error con la clave `message`. El
 * servidor la **eleva a la columna `message`** y la retira del contexto: si
 * ademas se guardara ahi estaria dos veces, con dos longitudes maximas
 * distintas. Por eso no esta en la lista y por eso se excluye aqui por su
 * nombre, no por descarte.
 */

/**
 * La raiz del repositorio, que no es la del backend.
 *
 * Por {@see Repo} y no por `base_path()` ni contando niveles: la suite
 * `Architecture` corre sobre PHPUnit puro —sin framework, `tests/Pest.php`— y
 * dentro del contenedor solo esta montado `backend/`; el arbol completo llega en
 * `/var/www/repo`. Ese ayudante ya resuelve las dos situaciones, y contar
 * niveles a mano es exactamente el error que su docblock cuenta que costo nueve
 * pruebas verdes en local y rojas en la CI.
 */
function repoRoot(): string
{
    return str_replace('\\', '/', Repo::root());
}

/**
 * Los ficheros de cliente de los que se extraen claves de contexto.
 *
 * @return list<string>
 */
function clientSourceFiles(): array
{
    $roots = [
        repoRoot().'/frontend-kiosk/src',
        repoRoot().'/packages/web-kit/src',
    ];

    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var Iterator<string, SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($found as $path => $file) {
            $name = str_replace('\\', '/', (string) $path);

            // `schema.d.ts` son los tipos generados del contrato: miles de
            // claves que nadie reporta.
            if (str_ends_with($name, '.d.ts')) {
                continue;
            }

            if (str_ends_with($name, '.ts') || str_ends_with($name, '.vue')) {
                $files[] = $name;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Las claves de contexto que aparecen en los ficheros de los clientes.
 *
 * @return array<string, list<string>> Clave => ficheros en los que aparece.
 */
function clientContextKeys(): array
{
    $keys = [];

    foreach (clientSourceFiles() as $file) {
        $code = (string) file_get_contents($file);
        $relative = str_replace(repoRoot().'/', '', $file);

        /*
         * Toda llamada a algo que reporta, con lo que venga detras.
         *
         * El `(?:\?\.)?` no es cosmetico: los callbacks opcionales del quiosco se
         * invocan con `options.onDiagnostic?.('codigo', { … })`, y sin admitir
         * esa forma la extraccion se dejaba fuera siete claves reales
         * —`audio_state`, `silence_ms`, `durable`, `entries`, `items`,
         * `missing`, `purged`—, que es justo el tipo de agujero silencioso que
         * esta prueba existe para cerrar.
         */
        preg_match_all(
            '/\b(?:report|onDiagnostic|onBlocked|onDenied|onError)\s*(?:\?\.)?\s*\(/',
            $code,
            $calls,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($calls[0] as [$match, $offset]) {
            // DESPUES del `(` de la llamada: si el recorrido empezara en el
            // nombre, ese parentesis contaria como apertura y el de cierre de la
            // llamada nunca llevaria la cuenta a cero.
            foreach (objectLiteralKeys($code, (int) $offset + strlen((string) $match)) as $key) {
                $keys[$key][] = $relative;
            }
        }
    }

    foreach ($keys as $key => $files) {
        $keys[$key] = array_values(array_unique($files));
    }

    ksort($keys);

    return $keys;
}

/**
 * Las claves del primer objeto literal que aparece tras `$offset`, dentro de la
 * misma llamada.
 *
 * Se recorre con un contador de llaves para que un literal de varias lineas
 * —los de `main.ts`— se lea entero, y se corta en el parentesis que cierra la
 * llamada para no arrastrar el codigo que venga despues.
 *
 * @return list<string>
 */
function objectLiteralKeys(string $code, int $offset): array
{
    $length = strlen($code);
    $depth = 1;
    $brace = 0;
    $literal = '';

    for ($i = $offset; $i < $length; $i++) {
        $char = $code[$i];

        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;

            if ($depth === 0) {
                break;
            }
        } elseif ($char === '{') {
            $brace++;
        } elseif ($char === '}') {
            $brace--;
        }

        if ($brace > 0) {
            $literal .= $char;
        }
    }

    if ($literal === '') {
        return [];
    }

    /*
     * `clave:` o la forma abreviada `{ clave, … }` de JavaScript, que los
     * clientes usan tanto como la otra (`{ reason, durable: false }`). Se exige
     * el `{` o la coma delante para no capturar el `:` de un tipo, de un
     * ternario o del valor de la clave anterior.
     */
    /*
     * El delimitador de cierre va en un LOOKAHEAD y no se consume, porque la
     * coma que termina una clave es la que empieza la siguiente. Consumiendola,
     * `{ reason, durable: false }` daba `reason` y se dejaba `durable` fuera —y
     * ese es exactamente el tipo de silencio que esta prueba persigue—.
     */
    preg_match_all('/[{,]\s*([a-z][a-z0-9_]*)\s*(?=[:,}])/i', $literal.'}', $matches);

    return array_values(array_unique($matches[1]));
}

it('extrae claves de contexto de los clientes, o la prueba no esta comprobando nada', function (): void {
    // La red bajo la red: si la extraccion dejara de encontrar ficheros -una
    // carpeta que se mueve, una extension que cambia-, las dos pruebas de abajo
    // pasarian vacias y nadie se enteraria.
    expect(clientSourceFiles())->not->toBeEmpty('No se ha encontrado ningun fichero de cliente que analizar.')
        ->and(clientContextKeys())->not->toBeEmpty('No se ha extraido ninguna clave de contexto de los clientes.');
})->group('RF-PD-15');

it('no deja fuera de la lista de permitidos ninguna clave que un cliente emita', function (): void {
    // Una clave que un cliente escribe y el servidor no admite se descarta EN
    // SILENCIO: el contexto llega mas pobre, o vacio, y no falla nada. Esta
    // prueba convierte ese silencio en un fallo con nombre.
    $permitidas = ErrorContextAllowlist::keys();
    $fuera = [];

    foreach (clientContextKeys() as $key => $files) {
        // La unica excepcion, y esta razonada: `message` asciende a la columna
        // `message` y se retira del contexto para no guardarse dos veces.
        if ($key === ErrorContextAllowlist::MESSAGE_KEY) {
            continue;
        }

        if (! in_array($key, $permitidas, true)) {
            $fuera[] = $key.' ('.implode(', ', $files).')';
        }
    }

    expect($fuera)->toBe([], count($fuera).' clave(s) de contexto que los clientes emiten NO estan en '
        .'ErrorContextAllowlist, asi que se descartan en silencio: '.implode('; ', $fuera));
})->group('RF-PD-15', 'RL-19');

it('atrapa las claves reales de los tres reporters', function (string $key): void {
    // La comprobacion de arriba es en un sentido; esta fija el suelo en el otro:
    // que la extraccion encuentre de verdad las claves que sabemos que existen.
    // Sin ella, una expresion regular rota daria una lista vacia y la prueba
    // anterior pasaria siempre.
    expect(clientContextKeys())->toHaveKey($key);
})->with([
    // Quiosco.
    'error_type', 'scope', 'cause', 'http_status', 'reason', 'durable', 'entries', 'items', 'missing',
    'purged', 'skew_seconds', 'audio_state', 'silence_ms',
    // Panel y portal (`web-kit`).
    'component', 'hook', 'source', 'line',
    // Y la que asciende a columna.
    'message',
])->group('RF-PD-15');

it('no declara en la lista ninguna clave que nadie escriba', function (): void {
    // La otra direccion, y la que atrapa el fallo original: una clave inventada
    // en la lista no rompe nada y hace creer que el contexto lleva algo que no
    // lleva. Las del servidor no salen de los ficheros de cliente, asi que se
    // nombran aqui —son la otra mitad de la lista, y `Capture` es de otro
    // agente—.
    $delServidor = ['route', 'method', 'job', 'queue', 'attempts', 'command'];
    $deLosClientes = array_keys(clientContextKeys());

    $inventadas = array_values(array_diff(ErrorContextAllowlist::keys(), $delServidor, $deLosClientes));

    expect($inventadas)->toBe([], count($inventadas).' clave(s) de ErrorContextAllowlist que no escribe ni el '
        .'servidor ni ningun cliente: '.implode(', ', $inventadas));
})->group('RF-PD-15');
