<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * La tabla de datos del CSV del registro propio (`GET /api/v1/me/export`),
 * con cada celda localizada **por el rotulo de su columna** y no por su
 * posicion.
 *
 * Las pruebas de volumen leian la duracion como `explode(';', $linea)[5]`, y
 * dejaron de cuadrar en cuanto la 19.2 añadio las columnas de desfase al lado
 * de cada hora (R1-RV-01). Aqui se lee la fila de rotulos —la primera tras la
 * linea en blanco que separa los criterios—, se traduce cada rotulo a su clave
 * de `personal-record.columns` en el idioma activo y se devuelve cada fila como
 * diccionario por clave. Un cambio de orden ya no rompe a nadie, y una columna
 * que desaparezca falla en voz alta.
 */
final readonly class PersonalRecordCsvTable
{
    private function __construct() {}

    /**
     * @return list<array<string, string>>
     */
    public static function rows(string $csv): array
    {
        $lines = explode("\r\n", rtrim(self::withoutByteOrderMark($csv), "\r\n"));
        $blank = array_search('', $lines, true);

        if (! \is_int($blank) || ! isset($lines[$blank + 1])) {
            throw new RuntimeException('El CSV del registro propio no tiene fila de rotulos tras los criterios.');
        }

        $keys = array_map(self::keyOf(...), self::cells($lines[$blank + 1]));
        $rows = [];

        foreach (\array_slice($lines, $blank + 2) as $line) {
            $rows[] = array_combine($keys, self::cells($line));
        }

        return $rows;
    }

    /**
     * Las filas de un tipo (`TRAMO` o `CORRECCION`).
     *
     * @return list<array<string, string>>
     */
    public static function rowsOfType(string $csv, string $recordType): array
    {
        return array_values(array_filter(
            self::rows($csv),
            static fn (array $row): bool => $row['record_type'] === $recordType,
        ));
    }

    /** Una duracion `HH:MM` en minutos. */
    public static function minutes(string $clockText): int
    {
        [$hours, $minutes] = explode(':', $clockText);

        return ((int) $hours * 60) + (int) $minutes;
    }

    private static function withoutByteOrderMark(string $csv): string
    {
        return str_starts_with($csv, "\u{FEFF}") ? substr($csv, 3) : $csv;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        return array_map(strval(...), str_getcsv($line, ';', '"', ''));
    }

    private static function keyOf(string $label): string
    {
        $labels = Lang::get('personal-record.columns');
        $key = \is_array($labels) ? array_search($label, $labels, true) : false;

        if (! \is_string($key)) {
            throw new RuntimeException('El rotulo «'.$label.'» no es de ninguna columna del registro propio.');
        }

        return $key;
    }
}
