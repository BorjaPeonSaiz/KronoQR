{{--
    El SELLO del informe por periodo (RF-IN-04, tarea 2.9).

    Lo repite el motor en CADA pagina. No es el pie del cuerpo del documento: un
    pie escrito en el flujo saldria una sola vez y una hoja suelta no diria de
    que informe es ni quien lo emitio.

    TODO EL ESTILO VA EN LINEA, y no es descuido: Chromium compone la plantilla
    del pie en un contexto aparte que IGNORA las hojas de estilo del documento.
    Sin `font-size` en linea, el pie se imprime a tamano cero — es decir, no se
    imprime, y nadie se entera hasta que mira un papel.

    Las clases `pageNumber` y `totalPages` las rellena el propio motor.

    SIN NOMBRES DE EMPLEADO. El unico nombre de persona que aparece es el de la
    cuenta que emitio el informe, que es de quien responde por el.
--}}
<div style="width:100%; font-family:Arial,Helvetica,sans-serif; font-size:6pt; color:#444; padding:0 10mm; line-height:1.35;">
    <div>
        {{ $generatedAt }} ({{ $timeZone }})
        &nbsp;·&nbsp; {{ $issuerLabel }}: {{ $issuer }}
        &nbsp;·&nbsp; {{ $periodLabel }}: {{ $period }}
        &nbsp;·&nbsp; <span class="pageNumber"></span>/<span class="totalPages"></span>
    </div>
    <div style="font-family:'Courier New',monospace; word-break:break-all;">
        {{ $digestLabel }}: {{ $digest }}
    </div>
</div>
