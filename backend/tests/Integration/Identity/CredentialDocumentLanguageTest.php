<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Infrastructure\Adapter\BrowsershotCardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\EndroidQrEncoder;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Product\FixedLogo;

/*
 * El `<title>` y el `lang` de los PDF de credenciales, en el idioma negociado
 * (RV-2; RF-QR-04).
 *
 * Eran fijos en castellano: un panel puesto en ingles descargaba un PDF titulado
 * «Credenciales» y declarado `lang="es"`, que es lo que un lector de pantalla usa
 * para elegir la voz. El titulo va al metadato del PDF y al historial de
 * descargas, asi que sigue sin llevar nombres (regla dura 21).
 */

function credentialLanguageHtml(CardFormat $format): string
{
    $renderer = new BrowsershotCardRenderer(
        new EndroidQrEncoder(Config::string('identity.credentials.card.error_correction', 'Q')),
        FixedBranding::product(),
        new FixedLogo,
    );

    return $renderer->htmlFor([
        new PrintableCard(
            credentialUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            payload: QrPayload::parse('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'),
            holder: new EmployeeCardProfile(
                employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
                employeeCode: 'E7QK2MXPR',
                fullName: 'Youssef Amrani',
                siteName: 'Hotel de pruebas',
                siteId: 1,
                departmentName: 'Recepcion',
            ),
        ),
    ], $format);
}

it('titula y declara el documento en el idioma negociado', function (string $locale, CardFormat $format, string $title): void {
    App::setLocale($locale);

    $html = credentialLanguageHtml($format);

    preg_match('/<title>(.*?)<\/title>/s', $html, $match);

    expect($html)->toContain('<html lang="'.$locale.'">')
        ->and($match[1] ?? null)->toBe($title)
        // Sin nombres en el metadato (regla dura 21).
        ->and($match[1] ?? '')->not->toContain('Amrani');
})->with([
    'tarjeta en castellano' => ['es', CardFormat::CARD, 'Credencial'],
    'hoja en castellano' => ['es', CardFormat::SHEET, 'Credenciales'],
    'tarjeta en ingles' => ['en', CardFormat::CARD, 'Credential'],
    'hoja en ingles' => ['en', CardFormat::SHEET, 'Credentials'],
])->group('RF-QR-04');

it('escribe el lang con guion, como pide HTML', function (): void {
    // `es_ES` es el formato de PHP; HTML y los lectores de pantalla esperan
    // BCP 47, `es-ES`.
    App::setLocale('es_ES');

    expect(credentialLanguageHtml(CardFormat::CARD))->toContain('<html lang="es-ES">');
})->group('RF-QR-04');
