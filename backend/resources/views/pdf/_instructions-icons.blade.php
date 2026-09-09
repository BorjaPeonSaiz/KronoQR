{{--
    Los pictogramas de la hoja del empleado (tarea 5.11b, RL-05).

    SVG ESCRITO AQUI, NUNCA UNA IMAGEN NI UNA FUENTE DE ICONOS. El PDF lo dibuja
    un Chromium sin salida a internet (ADR-016), y ademas un icono en un fichero
    aparte acabaria siendo un binario que nadie revisa. Escritos en el documento
    se leen en la revision como se lee el texto.

    DE LINEA Y MONOCROMOS, con `currentColor`: el color lo pone quien los
    incluye —el de acento de la marca de la instalacion (RF-PD-08)—, de modo que
    un hotel con marca propia ve sus pictogramas en su color sin que aqui haya
    ningun valor escrito.

    SIN NINGUNA PALABRA DENTRO, con una excepcion: el reloj `06:58` de la
    pantalla, que son cifras y se leen igual en los dos idiomas. Un pictograma
    con texto habria que traducirlo, y la traduccion vive en
    `lang/*/instructions-sheet.php`, que es un array plano atado a la guia de
    RRHH: no cabe ahi el interior de un dibujo.

    Todos comparten `viewBox="0 0 48 32"` para que ocupen exactamente lo mismo y
    los cinco bloques queden alineados.
--}}
@switch($icon)
    @case('card')
        {{-- La tarjeta acercandose a la camara de la tablet. --}}
        <svg class="icon" viewBox="0 0 48 32" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="1.5" y="1.5" width="21" height="29"/>
            <rect x="4.5" y="5.5" width="15" height="18"/>
            <circle cx="12" cy="27" r="1.4"/>
            <path d="M25.5 16h5"/>
            <path d="M28.5 13.5 31.5 16l-3 2.5"/>
            <rect x="33" y="9" width="13.5" height="14"/>
            <rect x="35.5" y="11.5" width="4" height="4"/>
            <path d="M35.5 18.5h1.5M39 18.5h1.5M42.5 11.5h1.5M42.5 15h1.5M42.5 18.5h1.5"/>
        </svg>
        @break

    @case('screen')
        {{-- La pantalla de la tablet confirmando: una marca de verificacion y la
             hora. Sin la palabra «Entrada»/«Clock-in»: la dice el texto del
             bloque, que si esta traducido. --}}
        <svg class="icon" viewBox="0 0 48 32" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="1.5" y="3.5" width="45" height="25"/>
            <path d="M8 16.5l4.5 4.5L21 12"/>
            <text x="25" y="20.5" font-size="9" font-family="inherit" fill="currentColor"
                  stroke="none">06:58</text>
        </svg>
        @break

    @case('keypad')
        {{-- El teclado del PIN de respaldo: los seis digitos ocultos arriba y las
             teclas debajo. --}}
        <svg class="icon" viewBox="0 0 48 32" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="12" y="1.5" width="24" height="29"/>
            <path d="M16 7h16"/>
            <circle cx="18" cy="7" r="0.9" fill="currentColor" stroke="none"/>
            <circle cx="21" cy="7" r="0.9" fill="currentColor" stroke="none"/>
            <circle cx="24" cy="7" r="0.9" fill="currentColor" stroke="none"/>
            <circle cx="27" cy="7" r="0.9" fill="currentColor" stroke="none"/>
            <circle cx="30" cy="7" r="0.9" fill="currentColor" stroke="none"/>
            <circle cx="33" cy="7" r="0.9" fill="currentColor" stroke="none"/>
            <circle cx="18" cy="14" r="1.8"/>
            <circle cx="24" cy="14" r="1.8"/>
            <circle cx="30" cy="14" r="1.8"/>
            <circle cx="18" cy="20" r="1.8"/>
            <circle cx="24" cy="20" r="1.8"/>
            <circle cx="30" cy="20" r="1.8"/>
            <circle cx="24" cy="26" r="1.8"/>
        </svg>
        @break

    @case('portal')
        {{-- El portal, desde el ordenador o desde el movil. --}}
        <svg class="icon" viewBox="0 0 48 32" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="1.5" y="3.5" width="29" height="19"/>
            <path d="M11 30h10M16 22.5V30"/>
            <path d="M5.5 9h13M5.5 13h9M5.5 17h11"/>
            <rect x="34.5" y="8.5" width="12" height="21"/>
            <path d="M38 12h5M38 16h5M38 20h3"/>
            <circle cx="40.5" cy="26.5" r="1.1" fill="currentColor" stroke="none"/>
        </svg>
        @break

    @case('correction')
        {{-- Avisar de un dato que no cuadra: la jornada y quien la corrige. --}}
        <svg class="icon" viewBox="0 0 48 32" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9.5 1.5h19l7 7v22h-26z"/>
            <path d="M28.5 1.5v7h7"/>
            <path d="M13.5 12h12M13.5 17h8"/>
            <path d="M31 27.5l9.5-9.5 3 3-9.5 9.5-4 1z"/>
        </svg>
        @break
@endswitch
