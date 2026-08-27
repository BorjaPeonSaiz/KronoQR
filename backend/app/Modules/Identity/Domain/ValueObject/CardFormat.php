<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

/**
 * Los dos formatos de impresion de RF-QR-04.
 *
 * ```
 * CARD    85,6 x 54 mm — el tamaño ISO/IEC 7810 ID-1, el de una tarjeta bancaria
 * SHEET   A4 con varias tarjetas por pagina, para cortar
 * ```
 *
 * **Por que exactamente 85,6 x 54 mm.** Es la medida de las fundas, de las
 * plastificadoras y de los portatarjetas que ya existen en cualquier hotel. Una
 * tarjeta de medida propia obliga al cliente a comprar consumibles especificos, y
 * eso lo convierte en un coste recurrente del producto.
 *
 * **Por que la hoja A4 no es un lujo** (doc 02 §5.5): *«La hoja A4 con varias
 * tarjetas por pagina es lo que hace viable dar de alta a 40 personas de
 * temporada en una tarde.»* Y es **un solo documento** con N tarjetas, no N
 * documentos: asi una sola invocacion del renderizador cubre el centro entero.
 *
 * **Las medidas viven aqui y no en la plantilla Blade.** La plantilla las recibe:
 * si estuvieran escritas en el CSS, cambiar el formato seria editar HTML y
 * ninguna prueba podria afirmar que la tarjeta mide lo que RF-QR-04 exige.
 */
enum CardFormat: string
{
    case CARD = 'card';

    case SHEET = 'sheet';

    /** Ancho de la tarjeta, en milimetros (ISO/IEC 7810 ID-1). */
    public const float CARD_WIDTH_MM = 85.6;

    /** Alto de la tarjeta, en milimetros. */
    public const float CARD_HEIGHT_MM = 54.0;

    /**
     * Tarjetas por pagina en el formato de hoja.
     *
     * Dos columnas por cinco filas caben con holgura en un A4 (210 x 297 mm)
     * dejando margen de corte: 2 x 85,6 = 171,2 mm de ancho y 5 x 54 = 270 mm de
     * alto. Apurar a tres columnas obligaria a recortar el margen de seguridad
     * del QR, que es lo que RF-QR-05 protege.
     */
    public const int CARDS_PER_SHEET = 10;

    /**
     * Nombre del fichero que se transmite. Sin nombres ni codigos de empleado:
     * un PDF de tarjetas es un instrumento al portador y su nombre de fichero
     * acaba en el historial de descargas del navegador.
     */
    public function fileName(): string
    {
        return match ($this) {
            self::CARD => 'credencial.pdf',
            self::SHEET => 'credenciales.pdf',
        };
    }
}
