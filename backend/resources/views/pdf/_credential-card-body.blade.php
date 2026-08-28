{{--
    El contenido de UNA tarjeta (RF-QR-04): nombre, codigo, departamento, centro
    y QR.

    Se incluye desde los dos formatos —la tarjeta suelta y cada hueco de la hoja
    A4— para que las dos sean identicas. Ver `_credential-styles.blade.php`.

    EL ORDEN DE LECTURA ES EL ORDEN DEL DOCUMENTO: nombre, codigo, departamento
    y centro. Quien busca su tarjeta en una caja lee el nombre; quien empareja
    una tarjeta suelta con su dueno lee el codigo, que por eso va inmediatamente
    debajo y en negrita.

    Variables: $card (name, department, site, employeeCode, qr) y $brand.
--}}
<div class="card">
    <div class="card__accent"></div>

    <div class="card__identity">
        @if ($brand['logo'] !== null)
            <img class="card__logo" src="{{ $brand['logo'] }}" alt="">
        @elseif (! empty($brand['name']))
            {{-- Sin logotipo se imprime el nombre de la instalacion. Si tampoco
                 lo hay, el centro ya aparece abajo y la tarjeta no queda coja. --}}
            <div class="card__brand">{{ $brand['name'] }}</div>
        @endif

        <p class="card__name">{{ $card['name'] }}</p>

        {{-- El codigo de empleado, NO el token: es opaco, no sirve para fichar
             y se puede leer en voz alta sin comprometer nada (doc 01 §5.5). --}}
        <strong class="card__code">{{ $card['employeeCode'] }}</strong>

        @if ($card['department'] !== null)
            <p class="card__department">{{ $card['department'] }}</p>
        @endif

        <p class="card__site">{{ $card['site'] }}</p>
    </div>

    <div class="card__qr">
        {{-- El `alt` esta vacio a proposito: describir el QR seria escribir en
             el PDF una pista sobre su contenido, y su contenido es un secreto. --}}
        <img src="{{ $card['qr'] }}" alt="">
    </div>
</div>
