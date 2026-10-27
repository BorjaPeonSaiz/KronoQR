<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lo que se **ve** al abrir una hoja XLSX: que filas estan fijas y que celdas
 * van en negrita.
 *
 * ## Por que existe
 *
 * `SimpleExcelReader` devuelve valores, no aspecto: una cabecera que pierde la
 * negrita o deja de estar congelada pasa todas las afirmaciones sobre el
 * contenido. Ocurrio de verdad: el estilo pasado como segundo argumento de
 * `SimpleExcelWriter::addRow()` se descarta cuando la fila ya es un `Row`, y la
 * cabecera salio sin negrita sin que ninguna prueba lo notara. Al cambiar de
 * version de OpenSpout (5.x) la forma de pedir las dos cosas cambio otra vez.
 *
 * Lee el XML del paquete directamente: `xl/worksheets/sheetN.xml` para el panel
 * congelado y las celdas, `xl/styles.xml` para saber si el formato de una celda
 * apunta a una fuente con `<b/>`.
 */
final readonly class XlsxAppearance
{
    private const string MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private function __construct(
        private SimpleXMLElement $sheet,
        private SimpleXMLElement $styles,
    ) {}

    public static function of(string $path, int $sheet = 1): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se puede abrir el XLSX.');
        }

        $sheetXml = $zip->getFromName("xl/worksheets/sheet{$sheet}.xml");
        $stylesXml = $zip->getFromName('xl/styles.xml');
        $zip->close();

        if (! \is_string($sheetXml) || ! \is_string($stylesXml)) {
            throw new RuntimeException('Al XLSX le falta la hoja o los estilos.');
        }

        return new self(self::parse($sheetXml), self::parse($stylesXml));
    }

    /**
     * Filas fijas en lo alto de la hoja: 0 si no hay panel congelado.
     */
    public function frozenRows(): int
    {
        foreach ($this->sheet->xpath('//m:sheetView/m:pane[@state="frozen"]') ?: [] as $pane) {
            return (int) ($pane['ySplit'] ?? 0);
        }

        return 0;
    }

    /**
     * Si **todas** las celdas con texto de la fila van en negrita.
     */
    public function rowIsBold(int $row): bool
    {
        $cells = $this->sheet->xpath("//m:sheetData/m:row[@r=\"{$row}\"]/m:c[m:is or m:v]") ?: [];

        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (! $this->styleIsBold((int) ($cell['s'] ?? 0))) {
                return false;
            }
        }

        return true;
    }

    private function styleIsBold(int $styleIndex): bool
    {
        $formats = $this->styles->xpath('//m:cellXfs/m:xf') ?: [];
        $fonts = $this->styles->xpath('//m:fonts/m:font') ?: [];

        $format = $formats[$styleIndex] ?? null;
        $font = $format === null ? null : ($fonts[(int) ($format['fontId'] ?? 0)] ?? null);

        if ($font === null) {
            return false;
        }

        $font->registerXPathNamespace('m', self::MAIN_NS);

        return ($font->xpath('m:b') ?: []) !== [];
    }

    private static function parse(string $xml): SimpleXMLElement
    {
        $element = new SimpleXMLElement($xml);
        $element->registerXPathNamespace('m', self::MAIN_NS);

        return $element;
    }
}
