{{--
    Formato HOJA A4 con varias tarjetas por pagina (RF-QR-04).

    POR QUE EXISTE (doc 02 §5.5): «La hoja A4 con varias tarjetas por pagina es
    lo que hace viable dar de alta a 40 personas de temporada en una tarde.»

    UN SOLO DOCUMENTO CON N TARJETAS, no N documentos. Es una sola invocacion de
    Chromium para los 60 empleados de la semilla y una sola respuesta que se
    imprime de una tirada.

    DIEZ POR HOJA: 2 columnas x 5 filas. Con la guia de corte, cada hueco mide
    86 x 54,4 mm (85,6 x 54 de tarjeta mas los 0,2 mm de guia por lado), asi que
    dos columnas son 172 mm y cinco filas 272 mm; con los 8 mm de margen de
    impresora que fija el renderizador, el area util del A4 es 194 x 281 mm y
    entra con holgura. Apurar a tres columnas obligaria a recortar el margen de
    seguridad del QR, que es exactamente lo que RF-QR-05 protege.

    LAS GUIAS DE CORTE SON UN BORDE PUNTEADO Y NO UNA LINEA CONTINUA: quien corta
    con guillotina necesita ver donde, y una linea continua queda impresa en el
    canto de la tarjeta si el corte se desvia medio milimetro.

    Y LA GUIA VA POR FUERA DE LA TARJETA (`box-sizing: content-box`), que es la
    unica linea de este fichero que hay que leer dos veces. Con el `border-box`
    global, el borde se come 0,4 mm del hueco y la tarjeta impresa en la hoja
    mediria 85,2 x 53,6 mm: no seria la misma que la del formato individual —que
    es justo lo que este parcial compartido existe para evitar— y el QR quedaria
    con menos mitad de la que se le reserva.

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
            /* Las filas se apilan desde arriba. Sin esto, un flex que envuelve
               repartiria el hueco sobrante entre las filas y las tarjetas
               dejarian de estar donde dice la guia. */
            align-content: flex-start;
            page-break-after: always;
        }

        .sheet:last-child {
            page-break-after: auto;
        }

        .slot {
            /* La guia por fuera: dentro tiene que caber la tarjeta ENTERA, con
               sus 85,6 x 54 mm exactos. Ver la cabecera de este fichero. */
            box-sizing: content-box;
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
