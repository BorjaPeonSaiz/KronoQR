<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Application\Port\ErrorHistoryRewriter;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventPage;
use App\Modules\Product\Domain\ValueObject\ErrorEventSummary;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorWriteOutcome;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateTimeImmutable;

/**
 * `error_events` en memoria, para las pruebas unitarias de ADR-048: lo que
 * llega al repositorio desde `RecordErrorEvent`, lo que lee el colector del
 * paquete y lo que reescribe `ResanitizeErrorHistory`.
 *
 * No reproduce el `ON CONFLICT` ni la reapertura de PostgreSQL —eso lo prueban
 * las de integracion—: guarda cada `upsert()` tal cual llega y agrupa por
 * huella lo justo para contar.
 */
final class InMemoryErrorHistory implements ErrorEventRepository, ErrorHistoryRewriter
{
    /** @var list<array{report: ErrorReport, fingerprint: string, message: string, context: array<string, scalar>}> */
    public array $writes = [];

    /** @var array<int, ErrorEvent> */
    private array $groups = [];

    /** Cuantas veces se ha llamado a `rewrite()` y a `merge()`. */
    public int $rewrites = 0;

    public int $merges = 0;

    /**
     * @param  list<ErrorEvent>  $groups
     */
    public function __construct(array $groups = [])
    {
        foreach ($groups as $group) {
            $this->groups[$group->id] = $group;
        }
    }

    /**
     * Un grupo con valores de serie, para sembrar.
     *
     * @param  array<string, scalar>  $context
     */
    public static function group(
        int $id,
        string $message,
        array $context = [],
        ErrorSource $source = ErrorSource::Admin,
        ?string $code = 'web.vue_error',
        int $occurrences = 1,
        string $firstSeenAt = '2026-09-01T10:00:00Z',
        string $lastSeenAt = '2026-09-02T10:00:00Z',
        ?string $resolvedAt = null,
        ?string $resolvedByUuid = null,
        ?string $fingerprint = null,
        string $appVersion = '2.1.0',
        ?string $file = null,
        ?string $exceptionClass = null,
        ?string $employeeUuid = null,
    ): ErrorEvent {
        return new ErrorEvent(
            id: $id,
            fingerprint: $fingerprint ?? hash('sha256', 'legacy|'.$id.'|'.$message),
            level: ErrorLevel::Error,
            source: $source,
            module: null,
            code: $code,
            message: $message,
            exceptionClass: $exceptionClass,
            file: $file,
            line: null,
            context: $context,
            traceId: null,
            deviceId: null,
            employeeUuid: $employeeUuid,
            appVersion: $appVersion,
            occurrences: $occurrences,
            firstSeenAt: new DateTimeImmutable($firstSeenAt),
            lastSeenAt: new DateTimeImmutable($lastSeenAt),
            resolvedAt: $resolvedAt === null ? null : new DateTimeImmutable($resolvedAt),
            resolvedByUuid: $resolvedByUuid,
            resolvedByName: $resolvedByUuid === null ? null : 'Cuenta de gestion',
        );
    }

    /** @return list<ErrorEvent> */
    public function all(): array
    {
        ksort($this->groups);

        return array_values($this->groups);
    }

    public function upsert(
        ErrorReport $report,
        ErrorFingerprint $fingerprint,
        string $message,
        array $context,
        DateTimeImmutable $seenAt,
        DateTimeImmutable $recordedAt,
    ): ErrorWriteOutcome {
        $known = $this->exists($fingerprint);

        $this->writes[] = [
            'report' => $report,
            'fingerprint' => $fingerprint->value,
            'message' => $message,
            'context' => $context,
        ];

        return $known ? ErrorWriteOutcome::Recurred : ErrorWriteOutcome::Opened;
    }

    public function countOpenGroups(ErrorSource $source): int
    {
        return 0;
    }

    public function exists(ErrorFingerprint $fingerprint): bool
    {
        foreach ($this->writes as $write) {
            if ($write['fingerprint'] === $fingerprint->value) {
                return true;
            }
        }

        return false;
    }

    public function page(ErrorEventQuery $query): ErrorEventPage
    {
        $rows = $this->all();

        return new ErrorEventPage($rows, \count($rows), 1, max(1, $query->perPage), 0, 0);
    }

    public function resolve(int $id, int $userId, DateTimeImmutable $at): ?ErrorEvent
    {
        return $this->groups[$id] ?? null;
    }

    public function pruneOlderThan(DateTimeImmutable $cutoff, int $batchSize): int
    {
        return 0;
    }

    public function summary(DateTimeImmutable $since): ErrorEventSummary
    {
        return new ErrorEventSummary([], [], \count($this->groups), 0);
    }

    public function groupsAfter(int $afterId, int $limit): array
    {
        return \array_slice(
            array_values(array_filter($this->all(), static fn (ErrorEvent $group): bool => $group->id > $afterId)),
            0,
            $limit,
        );
    }

    public function findByFingerprint(string $fingerprint): ?ErrorEvent
    {
        foreach ($this->groups as $group) {
            if ($group->fingerprint === $fingerprint) {
                return $group;
            }
        }

        return null;
    }

    public function rewrite(ErrorEvent $group): void
    {
        $this->rewrites++;
        $this->groups[$group->id] = $group;
    }

    public function merge(ErrorEvent $survivor, int $absorbedId): void
    {
        $this->merges++;
        unset($this->groups[$absorbedId]);
        $this->groups[$survivor->id] = $survivor;
    }
}
