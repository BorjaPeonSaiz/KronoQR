<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El fichero trae mas lineas de las admitidas y **no se aplica nada**
 * (RF-GP-05).
 *
 * ## Se dice en lugar de recortar en silencio
 *
 * Un recorte callado importa media plantilla, y nadie lo nota hasta que falta
 * gente delante de la tablet a las 06:00. El informe de validacion ya sale con
 * `truncated: true` para que se vea antes de confirmar; esta excepcion es la
 * segunda mitad, la que impide aplicarlo aunque alguien confirme sin mirar.
 *
 * ## `422` y no `409`
 *
 * Hay algo concreto que corregir en lo enviado —partir el fichero, o subir el
 * tope de `WORKFORCE_IMPORT_MAX_ROWS` si esa plantilla es asi de grande— y por
 * eso se cuelga del campo `file`. Un `409` diria que el problema esta en el
 * estado del sistema, y no lo esta.
 */
final class ImportTooLarge extends WorkforceDomainException
{
    public const string TRANSLATION_KEY = 'import.too_many_rows';

    public readonly string $translationKey;

    /** @var array<string, string|int> */
    public readonly array $parameters;

    public function __construct(int $maxRows)
    {
        $this->translationKey = self::TRANSLATION_KEY;
        $this->parameters = ['max' => $maxRows];

        parent::__construct(
            'The staff import file has more than '.$maxRows.' data rows, so nothing was applied.',
        );
    }
}
