<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Shared\Infrastructure\Persistence\AuditChainLock;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * El calculador de PIN de verdad, que **al calcular** pregunta a `pg_locks` si
 * su propia sesion tiene el candado consultivo de la cadena de `audit_log`
 * (ADR-046 §1.1 punto 5 y §6 punto 4; condicion A-3).
 *
 * bcrypt cuesta unos 160 ms con el coste de produccion. Calculado con la cadena
 * en la mano, cada restablecimiento de PIN congelaria esos 160 ms todos los
 * fichajes del hotel. La suite no puede verlo por tiempo —`BCRYPT_ROUNDS=4`—,
 * asi que se mira la condicion de la que depende: que cuando se calcula, la
 * cadena no esta tomada por esta sesion.
 */
final class ChainProbingPinHasher implements PinHasher
{
    /** @var list<bool> Si la sesion tenia la cadena, por cada calculo, en orden. */
    public array $heldTheChain = [];

    public function __construct(private readonly PinHasher $inner) {}

    public function hash(#[SensitiveParameter] string $pin): PinMaterial
    {
        /** @var object{held: bool} $row */
        $row = DB::selectOne(
            'SELECT count(*) > 0 AS held FROM pg_locks '
            .'WHERE locktype = ? AND granted AND pid = pg_backend_pid() AND classid = ? AND objid = ?',
            ['advisory', AuditChainLock::LOCK_NAMESPACE, AuditChainLock::LOCK_RESOURCE],
        );

        $this->heldTheChain[] = $row->held;

        return $this->inner->hash($pin);
    }
}
