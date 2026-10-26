<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * Reglas duras 1 y 2 de CLAUDE.md, la mitad que Deptrac no puede ver.
 *
 * Deptrac razona sobre *imports*, asi que cubre "Domain no depende de
 * Illuminate" pero es ciego a `now()`, `time()` o `strtotime()`: son funciones
 * globales del nucleo de PHP y no aparecen en ningun `use`. Esa mitad se cubre
 * aqui (doc 02 §9.2, que empareja Pest Arch con Deptrac) y con Semgrep, que ve
 * la tercera forma —`new DateTimeImmutable()` sin instante—, invisible para las
 * otras dos porque el import de la clase es legitimo.
 *
 * Sin esto no se pueden probar DST ni los turnos que cruzan medianoche, que son
 * la mitad del riesgo de este dominio.
 */

/** @return list<string> */
function domainFiles(): array
{
    return ModuleTree::filesInLayer(ModuleTree::MODULES, 'Domain');
}

it('no llama al reloj del sistema desde el dominio', function (): void {
    // Regla dura 2. Los limites se escriben explicitos: estas seis funciones y
    // esta clase, no "las funciones de fecha" en abstracto.
    $forbidden = ['now', 'time', 'date', 'mktime', 'microtime', 'strtotime'];

    $offenders = [];

    foreach (domainFiles() as $file) {
        $source = (string) file_get_contents($file);

        foreach ($forbidden as $function) {
            // Llamada a funcion global: el nombre seguido de '(', sin '->', '::'
            // ni 'function ' delante, para no confundir un metodo propio
            // llamado date() con la funcion global date().
            $pattern = '/(?<![\w>:$])'.preg_quote($function, '/').'\s*\(/';

            if (preg_match($pattern, $source) === 1) {
                $offenders[] = ModuleTree::relative($file).' llama a '.$function.'()';
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

it('no importa Carbon en el dominio', function (): void {
    $offenders = [];

    foreach (domainFiles() as $file) {
        foreach (ModuleTree::importsOf($file) as $import) {
            if (str_starts_with($import, 'Carbon\\')) {
                $offenders[] = ModuleTree::relative($file).' importa '.$import;
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

it('no importa el framework ni Eloquent en el dominio', function (): void {
    // Regla dura 1. Deptrac ya lo verifica y la CI falla; se duplica aqui a
    // proposito porque `make test` y `make quality` se ejecutan por separado y
    // esta es la regla que no debe poder colarse por ninguna de las dos vias.
    $offenders = [];

    foreach (domainFiles() as $file) {
        foreach (ModuleTree::importsOf($file) as $import) {
            if (str_starts_with($import, 'Illuminate\\') || str_starts_with($import, 'App\\Models\\')) {
                $offenders[] = ModuleTree::relative($file).' importa '.$import;
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

it('no conoce el puerto Clock desde el dominio', function (): void {
    // Verificacion literal de ADR-021: "Modules/*/Domain no importa
    // Shared\Application\*. El dominio recibe instantes; no conoce el puerto."
    $offenders = [];

    foreach (domainFiles() as $file) {
        foreach (ModuleTree::importsOf($file) as $import) {
            if (str_starts_with($import, 'App\\Modules\\Shared\\Application\\')) {
                $offenders[] = ModuleTree::relative($file).' importa '.$import;
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

it('no pasa un texto libre al analizador de fechas de PHP en el dominio (V1-AR-2)', function (): void {
    // `new DateTimeImmutable($texto)` acepta `"now"`, `"tomorrow"` o `"+1 week"`
    // y entonces lee el reloj del proceso aunque no lo parezca: el argumento es
    // una variable y ni la lista de funciones de arriba ni Semgrep (que busca
    // el `'now'` literal) lo ven. Un texto que llega de fuera se lee con
    // `UtcInstant::parse()` o `createFromFormat('!…')`, que solo admiten un
    // instante escrito. Lo que queda en el dominio compone la cadena con una
    // fecha ya validada (`WorkDate`, `IsoWeek`…); que tambien pase a
    // `createFromFormat('!…')` es de la tarea 19.4 (V1-AR-1).
    $offenders = [];

    foreach (domainFiles() as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*\$\w+\s*[,)]|(?<![\w>:$])date_create(?:_immutable)?\s*\(/', $source) === 1) {
            $offenders[] = ModuleTree::relative($file);
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');

it('no produce aleatoriedad en el dominio (H8)', function (): void {
    // El azar es un efecto, como el reloj: un dominio que lo produce por su
    // cuenta no se puede probar caracter a caracter. Lo ponen los adaptadores de
    // `CredentialSecretFactory` y `EmployeeCodeGenerator`. Se miran los tokens y
    // no el texto, para que los comentarios que explican por que no se usa
    // `random_int()` no cuenten como uso.
    $forbidden = ['random_int', 'random_bytes', 'rand', 'mt_rand', 'mt_srand', 'srand', 'lcg_value', 'uniqid', 'shuffle', 'str_shuffle', 'array_rand'];
    $offenders = [];

    foreach (domainFiles() as $file) {
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && \in_array(strtolower(ltrim($token->text, '\\')), $forbidden, true)
                && ($tokens[$i + 1] ?? null)?->text === '('
                && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                $offenders[] = ModuleTree::relative($file).' llama a '.$token->text.'()';
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('RNF-M-03');
