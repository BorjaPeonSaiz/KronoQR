<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use App\Modules\Shared\Application\Exception\DatabaseFailure;
use App\Modules\Shared\Application\Exception\DatabaseFailureKind;
use App\Modules\Shared\Application\Port\DatabaseFailureTranslator;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * {@see DatabaseFailureTranslator} para PostgreSQL con PDO.
 *
 * ## Que cuenta como fallo de la base de datos
 *
 * Lo que **llega** como `PDOException` —`QueryException` lo es—. Un fallo de
 * otra clase que lleve uno dentro no lo es: lo ha envuelto alguien que ya
 * decidio que significa.
 *
 * ## De donde sale el `SQLSTATE`
 *
 * De la primera causa de la cadena que lo tenga. Laravel convierte un
 * `40P01` dentro de una transaccion anidada en `DeadlockException`, un
 * `PDOException` con codigo `0`; el `SQLSTATE` esta en la `QueryException`
 * original, que va como causa. Se lee de `errorInfo` antes que de `getCode()`,
 * porque el codigo es el `SQLSTATE` en unos drivers y un entero en otros.
 */
final readonly class PostgresFailureTranslator implements DatabaseFailureTranslator
{
    public function translate(Throwable $failure): ?DatabaseFailure
    {
        if (! $failure instanceof PDOException) {
            return null;
        }

        $sqlState = self::sqlStateOf($failure);

        return DatabaseFailure::of(self::kindOf($sqlState), $sqlState, $failure);
    }

    private static function kindOf(?string $sqlState): DatabaseFailureKind
    {
        return match ($sqlState) {
            '55P03' => DatabaseFailureKind::LockNotAvailable,
            '40P01' => DatabaseFailureKind::Deadlock,
            '40001' => DatabaseFailureKind::SerializationFailure,
            '57014' => DatabaseFailureKind::QueryCanceled,
            default => DatabaseFailureKind::Other,
        };
    }

    private static function sqlStateOf(Throwable $failure): ?string
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if (! $cause instanceof PDOException) {
                continue;
            }

            $state = $cause->errorInfo[0] ?? null;

            if (\is_string($state) && self::isSqlState($state)) {
                return $state;
            }

            // Sin `errorInfo`, el codigo: con pdo_pgsql es el SQLSTATE. El `0`
            // de `DeadlockException` no lo es, y se sigue por la causa.
            $code = (string) $cause->getCode();

            if (self::isSqlState($code)) {
                return $code;
            }
        }

        return null;
    }

    private static function isSqlState(string $value): bool
    {
        return preg_match('/^[0-9A-Z]{5}$/', $value) === 1;
    }
}
