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

    /*
     * La unica excepcion, y acotada: `ServiceInspector::workRecordPrivileges`
     * nombra la tabla para preguntar al catalogo si el rol de la aplicacion
     * tiene `UPDATE` sobre ella (`has_table_privilege`, ADR-057 §1). No lee
     * ninguna fila. Aun asi, en ese fichero la tabla no puede aparecer tras un
     * `FROM`, un `JOIN` ni dentro de `table(...)`.
     */
    $privilegeOnly = ['ServiceInspector.php'];
    $read = '/(?:\bFROM|\bJOIN|table\(\s*[\'"])\s*"?discarded_scan_reports\b/i';

    $offenders = [];

    foreach ($collectors as $file) {
        $source = (string) file_get_contents($file);

        if (! str_contains($source, 'discarded_scan_reports')) {
            continue;
        }

        if (\in_array(basename($file), $privilegeOnly, true) && preg_match($read, $source) !== 1) {
            continue;
        }

        $offenders[] = basename($file);
    }

    expect($offenders)->toBe([], 'Estos ficheros del paquete de diagnostico leen discarded_scan_reports: '.implode(', ', $offenders));
})->group('RN-22', 'RF-PD-09', 'RF-PD-15');
