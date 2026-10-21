<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\AnonymizedPersonnelRecord;
use App\Modules\Compliance\Application\Port\PersonnelRecordArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Las fichas por la barrera de la base de datos (ADR-060, tarea 11.3 A): la
 * simulacion cuenta con `personnel_record_retention_candidates` y la ejecucion
 * llama a `personnel_record_anonymize_batch`, que calcula su propio corte
 * maximo y rechaza uno posterior con `42501`.
 *
 * La foto vive en un directorio del servidor, no en la base de datos: se borra
 * despues del `COMMIT` y por eso por separado. Sin los discos de Laravel, como
 * el resto de ficheros que maneja el producto (ADR-045): una ruta y `unlink`.
 */
final readonly class DatabasePersonnelRecordArchive implements PersonnelRecordArchive
{
    /** La funcion `SECURITY DEFINER` que minimiza. */
    public const string ANONYMIZE_FUNCTION = 'personnel_record_anonymize_batch';

    /** El lote mas grande que admite la funcion. */
    public const int MAX_BATCH = 10000;

    public function __construct(
        private ConnectionInterface $connection,
        /** Directorio de las fotos; `photo_path` es relativo a el. */
        private string $photoRoot,
    ) {}

    public function inspect(DateTimeImmutable $cutoff): RetentionTally
    {
        /** @var object{row_count: int|string, oldest: string|null, newest: string|null}|null $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
                SELECT count(*) AS row_count,
                       min(e.terminated_at)::text AS oldest,
                       max(e.terminated_at)::text AS newest
                  FROM public.personnel_record_retention_candidates(?::date) c
                  JOIN public.employees e ON e.id = c.id
                SQL,
            [$cutoff->format('Y-m-d')],
        );

        return new RetentionTally(
            scope: RetentionScope::PersonnelRecords,
            dataset: 'employees',
            rows: (int) ($row->row_count ?? 0),
            oldest: $row->oldest ?? null,
            newest: $row->newest ?? null,
        );
    }

    public function anonymize(DateTimeImmutable $cutoff, int $batchSize): array
    {
        /** @var list<object{employee_uuid: string, terminated_on: string, contracts: int|string, absences: int|string, tokens: int|string, photo_path: string|null}> $rows */
        $rows = $this->connection->select(
            'SELECT employee_uuid::text AS employee_uuid, terminated_on::text AS terminated_on, contracts, absences, tokens, photo_path'
            .' FROM public.'.self::ANONYMIZE_FUNCTION.'(?::date, ?::integer)',
            [$cutoff->format('Y-m-d'), min(self::MAX_BATCH, max(1, $batchSize))],
        );

        return array_map(
            static fn (object $row): AnonymizedPersonnelRecord => new AnonymizedPersonnelRecord(
                employeeUuid: $row->employee_uuid,
                terminatedOn: $row->terminated_on,
                contractsDeleted: (int) $row->contracts,
                absencesDeleted: (int) $row->absences,
                tokensDeleted: (int) $row->tokens,
                photoPath: $row->photo_path,
            ),
            $rows,
        );
    }

    public function forgetPhoto(string $path): bool
    {
        // Una ruta que se sale del directorio no se toca: `photo_path` viene de
        // la base de datos, y no se borra nada fuera de su sitio por ella.
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }

        $file = rtrim($this->photoRoot, '/').'/'.$path;

        try {
            // Un fichero que ya no esta cuenta como borrado: lo que importa es
            // que no quede.
            return ! file_exists($file) || unlink($file);
        } catch (Throwable) {
            return false;
        }
    }
}
