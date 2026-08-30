<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;

/*
 * **`HH:MM`, nunca decimal** (`/informe-nuevo` paso 6, RF-IN-04, RL-06).
 *
 * Es la regla de formato que el informe exportado no puede romper en ninguno de
 * sus tres ficheros, y por eso se prueba **aqui** —sobre el objeto de valor que
 * la implementa— y no dentro de un CSV: una prueba que la comprobara abriendo un
 * fichero fallaria por veinte motivos distintos y solo uno seria este.
 *
 * Los tres casos que importan y que un `sprintf` ingenuo se salta:
 *
 *   1. **Mas de 24 horas.** Esto no es una hora del reloj, es una cantidad de
 *      trabajo: el total mensual de una persona son ciento sesenta y tantas y se
 *      escribe `168:00`. Un formateador de fecha daria `00:00`.
 *   2. **Negativas.** La desviacion entre lo trabajado y lo contratado es
 *      negativa cuando se ha trabajado de menos, y `-12:30` es la unica forma
 *      honesta de escribirlo. El signo va delante y el resto se formatea sobre el
 *      valor absoluto: `-12:-30` no lo lee nadie.
 *   3. **Ninguna salida con coma ni con punto.** Es lo que se afirma al final, y
 *      se afirma sobre la cadena y no sobre la intencion: «7,75 horas» obliga a
 *      interpretar y ademas cambia de sentido segun el separador decimal del
 *      programa que abra el fichero.
 */

it('escribe una duracion normal con dos digitos en cada mitad', function (int $minutes, string $expected): void {
    expect(ReportedDuration::ofMinutes($minutes)->toClockText())->toBe($expected);
})->with([
    'cero' => [0, '00:00'],
    'un minuto' => [1, '00:01'],
    'nueve minutos, con cero a la izquierda' => [9, '00:09'],
    'una hora justa' => [60, '01:00'],
    'siete horas y cuarenta y cinco' => [465, '07:45'],
    'jornada de ocho horas y media' => [510, '08:30'],
    'el dia entero' => [1440, '24:00'],
])->group('RF-IN-04', 'RF-IN-01');

it('pasa de veinticuatro horas sin dar la vuelta, porque no es una hora del reloj', function (): void {
    // El total mensual de una persona a jornada completa. Un formateador de
    // fecha daria `00:00` y nadie se enteraria hasta cuadrar una nomina.
    expect(ReportedDuration::ofMinutes(10080)->toClockText())->toBe('168:00')
        ->and(ReportedDuration::ofMinutes(1441)->toClockText())->toBe('24:01')
        // Un año entero de una persona: cuatro digitos de hora y sigue siendo
        // legible.
        ->and(ReportedDuration::ofMinutes(120_000)->toClockText())->toBe('2000:00');
})->group('RF-IN-04');

it('escribe las negativas con el signo delante y nunca dentro', function (int $minutes, string $expected): void {
    expect(ReportedDuration::ofMinutes($minutes)->toClockText())->toBe($expected);
})->with([
    'un minuto de menos' => [-1, '-00:01'],
    'media hora de menos' => [-30, '-00:30'],
    'doce horas y media de menos' => [-750, '-12:30'],
    'un mes entero por debajo de lo contratado' => [-10080, '-168:00'],
])->group('RF-IN-04', 'RF-IN-03');

it('no produce jamas una hora decimal, en ningun valor', function (): void {
    // La afirmacion se hace sobre la SALIDA y con un barrido amplio, no sobre un
    // puñado de casos elegidos: lo que el requisito prohibe es que exista alguna
    // entrada que produzca `7,75` o `7.75`.
    foreach ([-100_000, -931, -60, -1, 0, 1, 59, 60, 465, 1440, 10_080, 100_000] as $minutes) {
        $text = ReportedDuration::ofMinutes($minutes)->toClockText();

        expect($text)->not->toContain(',')
            ->and($text)->not->toContain('.')
            // Y siempre con la forma `[-]H+:MM`, con los minutos a dos digitos:
            // `7:5` seria ambiguo entre cinco minutos y cincuenta.
            ->and($text)->toMatch('/^-?\d{2,}:\d{2}$/');
    }
})->group('RF-IN-04');

it('conserva los minutos que se le dieron, sin redondear por el camino', function (): void {
    // Los minutos son enteros desde el esquema (RN-06, ADR-007): entre la
    // proyeccion y el texto no puede haber ningun redondeo, porque un redondeo
    // por fila se convierte en horas al sumar quinientas.
    expect(ReportedDuration::ofMinutes(-931)->minutes)->toBe(-931)
        ->and(ReportedDuration::ofMinutes(-931)->toClockText())->toBe('-15:31');
})->group('RF-IN-04');
