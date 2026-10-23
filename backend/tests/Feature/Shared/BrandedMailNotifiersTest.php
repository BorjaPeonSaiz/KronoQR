<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\IncidentDigest;
use App\Modules\Compliance\Application\Port\IncidentNotice;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Compliance\Infrastructure\Notification\IncidentDigestNotification;
use App\Modules\Compliance\Infrastructure\Notification\MailIncidentNotifier;
use App\Modules\Reporting\Application\Port\ReportExportRecipient;
use App\Modules\Reporting\Application\Port\ReportExportRecipients;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\IsoWeek;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportCriterion;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;
use App\Modules\Reporting\Infrastructure\Notification\MailReportExportNotifier;
use App\Modules\Reporting\Infrastructure\Notification\MailWeeklySummaryNotifier;
use App\Modules\Reporting\Infrastructure\Notification\ReportExportReadyNotification;
use App\Modules\Reporting\Infrastructure\Notification\WeeklySummaryNotification;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\Branding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Reporting\ReportExportFixtures;

/*
 * Los tres notificadores de correo toman la marca del PUERTO (R6-PL-07).
 *
 * - Con un proveedor fijo, el nombre que llega al correo es
 *   `BrandingProvider::current()->applicationName`, y no `APP_NAME`.
 * - Si el proveedor lanza —la tabla de ajustes no responde—, el correo no
 *   sale, el notificador devuelve lo que hace que el caso de uso NO selle
 *   (`false`, o el canal `panel`), y el log dice que paso sin direccion ni
 *   nombre de nadie (regla dura 21).
 */

const BRANDED_MAIL_NOTIFIERS_EMAIL = 'rrhh@hotel-notificadores.example';

const BRANDED_MAIL_NOTIFIERS_PERSON = 'Persona De Prueba';

function brandedMailNotifiersBrand(): BrandingProvider
{
    return new FixedBranding(new Branding('Hotel Notificadores', null, '#0f766e'));
}

function brandedMailNotifiersBroken(): BrandingProvider
{
    return new class implements BrandingProvider
    {
        public function current(): Branding
        {
            throw new RuntimeException('installation_settings no responde');
        }
    };
}

function brandedMailNotifiersDigest(): IncidentDigest
{
    return new IncidentDigest(7, BRANDED_MAIL_NOTIFIERS_EMAIL, 'es', [
        new IncidentNotice(1, IncidentType::MissingBreak, IncidentSeverity::Medium, '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', BRANDED_MAIL_NOTIFIERS_PERSON, '2026-03-02'),
    ]);
}

function brandedMailNotifiersWeekly(): WeeklySummary
{
    return new WeeklySummary(
        IsoWeek::fromLabel('2026-W10'),
        new PeriodReport(
            rows: [],
            range: DateRange::between('2026-03-02', '2026-03-08'),
            granularity: ReportGranularity::Week,
            grouping: ReportGrouping::Employee,
            timeZone: 'Europe/Madrid',
            generatedAt: new DateTimeImmutable('2026-03-09T05:00:00Z'),
            criteria: ReportCriterion::listOf(['criteria.source']),
            contractCoverage: new ContractCoverage(0, 0),
        ),
        0,
    );
}

function brandedMailNotifiersWeeklyRecipient(): WeeklySummaryRecipient
{
    return new WeeklySummaryRecipient(7, BRANDED_MAIL_NOTIFIERS_EMAIL, 'es', AccessScope::unrestricted());
}

function brandedMailNotifiersExport(BrandingProvider $branding): MailReportExportNotifier
{
    return new MailReportExportNotifier(
        new class implements ReportExportRecipients
        {
            public function find(int $userId): ReportExportRecipient
            {
                return new ReportExportRecipient(BRANDED_MAIL_NOTIFIERS_PERSON, BRANDED_MAIL_NOTIFIERS_EMAIL, 'es');
            }
        },
        // Un transporte «real»: con `log` o `array` el notificador ni lo intenta.
        'smtp',
        $branding,
    );
}

