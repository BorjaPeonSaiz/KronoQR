<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\IncidentDigest;
use App\Modules\Compliance\Application\Port\IncidentNotice;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Compliance\Infrastructure\Notification\IncidentDigestNotification;
use App\Modules\Product\Domain\ValueObject\DataExportManifest;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Infrastructure\Export\TranslatedDataExportGuide;
use App\Modules\Reporting\Application\Port\ReportExportRecipient;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\IsoWeek;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportCriterion;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;
use App\Modules\Reporting\Infrastructure\Notification\ReportExportReadyNotification;
use App\Modules\Reporting\Infrastructure\Notification\WeeklySummaryNotification;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\Branding;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Reporting\ReportExportFixtures;

/*
 * Los correos y el README de la exportacion integra llevan la marca de la
 * instalacion y el idioma de quien los lee (R6-PL-07, V6-PL-05; RF-PD-08).
 *
 * Antes salian con la plantilla de Laravel: «KronoQR» en el asunto, la cabecera,
 * la despedida y el pie (de `APP_NAME`, que no es la marca), y «Regards,» y «All
 * rights reserved.» en ingles aunque el destinatario estuviera en castellano.
 *
 * Se pinta cada correo de verdad (`MailMessage::render()`) con el proceso EN
 * OTRO IDIOMA que el destinatario: es el caso de la orden programada de
 * madrugada, y lo que demuestra que el idioma sale de la cuenta y no del
 * proceso. La marca es una cualquiera; nada de un cliente esta en el producto.
 */

const BRANDED_MAIL_BRAND = 'Hotel Marina';

/** El idioma del proceso, siempre el contrario al del destinatario. */
function brandedMailOtherLocale(string $locale): string
{
    return $locale === 'es' ? 'en' : 'es';
}

/**
 * Los tres correos del producto, en el idioma pedido.
 *
 * @return array<string, Closure(string): Notification>
 */
function brandedMailNotifications(): array
{
    return [
        'incidencias pendientes' => static fn (string $locale): Notification => new IncidentDigestNotification(
            new IncidentDigest(7, 'rrhh@hotel.example', $locale, [
                new IncidentNotice(1, IncidentType::MissingBreak, IncidentSeverity::Medium, '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', 'Persona De Prueba', '2026-03-02'),
            ]),
            BRANDED_MAIL_BRAND,
        ),
        'resumen semanal' => static fn (string $locale): Notification => new WeeklySummaryNotification(
            new WeeklySummaryRecipient(7, 'rrhh@hotel.example', $locale, AccessScope::unrestricted()),
            new WeeklySummary(
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
            ),
            BRANDED_MAIL_BRAND,
        ),
        'informe listo' => static fn (string $locale): Notification => new ReportExportReadyNotification(
            ReportExportFixtures::completed(),
            new ReportExportRecipient('Persona De Prueba', 'rrhh@hotel.example', $locale),
            BRANDED_MAIL_BRAND,
        ),
    ];
}

function brandedMailOf(Notification $notification): MailMessage
{
    // Los tres correos del producto, y ningun otro: si aparece un cuarto, tiene
    // que entrar aqui y en `brandedMailNotifications()`.
    return match (true) {
        $notification instanceof IncidentDigestNotification,
        $notification instanceof WeeklySummaryNotification,
        $notification instanceof ReportExportReadyNotification => $notification->toMail(new stdClass),
        default => throw new LogicException('No es un correo del producto: '.$notification::class),
    };
}

it('lleva la marca de la instalacion en el asunto, la cabecera, la despedida y el pie', function (string $locale, string $saludo): void {
    App::setLocale(brandedMailOtherLocale($locale));

    foreach (brandedMailNotifications() as $correo => $build) {
        $mail = brandedMailOf($build($locale));
        // Sin el `<head>`: su `<title>` es de la envoltura de Laravel y ningun
        // cliente de correo lo enseña (ver `BrandedMailMessage`).
        $texto = strip_tags((string) preg_replace('#<head>.*</head>#s', '', (string) $mail->render()));

        expect($mail->subject)->toStartWith(BRANDED_MAIL_BRAND.' · ', $correo)
            ->and($mail->subject)->not->toContain('KronoQR')
            // Cabecera, despedida y pie: al menos tres veces el nombre.
            ->and(substr_count($texto, BRANDED_MAIL_BRAND))->toBeGreaterThanOrEqual(3, $correo)
            ->and($texto)->toContain($saludo)
            ->and($texto)->not->toContain('KronoQR')
            ->and($texto)->not->toContain('Laravel')
            ->and($texto)->not->toContain('All rights reserved');
    }
})->with([
    'castellano' => ['es', 'Un saludo,'],
    'ingles' => ['en', 'Kind regards,'],
])->group('RF-PD-08', 'RF-PR-01', 'RF-PR-05', 'RF-IN-06');

