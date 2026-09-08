<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;

/**
 * El `manifest.json` del ZIP: **como comprobar que la copia esta completa sin
 * abrirla** (**RF-PD-14**, RL-20, decision 1 de la ficha 5.10).
 *
 * ## Que responde
 *
 * - «¿De que instalacion y de que version es esto?» — `product_version`,
 *   `schema_version`, `site_timezone`.
 * - «¿Cuando se genero y quien la pidio?» — `generated_at` (UTC),
 *   `requested_via`, `requested_by`.
 * - «¿Esta entero?» — `files`, con el recuento de filas y el `sha256` de cada
 *   uno. Con esto, cualquiera puede comparar los recuentos contra su propia base
 *   de datos y recalcular las huellas con `sha256sum`, sin KronoQR delante.
 * - «¿Falta algo?» — `not_installed`, con lo que esta version **no registra**.
 *   Es lo que evita que un fichero ausente se lea como un dato perdido.
 *
 * ## Los instantes van en UTC (regla dura 3)
 *
 * Es una exportacion de datos, no un documento de presentacion: la exportacion
 * para la Inspeccion (RF-IN-05) si lleva la hora local porque la lee una persona
 * con el horario del contrato delante, y esta la lee un programa. `site_timezone`
 * viaja **ademas**, y el `README.md` explica como convertir.
 *
 * ## `requested_by` lleva nombre, y no es una fuga
 *
 * El ZIP contiene `users.csv` con el nombre y el correo de todas las cuentas de
 * gestion: el fichero entero es del cliente y para el cliente (RL-16). Lo que
 * nunca lleva nombres es lo que sale **hacia el fabricante** —el paquete de
 * diagnostico y `audit_log`— y eso sigue igual (regla dura 21).
 */
final readonly class DataExportManifest
{
    /**
     * @param  list<DataExportFile>  $files
     * @param  list<string>  $notInstalled
     */
    public function __construct(
        public string $productVersion,
        public DateTimeImmutable $generatedAt,
        public string $siteTimezone,
        public DataExportOrigin $requestedVia,
        public ?string $requestedByUuid,
        public ?string $requestedByName,
        public array $files,
        public array $notInstalled,
    ) {}

    /**
     * El nombre del ZIP: `kronoqr-export-<version>-<UTC>.zip`.
     *
     * Version e instante porque el caso normal es que un cliente guarde varias
     * exportaciones —una al año, una antes de renovar— y dos ficheros con el
     * mismo nombre en la misma carpeta se pisan sin avisar. El instante lo
     * compone {@see UtcInstant::compact()}, que es el unico sitio del producto
     * donde vive ese formato.
     */
    public function fileName(): string
    {
        return 'kronoqr-export-'.$this->productVersion.'-'.UtcInstant::compact($this->generatedAt).'.zip';
    }

    /**
     * Filas de datos por CONJUNTO: lo que va a `data_exports.row_counts` y lo que
     * el contrato declara (`employees`, `shift_entries`, `audit_log`, …).
     *
     * `README.md` y `manifest.json` no entran: no son datos, y sumarlos
     * falsearia el total que el cliente compara contra su base de datos.
     *
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        $counts = [];

        foreach ($this->files as $file) {
            if ($file->dataset === null) {
                continue;
            }

            $counts[$file->dataset] = $file->rows;
        }

        return $counts;
    }

    /** El total de filas de datos del ZIP: la cabecera `X-Kronoqr-Export-Rows`. */
    public function totalRows(): int
    {
        return array_sum($this->rowCounts());
    }

    /**
     * El documento tal como se escribe en el ZIP.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $files = [];

        foreach ($this->files as $file) {
            $files[$file->name] = [
                'rows' => $file->rows,
                'sha256' => $file->sha256,
                'size_bytes' => $file->sizeBytes,
            ];
        }

        return [
            'schema_version' => DataExportCatalog::SCHEMA_VERSION,
            'product_version' => $this->productVersion,
            'generated_at' => UtcInstant::of($this->generatedAt),
            'site_timezone' => $this->siteTimezone,
            'requested_via' => $this->requestedVia->value,
            'requested_by' => $this->requestedByUuid === null ? null : [
                'uuid' => $this->requestedByUuid,
                'name' => $this->requestedByName,
            ],
            'total_rows' => $this->totalRows(),
            'files' => $files,
            /*
             * Lo que esta version NO registra. Con una lista vacia seria
             * indistinguible de «no hay nada que declarar», que es informacion
             * distinta; con las claves presentes, quien lea el manifiesto sabe
             * que `absences.csv` no falta: no existe.
             */
            'not_installed' => $this->notInstalled,
        ];
    }
}
