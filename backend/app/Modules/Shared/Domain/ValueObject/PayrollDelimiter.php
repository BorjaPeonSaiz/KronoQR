<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * El separador de campos del fichero de nomina (`PAYROLL_EXPORT_DELIMITER`,
 * RF-IN-07).
 *
 * ## Por que es configuracion y no el `;` del resto del producto
 *
 * Los otros CSV de KronoQR los abre una **persona** con su Excel, y ahi el
 * separador lo decide el idioma de la instalacion ({@see PayrollColumn} y el
 * dialecto CSV compartido). Este fichero lo lee un **programa de nomina**, y el
 * separador que ese programa espera no tiene nada que ver con el idioma: hay
 * importadores que exigen `;`, otros `,` y otros el tabulador. Fijarlo en el
 * codigo obligaria a tocar el repositorio —o peor, a mantener una rama— por cada
 * cliente con otro programa, que es exactamente lo que ADR-017 y la regla dura
 * 13 prohiben.
 *
 * ## Tres valores y no «escribe tu separador»
 *
 * Un campo libre admitiria la comilla doble, el retorno de carro o una cadena de
 * dos caracteres, y cualquiera de las tres produce un fichero que no se puede
 * volver a leer. Con tres casos no hay ninguno que rompa el RFC 4180, y el dia
 * que aparezca un programa que pida la barra vertical se añade un caso: es
 * aditivo y no migra nada de lo guardado.
 */
enum PayrollDelimiter: string
{
    /** `;` — el de serie. Es el que espera Excel donde la coma es el separador decimal. */
    case Semicolon = 'semicolon';

    /** `,` — el del RFC 4180 puro, que piden los importadores anglosajones. */
    case Comma = 'comma';

    /** Tabulador. Muchos programas de nomina antiguos solo aceptan este. */
    case Tab = 'tab';

    /** El caracter que de verdad se escribe entre dos celdas. */
    public function character(): string
    {
        return match ($this) {
            self::Semicolon => ';',
            self::Comma => ',',
            self::Tab => "\t",
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $delimiter): string => $delimiter->value, self::cases());
    }
}
