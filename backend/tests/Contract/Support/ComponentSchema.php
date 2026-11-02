<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valida un documento contra un esquema de `components/schemas` del contrato,
 * con sus `$ref` resueltos, sin pasar por una respuesta HTTP.
 *
 * ## Para que, si ya esta Spectator
 *
 * Spectator elige el esquema de una respuesta por su tipo, y reduce todo
 * `+json` a `application/json`: con dos tipos JSON en el mismo `200` —el
 * paquete de diagnostico anonimizado y el cifrado (ADR-020, enmienda del
 * 01-11-2026)— valida los dos contra el ultimo declarado. El anonimizado lo
 * sigue validando Spectator; el cifrado se valida aqui, contra
 * `SealedDiagnosticsBundle`, con el mismo motor (opis/json-schema) que usa
 * Spectator por debajo.
 */
final class ComponentSchema
{
    private const string BASE = 'https://contrato.kronoqr.test/openapi.json';

    /**
     * Los errores de validar `$json` contra el componente, vacio si es valido.
     *
     * @return list<string>
     */
    public static function errors(string $component, string $json): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(20);
        $validator->resolver()?->registerRaw(
            json_encode(Contract::document(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            self::BASE,
        );

        $result = $validator->validate(
            json_decode($json, false, 512, JSON_THROW_ON_ERROR),
            (object) ['$ref' => self::BASE.'#/components/schemas/'.$component],
        );

        $error = $result->error();

        if ($result->isValid() || $error === null) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($error) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = $pointer.': '.(\is_scalar($message) ? (string) $message : json_encode($message));
            }
        }

        return $errors;
    }
}