/** Que el aviso del log no lleve la direccion ni el nombre (regla dura 21). */
function brandedMailNotifiersLogIsClean(MockInterface $log, string $event): void
{
    $log->shouldHaveReceived('warning')->withArgs(
        static function (string $message, array $context) use ($event): bool {
            $dump = (string) json_encode($context);

            return $message === $event
                && ! str_contains($dump, BRANDED_MAIL_NOTIFIERS_EMAIL)
                && ! str_contains($dump, BRANDED_MAIL_NOTIFIERS_PERSON);
        },
    )->once();
}

it('pone en el aviso de incidencias el nombre que da el proveedor de marca', function (): void {
    Notification::fake();

    expect((new MailIncidentNotifier(brandedMailNotifiersBrand()))->notify(brandedMailNotifiersDigest()))->toBeTrue();

    Notification::assertSentOnDemand(
        IncidentDigestNotification::class,
        static fn (IncidentDigestNotification $aviso): bool => str_starts_with((string) $aviso->toMail(new stdClass)->subject, 'Hotel Notificadores · '),
    );
})->group('RF-PD-08', 'RF-PR-01');

it('pone en el resumen semanal el nombre que da el proveedor de marca', function (): void {
    Notification::fake();

    expect((new MailWeeklySummaryNotifier(brandedMailNotifiersBrand()))->send(brandedMailNotifiersWeeklyRecipient(), brandedMailNotifiersWeekly()))->toBeTrue();

    Notification::assertSentOnDemand(
        WeeklySummaryNotification::class,
        static fn (WeeklySummaryNotification $aviso): bool => str_starts_with((string) $aviso->toMail(new stdClass)->subject, 'Hotel Notificadores · '),
    );
})->group('RF-PD-08', 'RF-PR-05');

it('pone en el aviso de informe listo el nombre que da el proveedor de marca', function (): void {
    Notification::fake();

    expect(brandedMailNotifiersExport(brandedMailNotifiersBrand())->notify(ReportExportFixtures::completed()))
        ->toBe(ReportExportNotificationChannel::Mail);

    Notification::assertSentOnDemand(
        ReportExportReadyNotification::class,
        static fn (ReportExportReadyNotification $aviso): bool => str_starts_with((string) $aviso->toMail(new stdClass)->subject, 'Hotel Notificadores · '),
    );
})->group('RF-PD-08', 'RF-IN-06');

it('sin marca legible no envia el aviso de incidencias, no deja sellar y lo anota sin datos personales', function (): void {
    Notification::fake();
    $log = Log::spy();

    // `false` es lo que hace que `NotifyPendingIncidents` no selle `notified_at`.
    expect((new MailIncidentNotifier(brandedMailNotifiersBroken()))->notify(brandedMailNotifiersDigest()))->toBeFalse();

    Notification::assertNothingSent();
    brandedMailNotifiersLogIsClean($log, 'incidents.digest_not_delivered');
})->group('RF-PD-08', 'RF-PR-01');

it('sin marca legible no envia el resumen semanal, lo cuenta como no entregado y lo anota sin datos personales', function (): void {
    Notification::fake();
    $log = Log::spy();

    expect((new MailWeeklySummaryNotifier(brandedMailNotifiersBroken()))->send(brandedMailNotifiersWeeklyRecipient(), brandedMailNotifiersWeekly()))->toBeFalse();

    Notification::assertNothingSent();
    brandedMailNotifiersLogIsClean($log, 'reporting.weekly_summary_not_delivered');
})->group('RF-PD-08', 'RF-PR-05');

it('sin marca legible no envia el aviso de informe listo, deja el canal en panel y lo anota sin datos personales', function (): void {
    Notification::fake();
    $log = Log::spy();

    // `panel` y no `mail`: la fila no se sella como avisada por correo.
    expect(brandedMailNotifiersExport(brandedMailNotifiersBroken())->notify(ReportExportFixtures::completed()))
        ->toBe(ReportExportNotificationChannel::Panel);

    Notification::assertNothingSent();
    brandedMailNotifiersLogIsClean($log, 'report_export.notification_not_delivered');
})->group('RF-PD-08', 'RF-IN-06');
