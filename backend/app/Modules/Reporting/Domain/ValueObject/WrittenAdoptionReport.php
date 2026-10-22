<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * El cuadro de impacto ya escrito: cuantas filas y con que criterios
 * (**RF-IN-08**). La huella y el tamaño del **fichero** los mide quien cierra
 * la fila, sobre el disco.
 */
final readonly class WrittenAdoptionReport
{
    /**
     * @param  list<string>  $criteria  Ya traducidos al idioma de la instalacion.
     */
    public function __construct(
        public int $rowCount,
        public array $criteria,
        /**
         * La huella del **contenido** del cuadro —la del pie del PDF, igual en
         * los tres formatos—, no la del fichero. Es la que lleva
         * `adoption_report.exported` en la descarga sincrona, y la que permite
         * comparar el asiento con el papel (revision de seguridad del bloque 14).
         */
        public string $contentDigest,
    ) {}
}
