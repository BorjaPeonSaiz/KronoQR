{{--
    Informe de horas por periodo, cuerpo del PDF (RF-IN-04, tarea 2.9).

    El SELLO NO ESTA AQUI. Fecha, emisor, periodo y huella los compone
    `pdf.period-report-footer` y los repite el motor en cada pagina: un pie
    escrito en este flujo saldria una sola vez, al final, y una hoja suelta
    fotocopiada del monton no diria de que informe es.

    SIN NINGUNA REFERENCIA A LA RED. Ni tipografia remota, ni hoja de estilos
    externa, ni imagen por URL: el producto se instala en servidores sin salida a
    internet (ADR-016), y una referencia externa convertiria la impresion de un
    informe en algo que depende de la red del cliente.

    EL TITULO DEL DOCUMENTO NO LLEVA NOMBRES (regla dura 21). Va al metadato del
    PDF y de ahi al historial de descargas del navegador.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            size: A4 landscape;
        }

        body {
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 7pt;
            color: #1a1a1a;
            margin: 0;
        }

        h1 {
            font-size: 13pt;
            margin: 0 0 6pt;
        }

        h2 {
            font-size: 9pt;
            margin: 10pt 0 4pt;
        }

        /* Los metadatos y los criterios se quedan en la primera pagina; la tabla
           sigue detras y se parte por filas, nunca por dentro de una fila. */
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
            padding: 1.5pt 2pt;
            text-align: left;
            /* Las horas se leen en columna: sin esto, `-12:30` y `07:45` no se
               alinean y comparar dos filas obliga a contar caracteres. */
            white-space: nowrap;
        }

        table.data th {
            background: #eeeeee;
            font-weight: bold;
        }

        /* La cabecera se repite en cada pagina de la tabla. Es el equivalente
           impreso de congelar la fila en la hoja de calculo. */
        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            page-break-inside: avoid;
        }

        .empty {
            margin-top: 8pt;
            font-style: italic;
        }
    </style>
</head>
<body>
<h1>{{ $title }}</h1>

<table class="meta">
    @foreach ($metadata as [$label, $value])
        <tr>
            <td class="label">{{ $label }}</td>
            <td class="{{ $loop->last ? 'digest' : '' }}">{{ $value }}</td>
        </tr>
    @endforeach
</table>

<h2>{{ $criteriaLabel }}</h2>
<ul class="criteria">
    @foreach ($criteria as $criterion)
        <li>{{ $criterion }}</li>
    @endforeach
</ul>

@if ($rows === [])
    {{-- Un informe vacio se entrega igual, y con sus criterios: «no hay nadie
         con horas en ese periodo» tambien es una afirmacion. --}}
    <p class="empty">{{ $emptyLabel }}</p>
@else
    <table class="data">
        <thead>
        <tr>
            @foreach ($header as $column)
                <th>{{ $column }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                @foreach ($row as $cell)
                    <td>{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
