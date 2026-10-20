<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use Closure;

/**
 * **Que filas tiene** el registro propio, en que orden y de que tipo, para sus
 * dos formatos (`GET /api/v1/me/export`, CSV y PDF; 2.3.0, bloque 8).
 *
 * El contrato promete que `X-Kronoqr-Report-Rows` da el mismo numero en el CSV
 * y en el PDF del mismo registro. Mientras cada formato tuvo su propio bucle,
 * la promesa dependia de que los dos bucles gemelos siguieran siendolo. Aqui
 * hay un solo recorrido: cada formato decide **como se escribe** cada tipo de
 * fila, y ninguno decide **cuales hay**.
 *
 * Los tres tipos:
 *
 * - una fila por tramo vigente;
 * - una por jornada que se quedo sin tramos vigentes, que **no desaparece**
 *   (regla dura 5): si se anularon todos, sigue con su total a cero y su
 *   historico intacto;
 * - una por correccion, con su autor y su motivo (RN-13).
 */
final readonly class PersonalRecordRows
{
    private function __construct() {}

    /**
     * Recorre el registro y compone cada fila con la funcion de su tipo.
     *
     * @template TRow
     *
     * @param  Closure(JournalWorkDay, JournalShiftEntry): TRow  $shift
     * @param  Closure(JournalWorkDay): TRow  $emptyDay
     * @param  Closure(JournalWorkDay, JournalCorrection): TRow  $correction
     * @return list<TRow>
     */
    public static function map(WorkDayJournal $journal, Closure $shift, Closure $emptyDay, Closure $correction): array
    {
        $rows = [];

        foreach ($journal->days as $day) {
            foreach ($day->shiftEntries as $entry) {
                $rows[] = $shift($day, $entry);
            }

            if ($day->shiftEntries === []) {
                $rows[] = $emptyDay($day);
            }

            foreach ($day->corrections as $dayCorrection) {
                $rows[] = $correction($day, $dayCorrection);
            }
        }

        return $rows;
    }

    /**
     * Cuantas filas de datos tiene el registro: lo que viaja en
     * `X-Kronoqr-Report-Rows`, sin componer ninguna.
     */
    public static function count(WorkDayJournal $journal): int
    {
        return \count(self::map(
            $journal,
            static fn (): bool => true,
            static fn (): bool => true,
            static fn (): bool => true,
        ));
    }
}
