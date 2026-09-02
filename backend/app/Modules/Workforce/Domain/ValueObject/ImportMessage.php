<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Un motivo de rechazo o un aviso sobre una linea (**RF-GP-05**).
 *
 * **Codigo y columna, nunca texto.** El dominio no sabe en que idioma se va a
 * leer el informe: el texto lo compone el borde con `lang/`, en el idioma
 * negociado. Es el mismo criterio con el que la degradacion de licencia lleva
 * `restriction` y no una frase.
 *
 * **Y nunca el valor de la celda.** «El correo `ana@hotel.example` ya es de otra
 * persona» seria un dato personal en un objeto que acaba en un informe y podria
 * acabar en un log; con `email_taken` y la columna basta para arreglarlo, y quien
 * lo arregla tiene el fichero delante.
 */
final readonly class ImportMessage
{
    private function __construct(
        public ImportMessageCode $code,
        public ?string $column,
    ) {}

    public static function of(ImportMessageCode $code, ?string $column = null): self
    {
        return new self($code, $column);
    }

    public function isWarning(): bool
    {
        return $this->code->isWarning();
    }
}
