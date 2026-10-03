<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RecordErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Product\Domain\ValueObject\ErrorVocabulary;
use Tests\Architecture\Support\Repo;
use Tests\Support\Product\FrequentPersonNames;

/*
 * EL VOCABULARIO CUBRE TODO LO QUE EL PRODUCTO EMITE (ADR-048, RF-PD-15).
 *
 * La otra mitad de `ErrorVocabularyTest`. Aquella vigila la privacidad (que no
 * haya nombres); esta vigila el diagnostico: una palabra que el producto usa en
 * sus mensajes, en sus eventos de log o en el contexto de sus clientes y que no
 * esta en el vocabulario se convierte en `…` en el historico de errores, y
 * nadie lo nota hasta que soporte abre un paquete ilegible.
 *
 * **Si falla, no es una fuga: es diagnostico que se pierde.** Se arregla
 * anadiendo la palabra a `ErrorVocabulary` (un PR revisado), salvo que sea un
 * nombre o apellido del conjunto de datos de `ErrorVocabularyTest`: esas se
 * pierden a proposito y aqui se toleran.
 *
 * Se extrae de los ficheros, no de una segunda lista escrita a mano: dos listas
 * que tienen que decir lo mismo acaban diciendo cosas distintas.
 */

/**
 * Las palabras (partes de mas de una letra, partidas por los cambios de caja)
 * de un texto, plegadas.
 *
 * @return list<string>
 */
function errorVocabularyCoverageWords(string $text): array
{
    preg_match_all('/\p{L}+/u', $text, $runs);
    $words = [];

    foreach ($runs[0] as $run) {
        if (ErrorVocabulary::contains($run)) {
            continue;
        }

        foreach (preg_split('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', $run) ?: [] as $part) {
            if (mb_strlen($part) > 1) {
                $words[] = ErrorVocabulary::fold($part);
            }
        }
    }

    return $words;
}

/**
 * Las palabras de `$texts` que no estan en el vocabulario ni son un nombre del
 * conjunto de datos, con el sitio donde aparecen.
 *
 * @param  array<string, list<string>>  $texts  Sitio => textos.
 * @return list<string>
 */
function errorVocabularyCoverageMissing(array $texts): array
{
    $names = array_map(ErrorVocabulary::fold(...), FrequentPersonNames::words());
    $missing = [];

    foreach ($texts as $where => $values) {
        foreach ($values as $value) {
            foreach (errorVocabularyCoverageWords($value) as $word) {
                if (! ErrorVocabulary::contains($word) && ! in_array($word, $names, true)) {
                    $missing[$word] = $word.' ('.$where.')';
                }
            }
        }
    }

    ksort($missing);

    return array_values($missing);
}

/**
 * @return list<string>
 */
function errorVocabularyCoverageFiles(string $relativeRoot, string $suffix): array
{
    $root = Repo::file($relativeRoot);

    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    /** @var Iterator<string, SplFileInfo> $found */
    $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($found as $path => $file) {
        $name = str_replace('\\', '/', (string) $path);

        if (str_ends_with($name, $suffix) && ! str_ends_with($name, '.d.ts')
            && preg_match('#\.(spec|test)\.ts$|/__tests__/|/tests?/#', $name) !== 1) {
            $files[] = $name;
        }
    }

    sort($files);

    return $files;
}

/**
 * Los literales de texto de un fichero PHP, sin comentarios.
 *
 * @return list<string>
 */
function errorVocabularyCoverageLiterals(string $file): array
{
    $literals = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $literals[] = $token[1];
        }
    }

    return $literals;
}

/**
 * Los mensajes de las excepciones del producto (ADR-048: 123 ficheros en
 * `Modules/*\/{Domain,Application}/Exception`).
 *
 * @return array<string, list<string>>
 */
function errorVocabularyCoverageExceptionMessages(): array
{
    $texts = [];

    foreach (['Domain', 'Application'] as $layer) {
        foreach (glob(Repo::file('backend/app/Modules').'/*/'.$layer.'/Exception/*.php') ?: [] as $file) {
            $texts[basename($file)] = errorVocabularyCoverageLiterals($file);
        }
    }

    return $texts;
}

