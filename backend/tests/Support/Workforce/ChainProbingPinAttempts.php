<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinAttemptReservation;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Infrastructure\Persistence\AuditChainLock;
use Illuminate\Support\Facades\DB;

/**
 * El contador de intentos de verdad, que **al limpiar** pregunta a `pg_locks`
 * si su propia sesion tiene el candado consultivo de la cadena de `audit_log`
 * (ADR-046).
 *
 * `clear()` toma el candado de cache de cada puerta y, con contienda, puede
 * esperar hasta medio segundo por puerta. Con la cadena en la mano, esa espera
 * la pagaria cada fichaje del hotel. Gemelo de {@see ChainProbingPinHasher}.
 */
final class ChainProbingPinAttempts implements PinAttempts
{
    /** @var list<bool> Si la sesion tenia la cadena, por cada limpieza, en orden. */
    public array $heldTheChain = [];

    public function __construct(private readonly PinAttempts $inner) {}

    public function isLocked(string $employeeUuid, PinOrigin $origin): bool
    {
        return $this->inner->isLocked($employeeUuid, $origin);
    }

    public function secondsUntilUnlock(string $employeeUuid, PinOrigin $origin): int
    {
        return $this->inner->secondsUntilUnlock($employeeUuid, $origin);
    }

    public function reserve(string $employeeCode, ?string $employeeUuid, PinOrigin $origin): PinAttemptReservation
    {
        return $this->inner->reserve($employeeCode, $employeeUuid, $origin);
    }

    public function clear(string $employeeUuid): void
    {
        /** @var object{held: bool} $row */
        $row = DB::selectOne(
            'SELECT count(*) > 0 AS held FROM pg_locks '
            .'WHERE locktype = ? AND granted AND pid = pg_backend_pid() AND classid = ? AND objid = ?',
            ['advisory', AuditChainLock::LOCK_NAMESPACE, AuditChainLock::LOCK_RESOURCE],
        );

        $this->heldTheChain[] = $row->held;

        $this->inner->clear($employeeUuid);
    }
}
