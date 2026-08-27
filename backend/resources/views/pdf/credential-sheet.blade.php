{{--
    Formato HOJA A4 con varias tarjetas por pagina (RF-QR-04).

    POR QUE EXISTE (doc 02 §5.5): «La hoja A4 con varias tarjetas por pagina es
    lo que hace viable dar de alta a 40 personas de temporada en una tarde.»

    UN SOLO DOCUMENTO CON N TARJETAS, no N documentos. Es una sola invocacion de
    Chromium para los 60 empleados de la semilla y una sola respuesta que se
    imprime de una tirada.

    DIEZ POR HOJA: 2 columnas x 5 filas. Dos columnas de 85,6 mm son 171,2 mm y
    cinco filas de 54 mm son 270 mm; con los 8 mm de margen de impresora que fija
    el renderizador, entra en un A4 (210 x 297 mm) con holgura. Apurar a tres
    columnas obligaria a recortar el margen de seguridad del QR, que es
    exactamente lo que RF-QR-05 protege.

    LAS GUIAS DE CORTE SON UN BORDE PUNTEADO Y NO UNA LINEA CONTINUA: quien corta
    con guillotina necesita ver donde, y una linea continua queda impresa en el
    canto de la tarjeta si el corte se desvia medio milimetro.

    EL ORDEN LO FIJA QUIEN PIDE EL LOTE —centro, departamento y apellido— y aqui
    se respeta tal cual: quien recorta las tarjetas las reparte en ese orden.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Credenciales</title>
    @include('pdf._credential-styles')
    <style>
        .sheet {
            display: flex;
            flex-wrap: wrap;
            /* Sin hueco entre tarjetas: se cortan por la guia, y un hueco
               desperdicia papel sin facilitar el corte. */
            gap: 0;
            page-break-after: always;
        }

        .sheet:last-child {
            page-break-after: auto;
        }

        .slot {
            width: {{ $widthMm }}mm;
            height: {{ $heightMm }}mm;
            border: 0.2mm dashed #d1d5db;
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
@foreach (array_chunk($cards, $cardsPerSheet) as $page)
    <div class="sheet">
        @foreach ($page as $card)
            <div class="slot">
                @include('pdf._credential-card-body', ['card' => $card, 'brand' => $brand])
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