it('no deja la despedida en ingles cuando el destinatario esta en castellano', function (): void {
    App::setLocale('en');

    foreach (brandedMailNotifications() as $correo => $build) {
        $texto = strip_tags((string) brandedMailOf($build('es'))->render());

        expect($texto)->not->toContain('Regards')
            ->and($texto)->not->toContain('regards');
    }
})->group('RF-PD-08');

it('envia cada correo en el idioma de quien lo recibe, no en el del proceso', function (string $locale): void {
    // `locale` es lo que Laravel usa para envolver el envio: con el, todo lo que
    // pinte el canal de correo sale en el idioma de la cuenta.
    App::setLocale(brandedMailOtherLocale($locale));

    foreach (brandedMailNotifications() as $correo => $build) {
        expect($build($locale)->locale)->toBe($locale, $correo);
    }
})->with(['es', 'en'])->group('RF-PD-08');

it('al enviarlo de verdad, el correo entero va en el idioma del destinatario', function (string $locale, string $saludo): void {
    // Por el camino real de `Notification::route()->notify()`, con el
    // transporte `array`: es el que envuelve el envio en el idioma de la
    // notificacion, y lo unico que demuestra que tambien el `lang` de la
    // envoltura HTML de Laravel sale en el del destinatario.
    config(['mail.default' => 'array']);
    App::setLocale(brandedMailOtherLocale($locale));

    NotificationFacade::route('mail', 'rrhh@hotel.example')
        ->notify(brandedMailNotifications()['incidencias pendientes']($locale));

    /** @var ArrayTransport $transport */
    $transport = app('mailer')->getSymfonyTransport();
    $last = $transport->messages()->last();
    $sent = $last instanceof SentMessage ? $last->getOriginalMessage() : null;

    expect($sent)->toBeInstanceOf(Email::class);

    $html = $sent instanceof Email ? (string) $sent->getHtmlBody() : '';

    expect($html)->toContain('lang="'.$locale.'"')
        ->and($html)->toContain($saludo)
        ->and($sent instanceof Email ? $sent->getSubject() : '')->toStartWith(BRANDED_MAIL_BRAND.' · ')
        // El idioma del proceso no ha cambiado al terminar.
        ->and(App::getLocale())->toBe(brandedMailOtherLocale($locale));
})->with([
    'castellano' => ['es', 'Un saludo,'],
    'ingles' => ['en', 'Kind regards,'],
])->group('RF-PD-08', 'RF-PR-01');

it('titula el README de la exportacion integra con el nombre de la instalacion', function (string $locale): void {
    $guide = new TranslatedDataExportGuide(
        app(Translator::class),
        new FixedBranding(new Branding(BRANDED_MAIL_BRAND, null, '#0f766e')),
    );

    $readme = $guide->render(new DataExportManifest(
        productVersion: '2.3.0',
        generatedAt: new DateTimeImmutable('2026-03-09T05:00:00Z'),
        siteTimezone: 'Europe/Madrid',
        requestedVia: DataExportOrigin::Console,
        requestedByUuid: null,
        requestedByName: null,
        files: [],
        notInstalled: [],
    ), $locale);

    $title = (string) strtok($readme, "\n");

    expect($title)->toBe('# '.__('data-export.title', ['app_name' => BRANDED_MAIL_BRAND], $locale))
        ->and($title)->toStartWith('# '.BRANDED_MAIL_BRAND.' · ')
        ->and($title)->not->toContain('KronoQR');
})->with(['es', 'en'])->group('RF-PD-14', 'RF-PD-08');
