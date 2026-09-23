<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Shared\Domain\ValueObject\PayrollColumn;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * La fila de rotulos del fichero de nomina (**RF-IN-07**).
 *
 * ## Por que no vive en la plantilla ni en cada escritor
 *
 * En la plantilla no, porque la plantilla es dominio puro y el dominio no tiene
 * idioma ({@see PayrollColumn::label()} devuelve una **clave**, no un texto). En
 * cada escritor tampoco: el CSV y el XLSX tienen que rotular igual, y con la
 * resolucion repartida bastaria una traduccion olvidada para que el mismo informe
 * saliera con cabeceras distintas segun el formato.
 *
 * ## El rotulo configurado gana, y no se traduce
 *
 * Si el cliente escribio `worked_hours=HORAS_TRAB`, eso es exactamente lo que va
 * en la cabecera: lo ha puesto para que encaje con la plantilla de importacion de
 * **su** programa de nomina, y traducirlo lo romperia el dia que alguien cambie
 * el idioma del panel. Solo cuando no hay rotulo configurado se usa el del
 * producto, en el idioma de la instalacion (regla dura 13; las rutas de documento
 * llevan `locale.installation`).
 *
 * Si falta la traduccion se rompe en voz alta, igual que en el informe por
 * periodo: una clave suelta en la cabecera de un fichero de nomina es peor que un
 * error, porque el programa que lo importa no la lee como un fallo — la da de
 * alta como campo.
 */
final readonly class PayrollHeadings
{
    private function __construct() {}

    /**
     * Los rotulos, en el orden de la plantilla.
     *
     * @return list<string>
     */
    public static function of(PayrollLayout $layout): array
    {
        return array_map(
            static fn (PayrollColumn $column): string => $layout->labelFor($column) ?? self::translated($column),
            $layout->columns,
        );
    }

    private static function translated(PayrollColumn $column): string
    {
        $label = Lang::get('reports.'.$column->label());

        if (! \is_string($label) || $label === 'reports.'.$column->label()) {
            throw new RuntimeException('Falta el rotulo de nomina "'.$column->label().'" en lang/*/reports.php.');
        }

        return $label;
    }
}
