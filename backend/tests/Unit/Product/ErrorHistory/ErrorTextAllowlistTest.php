<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorTextAllowlist;
use App\Modules\Product\Domain\ValueObject\ErrorVocabulary;

/*
 * LA LISTA BLANCA POR PALABRA, pasos 4 y 5 de ADR-048 (RF-PD-15, RL-19,
 * reglas duras 16 y 21).
 *
 * Un nombre de persona no tiene forma reconocible, pero tiene la propiedad de
 * no ser una palabra del vocabulario tecnico. Aqui se fija esa propiedad forma
 * por forma, y las reglas de cifras que cubren lo que ninguna lista de palabras
 * puede filtrar.
 */

it('sustituye un nombre en cualquiera de sus formas', function (string $texto, string $esperado): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($esperado);
})->with([
    'capitalizado' => ['Employee Rosa Ficticiana not found', 'Employee … not found'],
    'mayusculas' => ['Employee ROSA FICTICIANA not found', 'Employee … not found'],
    'minusculas' => ['employee rosa ficticiana not found', 'employee … not found'],
    'apellido, nombre' => ['Employee Ficticiana, Rosa not found', 'Employee …, … not found'],
    'compuesto con guion' => ['Employee Ficticiana-Inventadez not found', 'Employee …-… not found'],
    'con tilde y eñe' => ['Employee José Ñúñez not found', 'Employee … not found'],
    'Mc' => ['Employee McInventado not found', 'Employee … not found'],
    'O apostrofo' => ["Employee O'Testerson not found", "Employee O'… not found"],
    'escritura china' => ['Employee 王伟 not found', 'Employee … not found'],
    'escritura cirilica' => ['Employee Олена Шевченко not found', 'Employee … not found'],
    'nombre que coincide con una palabra tecnica frecuente' => ['Employee Max Campos not found', 'Employee … not found'],
    'apellido de dos letras' => ['Employee Li Wang not found', 'Employee … not found'],
])->group('RF-PD-15', 'RL-19');

it('conserva las palabras del vocabulario partiendo por los cambios de caja', function (string $texto): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($texto);
})->with([
    'clase' => ['EmployeeCodeAlreadyTaken'],
    'metodo' => ['getUserMedia'],
    'sigla delante' => ['HTTPError XMLHttpRequest'],
    'sigla detras' => ['IndexedDB'],
    'error del navegador' => ['TypeError: Cannot read properties of undefined'],
    'error de php' => ['Argument #1 must be of type string, null given'],
    'mensaje del producto' => ['Ya existe un empleado con ese codigo.'],
    'palabra entera aunque sus partes no esten' => ['PostgreSQL'],
    'alfanumerico del vocabulario' => ['sha256 base64 utf8mb4'],
])->group('RF-PD-15', 'RL-19');

it('si una sola parte no esta, cae la secuencia entera', function (): void {
    // `getRosa` no puede dejar `get…`: se sabria donde empieza el nombre.
    expect(ErrorTextAllowlist::apply('getFicticiana EmployeeRosa'))->toBe('…');
})->group('RF-PD-15', 'RL-19');

it('conserva una letra latina suelta y no una de otra escritura', function (): void {
    expect(ErrorTextAllowlist::apply('x e Y'))->toBe('x e Y')
        ->and(ErrorTextAllowlist::apply('error é'))->toBe('error é')
        ->and(ErrorTextAllowlist::apply('error 王'))->toBe('error …')
        ->and(ErrorTextAllowlist::apply('iPhone iRosa'))->toBe('iPhone …');
})->group('RF-PD-15', 'RL-19');

it('funde los marcadores seguidos para que no se sepa cuantas palabras eran', function (): void {
    expect(ErrorTextAllowlist::apply('error Rosa  Ficticiana Inventadez found'))->toBe('error … found')
        ->and(ErrorTextAllowlist::apply("error Rosa\tFicticiana"))->toBe('error …')
        // Solo con espacios entre medias: una coma separa dos datos y se ve.
        ->and(ErrorTextAllowlist::apply('Ficticiana, Rosa'))->toBe('…, …');
})->group('RF-PD-15', 'RL-19');

it('convierte en [n] toda serie que sume siete cifras o mas', function (string $texto, string $esperado): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($esperado);
})->with([
    'telefono compacto' => ['phone 612345678', 'phone [n]'],
    'telefono 3-3-3' => ['phone 612 345 678', 'phone [n]'],
    'telefono 2-3-2-2' => ['phone 91 234 56 78', 'phone [n]'],
    'telefono con prefijo' => ['phone +34 612 345 678', 'phone [n]'],
    'tarjeta con guiones' => ['card 4111-1111-1111-1111', 'card [n]'],
    'tarjeta con espacios' => ['card 4111 1111 1111 1111', 'card [n]'],
    'dni sin letra' => ['doc 45678912', 'doc [n]'],
    'siete justas' => ['x 1.2.3.4.5.6.7', 'x [n]'],
    'con barra' => ['x 28/1234/5', 'x [n]'],
])->group('RF-PD-15', 'RL-19');

it('conserva las series cortas', function (string $texto): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($texto);
})->with([
    'seis cifras en grupos' => ['x 1.2.3.4.5.6'],
    'version' => ['version 2.2.0'],
    'estado y recuento' => ['status 500 after 3 of 5'],
])->group('RF-PD-15');

