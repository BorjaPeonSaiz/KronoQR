<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Event\ReportExportGenerated;

/*
 * El evento del informe en diferido lleva el asiento de lectura ya resuelto
 * (tarea 11.4, V4-BE-5): conjunto, filas y contexto, para que el listener lo
 * escriba a nombre de quien lo pidio.
 */

it('lleva la divulgacion que acompaña a la generacion', function (): void {
    $event = new ReportExportGenerated(
        uuid: '0199f0c2-0000-7000-8000-000000000001',
        kind: 'period',
        format: 'csv',
        fileName: 'kronoqr-horas-2026-03-01_2026-03-31.csv',
        sha256: str_repeat('a', 64),
        sizeBytes: 120,
        rowCount: 3,
        expiresAt: '2026-04-07T00:00:00+00:00',
        employeeUuids: [],
        requestedByUserId: 7,
        disclosureDataset: 'period_report',
        disclosureRecordCount: 3,
        disclosureContext: ['format' => 'csv', 'scope' => 'all'],
        occurredAt: new DateTimeImmutable('2026-03-31T10:00:00Z'),
    );

    expect($event->disclosureDataset)->toBe('period_report')
        ->and($event->disclosureRecordCount)->toBe(3)
        ->and($event->disclosureContext)->toBe(['format' => 'csv', 'scope' => 'all'])
        ->and($event->requestedByUserId)->toBe(7)
        ->and($event->eventName())->toBe('reporting.report_export_generated')
        ->and($event->occurredAt()->format(DATE_ATOM))->toBe('2026-03-31T10:00:00+00:00');
})->group('RS-05', 'RF-IN-06');

it('lleva el asiento legal de la exportacion para la Inspeccion y ninguna lectura aparte', function (): void {
    // V5-PA-4: la diferida deja el mismo `legal_export.generated` que la
    // sincrona, y como ella, sin `personal_data.accessed`.
    $event = new ReportExportGenerated(
        uuid: '0199f0c2-0000-7000-8000-000000000001',
        kind: 'legal',
        format: 'csv',
        fileName: 'registro-horario-2026-03-01_2026-03-31.csv',
        sha256: str_repeat('a', 64),
        sizeBytes: 120,
        rowCount: 3,
        expiresAt: '2026-04-07T00:00:00+00:00',
        employeeUuids: [],
        requestedByUserId: 7,
        disclosureDataset: null,
        disclosureRecordCount: 3,
        disclosureContext: [],
        occurredAt: new DateTimeImmutable('2026-03-31T10:00:00Z'),
        legalExport: ['period_from' => '2026-03-01', 'scope' => 'all'],
    );

    expect($event->disclosureDataset)->toBeNull()
        ->and($event->legalExport)->toBe(['period_from' => '2026-03-01', 'scope' => 'all']);
})->group('RS-05', 'RF-IN-05');
