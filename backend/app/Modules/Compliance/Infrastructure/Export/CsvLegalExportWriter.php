<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Export;

use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\Port\LegalExportWriter;
use App\Modules\Compliance\Domain\ValueObject\ExportedCorrection;
use App\Modules\Compliance\Domain\ValueObject\ExportedDuration;
use App\Modules\Compliance\Domain\ValueObject\ExportedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\ExportedSubject;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use Throwable;

/**
 * Escribe el documento que se entrega a la Inspeccion: **CSV en UTF-8 con BOM,
 * separado por punto y coma, con las horas como texto `HH:MM`** (RL-06,
 * RF-IN-05, plan 1.17 paso 4).
 *
 * ## Por que CSV y no una hoja de calculo
 *
 * RL-06 exige un formato «legible y tratable, **no propietario**». `.xlsx` es un
 * formato de un fabricante; un inspector que lo reciba necesita el programa de
 * ese fabricante o un conversor. Un CSV lo abre cualquier cosa, incluido un
 * `cat`, y sigue siendo tratable dentro de una hoja de calculo.
 *
 * ## Las decisiones de codificacion no se declaran aqui
 *
 * BOM, punto y coma, entrecomillado del RFC 4180, sin escapado propietario y
 * `\r\n` como fin de linea viven en {@see CsvDialect}, que es tambien el que
 * escribe el CSV del historico propio del portal. Estaban duplicadas y dejaron
 * de coincidir sin que nadie se enterara: este fichero salia con `\n` y el otro
 * con `\r\n`, es decir, el producto tenia dos formatos.
 *
 * **Por eso las filas ya no las escribe `spatie/simple-excel`.** La libreria del
 * doc 02 §3.1 sigue siendo la del stack para las hojas de calculo de gestion
 * (RF-IN-04), pero su escritor de CSV llama a `fputcsv` con el fin de linea por
 * omision —`\n`— y no expone ninguna opcion para cambiarlo, asi que con ella era
 * imposible entregar el RFC 4180 que espera Excel en Windows. Lo que justificaba
 * la eleccion, «no carga en memoria un mes de 500 empleados», se conserva
 * intacto: aqui se escribe fila a fila sobre un descriptor abierto, y lo que
 * sostiene el streaming de verdad es que {@see LegalExportSource} ceda un
 * `Generator` sobre un cursor de servidor, no la libreria que formatea la linea.
 *
 * La decision de formato que es de **contenido** y no de bytes sigue estando
 * aqui: **`HH:MM` como texto, nunca decimal**. Es el requisito del plan y lo
 * garantiza {@see ExportedDuration}, que es quien sabe formatear una duracion;
 * aqui solo se pide la cadena.
 *
 * ## El fichero declara sus criterios antes de la tabla
 *
 * Las primeras lineas son un bloque de metadatos —instalacion, momento, periodo,
 * alcance, base legal y que entra y que no—, luego una linea en blanco y luego
 * la tabla. Un documento legal que no dice que contiene no se puede contrastar
 * dos años despues, que es cuando se abre. La linea en blanco es lo que permite
 * que una hoja de calculo reconozca la tabla al seleccionarla.
 *
 * ## Los textos salen de `lang/` y no de aqui
 *
 * `lang/{es,en}/legal-export.php`. El idioma del documento es configuracion de
 * la instalacion (regla dura 13, ADR-017), y el nombre de la instalacion sale de
 * `config/branding.php` (RF-PD-08). Un escritor con los rotulos incrustados
 * obligaria a tocar el repositorio para vender a un cliente que trabaja en
 * ingles.
 *
 * ## Escribe a fichero, y por eso devuelve el recuento
 *
 * El contrato del puerto lo explica: con el fichero cerrado antes de responder,
 * el asiento de `audit_log` se escribe sabiendo cuantas filas se entregaron de
 * verdad. Contar aqui es lo unico que evita recorrer el `iterable` dos veces —y
 * con el, consultar dos veces la base de datos—.
 */