/**
 * Los mensajes de las excepciones que se lanzan en cualquier punto de `app/`:
 * todos los literales que hay entre los parentesis de un `new …Exception(…)`
 * (o `Error`), tambien los que se concatenan en varias lineas. Es lo que
 * escribe el log tecnico y lo que guarda `error_events` cuando algo falla.
 *
 * @return array<string, list<string>>
 */
function errorVocabularyCoverageThrownMessages(): array
{
    $texts = [];

    foreach (errorVocabularyCoverageFiles('backend/app', '.php') as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        $literals = [];

        foreach (array_keys($tokens) as $index) {
            if (is_array($tokens[$index]) && $tokens[$index][0] === T_NEW) {
                array_push($literals, ...errorVocabularyCoverageExceptionLiterals($tokens, $index + 1));
            }
        }

        if ($literals !== []) {
            $texts[basename($file)] = $literals;
        }
    }

    return $texts;
}

/**
 * Los literales del `new` que empieza en `$from`, si lo que se construye es una
 * excepcion o un error; ninguno si no.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function errorVocabularyCoverageExceptionLiterals(array $tokens, int $from): array
{
    $count = count($tokens);
    $at = $from;

    while ($at < $count && $tokens[$at] !== '(') {
        $at++;
    }

    $name = implode('', array_map(
        static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
        array_slice($tokens, $from, $at - $from),
    ));

    if (preg_match('/(?:Exception|Error)\s*$/', $name) !== 1) {
        return [];
    }

    $literals = [];
    $depth = 0;

    // Hasta el parentesis que cierra la llamada.
    for (; $at < $count; $at++) {
        $depth += match ($tokens[$at]) {
            '(' => 1,
            ')' => -1,
            default => 0,
        };

        if ($depth === 0) {
            break;
        }

        if (is_array($tokens[$at]) && in_array($tokens[$at][0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $literals[] = $tokens[$at][1];
        }
    }

    return $literals;
}

/**
 * Los identificadores de evento del log tecnico (`product.error_history_write_failed`).
 *
 * @return array<string, list<string>>
 */
function errorVocabularyCoverageLogEvents(): array
{
    $texts = [];

    foreach (errorVocabularyCoverageFiles('backend/app', '.php') as $file) {
        preg_match_all(
            '/(?:->|::)(?:debug|info|notice|warning|error|critical|alert|emergency)\(\s*[\'"]([a-z0-9_.:\-]+)[\'"]/',
            (string) file_get_contents($file),
            $matches,
        );

        if ($matches[1] !== []) {
            $texts[basename($file)] = array_map(
                static fn (string $id): string => str_replace(['.', '_', ':', '-'], ' ', $id),
                $matches[1],
            );
        }
    }

    return $texts;
}

/**
 * Lo que mandan los clientes en el contexto de un error: los valores literales
 * de los objetos que pasan a `report(…)` y a los ganchos de diagnostico, los
 * codigos que pasan delante (el mensaje cae al codigo cuando no hay mensaje) y
 * los miembros de los tipos de causa, motivo y desenlace.
 *
 * @return array<string, list<string>>
 */
function errorVocabularyCoverageClientValues(): array
{
    $texts = [];
    $files = [
        ...errorVocabularyCoverageFiles('frontend-kiosk/src', '.ts'),
        ...errorVocabularyCoverageFiles('frontend-kiosk/src', '.vue'),
        ...errorVocabularyCoverageFiles('packages/web-kit/src', '.ts'),
    ];

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        $values = [];

        // Las llamadas que reportan, con su primer argumento y su objeto.
        preg_match_all(
            '/\b(?:report|onDiagnostic|onBlocked|onDenied|onError)\s*(?:\?\.)?\s*\(\s*([\'"]([^\'"]+)[\'"])?([^;]*?\{[^{}]*\})?/s',
            $code,
            $calls,
        );

        foreach ($calls[2] as $index => $firstArgument) {
            if ($firstArgument !== '') {
                $values[] = str_replace(['.', '_', ':', '-'], ' ', $firstArgument);
            }

            preg_match_all('/:\s*[\'"`]([^\'"`$]+)/', $calls[3][$index], $literals);

            foreach ($literals[1] as $literal) {
                $values[] = str_replace(['_', '-'], ' ', $literal);
            }
        }

        // Los tipos de causa, motivo, ambito, estado y desenlace.
        preg_match_all('/type\s+\w*(?:Cause|Outcome|Reason|Scope|State|Kind)\s*=((?:\s*(?:\/\/[^\n]*|\/\*.*?\*\/)?\s*\|?\s*\'[^\']+\')+)/s', $code, $unions);

        foreach ($unions[1] as $union) {
            preg_match_all('/\'([^\']+)\'/', $union, $members);

            foreach ($members[1] as $member) {
                $values[] = str_replace(['_', '-'], ' ', $member);
            }
        }

        if ($values !== []) {
            $texts[str_replace(str_replace('\\', '/', Repo::root()).'/', '', $file)] = $values;
        }
    }

    return $texts;
}

