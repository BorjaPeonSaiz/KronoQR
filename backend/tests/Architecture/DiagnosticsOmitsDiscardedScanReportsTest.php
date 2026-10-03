<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * RN-22 y ADR-020/ADR-047: el paquete de diagnostico, que viaja al fabricante,
 * NO lee `discarded_scan_reports`.
 *
 * Un aviso de fichaje descartado dice «alguien de esta plantilla ficho a esta
 * hora y no quedo registrado»: es un dato del registro del cliente, sale en su
 * exportacion integra (`DataExportCatalog`) y en ningun sitio mas. Lo unico que
 * el paquete sabe de los descartes es el recuento que declara cada quiosco en su
 * latido (`devices.unreported_discards`), que no identifica a nadie. Esta prueba
 * fija que ningun colector empiece a leer la tabla por descuido.
 */

it('no lee los avisos de fichaje descartado desde ningun colector del paquete', function (): void {
    $collectors = ModuleTree::phpFilesUnder(ModuleTree::root().'/Product/Infrastructure/Diagnostics');

    expect(\count($collectors))->toBeGreaterThan(5, 'El recorrido de los colectores no ha encontrado nada.');

    $offenders = [];

    foreach ($collectors as $file) {
        if (str_contains((string) file_get_contents($file), 'discarded_scan_reports')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], 'Estos ficheros del paquete de diagnostico leen discarded_scan_reports: '.implode(', ', $offenders));
})->group('RN-22', 'RF-PD-09', 'RF-PD-15');
