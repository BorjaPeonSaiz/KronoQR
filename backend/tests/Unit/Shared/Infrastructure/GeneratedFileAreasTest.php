<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileShape;
use App\Modules\Shared\Infrastructure\GeneratedFiles\GeneratedFileAreas;

/*
 * El catalogo de clases de fichero: raiz, patron exacto y forma (ADR-045,
 * condiciones C1 y C3).
 *
 * El patron es el confinamiento: lo que no casa no existe para la purga. Por eso
 * se prueba con los nombres que producen los escritores de verdad y con los que
 * NO deben casar nunca: el de otra clase de la misma raiz, uno con barra, uno
 * con salto de linea final y uno de un `uuid` que no es v7.
 */

const GENERATED_FILE_AREAS_UUID = '019a0000-0000-7000-8000-00000000dead';

it('cada clase admite los nombres que produce su escritor', function (GeneratedFileArea $area, string $nombre): void {
    expect($area->admits($nombre))->toBeTrue();
})->with([
    'ZIP de exportacion' => [GeneratedFileAreas::dataExportArchives('/r'), 'kronoqr-export-2.2.0-20261002T120000Z.zip'],
    'ZIP con version de pruebas' => [GeneratedFileAreas::dataExportArchives('/r'), 'kronoqr-export-2.1.0-1A2B3C4D.zip'],
    'temporal de ZipArchive' => [GeneratedFileAreas::dataExportArchiveTemporaries('/r'), 'kronoqr-export-2.2.0-20261002T120000Z.zip.Ab12Cd'],
    'espacio de trabajo' => [GeneratedFileAreas::dataExportWorkspaces('/r'), '.work-'.GENERATED_FILE_AREAS_UUID],
    'informe en diferido' => [GeneratedFileAreas::reportExports('/r'), GENERATED_FILE_AREAS_UUID],
    'temporal legal HTTP' => [GeneratedFileAreas::legalExportTemporaries('/r'), 'registro-horario-2026-01-01_2026-01-31-AbCdEf123456.csv'],
    'exportacion legal de consola' => [GeneratedFileAreas::legalExportConsole('/r'), 'registro-horario-2026-01-01_2026-01-31.csv'],
    'paquete de diagnostico' => [GeneratedFileAreas::diagnostics('/r'), 'kronoqr-diagnostics-2.2.0-20261002T120000Z.json'],
])->group('RF-PD-14', 'RF-IN-06', 'RF-IN-05');

it('ninguna clase admite un nombre ajeno, con barra o con salto de linea', function (GeneratedFileArea $area, string $nombre): void {
    expect($area->admits($nombre))->toBeFalse();
})->with([
    'el temporal no es un ZIP' => [GeneratedFileAreas::dataExportArchives('/r'), 'kronoqr-export-2.2.0.zip.Ab12Cd'],
    'el ZIP no es un temporal' => [GeneratedFileAreas::dataExportArchiveTemporaries('/r'), 'kronoqr-export-2.2.0.zip'],
    // Con un sufijo libre, «.zip.zip» casaria con las dos clases de la raiz.
    'un temporal acabado en zip' => [GeneratedFileAreas::dataExportArchiveTemporaries('/r'), 'kronoqr-export-a.zip.zip'],
    'un temporal de cinco' => [GeneratedFileAreas::dataExportArchiveTemporaries('/r'), 'kronoqr-export-a.zip.Ab12C'],
    'un ZIP con barra' => [GeneratedFileAreas::dataExportArchives('/r'), 'kronoqr-export-../x.zip'],
    'un ZIP con salto de linea final' => [GeneratedFileAreas::dataExportArchives('/r'), "kronoqr-export-2.2.0.zip\n"],
    'un ZIP sin prefijo' => [GeneratedFileAreas::dataExportArchives('/r'), 'copia.zip'],
    'un ZIP con prefijo dentro' => [GeneratedFileAreas::dataExportArchives('/r'), 'x-kronoqr-export-2.2.0.zip'],
    'un workspace sin punto' => [GeneratedFileAreas::dataExportWorkspaces('/r'), 'work-'.GENERATED_FILE_AREAS_UUID],
    'un workspace con uuid v4' => [GeneratedFileAreas::dataExportWorkspaces('/r'), '.work-019a0000-0000-4000-8000-00000000dead'],
    'un uuid en mayusculas' => [GeneratedFileAreas::reportExports('/r'), strtoupper(GENERATED_FILE_AREAS_UUID)],
    'un uuid con variante mala' => [GeneratedFileAreas::reportExports('/r'), '019a0000-0000-7000-c000-00000000dead'],
    'un uuid con salto de linea' => [GeneratedFileAreas::reportExports('/r'), GENERATED_FILE_AREAS_UUID."\n"],
    'otra clase en la raiz de informes' => [GeneratedFileAreas::reportExports('/r'), 'exports'],
    'los dos puntos' => [GeneratedFileAreas::reportExports('/r'), '..'],
    'un CSV legal sin prefijo' => [GeneratedFileAreas::legalExportTemporaries('/r'), 'horario.csv'],
    'un diagnostico que no es JSON' => [GeneratedFileAreas::diagnostics('/r'), 'kronoqr-diagnostics-2.2.0.zip'],
])->group('RF-PD-14', 'RF-IN-06', 'RF-IN-05');

it('declara la clase de metrica y la forma de cada area', function (GeneratedFileArea $area, GeneratedFileClass $clase, GeneratedFileShape $forma): void {
    expect($area->class)->toBe($clase)
        ->and($area->shape)->toBe($forma)
        ->and($area->holdsDirectories())->toBe($forma === GeneratedFileShape::Directory)
        ->and($area->root)->toBe('/raiz');
})->with([
    'ZIP' => [GeneratedFileAreas::dataExportArchives('/raiz'), GeneratedFileClass::DataExport, GeneratedFileShape::File],
    'temporal' => [GeneratedFileAreas::dataExportArchiveTemporaries('/raiz'), GeneratedFileClass::DataExportWork, GeneratedFileShape::File],
    'espacio de trabajo' => [GeneratedFileAreas::dataExportWorkspaces('/raiz'), GeneratedFileClass::DataExportWork, GeneratedFileShape::Directory],
    'informe' => [GeneratedFileAreas::reportExports('/raiz'), GeneratedFileClass::ReportExport, GeneratedFileShape::Directory],
    'temporal legal' => [GeneratedFileAreas::legalExportTemporaries('/raiz'), GeneratedFileClass::LegalExportTemporary, GeneratedFileShape::File],
    'legal de consola' => [GeneratedFileAreas::legalExportConsole('/raiz'), GeneratedFileClass::LegalExportConsole, GeneratedFileShape::File],
    'diagnostico' => [GeneratedFileAreas::diagnostics('/raiz'), GeneratedFileClass::Diagnostics, GeneratedFileShape::File],
])->group('RF-PD-14', 'RQ-06');

it('la etiqueta de las metricas es un catalogo cerrado de seis valores', function (): void {
    // C10: nunca un uuid ni una ruta. Si alguien añade un caso, esta prueba le
    // obliga a pensar en la alerta que lo leera.
    expect(array_map(static fn (GeneratedFileClass $clase): string => $clase->value, GeneratedFileClass::cases()))
        ->toBe(['data_export', 'data_export_work', 'report_export', 'legal_export_tmp', 'legal_export_console', 'diagnostics']);
})->group('RQ-06');
