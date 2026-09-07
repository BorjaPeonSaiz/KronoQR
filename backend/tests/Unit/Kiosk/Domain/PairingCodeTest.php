<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\Exception\InvalidDeviceName;
use App\Modules\Kiosk\Domain\Exception\InvalidPairingCode;
use App\Modules\Kiosk\Domain\ValueObject\DeviceName;
use App\Modules\Kiosk\Domain\ValueObject\PairingCode;

/*
 * Los dos objetos de valor del emparejamiento (RF-PD-06, tarea 5.6).
 *
 * `PairingCode` es lo que se lee de lejos y se teclea a mano, y `DeviceName` lo
 * que identifica al quiosco en el panel de salud durante los proximos años. Los
 * dos son pequeños y los dos tienen exactamente un sitio donde equivocarse: el
 * alfabeto que aceptan.
 */

it('acepta seis digitos decimales y nada mas', function (string $entrada, bool $valido): void {
    // El contrato declara `^[0-9]{6}$` y esta clase es la otra mitad de esa
    // promesa: lo que entra por consola no pasa por el contrato.
    expect(PairingCode::isWellFormed($entrada))->toBe($valido);
})->with([
    'seis digitos' => ['483921', true],
    'con cero delante' => ['049213', true],
    'todo ceros' => ['000000', true],
    'cinco digitos' => ['48392', false],
    'siete digitos' => ['4839210', false],
    'con letras' => ['48392A', false],
    'vacio' => ['', false],
    'con signo' => ['+83921', false],
])->group('RF-PD-06');

it('acepta el codigo tal y como lo copia quien lo lee de la pantalla', function (string $entrada): void {
    // La tablet lo muestra agrupado —«483 921»— y quien lo teclea lo copia tal
    // cual mas de una vez. Quitar espacios y guiones es corregir un artefacto de
    // la PRESENTACION, no ser permisivo: cualquier otro caracter sigue siendo un
    // codigo invalido.
    expect(PairingCode::of($entrada)->value)->toBe('483921');
})->with([
    'limpio' => ['483921'],
    'agrupado' => ['483 921'],
    'con guion' => ['483-921'],
    'con espacios de sobra' => ['  483 921  '],
])->group('RF-PD-06');

it('rechaza cualquier cosa que no sean seis digitos', function (): void {
    expect(fn (): PairingCode => PairingCode::of('no-es-un-codigo'))
        ->toThrow(InvalidPairingCode::class);
})->group('RF-PD-06');

it('no hashea nada, que es trabajo del adaptador', function (): void {
    // Regla dura 1. Si esta clase supiera con que algoritmo se guarda un secreto,
    // el agregado no se podria probar sin decidir tambien como se persiste. Lo
    // unico publico es el valor y su forma de pantalla.
    $publicos = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(PairingCode::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($publicos);

    // **Sin `forDisplay()`**: el agrupamiento «483 921» lo hace la PWA, que ya
    // decide su tipografia y su separacion. Un metodo de presentacion en un
    // objeto de valor del dominio es una opinion del servidor sobre como se ve
    // algo que no pinta.
    expect($publicos)->toBe(['equals', 'isWellFormed', 'of']);
})->group('RF-PD-06');

// --- DeviceName --------------------------------------------------------------

it('normaliza el nombre del quiosco y exige que diga algo', function (): void {
    expect(DeviceName::of('  Recepcion  ')->value)->toBe('Recepcion')
        ->and(fn (): DeviceName => DeviceName::of('   '))->toThrow(InvalidDeviceName::class);
})->group('RF-PD-06');

it('acota el nombre a lo que cabe en la columna', function (): void {
    // 120 caracteres, escritos aqui, en `devices.name` y en el contrato. La
    // columna es la ultima linea de defensa (§3.2) y esta clase es la que hace
    // cierto el limite tambien para la consola.
    expect(DeviceName::of(str_repeat('a', DeviceName::MAX_LENGTH))->value)
        ->toHaveLength(DeviceName::MAX_LENGTH)
        ->and(fn (): DeviceName => DeviceName::of(str_repeat('a', DeviceName::MAX_LENGTH + 1)))
        ->toThrow(InvalidDeviceName::class);
})->group('RF-PD-06');
