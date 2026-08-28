<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Infrastructure\Adapter\BrowsershotCardRenderer;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;
use Illuminate\Support\Facades\Log;
use Mockery\VerificationDirector;

/*
 * La DISPOSICION de la tarjeta impresa (RF-QR-04, RF-QR-05).
 *
 * SOBRE EL HTML Y NO SOBRE EL PDF. Lo que se comprueba aqui es el documento que
 * recibe Chromium, no los bytes que devuelve: un PDF no se puede interrogar sobre
 * «donde esta el codigo de empleado», y una prueba que arrancara un navegador
 * mediria ademas la presencia de un binario en la maquina —lo que ya se decidio
 * no hacer en `FakeCardRenderer`—. La geometria vive en milimetros en el HTML, y
 * en milimetros es donde significa algo: los milimetros de un PDF de 85,6 x 54 mm
 * son los milimetros de la tarjeta.
 *
 * LAS DOS MITADES. La derecha es del QR, la izquierda del texto, y el reparto lo
 * calcula el renderizador. Estas pruebas son las que impiden que la mitad del QR
 * se erosione: cada vez que alguien quiera meter una linea mas de texto, la
 * tentacion sera quitarle un par de milimetros al simbolo, y el sintoma —tarjetas
 * que se leen peor a los seis meses de uso— tarda una temporada en aparecer.
 */

/** El payload de ejemplo del doc 02 §5.1. No es de nadie: no hay credencial detras. */
const CARD_LAYOUT_PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * @return list<PrintableCard>
 */
function printableCards(int $count = 1): array
{
    $cards = [];

    for ($i = 0; $i < $count; $i++) {
        $cards[] = new PrintableCard(
            credentialUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b9'.$i,
            payload: QrPayload::parse(CARD_LAYOUT_PAYLOAD),
            holder: new EmployeeCardProfile(
                employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b9'.$i,
                employeeCode: 'E7QK2MXPR',
                fullName: 'Youssef Amrani',
                siteName: 'Hotel de pruebas',
                siteId: 1,
                departmentName: 'Recepcion',
            ),
        );
    }

    return $cards;
}

function cardHtml(CardFormat $format = CardFormat::CARD, int $count = 1): string
{
    return app(BrowsershotCardRenderer::class)->htmlFor(printableCards($count), $format);
}

it('imprime el codigo de empleado en negrita justo debajo del nombre', function (): void {
    // El orden importa y por eso se afirma con posiciones y no con «contiene»:
    // quien busca su tarjeta en una caja lee el nombre, y quien empareja una
    // tarjeta suelta con su dueno lee el codigo. La negrita esta en el
    // documento, no en la hoja de estilos, para que sobreviva a imprimir este
    // HTML sin CSS.
    $html = cardHtml();

    $name = strpos($html, '<p class="card__name">Youssef Amrani</p>');
    $code = strpos($html, '<strong class="card__code">E7QK2MXPR</strong>');
    $department = strpos($html, '<p class="card__department">Recepcion</p>');
    $site = strpos($html, '<p class="card__site">Hotel de pruebas</p>');

    expect($name)->toBeInt()
        ->and($code)->toBeInt()
        ->and($department)->toBeInt()
        ->and($site)->toBeInt();

    expect($code)->toBeGreaterThan((int) $name)
        ->and($department)->toBeGreaterThan((int) $code)
        ->and($site)->toBeGreaterThan((int) $department);
})->group('RF-QR-04');

it('escribe el codigo mas pequeno que el nombre', function (): void {
    // «Mayor» y «menor» de la peticion son relativos: el nombre es lo que se lee
    // de un vistazo y el codigo identifica. Si algun dia se igualan, la tarjeta
    // deja de tener jerarquia y las dos lineas compiten.
    $html = cardHtml();

    preg_match('/\.card__name \{[^}]*font-size: ([0-9.]+)pt/', $html, $name);
    preg_match('/\.card__code \{[^}]*font-size: ([0-9.]+)pt/', $html, $code);

    expect($name)->toHaveCount(2)->and($code)->toHaveCount(2);
    expect((float) ($code[1] ?? 0))->toBeLessThan((float) ($name[1] ?? 0));
})->group('RF-QR-04');

it('reserva al QR la mitad exacta del ancho de la tarjeta', function (): void {
    // La mitad por el ancho y no por el alto: la tarjeta es apaisada, asi que
    // media a lo ancho son 42,8 mm y media a lo alto 27. Partirla por el alto
    // daria un simbolo con poco mas de la mitad de area.
    expect(BrowsershotCardRenderer::qrZoneMm())->toBe(CardFormat::CARD_WIDTH_MM / 2)
        ->and(BrowsershotCardRenderer::qrZoneMm())->toBeGreaterThan(CardFormat::CARD_HEIGHT_MM / 2);

    expect(cardHtml())->toContain('flex: 0 0 42.8mm');
})->group('RF-QR-04', 'RF-QR-05');