/**
 * El catalogo cerrado de codigos de cliente y los tipos de problema del
 * contrato.
 *
 * @return array<string, list<string>>
 */
function errorVocabularyCoverageCatalogues(): array
{
    preg_match_all(
        '/\'((?:kiosk|web)\.[a-z0-9_.]+)\'/',
        (string) file_get_contents(Repo::file('backend/app/Modules/Shared/Domain/ValueObject/ClientErrorCode.php')),
        $codes,
    );

    preg_match_all(
        '/urn:kronoqr:problem:([a-z0-9\-]+)/',
        (string) file_get_contents(Repo::file('docs/api/openapi.yaml')),
        $problems,
    );

    return [
        'ClientErrorCode' => array_map(static fn (string $c): string => str_replace(['.', '_'], ' ', $c), $codes[1]),
        'openapi.yaml' => array_map(static fn (string $p): string => 'urn kronoqr problem '.str_replace('-', ' ', $p), $problems[1]),
    ];
}

/**
 * Lo que el servidor pone en el contexto por su cuenta: las rutas y los
 * comandos.
 *
 * @return array<string, list<string>>
 */
function errorVocabularyCoverageServerContext(): array
{
    preg_match_all(
        '/Route::(?:get|post|put|patch|delete|prefix)\(\s*[\'"]([^\'"]*)[\'"]/',
        (string) file_get_contents(Repo::file('backend/routes/api_v1.php')),
        $routes,
    );

    $commands = [];

    foreach (errorVocabularyCoverageFiles('backend/app', '.php') as $file) {
        if (preg_match('/\$signature\s*=\s*[\'"]([^\s\'"{]+)/', (string) file_get_contents($file), $signature) === 1) {
            $commands[] = str_replace([':', '-', '_'], ' ', $signature[1]);
        }
    }

    return [
        'routes/api_v1.php' => array_map(static fn (string $r): string => str_replace(['/', '-', '_', '{', '}', '?'], ' ', $r), $routes[1]),
        'comandos' => $commands,
    ];
}

it('encuentra lo que tiene que comprobar, o no esta comprobando nada', function (): void {
    expect(count(errorVocabularyCoverageExceptionMessages()))->toBeGreaterThan(100)
        ->and(count(errorVocabularyCoverageLogEvents()))->toBeGreaterThan(20)
        ->and(errorVocabularyCoverageClientValues())->toHaveKey('frontend-kiosk/src/main.ts')
        ->and(errorVocabularyCoverageClientValues())->toHaveKey('frontend-kiosk/src/shared/api/client.ts')
        ->and(errorVocabularyCoverageCatalogues()['ClientErrorCode'])->not->toBeEmpty()
        ->and(errorVocabularyCoverageCatalogues()['openapi.yaml'])->not->toBeEmpty()
        ->and(errorVocabularyCoverageServerContext()['routes/api_v1.php'])->not->toBeEmpty()
        ->and(errorVocabularyCoverageServerContext()['comandos'])->not->toBeEmpty();
})->group('RF-PD-15');

it('cubre las palabras de los mensajes de las excepciones del producto', function (): void {
    $faltan = errorVocabularyCoverageMissing(errorVocabularyCoverageExceptionMessages());

    expect($faltan)->toBe([], count($faltan).' palabra(s) de las excepciones del producto no estan en '
        .'ErrorVocabulary y se perderian en el historico de errores: '.implode(', ', $faltan));
})->group('RF-PD-15');