final readonly class CsvLegalExportWriter implements LegalExportWriter
{
    /**
     * Las columnas de la tabla, en orden. La clave es la de `lang/*` y el orden
     * es el del documento.
     *
     * **Escrito una vez.** La cabecera y cada fila se generan de esta lista, de
     * modo que una columna nueva no puede quedar rotulada en un sitio y vacia en
     * el otro: los dos recorren el mismo array.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'record_type',
        'employee_code',
        'employee_name',
        'employee_uuid',
        'site',
        'department',
        'timezone',
        'work_date',
        'entry_number',
        'shift_entry_id',
        'local_in',
        'local_out',
        'duration',
        'day_total',
        'status',
        'clock_in_source',
        'clock_out_source',
        'utc_in',
        'utc_out',
        'correction_local_at',
        'correction_utc_at',
        'correction_author',
        'correction_author_id',
        'correction_action',
        'correction_reason',
        'correction_explanation',
        'correction_before',
        'correction_after',
    ];

    /**
     * Los criterios de inclusion que se imprimen, en orden. Los mismos que
     * describe el contrato de {@see LegalExportSource}.
     *
     * @var list<string>
     */
    private const array CRITERIA = ['entries', 'voided', 'superseded', 'corrections', 'times', 'durations', 'file'];

    public function write(LegalExportManifest $manifest, string $destinationPath, iterable $records): LegalExportTally
    {
        $this->ensureDirectoryExists($destinationPath);

        $handle = fopen($destinationPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('No se ha podido abrir «'.$destinationPath.'» para escribir la exportacion legal.');
        }

        $shiftEntries = 0;
        $corrections = 0;
        /** @var array<string, true> $employees */
        $employees = [];

        try {
            CsvDialect::writeByteOrderMark($handle);
            $this->writeManifest($handle, $manifest);

            foreach ($records as $record) {
                $employees[$record->subject()->employeeUuid] = true;

                if ($record instanceof ExportedShiftEntry) {
                    $shiftEntries++;
                    CsvDialect::writeRow($handle, $this->shiftEntryRow($record));

                    continue;
                }

                if ($record instanceof ExportedCorrection) {
                    $corrections++;
                    CsvDialect::writeRow($handle, $this->correctionRow($record));

                    continue;
                }

                throw new RuntimeException('Tipo de fila desconocido en la exportacion legal: '.$record::class.'.');
            }
        } finally {
            // `fclose()` en el `finally` y no al final del camino feliz: si algo
            // falla a mitad, el fichero tiene que quedar cerrado antes de que
            // quien llama decida que hacer con el. Un descriptor abierto en un
            // `finally` ajeno es como se acaba entregando media exportacion.
            fclose($handle);
        }

        return LegalExportTally::of($shiftEntries, $corrections, count($employees));
    }

    /**
     * La cabecera de criterios y, tras una linea en blanco, los rotulos de la
     * tabla.
     *
     * @param  resource  $handle
     */
    private function writeManifest($handle, LegalExportManifest $manifest): void
    {
        CsvDialect::writeRow($handle, [$this->text('title')]);
        CsvDialect::writeRow($handle, [$this->label('installation'), $this->installationName()]);
        CsvDialect::writeRow($handle, [$this->label('generated_at'), $manifest->generatedAt->format('Y-m-d\TH:i:s\Z')]);
        CsvDialect::writeRow($handle, [$this->label('period'), $this->text('period_value', [
            'from' => $manifest->period->from,
            'to' => $manifest->period->to,
        ])]);
        CsvDialect::writeRow($handle, [$this->label('scope'), $this->scopeText($manifest)]);
        CsvDialect::writeRow($handle, [$this->label('legal_basis'), $this->text('legal_basis_value')]);

        foreach (self::CRITERIA as $index => $criterion) {
            // El rotulo solo en la primera: las siguientes cuelgan de ella, que
            // es como se lee una lista en una tabla de dos columnas.
            CsvDialect::writeRow($handle, [
                $index === 0 ? $this->label('criteria') : '',
                $this->text('criteria.'.$criterion),
            ]);
        }

        CsvDialect::writeRow($handle, []);
        CsvDialect::writeRow(
            $handle,
            array_map(fn (string $column): string => $this->text('columns.'.$column), self::COLUMNS),
        );
    }

    /**
     * @return list<string>
     */
    private function shiftEntryRow(ExportedShiftEntry $entry): array
    {
        return $this->row([
            'record_type' => $this->text('record_type.shift_entry'),
            ...$this->subjectCells($entry->subject()),
            'entry_number' => (string) $entry->entryNumber,
            'shift_entry_id' => $entry->shiftEntryUuid,
            'local_in' => $entry->localClockedInAt,
            'local_out' => $entry->localClockedOutAt,
            'duration' => $entry->duration->toClockText(),
            'day_total' => $entry->dayTotal->toClockText(),
            'status' => $this->translated('status.'.$entry->status, $entry->status),
            'clock_in_source' => $this->translated('source.'.$entry->clockInSource, $entry->clockInSource),
            'clock_out_source' => $this->translated('source.'.$entry->clockOutSource, $entry->clockOutSource),
            'utc_in' => $entry->utcClockedInAt,
            'utc_out' => $entry->utcClockedOutAt,
        ]);
    }

    /**
     * @return list<string>
     */
    private function correctionRow(ExportedCorrection $correction): array
    {
        return $this->row([
            'record_type' => $this->text('record_type.correction'),
            ...$this->subjectCells($correction->subject()),
            'shift_entry_id' => $correction->shiftEntryUuid,
            'correction_local_at' => $correction->localPerformedAt,
            'correction_utc_at' => $correction->utcPerformedAt,
            // RN-13 y RL-04: autor, momento y motivo. Si esta fila no llevara
            // nombre de autor, el informe estaria escondiendo justo lo que una
            // inspeccion viene a mirar.
            'correction_author' => $correction->authorName,
            'correction_author_id' => $correction->authorUuid,
            'correction_action' => $this->translated('action.'.$correction->action, $correction->action),
            // El codigo del Anexo C va SIN traducir: es un catalogo cerrado que
            // tiene que leerse igual aqui, en `shift_corrections` y en
            // `audit_log`, o el fichero deja de poder cruzarse con ellos.
            'correction_reason' => $correction->reasonCode,
            'correction_explanation' => $correction->reasonText,
            'correction_before' => $correction->before->describe(),
            'correction_after' => $correction->after->describe(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function subjectCells(ExportedSubject $subject): array
    {
        return [
            'employee_code' => $subject->employeeCode,
            'employee_name' => $subject->fullName(),
            'employee_uuid' => $subject->employeeUuid,
            'site' => $subject->siteName,
            'department' => $subject->departmentName ?? '',
            'timezone' => $subject->timezone,
            'work_date' => $subject->workDate,
        ];
    }

    /**
     * Coloca los valores en el orden de {@see self::COLUMNS} y rellena de vacio
     * lo que ese tipo de fila no dice.
     *
     * **Vacio y no cero.** Una fila de correccion no tiene duracion y una de
     * tramo no tiene autor: escribir `00:00` o un guion en esas celdas afirmaria
     * algo que la fila no afirma.
     *
     * @param  array<string, string>  $cells
     * @return list<string>
     */
    private function row(array $cells): array
    {
        return array_map(
            static fn (string $column): string => $cells[$column] ?? '',
            self::COLUMNS,
        );
    }

    private function scopeText(LegalExportManifest $manifest): string
    {
        return $manifest->scope->isEveryone()
            ? $this->text('scope.everyone')
            : $this->text('scope.employee', ['uuid' => (string) $manifest->scope->employeeUuid]);
    }

    /**
     * El nombre de la instalacion, de `config/branding.php` (RF-PD-08, regla
     * dura 13). Puede estar vacio: el centro va en cada fila y siempre existe.
     */
    private function installationName(): string
    {
        // `Config::get` y no `Config::string`: la clave existe y vale `null`
        // cuando `BRANDING_NAME` no esta definida, que es el caso por defecto —y
        // `Config::string` se niega a devolver un nulo—.
        $name = Config::get('branding.name');

        return is_string($name) ? $name : '';
    }

    private function label(string $key): string
    {
        return $this->text('header.'.$key);
    }

    /**
     * Un texto de `legal-export.php` del idioma en curso.
     *
     * @param  array<string, string>  $replacements
     */
    private function text(string $key, array $replacements = []): string
    {
        $line = Lang::get('legal-export.'.$key, $replacements);

        // `Lang::get` devuelve la clave cuando no hay traduccion y un array
        // cuando la clave apunta a un grupo. Ni una cosa ni la otra deben salir
        // en un documento que se entrega: es preferible fallar al generarlo.
        if (! is_string($line) || $line === 'legal-export.'.$key) {
            throw new RuntimeException('Falta el texto «legal-export.'.$key.'» en lang/'.Lang::getLocale().'.');
        }

        return $line;
    }

    /**
     * Un valor de catalogo traducido, o el propio valor si la instalacion tiene
     * uno que el idioma no conoce.
     *
     * **No falla como {@see self::text}**, y la diferencia importa: un rotulo que
     * falta es un error del producto, pero un estado o un origen desconocido es
     * un dato que ya esta en la base. Negarse a exportarlo dejaria sin registro
     * legal a quien mas lo necesita; escribir el codigo en crudo es feo y
     * verdadero.
     */
    private function translated(string $key, string $fallback): string
    {
        if ($fallback === '') {
            return '';
        }

        $line = Lang::get('legal-export.'.$key);

        return is_string($line) && $line !== 'legal-export.'.$key ? $line : $fallback;
    }

    /**
     * El destino puede ser un temporal de la peticion o una ruta que escribio a
     * mano quien atiende un requerimiento. En el segundo caso el directorio
     * puede no existir, y `fopen()` fallaria con un error de flujo que no dice
     * cual es el problema.
     */
    private function ensureDirectoryExists(string $destinationPath): void
    {
        $directory = dirname($destinationPath);

        if (is_dir($directory)) {
            return;
        }

        try {
            if (! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
                throw new RuntimeException('No se ha podido crear el directorio «'.$directory.'».');
            }
        } catch (Throwable $failure) {
            throw new RuntimeException(
                'No se ha podido crear el directorio de la exportacion legal «'.$directory.'».',
                0,
                $failure,
            );
        }
    }
}
