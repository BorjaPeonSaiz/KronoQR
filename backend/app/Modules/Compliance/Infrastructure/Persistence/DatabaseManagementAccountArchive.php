<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\AnonymizedManagementAccount;
use App\Modules\Compliance\Application\Port\ManagementAccountArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Las cuentas de gestion por la barrera de la base de datos (ADR-060, tarea
 * 11.3 B): la simulacion cuenta con `management_account_retention_candidates`
 * y la ejecucion llama a `management_account_anonymize_batch`, que calcula su
 * propio corte maximo y rechaza uno posterior con `42501`.
 */
final readonly class DatabaseManagementAccountArchive implements ManagementAccountArchive
{
    /** La funcion `SECURITY DEFINER` que minimiza. */
    public const string ANONYMIZE_FUNCTION = 'management_account_anonymize_batch';

    public function __construct(private ConnectionInterface $connection) {}

    public function inspect(DateTimeImmutable $cutoff): RetentionTally
    {
        /** @var object{row_count: int|string, oldest: string|null, newest: string|null}|null $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
                SELECT count(*) AS row_count,
                       min((u.deactivated_at AT TIME ZONE 'UTC')::date)::text AS oldest,
                       max((u.deactivated_at AT TIME ZONE 'UTC')::date)::text AS newest
                  FROM public.management_account_retention_candidates(?::date) c
                  JOIN public.users u ON u.id = c.id
                SQL,
            [$cutoff->format('Y-m-d')],
        );

        return new RetentionTally(
            scope: RetentionScope::ManagementAccounts,
            dataset: 'users',
            rows: (int) ($row->row_count ?? 0),
            oldest: $row->oldest ?? null,
            newest: $row->newest ?? null,
        );
    }

    public function candidates(DateTimeImmutable $cutoff): array
    {
        /** @var list<object{id: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT c.id FROM public.management_account_retention_candidates(?::date) c ORDER BY c.id',
            [$cutoff->format('Y-m-d')],
        );

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    public function anonymize(DateTimeImmutable $cutoff, int $batchSize, array $only): array
    {
        /** @var list<object{user_id: int|string, user_uuid: string, deactivated_on: string, tokens: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT user_id, user_uuid::text AS user_uuid, deactivated_on::text AS deactivated_on, tokens'
            .' FROM public.'.self::ANONYMIZE_FUNCTION.'(?::date, ?::integer, ?::bigint[])',
            [$cutoff->format('Y-m-d'), min(self::MAX_BATCH, max(1, $batchSize)), self::idArray($only)],
        );

        return array_map(
            static fn (object $row): AnonymizedManagementAccount => new AnonymizedManagementAccount(
                userId: (int) $row->user_id,
                userUuid: $row->user_uuid,
                deactivatedOn: $row->deactivated_on,
                tokensDeleted: (int) $row->tokens,
            ),
            $rows,
        );
    }

    /**
     * @param  list<int>  $ids
     */
    private static function idArray(array $ids): string
    {
        return '{'.implode(',', array_map(static fn (int $id): string => (string) $id, $ids)).'}';
    }
}
