<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use App\Support\Database\LimitsMigrationLocks;
use Closure;
use Illuminate\Database\Migrations\Migration;

/**
 * Una migracion de prueba que expone los ayudantes protegidos de
 * {@see LimitsMigrationLocks}, para ensayarlos sobre PostgreSQL de verdad sin
 * escribir un fichero de migracion (`tests/Integration/Schema/MigrationLockLimitsTest.php`).
 *
 * Clase con nombre y no anonima: PHPStan 9 no ve los metodos publicos de una
 * clase anonima devuelta como `Migration`.
 */
final class LockLimitedMigration extends Migration
{
    use LimitsMigrationLocks;

    /** `null` es la conexion por defecto, como en cualquier migracion. */
    public function __construct(?string $connection = null)
    {
        $this->connection = $connection;
    }

    public function limit(): bool
    {
        $this->limitLockWait();

        return true;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $statements
     * @return TResult
     */
    public function lockWaitOnly(Closure $statements): mixed
    {
        return $this->withLockWaitOnly($statements);
    }

    public function validate(string $table, string $constraint): bool
    {
        $this->validateConstraint($table, $constraint);

        return true;
    }

    public function createIndex(string $index, string $definition): bool
    {
        $this->createIndexConcurrently($index, $definition);

        return true;
    }

    public function dropIndex(string $index): bool
    {
        $this->dropIndexConcurrently($index);

        return true;
    }
}
