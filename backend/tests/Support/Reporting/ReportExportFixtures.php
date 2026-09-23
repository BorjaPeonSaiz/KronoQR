<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Un {@see ReportExport} recien pedido, para las pruebas unitarias del modelo
 * (**RF-IN-06**).
 *
 * Existe para que las pruebas de transicion no tengan que escribir veintisiete
 * argumentos cada vez: lo que cada una necesita decir es **de que estado parte**,
 * y el resto es ruido que esconde lo que se esta probando.
 *
 * **No toca la base de datos.** Es un objeto de dominio puro, que es justo lo
 * que permite probar el ciclo de vida entero —incluidos el enlace y su
 * caducidad— sin levantar PostgreSQL ni mover el reloj de la maquina.
 */
final class ReportExportFixtures
{
    /** El instante de referencia de todas las pruebas del modelo. */
    public const string REQUESTED_AT = '2026-03-08T09:00:00+00:00';

    public static function pending(
        ReportExportKind $kind = ReportExportKind::Period,
        string $format = 'csv',
        int $requestedByUserId = 7,
    ): ReportExport {
        return new ReportExport(
            id: 1,
            uuid: '019a12b4-5c6d-7e8f-9012-3456789abcde',
            kind: $kind,
            format: $format,
            status: ReportExportStatus::Pending,
            parameters: new ReportExportParameters(
                from: '2026-03-01',
                to: '2026-03-31',
                granularity: ReportGranularity::Day,
                grouping: ReportGrouping::Employee,
                includeOpenShifts: false,
                departmentId: null,
                employeeUuid: null,
            ),
            scope: AccessScope::unrestricted(),
            requestedByUserId: $requestedByUserId,
            requestedByUuid: '019a12b4-0000-7000-8000-000000000001',
            requestedByName: 'Direccion',
            requestedAt: self::at(self::REQUESTED_AT),
            startedAt: null,
            completedAt: null,
            failedAt: null,
            failureReason: null,
            filePath: null,
            fileName: null,
            sizeBytes: null,
            sha256: null,
            rowCount: null,
            criteria: [],
            expiresAt: null,
            purgedAt: null,
            downloadTokenHash: null,
            downloadTokenExpiresAt: null,
            downloadedAt: null,
            downloadCount: 0,
            notifiedAt: null,
            notificationChannel: null,
        );
    }

    /**
     * La misma exportacion ya terminada, con fichero y caducidad.
     *
     * Se compone con las transiciones de verdad —`start()` y `complete()`— y no
     * construyendo el estado final a mano: asi el punto de partida de las pruebas
     * del enlace es un estado que el propio modelo admite, y no uno que solo
     * existe en el fixture.
     */
    public static function completed(string $format = 'csv'): ReportExport
    {
        return self::pending(format: $format)
            ->start(self::at('2026-03-08T09:00:05+00:00'))
            ->complete(
                completedAt: self::at('2026-03-08T09:02:00+00:00'),
                filePath: '/var/www/html/storage/app/reports/019a12b4/kronoqr-horas-2026-03-01_2026-03-31.csv',
                fileName: 'kronoqr-horas-2026-03-01_2026-03-31.csv',
                sizeBytes: 4096,
                sha256: str_repeat('a', 64),
                rowCount: 31,
                criteria: ['Los totales salen del registro horario ya consolidado.'],
                expiresAt: self::at('2026-03-15T09:02:00+00:00'),
            );
    }

    public static function at(string $wallClock): DateTimeImmutable
    {
        return new DateTimeImmutable($wallClock, new DateTimeZone('UTC'));
    }

    /** No se instancia: es una fabrica de datos de prueba. */
    private function __construct() {}
}