it('cubre las palabras de las excepciones que se lanzan en cualquier punto de app/', function (): void {
    $faltan = errorVocabularyCoverageMissing(errorVocabularyCoverageThrownMessages());

    expect($faltan)->toBe([], count($faltan).' palabra(s) de excepciones lanzadas en app/ no estan en '
        .'ErrorVocabulary y saldrian como «…» en el log y en el historico: '.implode(', ', $faltan));
})->group('RF-PD-15');

it('cubre los identificadores de evento del log tecnico', function (): void {
    $faltan = errorVocabularyCoverageMissing(errorVocabularyCoverageLogEvents());

    expect($faltan)->toBe([], 'Partes de eventos de log fuera de ErrorVocabulary: '.implode(', ', $faltan));
})->group('RF-PD-15');

it('cubre todo lo que mandan los clientes en el contexto de un error', function (): void {
    $faltan = errorVocabularyCoverageMissing(errorVocabularyCoverageClientValues());

    expect($faltan)->toBe([], 'Valores de contexto de los clientes fuera de ErrorVocabulary: '.implode(', ', $faltan));
})->group('RF-PD-15', 'RL-19');

it('cubre el catalogo de codigos de cliente y los tipos de problema del contrato', function (): void {
    $faltan = errorVocabularyCoverageMissing(errorVocabularyCoverageCatalogues());

    expect($faltan)->toBe([], 'Codigos o tipos de problema fuera de ErrorVocabulary: '.implode(', ', $faltan));
})->group('RF-PD-15');

it('cubre las rutas y los comandos que el servidor pone en el contexto', function (): void {
    $faltan = errorVocabularyCoverageMissing(errorVocabularyCoverageServerContext());

    expect($faltan)->toBe([], 'Partes de rutas o comandos fuera de ErrorVocabulary: '.implode(', ', $faltan));
})->group('RF-PD-15');

it('deja pasar enteros los valores que emiten hoy los clientes', function (string $valor): void {
    // Los de §1.4 del diseño, valor a valor: estos son los que llegan tal cual.
    expect(ErrorMessageSanitizer::sanitizeContextValue($valor))->toBe($valor);
})->with([
    // `error_type`: nombres de `Error` y de `DOMException` de los navegadores.
    'TypeError', 'RangeError', 'SyntaxError', 'ReferenceError', 'AbortError', 'TimeoutError', 'NotAllowedError',
    'NotFoundError', 'NotReadableError', 'OverconstrainedError', 'SecurityError', 'NotSupportedError',
    'InvalidStateError', 'QuotaExceededError', 'DataCloneError', 'ConstraintError', 'TransactionInactiveError',
    'VersionError', 'UnknownError', 'unknown',
    // `hook`: las cadenas de `ErrorTypeStrings` de Vue y la referencia de produccion.
    'setup function', 'render function', 'watcher getter', 'watcher callback', 'watcher cleanup function',
    'native event handler', 'component event handler', 'vnode hook', 'directive hook', 'transition hook',
    'app errorHandler', 'app warnHandler', 'ref function', 'async component loader', 'scheduler flush',
    'component update', 'app unmount cleanup function', 'https://vuejs.org/error-reference/#runtime-1',
    // `component`, `scope` y `audio_state`.
    '(anonimo)', 'vue', 'window', 'promise', 'suspended', 'running', 'closed', 'interrupted',
])->group('RF-PD-15', 'RL-19');

it('deja legible el mensaje fijo del grupo de desbordamiento', function (): void {
    // El colector vuelve a sanear al leer: si una palabra de este mensaje no
    // estuviera en el vocabulario, el grupo de desbordamiento saldria roto.
    $mensaje = (new ReflectionClassConstant(RecordErrorEvent::class, 'OVERFLOW_MESSAGE'))->getValue();

    if (! is_string($mensaje)) {
        throw new RuntimeException('RecordErrorEvent::OVERFLOW_MESSAGE ha dejado de ser texto.');
    }

    expect(ErrorMessageSanitizer::sanitize($mensaje))->toBe($mensaje);
})->group('RF-PD-15');
