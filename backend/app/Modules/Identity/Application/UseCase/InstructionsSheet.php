<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

/**
 * La hoja de instrucciones ya compuesta: los bytes del PDF y el idioma en el que
 * salio (tarea 5.11b, RL-05).
 *
 * **El idioma viaja con el documento** porque el nombre del fichero lo lleva
 * —`hoja-empleado-es.pdf`— y quien imprime las dos versiones necesita
 * distinguirlas en la carpeta de descargas sin abrirlas. No es el idioma que
 * pidio el cliente: es el que se aplico, que puede ser el de la instalacion
 * cuando no se pidio ninguno.
 *
 * **Ningun dato personal, y por eso el nombre del fichero es fijo.** Es la misma
 * hoja para toda la plantilla (ficha 5.11b, decision 1), asi que aqui no hay que
 * cuidar lo que cuida {@see PrintedCards}: no hay titular al que nombrar.
 */
final readonly class InstructionsSheet
{
    /**
     * @param  non-empty-string  $locale
     */
    public function __construct(
        public string $pdf,
        public string $locale,
    ) {}

    /**
     * El nombre con el que se descarga.
     *
     * **En castellano en los dos idiomas, a proposito**: quien lo descarga es
     * RRHH del hotel, el nombre acaba en su carpeta y no es texto que lea el
     * empleado. Lo declara asi el contrato
     * (`attachment; filename="hoja-empleado-es.pdf"`), que es la fuente de verdad
     * de los tres clientes.
     */
    public function fileName(): string
    {
        return 'hoja-empleado-'.$this->locale.'.pdf';
    }
}
