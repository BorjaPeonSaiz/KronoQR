<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use Tests\Architecture\Support\ModuleTree;

/*
 * UN PAQUETE CON EL ASIENTO PENDIENTE NO SE PUBLICA SIN SU PENDIENTE (V4-PL-1;
 * RF-PD-09, RL-04, RL-19; ADR-020, enmienda del 14-10-2026).
 *
 * La secuencia temporal → pendiente → renombrado vive entera en
 * `DeliverDiagnosticsBundle`, y el escritor no publica un paquete pendiente sin
 * el comprobante `DeferredAuditReceipt` de su pendiente. PHP no tiene
 * visibilidad de paquete: que solo ese caso de uso escriba, guarde pendientes
 * y emita comprobantes lo fija esta prueba, sobre el codigo de `app/` sin
 * comentarios.
 */

const DIAGNOSTICS_DELIVERY_USE_CASE = 'Modules/Product/Application/UseCase/DeliverDiagnosticsBundle.php';

/**
 * Los ficheros de `app/` cuyo CODIGO (sin comentarios) nombra `$symbol` y hace
 * alguna de las llamadas de `$calls`, relativos a `app/`.
 *
 * @param  list<string>  $calls
 * @return list<string>
 */
function diagnosticsDeliveryCallers(string $symbol, array $calls): array
{
    $app = dirname(ModuleTree::root());
    $found = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (! str_contains($code, $symbol)) {
            continue;
        }

        foreach ($calls as $call) {
            if (str_contains($code, $call)) {
                $found[] = substr($file, strlen($app) + 1);

                break;
            }
        }
    }

    return $found;
}

it('solo DeliverDiagnosticsBundle escribe, publica o descarta paquetes', function (): void {
    expect(diagnosticsDeliveryCallers('DiagnosticsBundleWriter', ['->stage(', '->publish(', '->discard(']))
        ->toBe([DIAGNOSTICS_DELIVERY_USE_CASE]);
})->group('RF-PD-09', 'RL-04', 'RL-19');

it('solo DeliverDiagnosticsBundle guarda pendientes de asiento y emite su comprobante', function (): void {
    expect(diagnosticsDeliveryCallers('PendingDiagnosticsAudits', ['->save(']))
        ->toBe([DIAGNOSTICS_DELIVERY_USE_CASE])
        ->and(diagnosticsDeliveryCallers('DeferredAuditReceipt', ['DeferredAuditReceipt::forSaved(']))
        ->toBe([DIAGNOSTICS_DELIVERY_USE_CASE]);
})->group('RF-PD-09', 'RL-04', 'RL-19');

it('el escritor no tiene un write() de un paso que se salte el pendiente', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(DiagnosticsBundleWriter::class))->getMethods(),
    );

    expect($methods)->toBe(['stage', 'publish', 'discard', 'read']);
})->group('RF-PD-09', 'RL-04');