it('convierte en [n] lo que lleva cuatro cifras seguidas', function (string $texto, string $esperado): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($esperado);
})->with([
    'puerto' => ['port 5432', 'port [n]'],
    'codigo heredado alfanumerico' => ['code HTL2019X0042', 'code [n]'],
    'codigo heredado numerico' => ['code 739104', 'code [n]'],
    'año' => ['de 1985', 'de [n]'],
    'cifras delante de letras' => ['x 1234abc', 'x [n]'],
    'un grupo de una serie corta' => ['x 12 3456', 'x 12 [n]'],
    'con signo' => ['x +1234', 'x +[n]'],
])->group('RF-PD-15', 'RL-19');

it('convierte en [n] los alfanumericos con dos cifras o mas (H4)', function (string $texto, string $esperado): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($esperado);
})->with([
    'alternado' => ['code a1b2c3', 'code [n]'],
    'letras cifras letras' => ['code ab12cd', 'code [n]'],
    'mezclado' => ['code x7k2m9', 'code [n]'],
    'en una consulta' => ['?t=x7k2m9', '?t=[n]'],
    'cinco justos' => ['code ab1c2', 'code [n]'],
])->group('RF-PD-15', 'RL-19');

it('no aplica H4 por debajo de sus limites', function (string $texto, string $esperado): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($esperado);
})->with([
    'una cifra' => ['utf8 v13', 'utf8 v13'],
    'cuatro caracteres' => ['x509 a1b2', 'x509 a1b2'],
    'solo cifras' => ['code 123', 'code 123'],
    'una cifra y un nombre' => ['Ficticiana2', '…2'],
])->group('RF-PD-15', 'RL-19');

it('conserva un numero de linea en sus posiciones tecnicas (H3)', function (string $texto): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($texto);
})->with([
    'line' => ['Undefined array key 3 on line 1234'],
    'Line' => ['Error at Line 1234'],
    'linea' => ['error en linea 1234'],
    'línea' => ['error en línea 1234'],
    'php' => ['app/Modules/Product/Domain/Collector.php:1234'],
    'js con columna' => ['app.js:1:123456'],
    'ts' => ['client.ts:4567'],
    'vue' => ['ScanView.vue:1234'],
    'argumento' => ['Argument #1234 must be of type string'],
    'seis cifras' => ['on line 123456'],
])->group('RF-PD-15', 'RL-19');

it('no convierte una posicion tecnica en un escondite (H3)', function (string $texto, string $esperado): void {
    expect(ErrorTextAllowlist::apply($texto))->toBe($esperado);
})->with([
    'telefono detras de line' => ['line 612345678', 'line [n]'],
    'telefono agrupado detras de line' => ['line 612 345 678', 'line [n]'],
    'siete cifras' => ['on line 1234567', 'on line [n]'],
    'dos grupos cortos' => ['line 1234 56', 'line [n] 56'],
    'detras de una almohadilla' => ['#612345678', '#[n]'],
])->group('RF-PD-15', 'RL-19');

it('conserva los marcadores del propio saneado', function (): void {
    $marcadores = "[email] [iban] [id] [code] [phone] [time] [ip] [secret] [redacted] [uuid] [n] '…' …";

    expect(ErrorTextAllowlist::apply($marcadores))->toBe($marcadores);
})->group('RF-PD-15');

it('es idempotente', function (string $texto): void {
    $una = ErrorTextAllowlist::apply($texto);

    expect(ErrorTextAllowlist::apply($una))->toBe($una);
})->with([
    'nombre' => ['Employee Rosa Ficticiana not found'],
    'cifras' => ['line 612 345 678 port 5432 code a1b2c3 tel +34 612 345 678'],
    'marcador pegado a cifra' => ['Ficticiana2 Rosa'],
    'de todo' => ['TypeError: getFicticiana(x7k2m9) at app.js:1:123456, line 1234 de 1985'],
])->group('RF-PD-15', 'RL-19');

it('no conserva ninguna secuencia de letras fuera del vocabulario', function (string $texto): void {
    // La propiedad, comprobada sobre textos mezclados: toda secuencia de letras
    // que queda es una palabra del vocabulario, una letra latina suelta o
    // partes de una que si esta.
    preg_match_all('/\p{L}+/u', ErrorTextAllowlist::apply($texto), $restos);

    foreach ($restos[0] as $resto) {
        $partes = preg_split('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', $resto) ?: [];
        $conocida = ErrorVocabulary::contains($resto)
            || array_filter($partes, static fn (string $p): bool => mb_strlen($p) > 1 && ! ErrorVocabulary::contains($p)) === [];

        expect($conocida)->toBeTrue('Se ha conservado «'.$resto.'».');
    }

    expect(ErrorTextAllowlist::apply($texto))->not->toBe($texto);
})->with([
    'mensaje de cliente' => ['Cannot read Rosa of Ficticiana (reading Inventadez) at LuzMaria.vue:12'],
    'mensaje del servidor' => ['SQLSTATE: duplicate Testerson key Will violates Wang'],
    'mezcla de cajas' => ['ROSA rosa Rosa rOSA RoSa'],
])->group('RF-PD-15', 'RL-19');

it('falla cerrado ante UTF-8 invalido', function (): void {
    expect(ErrorTextAllowlist::apply("Rosa Ficticiana \xC3\x28"))->toBe('');
})->group('RF-PD-15', 'RL-19');

it('convierte en [n] cuatro cifras seguidas aunque no haya ninguna letra al lado', function (): void {
    // `²` es una cifra para `\p{N}` pero no para `\d`: la secuencia no la
    // recoge la regla de series y la de H4 exige una letra. La de las cuatro
    // cifras es la unica que la atrapa.
    expect(ErrorTextAllowlist::apply('x 1234²'))->toBe('x [n]')
        ->and(ErrorTextAllowlist::apply('x 123²'))->toBe('x 123²');
})->group('RF-PD-15', 'RL-19');
