<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Export;

use App\Modules\Product\Application\Port\DataExportGuide;
use App\Modules\Product\Domain\ValueObject\DataExportCatalog;
use App\Modules\Product\Domain\ValueObject\DataExportManifest;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Contracts\Translation\Translator;

/**
 * Compone el `README.md` que va dentro del ZIP (**RF-PD-14**, RL-20).
 *
 * ## Recorre el catalogo, no una lista propia
 *
 * Los apartados de «que hay en cada fichero» salen de
 * {@see DataExportCatalog::datasets()}, columna a columna. Es lo que hace
 * imposible que el README y el ZIP se separen: un conjunto nuevo aparece aqui
 * automaticamente, y una columna nueva tambien.
 *
 * **Una columna sin traduccion no se salta**: sale con su nombre tecnico y una
 * nota que dice que no esta descrita. Un README que omitiera en silencio la
 * columna que alguien acaba de añadir mentiria por omision, que es peor que
 * decir «esto no lo se explicar». La prueba unitaria del catalogo lo caza antes
 * de que llegue a un cliente.
 *
 * ## Markdown y no PDF ni HTML
 *
 * Se lee tal cual con cualquier editor de texto —que es lo que habra dentro de
 * dos años, sin KronoQR delante— y se ve bien formateado en cualquier
 * visualizador moderno. Un PDF pesaria mas, no se podria buscar con `grep` y
 * necesitaria una herramienta para generarlo.
 *
 * ## El traductor se usa aqui y no en el caso de uso
 *
 * Porque es framework, y `Application/` no lo toca (§3.5). El puerto
 * {@see DataExportGuide} es la frontera.
 */
final readonly class TranslatedDataExportGuide implements DataExportGuide
{
    public function __construct(private Translator $translator) {}

    public function render(DataExportManifest $manifest, string $locale): string
    {
        $sections = [
            '# '.$this->text('title', [], $locale),
            $this->text('intro', [], $locale),
            $this->section('generated_heading', 'generated', $this->about($manifest, $locale), $locale),
            $this->section('timezone_heading', 'timezone_body', ['timezone' => $manifest->siteTimezone], $locale),
            $this->section('integrity_heading', 'integrity_body', [], $locale),
            $this->section('format_heading', 'format_body', [
                'delimiter' => CsvDialect::delimiterFor($locale),
            ], $locale),
            $this->section('chain_heading', 'chain_body', [], $locale),
            '## '.$this->text('files_heading', [], $locale),
        ];

        foreach (DataExportCatalog::datasets() as $dataset) {
            $sections[] = $this->dataset($dataset, $manifest, $locale);
        }

        $sections[] = $this->notInstalled($manifest, $locale);

        return implode("\n\n", $sections)."\n";
    }

    /**
     * @return array<string, string|int>
     */
    private function about(DataExportManifest $manifest, string $locale): array
    {
        return [
            'generated_at' => UtcInstant::of($manifest->generatedAt),
            'product_version' => $manifest->productVersion,
            'schema_version' => DataExportCatalog::SCHEMA_VERSION,
            'timezone' => $manifest->siteTimezone,
            'requested_via' => $this->text('requested_via_'.$manifest->requestedVia->value, [], $locale),
            'requested_by' => $manifest->requestedByName
                ?? $this->text('requested_by_nobody', [], $locale),
            'total_rows' => $manifest->totalRows(),
        ];
    }

    private function dataset(ExportedDataset $dataset, DataExportManifest $manifest, string $locale): string
    {
        $key = 'data-export.files.'.$dataset->name;

        $heading = '### '.$this->text('file_heading', [
            'file' => '`'.$dataset->fileName().'`',
            'rows' => $this->rowsOf($dataset, $manifest),
        ], $locale);

        $columns = [];

        foreach ($dataset->columns() as $column) {
            $columns[] = '- **`'.$column.'`** — '.$this->columnText($key, $column, $locale);
        }

        return $heading."\n\n".$this->text($key.'.summary', [], $locale)."\n\n".implode("\n", $columns);
    }

    private function rowsOf(ExportedDataset $dataset, DataExportManifest $manifest): int
    {
        foreach ($manifest->files as $file) {
            if ($file->name === $dataset->fileName()) {
                return $file->rows;
            }
        }

        return 0;
    }

    private function notInstalled(DataExportManifest $manifest, string $locale): string
    {
        $items = [];

        foreach ($manifest->notInstalled as $name) {
            $items[] = '- **`'.$name.'`** — '
                .$this->text('not_installed_names.'.$name, [], $locale);
        }

        return '## '.$this->text('not_installed_heading', [], $locale)."\n\n"
            .$this->text('not_installed_body', [
                'product_version' => $manifest->productVersion,
                'list' => implode("\n", $items),
            ], $locale);
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    private function section(string $heading, string $body, array $replacements, string $locale): string
    {
        return '## '.$this->text($heading, [], $locale)."\n\n".$this->text($body, $replacements, $locale);
    }

    /**
     * La descripcion de una columna, o su nombre tecnico con la nota. Ver el
     * docblock de la clase: no se salta ninguna.
     */
    private function columnText(string $datasetKey, string $column, string $locale): string
    {
        $key = $datasetKey.'.columns.'.$column;
        $text = $this->text($key, [], $locale);

        return $text === $key
            ? $this->text('unknown_column', ['column' => $column], $locale)
            : $text;
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    private function text(string $key, array $replacements, string $locale): string
    {
        $full = str_contains($key, '.') && str_starts_with($key, 'data-export.')
            ? $key
            : 'data-export.'.$key;

        $message = $this->translator->get($full, $replacements, $locale);

        return is_string($message) ? $message : $full;
    }
}
