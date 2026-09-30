<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * RN-19 y ADR-020/ADR-043: el paquete de diagnostico, que viaja al fabricante,
 * NO lleva a quien correspondia el codigo de un PIN rechazado.
 *
 * `claimed_employee_id` dice «alguien intento fichar con el codigo de esta
 * persona»: es un dato del registro del cliente, sale en su exportacion integra
 * (`RejectedPinScanExportTest`) y en ningun sitio mas. El colector de datos
 * personales del paquete elige sus columnas una a una; esta prueba fija que
 * ninguna de las dos nuevas entra por descuido el dia que alguien amplie su
 * `select`.
 */

it('no selecciona el dueño del codigo ni el bloqueo en el paquete de diagnostico', function (): void {
    $source = (string) file_get_contents(
        ModuleTree::root().'/Product/Infrastructure/Diagnostics/Collector/PersonalDataCollector.php',
    );

    expect($source)->toContain("->table('scan_events')")
        ->and($source)->not->toContain('claimed_employee')
        ->and($source)->not->toContain('pin_lockout');
})->group('RN-19', 'RF-PD-15');
