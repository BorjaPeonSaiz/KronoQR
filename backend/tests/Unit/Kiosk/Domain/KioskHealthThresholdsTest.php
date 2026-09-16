<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;

/*
 * Los tres umbrales con los que se juzga un quiosco (**RF-PA-07**, ficha de la
 * tarea 3.3, decisiones 2 y 5).
 *
 * ## Por que el objeto tiene su propio fichero
 *
 * `CheckKioskHealthTest` comprueba el veredicto **usando** estos umbrales; aqui
 * se comprueba lo que el objeto acepta y lo que rechaza, que es otra cosa. La
 * diferencia se ve en la mutacion: los dos extremos del porcentaje —el 0 y el
 * 100— no los recorria ninguna prueba, asi que `> 100` podia convertirse en
 * `>= 100` sin que nada se cayera, y un cliente que escribiera
 * `KIOSK_HEALTH_BATTERY_LOW_PERCENT=100` se habria encontrado con que el
 * producto se niega a arrancar.
 *
 * ## Los extremos son casos de uso reales, no numeros bonitos
 *
 * **0** es «no me avises nunca por bateria»: el hotel cuyas tablets estan
 * atornilladas al cargador. **100** es «avisame siempre que una tablet este
 * descargandose»: el hotel que las rota a mano entre turnos. Los dos tienen que
 * poder escribirse.
 */

it('acepta los dos extremos del porcentaje de bateria', function (int $percent): void {
    $thresholds = new KioskHealthThresholds(
        freshWithinSeconds: 120,
        silentAfterSeconds: 600,
        batteryLowPercent: $percent,
    );

    expect($thresholds->batteryLowPercent)->toBe($percent);
})->with([
    'cero: no avisar nunca por bateria' => [0],
    'cien: avisar siempre que no este cargando' => [100],
])->group('RF-PA-07');

it('rechaza un porcentaje de bateria que no es un porcentaje', function (int $percent): void {
    // Fuera de 0..100 el umbral no avisaria nunca o avisaria siempre, y las dos
    // cosas acaban con alguien ignorando la columna.
    expect(fn (): KioskHealthThresholds => new KioskHealthThresholds(
        freshWithinSeconds: 120,
        silentAfterSeconds: 600,
        batteryLowPercent: $percent,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'uno por debajo del minimo' => [-1],
    'uno por encima del maximo' => [101],
])->group('RF-PA-07');

it('acepta un plazo de latido fresco de un solo segundo', function (): void {
    // Un segundo es absurdo en produccion y aun asi es legitimo: una instalacion
    // de pruebas que quiera ver el aviso sin esperar dos minutos. Lo que no es
    // legitimo es cero, que es el caso de abajo.
    $thresholds = new KioskHealthThresholds(freshWithinSeconds: 1, silentAfterSeconds: 2);

    expect($thresholds->freshWithinSeconds)->toBe(1);
})->group('RF-PA-07');

it('rechaza un plazo de latido fresco de cero segundos', function (): void {
    // Con cero, un quiosco estaria atrasado en el mismo instante en que acaba de
    // latir: la columna «al dia» no se pondria verde jamas.
    expect(fn (): KioskHealthThresholds => new KioskHealthThresholds(
        freshWithinSeconds: 0,
        silentAfterSeconds: 600,
    ))->toThrow(InvalidArgumentException::class);
})->group('RF-PA-07');

it('rechaza dos plazos iguales, que no dejarian zona de aviso', function (): void {
    // Iguales, un quiosco pasaria de correcto a fallo sin que nadie hubiera
    // podido mirar la red antes. El de silencio va DESPUES, no a la par.
    expect(fn (): KioskHealthThresholds => new KioskHealthThresholds(
        freshWithinSeconds: 600,
        silentAfterSeconds: 600,
    ))->toThrow(InvalidArgumentException::class);
})->group('RF-PA-07');