it('deja al simbolo el mayor lado que cabe con cuatro modulos de zona tranquila', function (): void {
    // ISO/IEC 18004 pide 4 modulos de blanco por lado. El payload son 47
    // caracteres con nivel Q, o sea 33 x 33 modulos, asi que 4 modulos son
    // 4 x lado / 33. Lo que sobra de la mitad tiene que dar para eso.
    $side = BrowsershotCardRenderer::qrSideMm();
    $quietZone = (BrowsershotCardRenderer::qrZoneMm() - $side) / 2;

    expect($side)->toBe(34.4)
        ->and($quietZone)->toBeGreaterThanOrEqual(4 * $side / 33);

    // Y a lo alto tambien: la banda blanca es la tarjeta menos el filete.
    expect((CardFormat::CARD_HEIGHT_MM - 2.5 - $side) / 2)->toBeGreaterThanOrEqual(4 * $side / 33);
})->group('RF-QR-05');

it('imprime un simbolo mas grande que el minimo garantizado por la configuracion', function (): void {
    // RF-QR-05 declara un «tamano minimo garantizado» y eso es `qr_size_mm`. Lo
    // que se imprime es todo lo que cabe en media tarjeta, que es bastante mas:
    // esta prueba es la que sostiene que el minimo se cumple de verdad y no de
    // palabra.
    $minimum = config()->float('identity.credentials.card.qr_size_mm');

    expect(BrowsershotCardRenderer::qrSideMm())->toBeGreaterThanOrEqual($minimum);
})->group('RF-QR-05');

it('hace crecer la franja del QR si la instalacion sube el minimo', function (): void {
    // Un minimo por encima de la mitad de la tarjeta se honra quitandole sitio
    // al TEXTO, nunca recortando el simbolo ni su zona tranquila: el texto cede
    // antes que el QR.
    config()->set('identity.credentials.card.qr_size_mm', 40.0);

    $side = BrowsershotCardRenderer::qrSideMm();
    $zone = BrowsershotCardRenderer::qrZoneMm();

    expect($side)->toBe(40.0)
        ->and($zone)->toBeGreaterThan(CardFormat::CARD_WIDTH_MM / 2)
        ->and(($zone - $side) / 2)->toBeGreaterThanOrEqual(4 * $side / 33);
})->group('RF-QR-05');

it('no admite un minimo que no cabe a lo alto de la tarjeta', function (): void {
    // Entre incumplir un minimo mal configurado y sacar un QR recortado, se
    // incumple el minimo: un simbolo cortado no lo lee ningun lector.
    config()->set('identity.credentials.card.qr_size_mm', 90.0);

    $side = BrowsershotCardRenderer::qrSideMm();

    expect($side)->toBeLessThan(CardFormat::CARD_HEIGHT_MM)
        ->and((CardFormat::CARD_HEIGHT_MM - 2.5 - $side) / 2)->toBeGreaterThanOrEqual(4 * $side / 33);
})->group('RF-QR-05');

it('avisa en el log cuando el minimo configurado no cabe en la tarjeta', function (): void {
    // El tope de la prueba anterior es correcto —un QR recortado no lo lee
    // nadie— pero aplicarlo en silencio no lo es: quien escribe `QR_SIZE_MM=45`
    // recibe 41,4 mm y no se entera hasta que mide una tarjeta impresa con una
    // regla. Se afirma sobre los dos numeros porque un aviso sin ellos obliga a
    // reproducir la instalacion para saber que paso.
    //
    // Y sobre una hoja de DIEZ tarjetas con UNA sola linea: el aviso es por
    // documento. Un aviso por tarjeta convertiria una impresion de plantilla en
    // cientos de lineas identicas y el log dejaria de leerse.
    config()->set('identity.credentials.card.qr_size_mm', 45.0);

    $log = Log::spy();

    cardHtml(CardFormat::SHEET, CardFormat::CARDS_PER_SHEET);

    /** @var VerificationDirector $warning */
    $warning = $log->shouldHaveReceived('warning');

    $warning->once()->withArgs(
        /** @param  array<string, float>  $context */
        function (string $message, array $context): bool {
            expect($message)->toContain('does not fit')
                ->and($context)->toBe(['configured_mm' => 45.0, 'effective_mm' => 41.4]);

            return true;
        },
    );
})->group('RF-QR-05');

it('no avisa cuando el minimo configurado cabe de sobra', function (): void {
    // El de serie son 26 mm y se imprimen 34,4: la instalacion normal no puede
    // ensuciar el log con un aviso que nadie tiene que atender. Sin esta prueba,
    // el arreglo del aviso se convierte en ruido en todas las instalaciones.
    $log = Log::spy();

    cardHtml();

    $log->shouldNotHaveReceived('warning');
})->group('RF-QR-05');

