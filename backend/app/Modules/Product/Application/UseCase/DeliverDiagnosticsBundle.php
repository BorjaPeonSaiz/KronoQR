<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DeferredAuditReceipt;
use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Application\Port\StagedDiagnosticsBundle;
use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Throwable;

/**
 * Deja en el disco el paquete que genero la consola (**RF-PD-09**, RL-04,
 * RL-19; ADR-020, enmienda del 14-10-2026).
 *
 * ## La secuencia, entera y en un solo sitio
 *
 * 1. `stage()`: el paquete entero en un temporal junto a su destino.
 * 2. Si su asiento quedo pendiente (la base no respondia), se guarda el
 *    pendiente. Si no se puede, se borra el temporal y lanza
 *    {@see DiagnosticsAuditNotDeferred}: sin pendiente, el asiento se perderia,
 *    y el paquete no sale.
 * 3. `publish()`: el renombrado atomico, con el comprobante
 *    {@see DeferredAuditReceipt} del pendiente guardado. El escritor se niega a
 *    publicar un paquete pendiente sin el.
 * 4. Si el renombrado falla, se borra el temporal **y el pendiente**: el
 *    asiento de un paquete que no llego a escribirse diria que se genero algo
 *    que nadie tiene.
 *
 * Es el unico llamante de `stage()`, `publish()`, `discard()` y de
 * `PendingDiagnosticsAudits::save()`, y el unico emisor del comprobante:
 * `DiagnosticsDeliveryTest` (Architecture) lo exige. Al comando le queda la
 * entrada y salida de consola.
 *
 * Ventana residual, documentada en ADR-020: si el proceso muere entre el paso 2
 * y el 3, queda el asiento de un paquete generado y no entregado, con su
 * temporal en el disco hasta la purga por antiguedad.
 */
final readonly class DeliverDiagnosticsBundle
{
    public function __construct(
        private DiagnosticsBundleWriter $writer,
        private PendingDiagnosticsAudits $pending,
    ) {}

    /**
     * @param  string|null  $path  Ruta pedida con `--output`, o nula para el
     *                             directorio de serie.
     * @return string La ruta absoluta donde quedo el paquete.
     *
     * @throws DiagnosticsAuditNotDeferred Si el pendiente no se pudo guardar.
     */
    public function handle(DiagnosticsBundle $bundle, ?string $path = null): string
    {
        return UseCaseInstrumentation::run('product.deliver_diagnostics_bundle', fn (): string => $this->doHandle($bundle, $path));
    }

    /**
     * @param  string|null  $path  Ruta pedida con `--output`, o nula para el
     *                             directorio de serie.
     * @return string La ruta absoluta donde quedo el paquete.
     */
    private function doHandle(DiagnosticsBundle $bundle, ?string $path = null): string
    {
        $staged = $this->writer->stage($bundle, $path);
        $receipt = $bundle->generationEntryPending() ? $this->deferAudit($bundle, $staged) : null;

        try {
            return $this->writer->publish($staged, $receipt);
        } catch (Throwable $failure) {
            $this->writer->discard($staged);

            if ($receipt instanceof DeferredAuditReceipt) {
                $this->forget($receipt);
            }

            throw $failure;
        }
    }

    /**
     * Borra el pendiente del paquete que no se publico. Si ni eso se puede, sube
     * el fallo original y no este: `product:doctor` avisara del pendiente.
     */
    private function forget(DeferredAuditReceipt $receipt): void
    {
        try {
            $this->pending->remove($receipt->sha256);
        } catch (Throwable) {
            // Ver el docblock.
        }
    }

    private function deferAudit(DiagnosticsBundle $bundle, StagedDiagnosticsBundle $staged): DeferredAuditReceipt
    {
        $saved = PendingDiagnosticsAudit::of($bundle, PendingDiagnosticsAudit::REASON_DATABASE_UNAVAILABLE);

        try {
            $this->pending->save($saved);
        } catch (Throwable $failure) {
            $this->writer->discard($staged);

            throw $failure;
        }

        return DeferredAuditReceipt::forSaved($saved);
    }
}
