<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseLimits;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Shared\Domain\ValueObject\Feature;

/*
 * La carga util de una clave: que se acepta, que se rechaza y que se ignora.
 *
 * Es la mitad del dominio de licencia que no depende del reloj. Lo que se
 * prueba aqui no es criptografia —eso es la prueba del verificador— sino la
 * decision sobre lo que la clave AFIRMA.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validClaims(array $overrides = []): array
{
    return [
        'license_id' => 'lic-1',
        'customer_name' => 'Hotel de Pruebas, S.L.',
        'plan' => 'estandar',
        'max_employees' => 50,
        'max_devices' => 3,
        'features' => ['advanced_reports'],
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2025-12-15T10:00:00Z',
        ...$overrides,
    ];
}

it('lee una clave completa', function (): void {
    $license = License::fromClaims(validClaims());

    expect($license->customerName)->toBe('Hotel de Pruebas, S.L.')
        ->and($license->plan)->toBe('estandar')
        ->and($license->limits->maxEmployees)->toBe(50)
        ->and($license->limits->maxDevices)->toBe(3)
        ->and($license->features)->toBe([Feature::AdvancedReports])
        ->and($license->validUntil->format('Y-m-d H:i:s'))->toBe('2026-12-31 23:59:59');
})->group('RF-PD-04');

it('rechaza una clave sin un campo obligatorio', function (string $field): void {
    $claims = validClaims();
    unset($claims[$field]);

    expect(static fn () => License::fromClaims($claims))->toThrow(InvalidLicenseKey::class);
})->with([
    'license_id',
    'customer_name',
    'plan',
    'max_employees',
    'max_devices',
    'features',
    'valid_from',
    'valid_until',
    'issued_at',
])->group('RF-PD-04');

it('exige que los limites sean enteros positivos', function (string $field, mixed $value): void {
    // Estricto: `"50"` no es 50. Una clave la emite el fabricante con su propia
    // herramienta, asi que una cadena donde va un numero es un fallo de emision
    // que conviene ver al activar y no al contar.
    //
    // **Las dos magnitudes se comprueban**, no solo la primera: una validacion
    // que solo mirara `max_employees` dejaria pasar un `max_devices: 0`, y ese
    // cero convertiria cualquier quiosco en un exceso desde el primero.
    expect(static fn () => License::fromClaims(validClaims([$field => $value])))
        ->toThrow(InvalidLicenseKey::class);
})->with([
    'personas: cero' => ['max_employees', 0],
    'personas: negativo' => ['max_employees', -1],
    'personas: cadena' => ['max_employees', '50'],
    'personas: decimal' => ['max_employees', 50.5],
    'quioscos: cero' => ['max_devices', 0],
    'quioscos: negativo' => ['max_devices', -3],
    'quioscos: cadena' => ['max_devices', '3'],
])->group('RF-PD-04');

it('acepta el plan mas pequeño posible: una persona y un quiosco', function (): void {
    // El limite inferior EXACTO. Un hotel diminuto —o una instalacion de
    // demostracion— contrata uno de cada, y rechazarlo por un `<=` de mas seria
    // un fallo que solo aparece en el cliente mas pequeño.
    $license = License::fromClaims(validClaims(['max_employees' => 1, 'max_devices' => 1]));

    expect($license->limits->maxEmployees)->toBe(1)
        ->and($license->limits->maxDevices)->toBe(1);
})->group('RF-PD-04');

it('rechaza un campo de texto que no es texto, o que esta en blanco', function (mixed $value): void {
    // Sin la comprobacion de tipo, un `customer_name: 12345` acabaria en el
    // panel y en el asiento de auditoria como la cadena «12345»; sin la de
    // blancos, un nombre de cliente de tres espacios pasaria por bueno.
    expect(static fn () => License::fromClaims(validClaims(['customer_name' => $value])))
        ->toThrow(InvalidLicenseKey::class);
})->with([
    'un numero' => [12345],
    'una lista' => [['Hotel']],
    'nulo' => [null],
    'vacio' => [''],
    'solo espacios' => ['   '],
])->group('RF-PD-04');

it('recorta los espacios de los campos de texto', function (): void {
    // Una clave emitida con un nombre de cliente copiado con espacios delante no
    // debe producir un panel que enseñe «  Hotel Ejemplo».
    $license = License::fromClaims(validClaims([
        'customer_name' => '  Hotel Ejemplo, S.L.  ',
        'plan' => "\testandar\n",
    ]));

    expect($license->customerName)->toBe('Hotel Ejemplo, S.L.')
        ->and($license->plan)->toBe('estandar');
})->group('RF-PD-04');

it('acepta una vigencia que empieza y termina en el mismo instante', function (): void {
    // El limite EXACTO de la comprobacion de vigencia invertida. Una licencia de
    // un solo instante es rara pero no es un error de emision, y rechazarla por
    // un `<=` de mas convertiria un caso legitimo en una clave inservible.
    $license = License::fromClaims(validClaims([
        'valid_from' => '2026-06-15T12:00:00Z',
        'valid_until' => '2026-06-15T12:00:00Z',
    ]));

    expect($license->validFrom)->toEqual($license->validUntil);
})->group('RF-PD-04');

it('exige instantes UTC canonicos', function (string $value): void {
    expect(static fn () => License::fromClaims(validClaims(['valid_until' => $value])))
        ->toThrow(InvalidLicenseKey::class);
})->with([
    // Aceptar un desfase explicito haria que la caducidad dependiera de la zona
    // con la que se emitio, y con vigencias que acaban a medianoche eso es un
    // dia de diferencia.
    'con desfase' => ['2026-12-31T23:59:59+02:00'],
    'sin zona' => ['2026-12-31T23:59:59'],
    'solo fecha' => ['2026-12-31'],
    // `createFromFormat` acepta el 31 de febrero y lo desplaza a marzo: una
    // caducidad desplazada en silencio no se descubre hasta que alguien discute
    // una factura.
    'dia inexistente' => ['2026-02-31T00:00:00Z'],
    'mes inexistente' => ['2026-13-01T00:00:00Z'],
])->group('RF-PD-04');

it('rechaza una vigencia que termina antes de empezar', function (): void {
    expect(static fn () => License::fromClaims(validClaims([
        'valid_from' => '2026-12-31T00:00:00Z',
        'valid_until' => '2026-01-01T00:00:00Z',
    ])))->toThrow(InvalidLicenseKey::class);
})->group('RF-PD-04');

it('ignora los campos que esta version no conoce, incluido max_sites', function (): void {
    // ADR-040 retiro `max_sites`. Una clave que lo traiga verifica igual y el
    // campo NO llega al dominio: un limite que no limita nada no debe poder
    // consultarse. Y la tolerancia general es lo que permite a un hotel en la
    // 1.2 activar la renovacion emitida con la 1.6.
    $license = License::fromClaims(validClaims([
        'max_sites' => 7,
        'un_campo_del_futuro' => ['lo', 'que', 'sea'],
    ]));

    expect($license->limits->maxEmployees)->toBe(50)
        ->and($license->limits->maxDevices)->toBe(3)
        // No hay ninguna forma de preguntar por `max_sites`: el enum tiene dos
        // casos y no tres.
        ->and(PlanLimit::cases())->toBe([PlanLimit::Employees, PlanLimit::Devices]);
})->group('RF-PD-04');

it('descarta las funcionalidades que no conoce y no rechaza la clave', function (): void {
    $license = License::fromClaims(validClaims([
        'features' => ['advanced_reports', 'algo_que_llega_en_la_2_0', 'realtime_presence'],
    ]));

    expect($license->features)->toBe([Feature::AdvancedReports, Feature::RealtimePresence]);
})->group('RF-PD-04');

it('normaliza la lista de funcionalidades: sin repetidos y en orden de catalogo', function (): void {
    $license = License::fromClaims(validClaims([
        'features' => ['realtime_presence', 'advanced_reports', 'realtime_presence'],
    ]));

    expect($license->features)->toBe([Feature::AdvancedReports, Feature::RealtimePresence])
        ->and($license->featureNames())->toBe(['advanced_reports', 'realtime_presence']);
})->group('RF-PD-04');

it('exige que features sea una lista de cadenas', function (mixed $value): void {
    expect(static fn () => License::fromClaims(validClaims(['features' => $value])))
        ->toThrow(InvalidLicenseKey::class);
})->with([
    'objeto' => [['advanced_reports' => true]],
    'cadena' => ['advanced_reports'],
    'lista con numeros' => [[1, 2]],
])->group('RF-PD-04');

it('describe el exceso de un limite sin autorizar nada', function (): void {
    // `LicenseLimits` no tiene ningun metodo del tipo «¿cabe uno mas?», y esta
    // prueba fija esa ausencia: si existiera, alguien lo llamaria desde el alta
    // de un empleado y eso deja a una persona trabajando sin registro horario
    // (ADR-028).
    $limits = LicenseLimits::of(50, 3);

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(LicenseLimits::class))->getMethods(),
    );

    expect($limits->contractedFor(PlanLimit::Employees))->toBe(50)
        ->and($limits->contractedFor(PlanLimit::Devices))->toBe(3)
        // Por debajo del plan el exceso es CERO y no un negativo: la cifra
        // acaba en un asiento de auditoria y en el panel, y un «-8» ahi no
        // significa nada para quien lo lee.
        ->and($limits->excessOf(PlanLimit::Employees, 40))->toBe(0)
        ->and($limits->excessOf(PlanLimit::Employees, 0))->toBe(0)
        ->and($limits->excessOf(PlanLimit::Employees, 50))->toBe(0)
        ->and($limits->excessOf(PlanLimit::Employees, 53))->toBe(3)
        ->and($limits->isExceededBy(PlanLimit::Employees, 50))->toBeFalse()
        ->and($limits->isExceededBy(PlanLimit::Employees, 51))->toBeTrue()
        // La ausencia que importa, fijada por reflexion para que un metodo
        // nuevo con cualquiera de estos nombres haga fallar la prueba.
        ->and($methods)->not->toContain('allowsAnother')
        ->and($methods)->not->toContain('canAddAnother')
        ->and($methods)->not->toContain('hasRoomFor');
})->group('RF-PD-04', 'RF-PD-05');
