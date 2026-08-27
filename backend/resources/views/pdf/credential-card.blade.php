{{--
    Formato TARJETA DE CREDITO: 85,6 x 54 mm (RF-QR-04).

    La pagina del PDF **es** la tarjeta: el tamano de papel se fija en
    `BrowsershotCardRenderer` con las medidas de `CardFormat`, y aqui no hay
    margen de pagina ninguno. Cualquier margen encogeria la tarjeta y el
    resultado dejaria de caber en una funda estandar.

    Una tarjeta por pagina. Cuando se piden varias en este formato salen paginas
    consecutivas, no una rejilla: para eso esta la hoja A4.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- El titulo va al metadato del PDF y de ahi al historial de descargas del
         navegador. Sin nombres: un PDF de tarjetas es un instrumento al
         portador (regla dura 21). --}}
    <title>Credencial</title>
    @include('pdf._credential-styles')
    <style>
        .page {
            width: {{ $widthMm }}mm;
            height: {{ $heightMm }}mm;
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto;
        }
    </style>
</head>
<body>
@foreach ($cards as $card)
    <div class="page">
        @include('pdf._credential-card-body', ['card' => $card, 'brand' => $brand])
    </div>
@endforeach
</body>
</html>
