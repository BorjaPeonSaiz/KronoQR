{{--
    Cuadro de impacto y adopcion, cuerpo del PDF (RF-IN-08, tarea 3.13).

    VISTA PROPIA Y NO LA DEL INFORME POR PERIODO, y la razon es una sola: este
    documento lleva DOS TABLAS -los doce indicadores y el reparto de fichajes por
    origen- y aquella solo sabe pintar una. Añadir un bloque opcional alli habria
    metido en la vista del informe de horas un `@if` que solo usa este cuadro, y
    con el la posibilidad de mover sin querer la tabla que un hotel ya tiene
    archivada. El pie SI es compartido: `pdf.period-report-footer` sirve a los
    dos porque el sello -fecha, emisor, periodo y huella- es identico, y esa es
    exactamente la parte que no puede divergir entre dos documentos del producto.

    A4 EN VERTICAL, al contrario que el informe de horas. Aquel tiene veintiuna
    columnas y necesita el apaisado; aqui son seis, y en vertical caben las dos
    tablas en una pagina, que es lo que hace que este papel se lea de un vistazo
    en una reunion.

    SIN NINGUNA REFERENCIA A LA RED. Ni tipografia remota, ni hoja de estilos
    externa, ni imagen por URL: el producto se instala en servidores sin salida a
    internet (ADR-016), y una referencia externa convertiria la impresion de este
    cuadro en algo que depende de la red del cliente.

    EL TITULO NO LLEVA NOMBRES (regla dura 21) y aqui es facil: el cuadro entero
    es un agregado de la instalacion y no hay ni un nombre que ocultar.

    LA MARCA ES CONFIGURACION (RF-PD-08, regla dura 13). Nombre, color y logotipo
    llegan desde fuera, y el logotipo YA INCRUSTADO en base64. Todo puede faltar
    menos el nombre, que el catalogo entrega de serie.

    EL ESTADO NO SE PINTA CON COLOR COMO UNICO CANAL (doc 06, accesibilidad AA):
    «Dentro del objetivo» / «Fuera del objetivo» va escrito en su columna. Este
    PDF se imprime en blanco y negro y se fotocopia.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            size: A4 portrait;
        }

        body {
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 8pt;
            color: #1a1a1a;
            margin: 0;
        }

        h1 {
            font-size: 14pt;
            margin: 0 0 6pt;
        }

        /* La cabecera de marca va SOBRE el titulo y no compite con el: quien coge
           esta hoja del monton necesita saber primero que documento es y despues
           de quien. */
        .brand {
            margin: 0 0 8pt;
        }

        .brand img {
            max-height: 12mm;
            max-width: 60mm;
        }

        .brand__name {
            font-size: 10pt;
            font-weight: bold;
        }

        h2 {
            font-size: 10pt;
            margin: 10pt 0 4pt;
        }

        .meta td {
            padding: 1pt 8pt 1pt 0;
            vertical-align: top;
        }

        .meta .label {
            font-weight: bold;
            white-space: nowrap;
        }

        .digest {
            font-family: "DejaVu Sans Mono", monospace;
            word-break: break-all;
        }

        ul.criteria {
            margin: 0;
            padding-left: 12pt;
        }

        ul.criteria li {
            margin-bottom: 2pt;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4pt;
        }

        table.data th,
        table.data td {
            border: 0.4pt solid #999;
            padding: 2pt 3pt;
            text-align: left;
        }

        /* El rotulo del indicador SI se parte en varias lineas -«Tiempo medio
           hasta resolver un turno sin cerrar» no cabe en una-, y las cifras NO:
           sin `nowrap`, `+2,30 pp` se rompe por el espacio y deja de alinearse
           con la fila de arriba. */
        table.data td.label {
            white-space: normal;
        }

        table.data td.figure,
        table.data th {
            white-space: nowrap;
        }

        table.data th {
            background: #eeeeee;
            font-weight: bold;
        }

        /* La cabecera se repite en cada pagina. Es el equivalente impreso de
           congelar la fila en la hoja de calculo. */
        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            page-break-inside: avoid;
        }

        /* La segunda tabla no puede quedarse con su titulo en una pagina y su
           contenido en la siguiente. */
        .breakdown {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
<div class="brand">
    @if ($brandLogo !== null)
        {{-- Con logotipo, el nombre NO se repite al lado: seria la misma
             informacion dos veces. Va en el `alt`, que es donde sirve. --}}
        <img src="{{ $brandLogo }}" alt="{{ $brandName }}">
    @else
        <div class="brand__name" style="color: {{ $brandAccent }}">{{ $brandName }}</div>
    @endif
</div>

<h1>{{ $title }}</h1>

<table class="meta">
    @foreach ($metadata as [$label, $value])
        <tr>
            <td class="label">{{ $label }}</td>
            <td class="{{ $loop->last ? 'digest' : '' }}">{{ $value }}</td>
        </tr>
    @endforeach
</table>

<table class="data">
    <thead>
    <tr>
        @foreach ($header as $column)
            <th>{{ $column }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    {{-- Los doce salen siempre, tambien los que no tienen valor: uno que
         desapareciera de la tabla se leeria como un dato perdido, y lo que pasa
         de verdad es que no habia con que calcularlo. La celda lo dice con el
         guion de «sin dato». --}}
    @foreach ($rows as $row)
        <tr>
            @foreach ($row as $index => $cell)
                <td class="{{ $index === 0 ? 'label' : 'figure' }}">{{ $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>

<div class="breakdown">
    <h2>{{ $breakdownLabel }}</h2>
    <table class="data">
        <thead>
        <tr>
            @foreach ($originHeader as $column)
                <th>{{ $column }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($originRows as $row)
            <tr>
                @foreach ($row as $index => $cell)
                    <td class="{{ $index === 0 ? 'label' : 'figure' }}">{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<h2>{{ $criteriaLabel }}</h2>
<ul class="criteria">
    @foreach ($criteria as $criterion)
        <li>{{ $criterion }}</li>
    @endforeach
</ul>
</body>
</html>
