<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Infrastructure\Persistence\DatabaseResponsibleAccounts;
use Illuminate\Support\Facades\DB;

/**
 * La baja de la cuenta que firma una orden de consola mientras se firma (doc 01
 * §7.2, «Firmas por consola»).
 *
 * La consola ve la cuenta tal como esta (`find`, de verdad) y pregunta con
 * ella; el caso de uso deja de darla por buena a partir de la comprobacion
 * numero `$validChecks + 1`. Con `0`, la baja llega entre la pregunta y la
 * primera comprobacion del caso de uso; con `1`, entre la primera —antes de
 * escribir nada— y la que se hace con la cadena tomada.
 */
final class DeactivatedWhileSigning
{
    public static function after(int $validChecks = 0): void
    {
        app()->instance(ResponsibleAccounts::class, new class($validChecks) implements ResponsibleAccounts
        {
            public function __construct(private int $remaining) {}

            public function find(ResponsibleReference $reference): ?ResponsibleAccount
            {
                return new DatabaseResponsibleAccounts(DB::connection())->find($reference);
            }

            public function isActiveManagementAccount(int $userId, array $roles = []): bool
            {
                return $this->remaining-- > 0;
            }
        });
    }
}
