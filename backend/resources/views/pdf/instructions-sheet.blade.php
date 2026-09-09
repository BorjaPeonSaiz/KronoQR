{{--
    LA HOJA QUE SE ENTREGA CON LA TARJETA (tarea 5.11b, RL-05, ficha 5.11b
    decision 1).

    UNA SOLA CARA A4. Se entrega en mano junto con la tarjeta y el PIN, en un
    unico acto (tareas 1.10 y 1.13): una segunda cara es una cara que la mitad de
    la gente no lee. El tamano de papel y los margenes los fija
    `BrowsershotInstructionsSheetRenderer`; que quepa lo comprueba una prueba de
    integracion que cuenta las paginas del PDF de verdad, en los dos idiomas.

    SE LEE DE PIE, delante de la tablet o con la tarjeta recien entregada en la
    mano: titulos grandes, cinco bloques numerados con su pictograma, y la
    direccion del portal en cuerpo grande porque es lo unico que hay que teclear.

    NINGUNA PALABRA ESTA ESCRITA AQUI. Todas llegan de
    `lang/<idioma>/instructions-sheet.php` con los marcadores ya sustituidos, y
    esas mismas frases las reproduce `docs/cliente/hoja-empleado.md` para que RRHH
    sepa que entrega sin abrir el PDF. Cambiar una frase aqui seria escribirla en
    dos sitios y que uno de los dos se quedara viejo.

    SIN NINGUNA REFERENCIA A LA RED (ADR-016): pictogramas SVG en el documento,
    logotipo en base64, tipografia del sistema. La direccion del portal aparece
    como TEXTO IMPRESO, no como recurso que haya que ir a buscar.

    NINGUN DATO PERSONAL. Es la misma hoja para toda la plantilla, y por eso el
    unico hueco con el nombre de alguien --la persona de contacto-- es una linea
    en blanco que RRHH rellena a mano.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    {{-- El titulo va al metadato del PDF y de ahi al historial de descargas.
         Sin nombres, como en las tarjetas (regla dura 21). --}}
    <title>{{ $texts['title'] }}</title>
    <style>
        @page {
            size: A4 portrait;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            /* La misma pila que las tarjetas: del sistema, nunca remota
               (ADR-016). */
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 10.5pt;
            line-height: 1.35;
            color: #111827;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        p {
            margin: 0;
        }

        /* --- Cabecera de marca (RF-PD-08) ------------------------------- */

        .brand {
            display: flex;
            align-items: center;
            gap: 4mm;
            padding-bottom: 2.5mm;
            border-bottom: 0.8mm solid {{ $brand['accent'] }};
        }

        /* Alto acotado y ancho automatico: un logotipo muy apaisado no puede
           empujar el contenido a una segunda pagina. */
        .brand__logo {
            max-height: 11mm;
            max-width: 45mm;
        }

        .brand__name {
            font-size: 9pt;
            letter-spacing: 0.6pt;
            text-transform: uppercase;
            color: {{ $brand['accent'] }};
        }

        h1 {
            font-size: 19pt;
            line-height: 1.15;
            margin: 6mm 0 2.5mm;
        }

        .intro {
            font-size: 11pt;
            color: #374151;
            margin-bottom: 5mm;
        }

        /* --- Los cinco bloques ------------------------------------------ */

        .block {
            display: flex;
            align-items: flex-start;
            gap: 5mm;
            margin-bottom: 4.5mm;
        }

        .block__icon {
            flex: 0 0 18mm;
            /* Los pictogramas heredan el color de acento por `currentColor`. */
            color: {{ $brand['accent'] }};
        }

        .icon {
            width: 18mm;
            height: 12mm;
            display: block;
        }

        .block__body {
            flex: 1 1 auto;
            min-width: 0;
        }

        h2 {
            font-size: 12.5pt;
            margin: 0 0 1.2mm;
            color: {{ $brand['accent'] }};
        }

        .list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .list li {
            margin-bottom: 1.2mm;
            padding-left: 4mm;
            position: relative;
        }

        .list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 1.6mm;
            width: 1.6mm;
            height: 1.6mm;
            background: {{ $brand['accent'] }};
        }

        /* La direccion del portal es lo unico que hay que teclear, asi que es lo
           mas grande de la hoja despues del titulo. Monoespaciada para que no se
           confundan un uno y una ele, y partible para que una direccion larga no
           se salga del papel. */
        .portal-url {
            font-family: "Courier New", Courier, monospace;
            font-size: 14pt;
            font-weight: 700;
            margin: 2mm 0;
            word-break: break-all;
        }

        /* --- Contacto y pie --------------------------------------------- */

        .contact {
            margin-top: 5mm;
            padding: 3.5mm 4mm 4mm;
            border: 0.3mm solid #9ca3af;
        }

        .contact__title {
            font-size: 11pt;
            font-weight: 700;
            margin-bottom: 1.5mm;
        }

        /* La linea en blanco para escribir a mano. Existe porque el producto no
           tiene ningun ajuste con el nombre de la persona de contacto, y porque
           en un hotel esa persona cambia de turno (ficha 5.11b, decision 1). */
        .contact__line {
            display: flex;
            align-items: flex-end;
            gap: 3mm;
        }

        .contact__rule {
            flex: 1 1 auto;
            border-bottom: 0.3mm solid #4b5563;
            height: 6mm;
        }

        .footer {
            margin-top: 5mm;
            padding-top: 2mm;
            border-top: 0.3mm solid #d1d5db;
            font-size: 8.5pt;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="brand">
    @if ($brand['logo'] !== null)
        <img class="brand__logo" src="{{ $brand['logo'] }}" alt="">
    @endif
    <span class="brand__name">{{ $brand['name'] }}</span>
</div>

<h1>{{ $texts['title'] }}</h1>
<p class="intro">{{ $texts['intro'] }}</p>

<div class="block">
    <div class="block__icon">@include('pdf._instructions-icons', ['icon' => 'card'])</div>
    <div class="block__body">
        <h2>{{ $texts['scan_title'] }}</h2>
        <p>{{ $texts['scan_body'] }}</p>
    </div>
</div>

<div class="block">
    <div class="block__icon">@include('pdf._instructions-icons', ['icon' => 'screen'])</div>
    <div class="block__body">
        <h2>{{ $texts['results_title'] }}</h2>
        <ul class="list">
            <li>{{ $texts['result_ok'] }}</li>
            <li>{{ $texts['result_pending'] }}</li>
            <li>{{ $texts['result_debounced'] }}</li>
            <li>{{ $texts['result_rejected'] }}</li>
        </ul>
    </div>
</div>

<div class="block">
    <div class="block__icon">@include('pdf._instructions-icons', ['icon' => 'keypad'])</div>
    <div class="block__body">
        <h2>{{ $texts['no_card_title'] }}</h2>
        <p>{{ $texts['no_card_body'] }}</p>
    </div>
</div>

<div class="block">
    <div class="block__icon">@include('pdf._instructions-icons', ['icon' => 'portal'])</div>
    <div class="block__body">
        <h2>{{ $texts['portal_title'] }}</h2>
        <p>{{ $texts['portal_body'] }}</p>
        {{-- La direccion se imprime TAL CUAL y nunca como enlace: en papel no se
             pulsa, y un `<a>` invitaria a que alguien sirviera algun dia el mismo
             documento desde una pantalla. --}}
        <p class="portal-url">{{ $portalUrl }}</p>
        <p>{{ $texts['portal_credentials'] }}</p>
        <p>{{ $texts['portal_pin_reset'] }}</p>
    </div>
</div>

<div class="block">
    <div class="block__icon">@include('pdf._instructions-icons', ['icon' => 'correction'])</div>
    <div class="block__body">
        <h2>{{ $texts['problems_title'] }}</h2>
        <p>{{ $texts['problems_body'] }}</p>
    </div>
</div>

<div class="contact">
    <p class="contact__title">{{ $texts['contact_title'] }}</p>
    <div class="contact__line">
        <span>{{ $texts['contact_line'] }}</span>
        <span class="contact__rule"></span>
    </div>
</div>

<p class="footer">{{ $texts['footer'] }}</p>
</body>
</html>
