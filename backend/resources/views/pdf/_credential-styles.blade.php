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

    LA TARJETA SON DOS MITADES. La derecha —`$qrZoneMm`, la mitad exacta del
    ancho— es del QR y de nadie mas; la izquierda es el texto. El reparto lo
    calcula {@see BrowsershotCardRenderer} y llega hecho: aqui no hay ninguna
    medida inventada. Se parte por el ancho y no por el alto porque la tarjeta es
    apaisada: media tarjeta a lo ancho son 42,8 mm y media a lo alto 27 mm, asi
    que partirla por el alto daria un QR que cabe en 27 mm —la mitad de area— sin
    ninguna ganancia a cambio.

    LA ZONA TRANQUILA NO ES UN `padding`. `* { box-sizing: border-box }` hace que
    un `padding` sobre la propia imagen SE COMA el simbolo en vez de rodearlo:
    con `width: 26mm; padding: 1.5mm` lo que se imprimia eran 23 mm de QR, tres
    milimetros menos de los que prometia la configuracion, y nadie lo veia porque
    el sintoma es «se lee un poco peor». El hueco blanco alrededor lo pone el
    ancho sobrante de la mitad del QR, que es un espacio real de la tarjeta y no
    depende de como interprete nadie el modelo de caja.

    SIN NINGUNA URL. Ni tipografias remotas, ni hojas de estilo externas: el
    producto se instala en servidores sin salida a internet (ADR-016) y una
    fuente de Google convertiria la impresion en algo que depende de la red del
    cliente. Se usa la pila de fuentes del sistema.

    LA MARCA LLEGA DE FUERA (regla dura 13, RF-PD-08). El color de acento es una
    variable; el logotipo, si lo hay, viene incrustado. Nada de esto esta escrito
    aqui. Y la marca **no desplaza ni encoge el QR** (tarea 5.8): el logotipo
    vive en la mitad del texto y su altura maxima esta acotada, de modo que un
    logotipo grande recorta el texto y jamas el simbolo.
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

    /* Sin `padding` propio: cada mitad pone el suyo. Un `padding` en la tarjeta
       le restaria milimetros a la mitad del QR y esa mitad tiene que medir
       exactamente la mitad. */
    .card {
        position: relative;
        width: 100%;
        height: 100%;
        padding: 0;
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
        height: {{ $accentMm }}mm;
        background: {{ $brand['accent'] }};
    }

    /* La mitad del texto: lo que sobra despues de reservar la del QR. `min-width`
       a cero es lo que permite que el texto se recorte en vez de empujar; sin
       eso, un nombre largo ensancharia esta columna y se llevaria por delante la
       zona tranquila del simbolo. */
    .card__identity {
        flex: 1 1 auto;
        min-width: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        /* Arriba, por debajo del filete de color. */
        padding: calc({{ $accentMm }}mm + 2.5mm) 1.5mm 4mm 4.5mm;
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
       en una caja con cuarenta, y por eso es el elemento mas grande. Se permite
       que ocupe tres lineas —la columna es media tarjeta— y se corta a partir de
       ahi: un nombre truncado sigue siendo reconocible por su dueno, un QR
       encogido no lo lee nadie. */
    .card__name {
        font-size: 12pt;
        font-weight: 700;
        line-height: 1.15;
        margin: 0 0 1.2mm 0;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* El codigo de empleado, en negrita y justo debajo del nombre. NO ES EL
       TOKEN y no sirve para fichar: es opaco y aleatorio (doc 01 §5.5), y esta
       ahi para que RRHH pueda emparejar una tarjeta suelta con su dueno sin
       escanearla, y para que quien entra al portal lo tenga a mano (ADR-015).
       Va en `<strong>` y no en un `<p>` con `font-weight`: la negrita es del
       documento, no de la hoja de estilos, asi que sobrevive a que alguien
       imprima este HTML sin CSS.
       Mas pequeno que el nombre a proposito: identifica, no titula. */
    .card__code {
        display: block;
        font-family: "Courier New", Courier, monospace;
        font-size: 9pt;
        font-weight: 700;
        letter-spacing: 0.5pt;
        line-height: 1.1;
        margin: 0 0 1.5mm 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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

    /* La mitad del QR: ancho fijo y sin encogerse nunca. `flex-shrink: 0` es lo
       que convierte en verdad la frase «el QR manda»: cuando el texto no cabe,
       cede el texto. */
    .card__qr {
        flex: 0 0 {{ $qrZoneMm }}mm;
        width: {{ $qrZoneMm }}mm;
        display: flex;
        align-items: center;
        justify-content: center;
        /* Para centrar el simbolo en la banda blanca, por debajo del filete. */
        padding-top: {{ $accentMm }}mm;
    }

    /* El simbolo, a pelo: ni `padding` ni `border`. Lo que lo rodea es el blanco
       que sobra de la mitad —{{ $qrZoneMm }} mm menos {{ $qrSizeMm }} mm— y es
       zona tranquila de sobra para el estandar (>= 4 modulos). */
    .card__qr img {
        width: {{ $qrSizeMm }}mm;
        height: {{ $qrSizeMm }}mm;
        display: block;
        background: #ffffff;
    }
</style>
