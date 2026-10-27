<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * CADA MENSAJE PROPIO DE UNA VALIDACION TIENE SU INGLES EN `lang/en.json`
 * (RF-KI-05).
 *
 * Una `FormRequest` que escribe su mensaje en `messages()` —`'scan_id.regex' =>
 * 'El identificador del escaneo…'`— lo devuelve tal cual: el validador de
 * Laravel no traduce un mensaje propio. Lo traduce el borde al responder
 * (`ProblemDetails`, por campo), buscando el texto español en `lang/en.json`; si
 * no hay entrada, un quiosco o un panel en ingles recibe el `422` en español. Es
 * el mismo mecanismo que `ProblemDetailsEnglishTest` vigila para los textos de
 * `bootstrap/app.php`, y aqui se vigila para las `Request`.
 *
 * Un mensaje con un hueco (`:max`) no casa con su entrada despues de que el
 * validador ponga la cifra, asi que esa `Request` lo pasa por `__()` antes de
 * devolverlo; su entrada tiene que existir igual.
 */

/**
 * Los mensajes propios de validacion de las `Request` de `app/`: los literales
 * que siguen a una clave `campo.regla`.
 *
 * @return list<string> `Fichero: mensaje`
 */
function validationMessagesEnglishMessages(): array
{
    $root = dirname(ModuleTree::root());
    $messages = [];

    foreach (ModuleTree::phpFilesUnder($root) as $file) {
        $relative = ModuleTree::relative($file, $root);

        if (str_contains($relative, '/Http/Request/') || str_starts_with($relative, 'Http/Requests/')) {
            foreach (validationMessagesEnglishIn((string) file_get_contents($file)) as $text) {
                $messages[] = basename($relative).': '.$text;
            }
        }
    }

    return $messages;
}

/**
 * @return list<string>
 */
function validationMessagesEnglishIn(string $code): array
{
    $tokens = array_values(array_filter(
        token_get_all($code),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $texts = [];

    foreach (array_keys($tokens) as $i) {
        $text = validationMessagesEnglishValueAt($tokens, $i);

        if ($text !== null && preg_match('~\p{Ll}{3,} ~u', $text) === 1) {
            $texts[] = $text;
        }
    }

    return $texts;
}

/**
 * El mensaje si en `$i` empieza `'campo.regla' => '…'`, o `null`.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function validationMessagesEnglishValueAt(array $tokens, int $i): ?string
{
    $key = $tokens[$i];
    $arrow = $tokens[$i + 1] ?? null;
    $value = $tokens[$i + 2] ?? null;

    $isMessage = is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING
        && preg_match('~\.[a-z_]+\'$~', $key[1]) === 1
        && is_array($arrow) && $arrow[0] === T_DOUBLE_ARROW
        && is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING;

    return $isMessage ? stripslashes(substr($value[1], 1, -1)) : null;
}

it('encuentra los mensajes propios de las Request (red de seguridad)', function (): void {
    expect(validationMessagesEnglishMessages())
        ->toContain('RegisterScanRequest.php: El identificador del escaneo debe ser un UUID v7 generado en el cliente.');
})->group('RF-KI-05');

it('cada mensaje propio de una Request tiene su entrada en lang/en.json', function (): void {
    /** @var array<string, string> $catalogo */
    $catalogo = json_decode(Repo::contents('backend/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    $faltan = [];

    foreach (validationMessagesEnglishMessages() as $mensaje) {
        $texto = substr($mensaje, strpos($mensaje, ': ') + 2);

        if (! isset($catalogo[$texto]) || trim($catalogo[$texto]) === '') {
            $faltan[] = $mensaje;
        }
    }

    expect($faltan)->toBe([], 'Mensajes de validacion sin ingles en lang/en.json');
})->group('RF-KI-05');
