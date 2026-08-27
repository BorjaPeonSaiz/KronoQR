<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Persistence\Row;

/*
 * Lectura tipada de una fila de PDO (`Shared\Infrastructure\Persistence\Row`).
 *
 * **Por que esto merece una prueba propia.** Es la clase que sostiene el total de
 * minutos que acaba en la exportacion legal a la Inspeccion de Trabajo y en el
 * historico que el empleado se descarga del portal. El modo de fallo que existe
 * para cerrar es silencioso por definicion: una columna nula que pasa por un
 * `(int)` sale como `0` — un cero que parece un dato y que nadie cuestiona.
 *
 * **Los casos son los tipos que devuelve PDO de verdad, no los que seria comodo
 * suponer.** El driver de PostgreSQL no promete el tipo PHP de cada columna: un
 * `integer` llega como `int` o como cadena numerica segun como este compilado
 * PDO, y un `boolean` como `bool`, como `'t'` o como `'true'` segun la version.
 * Una prueba que solo pasara `int` y `bool` daria por buena una implementacion
 * que se rompe en la maquina del cliente.
 */

/**
 * Una fila tal y como la entrega `ConnectionInterface::select()`: un `stdClass`.
 *
 * @param  array<string, mixed>  $values
 */
function pdoRow(array $values): Row
{
    return Row::of((object) $values);
}

it('lee un entero venga como entero o como cadena numerica', function (mixed $raw): void {
    // Los dos tipos que PDO puede devolver para la MISMA columna `integer`.
    expect(pdoRow(['total_minutes' => $raw])->int('total_minutes'))->toBe(480);
})->with([
    'entero nativo' => 480,
    'cadena numerica' => '480',
])->group('RL-06', 'RN-06');

it('distingue una columna nula de un cero', function (): void {
    // El modo de fallo que motiva la clase. Cero minutos es una jornada sin
    // tramos computables; nulo es «no hay proyeccion para ese dia». En un
    // documento con efectos legales no son lo mismo.
    $row = pdoRow(['total_minutes' => null, 'other_minutes' => 0]);

    expect($row->nullableInt('total_minutes'))->toBeNull()
        ->and($row->nullableInt('other_minutes'))->toBe(0);
})->group('RL-06', 'RN-06');

it('se niega a inventar un numero cuando la columna obligatoria no viene', function (): void {
    // Fallar en voz alta. La alternativa —devolver 0— produce una nomina
    // incorrecta sin que nadie vea nunca un error.
    expect(fn (): int => pdoRow([])->int('total_minutes'))
        ->toThrow(RuntimeException::class);
})->group('RL-06');

it('se niega a inventar un numero cuando la columna trae texto', function (): void {
    expect(fn (): int => pdoRow(['total_minutes' => 'ocho horas'])->int('total_minutes'))
        ->toThrow(RuntimeException::class);
})->group('RL-06');

it('reconoce como verdadero cada forma en que PostgreSQL devuelve un booleano', function (mixed $raw): void {
    expect(pdoRow(['is_corrected' => $raw])->bool('is_corrected'))->toBeTrue();
})->with([
    'bool nativo' => true,
    'la t de PostgreSQL' => 't',
    'la palabra completa' => 'true',
    'entero uno' => 1,
    'cadena uno' => '1',
])->group('RL-06', 'RN-13');

it('no toma por verdadero nada que no lo sea', function (mixed $raw): void {
    // El control negativo, y no es simetria: una comparacion laxa daria
    // verdadero para `'f'` (cadena no vacia) y marcaria como corregido cada
    // tramo del fichero que se entrega a la Inspeccion.
    expect(pdoRow(['is_corrected' => $raw])->bool('is_corrected'))->toBeFalse();
})->with([
    'la f de PostgreSQL' => 'f',
    'la palabra completa' => 'false',
    'cero' => 0,
    'cadena cero' => '0',
    'nulo' => null,
    'cadena vacia' => '',
])->group('RL-06', 'RN-13');

it('da por falsa una columna booleana que no viene', function (): void {
    // Una columna ausente no es un booleano verdadero. Para las columnas donde
    // la ausencia importa, el adaptador usa `nullableInt`/`nullableString`, que
    // si la distinguen.
    expect(pdoRow([])->bool('is_corrected'))->toBeFalse();
})->group('RL-06');

it('devuelve los instantes en UTC aunque la columna llegue con otro desfase', function (): void {
    // Regla dura 3. La conversion a la zona del centro es de la capa de
    // presentacion; aqui todo sale en UTC o el registro legal deja de cuadrar.
    // 08:30+02:00 son las 06:30 UTC: dos horas, no dos minutos.
    expect(pdoRow(['occurred_at' => '2026-03-29 08:30:00+02'])->instant('occurred_at')->format('Y-m-d\TH:i:sP'))
        ->toBe('2026-03-29T06:30:00+00:00');
})->group('RL-06', 'RN-05');

it('convierte a UTC tambien un instante que ya viene como objeto', function (): void {
    $madrid = new DateTimeImmutable('2026-10-25 02:30:00', new DateTimeZone('Europe/Madrid'));

    expect(pdoRow(['occurred_at' => $madrid])->instant('occurred_at')->getTimezone()->getName())
        ->toBe('UTC');
})->group('RL-06', 'RN-05');

it('distingue un instante ausente de uno nulo solo cuando se le permite', function (): void {
    expect(pdoRow(['ended_at' => null])->nullableInstant('ended_at'))->toBeNull();

    // Un turno abierto no tiene salida, y eso es un dato. Pero pedirla como
    // obligatoria tiene que fallar, no devolver la epoca.
    expect(fn (): DateTimeImmutable => pdoRow(['ended_at' => null])->instant('ended_at'))
        ->toThrow(RuntimeException::class);
})->group('RL-06', 'RN-05');

it('lee una columna JSONB venga decodificada o como texto', function (mixed $raw): void {
    expect(pdoRow(['before' => $raw])->json('before'))->toBe(['minutes' => 480]);
})->with([
    'texto del driver' => '{"minutes":480}',
    'array ya decodificado' => [['minutes' => 480]],
])->group('RN-13');

it('conserva el nulo de un JSONB, que en una correccion es significativo', function (): void {
    // `shift_corrections.before` es nulo en un alta y `.after` en una anulacion
    // (RN-13). Un array vacio en su lugar afirmaria que hubo un valor anterior y
    // que estaba vacio, que es otra cosa.
    expect(pdoRow(['before' => null])->json('before'))->toBeNull();
})->group('RN-13');

it('trata la cadena vacia como texto y no como ausencia', function (): void {
    // `''` no es `null`. Un motivo de correccion vacio es un dato mal escrito que
    // hay que poder ver en el fichero, no una columna que no vino.
    $row = pdoRow(['reason_text' => '']);

    expect($row->nullableString('reason_text'))->toBe('')
        ->and($row->string('reason_text'))->toBe('');
})->group('RL-06', 'RN-13');

it('se niega a convertir en texto una columna que trae una estructura', function (): void {
    // `(string) $array` daria «Array» y lo escribiria en el fichero legal.
    expect(fn (): string => pdoRow(['reason_text' => ['a']])->string('reason_text'))
        ->toThrow(RuntimeException::class);
})->group('RL-06');
