<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;

/**
 * `diagnostics.pending_audit`: hay paquetes de diagnostico generados con la
 * base de datos caida cuyo asiento lleva mas de una hora sin escribirse
 * (ADR-020, enmienda del 14-10-2026).
 *
 * El asiento lo escribe el planificador cada cinco minutos en cuanto la base
 * vuelve. Si un pendiente pasa de una hora, o la base lleva una hora caida —y
 * `database.connection` ya lo dice—, o el planificador no lo esta escribiendo:
 * en los dos casos hay un paquete entregado sin su asiento, y quien administra
 * el servidor tiene que saberlo. **Aviso, nunca fallo**: fichar y consultar el
 * registro no dependen de esto.
 *
 * No toca la base: lee los ficheros del volumen.
 */
final readonly class PendingDiagnosticsAuditProbe implements DoctorProbe
{
    /** A partir de cuanto un pendiente deja de ser normal. */
    public const int STALE_AFTER_SECONDS = 3600;

    public function __construct(
        private PendingDiagnosticsAudits $pending,
        private Clock $clock,
    ) {}

    public function family(): string
    {
        return 'diagnostics';
    }

    public function run(): array
    {
        $id = 'diagnostics.pending_audit';
        $pending = $this->pending->all();
        $limit = $this->clock->now()->modify('-'.self::STALE_AFTER_SECONDS.' seconds');

        $stale = array_values(array_filter(
            $pending,
            static fn (PendingDiagnosticsAudit $entry): bool => $entry->generatedAt <= $limit,
        ));

        if ($stale === []) {
            return [DoctorFinding::ok($id, ['pending' => \count($pending), 'stale' => 0])];
        }

        // `all()` los devuelve del mas antiguo al mas reciente.
        $oldest = UtcInstant::of($stale[0]->generatedAt);

        return [DoctorFinding::warning(
            $id,
            params: ['count' => \count($stale), 'oldest' => $oldest],
            details: ['pending' => \count($pending), 'stale' => \count($stale), 'oldest_generated_at' => $oldest],
        )];
    }
}