it('no rodea el simbolo con un padding que se lo comeria', function (): void {
    // `* { box-sizing: border-box }` hace que un `padding` sobre la imagen reste
    // del lado en vez de sumarse: asi es como un QR declarado de 26 mm acababa
    // imprimiendose a 23. El blanco de alrededor lo pone el ancho sobrante de la
    // franja, que es espacio real de la tarjeta.
    preg_match('/\.card__qr img \{([^}]*)\}/', cardHtml(), $rule);

    expect($rule)->toHaveCount(2);
    expect($rule[1] ?? '')->toContain('width: 34.4mm')
        ->and($rule[1] ?? '')->not->toContain('padding')
        ->and($rule[1] ?? '')->not->toContain('border');
})->group('RF-QR-05');

it('no deja que la marca desplace ni reduzca el QR', function (): void {
    // Tarea 5.8: la marca es configuracion y no puede comerse el simbolo. El
    // logotipo vive en la mitad del texto y su altura esta acotada, asi que un
    // logotipo enorme recorta el texto y jamas el QR.
    $sinMarca = cardHtml();

    // Un PNG de 1x1 de verdad, escrito en un temporal: `logoDataUri()` exige que
    // la ruta sea un fichero existente y lo incrusta en base64.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );

    expect($png)->toBeString();

    $logo = sys_get_temp_dir().'/kronoqr-logo-'.bin2hex(random_bytes(6)).'.png';
    file_put_contents($logo, (string) $png);

    config()->set('branding.name', 'Cadena Hotelera De Nombre Larguisimo');
    config()->set('branding.logo_path', $logo);
    config()->set('branding.accent_color', '#0f172a');

    $conMarca = cardHtml();

    unlink($logo);

    expect($conMarca)->toContain('data:image/png;base64,')
        ->and($conMarca)->toContain('flex: 0 0 42.8mm')
        ->and($conMarca)->toContain('width: 34.4mm');

    // Y la geometria del simbolo es la misma con marca y sin ella.
    foreach (['flex: 0 0 42.8mm', 'width: 34.4mm'] as $medida) {
        expect(substr_count($conMarca, $medida))->toBe(substr_count($sinMarca, $medida), $medida);
    }
})->group('RF-QR-04', 'RF-PD-08');

it('no imprime nada del payload en el texto de la tarjeta', function (): void {
    // Regla dura 10 y doc 02 §5.2: el token solo existe dentro del SVG. Si
    // apareciera como texto, una fotocopia de la tarjeta lo llevaria en claro.
    $html = cardHtml();

    expect($html)->not->toContain(CARD_LAYOUT_PAYLOAD)
        ->and($html)->not->toContain('7QK2mXpR9vLdN4tZbYcF1w');
})->group('RF-QR-04', 'RS-03');

it('da a la hoja A4 exactamente la misma tarjeta que al formato individual', function (): void {
    // El parcial es el mismo y las medidas tambien: si el lote imprimiera un QR
    // mas pequeno que la tarjeta suelta, el sintoma seria «se leen peor segun
    // como se impriman» y tardaria una temporada en aparecer.
    $sheet = cardHtml(CardFormat::SHEET, CardFormat::CARDS_PER_SHEET);

    expect($sheet)->toContain('flex: 0 0 42.8mm')
        ->and($sheet)->toContain('width: 34.4mm')
        ->and(substr_count($sheet, '<strong class="card__code">'))->toBe(CardFormat::CARDS_PER_SHEET);
})->group('RF-QR-04');

it('mantiene la tarjeta entera dentro de su hueco de la hoja', function (): void {
    // La guia de corte va POR FUERA (`content-box`): con el `border-box` global
    // se comeria 0,4 mm del hueco y la tarjeta del lote mediria 85,2 x 53,6.
    $sheet = cardHtml(CardFormat::SHEET, CardFormat::CARDS_PER_SHEET);

    expect($sheet)->toContain('box-sizing: content-box')
        ->and($sheet)->toContain('width: 85.6mm')
        ->and($sheet)->toContain('height: 54mm')
        ->and($sheet)->toContain('page-break-inside: avoid');
})->group('RF-QR-04');

it('cabe en un A4 con el margen de impresora, sin cortar tarjetas entre paginas', function (): void {
    // 2 columnas x 5 filas con la guia por fuera: (85,6 + 0,4) x 2 = 172 mm y
    // (54 + 0,4) x 5 = 272 mm, dentro de los 194 x 281 mm utiles de un A4 con
    // los 8 mm de margen que fija el renderizador. La cuenta se escribe aqui
    // porque es la que deja de cumplirse el dia que alguien pruebe con tres
    // columnas.
    $guide = 0.4;
    $columns = 2;
    $rows = CardFormat::CARDS_PER_SHEET / $columns;

    expect($columns * (CardFormat::CARD_WIDTH_MM + $guide))->toBeLessThanOrEqual(210 - 16)
        ->and($rows * (CardFormat::CARD_HEIGHT_MM + $guide))->toBeLessThanOrEqual(297 - 16);

    // Y una tarjeta de mas abre pagina nueva en vez de partirse.
    $sheet = cardHtml(CardFormat::SHEET, CardFormat::CARDS_PER_SHEET + 1);

    expect(substr_count($sheet, '<div class="sheet">'))->toBe(2);
})->group('RF-QR-04');
