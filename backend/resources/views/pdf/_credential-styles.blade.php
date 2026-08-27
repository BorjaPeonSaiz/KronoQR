{{--
    Estilos comunes de la tarjeta impresa (RF-QR-04, RF-QR-05).

    UNA SOLA DEFINICION PARA LOS DOS FORMATOS. La tarjeta de 85,6 x 54 mm y la
    que va dentro de la hoja A4 tienen que ser LA MISMA: si se escribieran dos
    veces, la del lote acabaria con un QR mas pequeno que la individual y el
    sintoma —tarjetas que se leen peor segun como se imprimieran— tardaria una
    temporada en aparecer.

    TODO EN MILIMETROS. Es un documento para imprimir, no una pagina web: `px`
    depende de la resolucion que elija Chromium y `mm` no. El tamano minimo del
    QR de RF-QR-05 solo significa algo si se expresa en la unidad en la que se
    mide una tarjeta.

    SIN NINGUNA URL. Ni tipografias remotas, ni hojas de estilo externas: el
    producto se instala en servidores sin salida a internet (ADR-016) y una
    fuente de Google convertiria la impresion en algo que depende de la red del
    cliente. Se usa la pila de fuentes del sistema.

    LA MARCA LLEGA DE FUERA (regla dura 13, RF-PD-08). El color de acento es una
    variable; el logotipo, si lo hay, viene incrustado. Nada de esto esta escrito
    aqui.
--}}
<style>
    @page {
        margin: 0;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .card {
        position: relative;
        width: {{ $widthMm }}mm;
        height: {{ $heightMm }}mm;
        padding: 4mm;
        background: #ffffff;
        color: #111827;
        overflow: hidden;
        display: flex;
        flex-direction: row;
        align-items: stretch;
        justify-content: space-between;
    }

    /* Filete superior con el color del cliente. Es lo unico decorativo de la
       tarjeta y lo primero que un hotel quiere cambiar. */
    .card__accent {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2.5mm;
        background: {{ $brand['accent'] }};
    }

    .card__identity {
        display: flex;
        flex-direction: column;
        justify-content: center;
        /* El QR manda: lo que sobra es para el texto, y el texto se recorta
           antes de que el QR se encoja por debajo del minimo de RF-QR-05. */
        width: calc({{ $widthMm }}mm - {{ $qrSizeMm }}mm - 12mm);
        padding-top: 2.5mm;
    }

    .card__brand {
        font-size: 6pt;
        letter-spacing: 0.4pt;
        text-transform: uppercase;
        color: {{ $brand['accent'] }};
        margin-bottom: 1mm;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card__logo {
        max-height: 6mm;
        max-width: 28mm;
        margin-bottom: 1.5mm;
    }

    /* El nombre es lo que se lee de un vistazo cuando alguien busca su tarjeta
       en una caja con cuarenta. Se permite que ocupe dos lineas y se corta a
       partir de ahi: un nombre truncado sigue siendo reconocible por su dueno,
       un QR encogido no lo lee nadie. */
    .card__name {
        font-size: 12pt;
        font-weight: 700;
        line-height: 1.15;
        margin: 0 0 1.5mm 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .card__department,
    .card__site {
        font-size: 7.5pt;
        line-height: 1.25;
        margin: 0;
        color: #374151;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card__site {
        color: #6b7280;
    }

    .card__qr {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding-top: 2.5mm;
    }

    /* La zona tranquila del estandar, en milimetros de la tarjeta y no en
       modulos: el hueco reservado es fijo y el numero de modulos no. */
    .card__qr img {
        width: {{ $qrSizeMm }}mm;
        height: {{ $qrSizeMm }}mm;
        display: block;
        padding: 1.5mm;
        background: #ffffff;
    }

    /* El codigo de empleado impreso debajo del QR. NO ES EL TOKEN y no sirve
       para fichar: es opaco y aleatorio (doc 01 §5.5), y esta ahi para que RRHH
       pueda emparejar una tarjeta suelta con su dueno sin escanearla. */
    .card__code {
        font-family: "Courier New", Courier, monospace;
        font-size: 6pt;
        letter-spacing: 0.6pt;
        color: #6b7280;
        margin-top: 0.8mm;
    }
</style>
