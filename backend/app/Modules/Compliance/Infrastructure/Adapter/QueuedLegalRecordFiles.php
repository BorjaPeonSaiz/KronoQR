<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Application\UseCase\WriteLegalExportFile;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Shared\Application\Port\LegalRecordFileWriter;
use App\Modules\Shared\Domain\ValueObject\LegalRecordFile;
use Closure;
use DateTimeImmutable;

/**
 * La exportacion para la Inspeccion, escrita por la cola de informes
 * (**RF-IN-05**). Traduce el puerto de `Shared` al caso de uso de
 * este modulo: el periodo y el alcance a sus objetos de valor, y el recuento
 * del escritor al {@see LegalRecordFile} que `Reporting` sabe leer.
 */
final readonly class QueuedLegalRecordFiles implements LegalRecordFileWriter
{
    public function __construct(private WriteLegalExportFile $files) {}

    public function write(
        string $from,
        string $to,
        ?string $employeeUuid,
        string $destinationPath,
        Closure $onProgress,
    ): LegalRecordFile {
        $period = LegalExportPeriod::between($from, $to);
        $scope = $employeeUuid === null ? LegalExportScope::everyone() : LegalExportScope::employee($employeeUuid);

        $tally = $this->files->handle($period, $scope, $destinationPath, $onProgress);

        return new LegalRecordFile(
            fileName: $this->fileNameFor($from, $to),
            from: $period->from,
            to: $period->to,
            employeeUuid: $scope->employeeUuid,
            shiftEntryRows: $tally->shiftEntries,
            correctionRows: $tally->corrections,
            employees: $tally->employees,
        );
    }

    /**
     * El nombre lo decide el manifiesto, como en la descarga sincrona: el
     * instante no interviene en el, y por eso cualquiera sirve aqui.
     */
    public function fileNameFor(string $from, string $to): string
    {
        return (new LegalExportManifest(
            generatedAt: new DateTimeImmutable('@0'),
            period: LegalExportPeriod::between($from, $to),
            scope: LegalExportScope::everyone(),
        ))->filename();
    }
}
