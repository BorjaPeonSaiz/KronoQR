<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\ReportExportRecipient;
use App\Modules\Reporting\Infrastructure\Notification\ReportExportReadyNotification;
use Tests\Support\Reporting\ReportExportFixtures;

/*
 * La caducidad del correo «tu informe esta listo» va en UTC, como dice su
 * texto (RN-04, RF-IN-06).
 *
 * El texto rotula la hora «(hora UTC)». Antes se escribia con
 * `format('Y-m-d H:i')` sin pasar a UTC: un instante que llegara con desfase
 * salia con su hora local y la etiqueta mentia. Sin base de datos: se compone
 * el correo con el modelo de dominio y se lee su cuerpo.
 */

it('escribe la caducidad en UTC aunque el instante llegue con desfase', function (string $locale): void {
    // 10:02 en Madrid el 15 de marzo (+01:00) son las 09:02 UTC.
    $export = ReportExportFixtures::pending()
        ->start(ReportExportFixtures::at('2026-03-08T09:00:05+00:00'))
        ->complete(
            completedAt: ReportExportFixtures::at('2026-03-08T09:02:00+00:00'),
            filePath: '/var/www/html/storage/app/reports/019a12b4/kronoqr-horas-2026-03-01_2026-03-31.csv',
            fileName: 'kronoqr-horas-2026-03-01_2026-03-31.csv',
            sizeBytes: 4096,
            sha256: str_repeat('a', 64),
            rowCount: 31,
            criteria: [],
            expiresAt: new DateTimeImmutable('2026-03-15 10:02:00', new DateTimeZone('Europe/Madrid')),
        );

    $mail = new ReportExportReadyNotification(
        $export,
        new ReportExportRecipient('Persona De Prueba', 'rrhh@hotel.example', $locale),
        'Hotel De Prueba',
    )->toMail(new stdClass);

    $body = implode("\n", array_map(
        static fn (mixed $line): string => \is_string($line) ? $line : '',
        [...$mail->introLines, ...$mail->outroLines],
    ));

    expect($body)->toContain('2026-03-15 09:02')
        ->and($body)->not->toContain('10:02');
})->with(['es', 'en'])->group('RF-IN-06', 'RN-04');
