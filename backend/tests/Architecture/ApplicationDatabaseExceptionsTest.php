<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * `Application/` no interpreta los fallos del driver (RNF-M-03, ADR-002).
 *
 * Distinguir un `lock_timeout` de una escritura fallida, o un
 * `statement_timeout` de una base de datos caida, es leer el SQLSTATE y los
 * tipos del driver: eso ata la decision al motor y al driver (el codigo de una
 * misma excepcion es el SQLSTATE en unos drivers y un entero en otros). Lo
 * hace el adaptador del puerto `DatabaseFailureTranslator`, y el caso de uso
 * solo conoce `DatabaseFailure` y `DatabaseFailureKind`.
 *
 * Deptrac no lo ve: `Illuminate\Database\ConnectionInterface` es legitimo en
 * `Application` (las transacciones) y vive en el mismo espacio de nombres que
 * `QueryException`. Esta prueba mira los `use`, los nombres calificados en el
 * codigo y la lectura de `errorInfo`, sin contar los comentarios.
 */

/** Si el nombre es una excepcion de la base de datos o del driver. */
function applicationDbExceptionIsForbidden(string $name): bool
{
    $name = ltrim($name, '\\');

    return $name === 'PDOException'
        || preg_match('/^Illuminate\\\\Database\\\\(?:[A-Za-z]+\\\\)*[A-Za-z]*Exception$/', $name) === 1;
}

/**
 * Lo que un fichero de `Application/` hace con los fallos del driver.
 *
 * @return list<string>
 */
function applicationDbExceptionUsesIn(string $file): array
{
    $found = array_map(
        static fn (string $import): string => 'importa '.$import,
        array_values(array_filter(ModuleTree::importsOf($file), applicationDbExceptionIsForbidden(...))),
    );

    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $index => $token) {
        $use = \is_array($token) ? applicationDbExceptionTokenUse($token, $tokens[$index - 1] ?? null) : null;

        if ($use !== null) {
            $found[] = $use;
        }
    }

    return $found;
}

/**
 * Lo que dice un token: un nombre calificado de excepcion del driver, o la
 * lectura de `->errorInfo`.
 *
 * @param  array{0: int, 1: string, 2: int}  $token
 * @param  array{0: int, 1: string, 2: int}|string|null  $previous
 */
function applicationDbExceptionTokenUse(array $token, array|string|null $previous): ?string
{
    if (\in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) && applicationDbExceptionIsForbidden($token[1])) {
        return 'linea '.$token[2].' nombra '.$token[1];
    }

    $afterArrow = \is_array($previous) && \in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

    return $token[0] === T_STRING && $token[1] === 'errorInfo' && $afterArrow
        ? 'linea '.$token[2].' lee errorInfo'
        : null;
}

it('reconoce lo que vigila', function (): void {
    // El control: si el patron se rompiera, la prueba de abajo pasaria en
    // verde sin mirar nada.
    expect(applicationDbExceptionIsForbidden('Illuminate\Database\QueryException'))->toBeTrue()
        ->and(applicationDbExceptionIsForbidden('\Illuminate\Database\DeadlockException'))->toBeTrue()
        ->and(applicationDbExceptionIsForbidden('Illuminate\Database\UniqueConstraintViolationException'))->toBeTrue()
        ->and(applicationDbExceptionIsForbidden('PDOException'))->toBeTrue()
        ->and(applicationDbExceptionIsForbidden('Illuminate\Database\ConnectionInterface'))->toBeFalse()
        ->and(applicationDbExceptionIsForbidden('App\Modules\Shared\Application\Port\DatabaseFailure'))->toBeFalse();
})->group('RNF-M-03');

it('ningun caso de uso nombra las excepciones de la base de datos ni lee su SQLSTATE', function (): void {
    $offenders = [];

    foreach (ModuleTree::filesInLayer(ModuleTree::MODULES, 'Application') as $file) {
        foreach (applicationDbExceptionUsesIn($file) as $use) {
            $offenders[] = ModuleTree::relative($file).': '.$use;
        }
    }

    expect($offenders)->toBe([], 'Traduce el fallo con DatabaseFailureTranslator en lugar de interpretarlo en Application.');
})->group('RNF-M-03');
