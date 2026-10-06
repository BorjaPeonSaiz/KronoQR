<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use Illuminate\Support\Facades\Config;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:doctor` dice en voz alta lo que hacen las tres redes del borde
 * (PP-01, 2.2.0).
 *
 * ## Por que hace falta
 *
 * El valor de serie de `PORTAL_INTERNAL_CIDR` era la red de desarrollo
 * (`172.28.0.0/16`), que en un servidor real no existe: toda la plantilla
 * recibia un 403 y ningun control lo avisaba. Y el propietario abre el portal a
 * internet a proposito (RF-ID-08): ese caso legitimo tiene que ser VISIBLE, pero
 * nunca un fallo, porque un `2` aborta `update.sh` por algo que no esta roto.
 *
 * ## Que se fija
 *
 *  - Sintaxis invalida = fallo (nginx no arranca con ella).
 *  - `0.0.0.0/0`, direcciones publicas o el rango de ejemplo = AVISO, nunca fallo.
 *  - Nada del rango en `details`: viaja en el paquete de diagnostico (ADR-020).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

/** Deja las tres redes como diga la prueba. */
function conRedesDelBorde(string $quioscos, string $portal, string $metricas): void
{
    Config::set('security.edge_networks.kiosk_vlan', $quioscos);
    Config::set('security.edge_networks.portal_internal', $portal);
    Config::set('security.edge_networks.metrics_allow', $metricas);
}

/** Una de las tres comprobaciones `network.*` dentro del informe. */
function comprobacionDeRed(string $id, string $idioma = 'es'): DoctorCheck
{
    $informe = app(RunDoctorHandler::class)->handle($idioma);

    $encontrada = array_values(array_filter(
        $informe->checks,
        static fn (DoctorCheck $check): bool => $check->id === $id,
    ));

    expect($encontrada)->not->toBeEmpty("`doctor` no incluye la comprobacion {$id}.");

    return $encontrada[0];
}

it('sale en verde con tres redes privadas y acotadas', function (): void {
    conRedesDelBorde('10.0.20.0/24', '10.20.0.0/16', '172.29.0.20/32');

    foreach (['network.portal', 'network.kiosk_vlan', 'network.metrics'] as $id) {
        $comprobacion = comprobacionDeRed($id);

        expect($comprobacion->status)->toBe(DoctorStatus::Ok)
            ->and($comprobacion->fix)->toBeNull();
    }
})->group('RF-PD-13', 'RF-ID-08');

it('avisa, sin fallar, de un portal abierto a internet', function (): void {
    // La decision legitima del propietario: visible, pero un aviso. Un fallo
    // devolveria 2 y abortaria update.sh por algo que no esta roto.
    conRedesDelBorde('10.0.20.0/24', '0.0.0.0/0', '172.29.0.20/32');

    $comprobacion = comprobacionDeRed('network.portal');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('abierto a internet')
        // La longitud ya no es fija (ADR-050): el texto no la afirma y remite a
        // la comprobacion que si la mira, sin mandar al cliente a leer un ADR.
        ->and($comprobacion->summary)->not->toContain('6 digitos')
        ->and($comprobacion->fix)->toContain('PORTAL_INTERNAL_CIDR')
        ->and($comprobacion->fix)->toContain('endurecimiento.md')
        ->and($comprobacion->fix)->toContain('access.pin_length')
        ->and($comprobacion->fix)->not->toContain('ADR-');
})->group('RF-PD-13', 'RF-ID-08', 'RF-ID-09');

it('avisa de un portal abierto a direcciones que no son de una red privada', function (): void {
    conRedesDelBorde('10.0.20.0/24', '203.0.113.0/24', '172.29.0.20/32');

    $comprobacion = comprobacionDeRed('network.portal');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('no son de una red privada');

    // Y un rango privado que se sale por un extremo de 172.16/12 tambien lo es.
    conRedesDelBorde('10.0.20.0/24', '172.16.0.0/11', '172.29.0.20/32');

    expect(comprobacionDeRed('network.portal')->status)->toBe(DoctorStatus::Warning);
})->group('RF-PD-13', 'RF-ID-08');

it('avisa cuando el portal conserva el rango de ejemplo de la red de desarrollo', function (): void {
    // El fallo del primer despliegue real: el valor de serie no cubre a nadie.
    conRedesDelBorde('10.0.20.0/24', '172.28.0.0/16', '172.29.0.20/32');

    $comprobacion = comprobacionDeRed('network.portal');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('valor de ejemplo')
        ->and($comprobacion->fix)->toContain('portal-403.md');
})->group('RF-PD-13', 'RF-ID-08');

it('falla con una sintaxis que nginx rechazaria', function (string $valor): void {
    conRedesDelBorde($valor, $valor, $valor);

    foreach (['network.portal', 'network.kiosk_vlan', 'network.metrics'] as $id) {
        $comprobacion = comprobacionDeRed($id);

        expect($comprobacion->status)->toBe(DoctorStatus::Failure)
            ->and($comprobacion->fix)->toContain('docker compose up -d nginx');
    }
})->with([
    'prefijo 33' => '10.0.0.0/33',
    'octeto 256' => '10.256.0.0/16',
    'sin prefijo' => '10.0.0.5',
    'dos rangos' => '10.0.0.0/8,172.16.0.0/12',
    'IPv6' => 'fd00::/8',
    'ceros a la izquierda' => '010.0.0.0/8',
    'basura' => 'la red del hotel',
])->group('RF-PD-13', 'RF-ID-08');

it('avisa de quioscos y metricas abiertos a cualquier origen', function (): void {
    conRedesDelBorde('0.0.0.0/0', '10.20.0.0/16', '0.0.0.0/0');

    $quioscos = comprobacionDeRed('network.kiosk_vlan');
    $metricas = comprobacionDeRed('network.metrics');

    expect($quioscos->status)->toBe(DoctorStatus::Warning)
        ->and($quioscos->summary)->toContain('cualquier origen')
        ->and($quioscos->fix)->toContain('KIOSK_VLAN_CIDR')
        ->and($metricas->status)->toBe(DoctorStatus::Warning)
        ->and($metricas->summary)->toContain('/metrics')
        ->and($metricas->fix)->toContain('172.29.0.20/32');
})->group('RF-PD-13', 'RS-02', 'RS-09');

it('declara que no ha comprobado nada cuando la aplicacion no recibe las redes', function (): void {
    // Una comprobacion que calla no es una comprobacion: sale en verde pero lo
    // dice, y no avisa ni falla (una prueba, o un servicio sin la variable).
    conRedesDelBorde('', '', '');

    foreach (['network.portal', 'network.kiosk_vlan', 'network.metrics'] as $id) {
        $comprobacion = comprobacionDeRed($id);

        expect($comprobacion->status)->toBe(DoctorStatus::Ok)
            ->and($comprobacion->summary)->toContain('no recibe');
    }
})->group('RF-PD-13');

it('no publica ningun rango en los detalles ni en el texto', function (): void {
    // El informe viaja en el paquete de diagnostico (ADR-020) y un rango de
    // red describe la topologia interna del cliente.
    conRedesDelBorde('10.77.20.0/24', '203.0.113.0/24', '10.78.0.5/32');

    foreach (['network.portal', 'network.kiosk_vlan', 'network.metrics'] as $id) {
        $comprobacion = comprobacionDeRed($id);
        $publico = json_encode($comprobacion->details).$comprobacion->summary.($comprobacion->fix ?? '');

        expect($publico)->not->toContain('10.77.')
            ->and($publico)->not->toContain('203.0.113')
            ->and($publico)->not->toContain('10.78.');
    }
})->group('RF-PD-13', 'RS-05');

it('lo dice tambien en ingles', function (): void {
    conRedesDelBorde('10.0.20.0/24', '0.0.0.0/0', '172.29.0.20/32');

    $resumen = comprobacionDeRed('network.portal', 'en')->summary;

    expect($resumen)->toContain('open to the internet')
        ->and($resumen)->not->toContain('6-digit');

    conRedesDelBorde('10.0.20.0/24', '10.0.0.0/33', '172.29.0.20/32');

    expect(comprobacionDeRed('network.portal', 'en')->summary)
        ->toContain('not a valid IPv4 network range');
})->group('RF-PD-13', 'RF-ID-08');

/*
 * `ADMIN_INTERNAL_CIDR` (PP-10, ADR-050 §4). Vacia es el valor de serie y una
 * decision del propietario: se avisa del riesgo aceptado, nunca se falla.
 */

/** Deja `ADMIN_INTERNAL_CIDR` como diga la prueba; nulo es «la aplicacion no la recibe». */
function conRedDelPanel(?string $panel): void
{
    Config::set('security.edge_networks.admin_internal', $panel);
}

it('avisa, sin fallar, de un panel sin filtro de red', function (?string $valor): void {
    conRedesDelBorde('10.0.20.0/24', '0.0.0.0/0', '172.29.0.20/32');
    conRedDelPanel($valor);

    $comprobacion = comprobacionDeRed('network.admin');

    expect($comprobacion->status)->toBe(DoctorStatus::Warning)
        ->and($comprobacion->summary)->toContain('no se filtran por red')
        ->and($comprobacion->summary)->toContain('riesgo aceptado')
        ->and($comprobacion->fix)->toContain('ADMIN_INTERNAL_CIDR=10.20.0.0/16')
        ->and($comprobacion->fix)->toContain('docker compose up -d nginx');
})->with([
    'vacia (el valor de serie)' => '',
    'abierta a cualquier origen' => '0.0.0.0/0',
])->group('RF-PD-13', 'RS-06');

it('sale en verde con el panel cerrado a una red, sin publicar el rango', function (): void {
    conRedDelPanel('10.77.0.0/16');

    $comprobacion = comprobacionDeRed('network.admin');
    $publico = json_encode($comprobacion->details).$comprobacion->summary;

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($publico)->not->toContain('10.77.');
})->group('RF-PD-13', 'RS-05');

it('falla con un rango del panel que nginx rechazaria', function (): void {
    conRedDelPanel('10.0.0.0/33');

    $comprobacion = comprobacionDeRed('network.admin');

    expect($comprobacion->status)->toBe(DoctorStatus::Failure)
        ->and($comprobacion->fix)->toContain('docker compose up -d nginx');
})->group('RF-PD-13');

it('declara que no ha comprobado el panel cuando la aplicacion no recibe la variable', function (): void {
    conRedDelPanel(null);

    $comprobacion = comprobacionDeRed('network.admin');

    expect($comprobacion->status)->toBe(DoctorStatus::Ok)
        ->and($comprobacion->summary)->toContain('no recibe ADMIN_INTERNAL_CIDR');
})->group('RF-PD-13');

it('avisa del panel sin filtro tambien en ingles', function (): void {
    conRedDelPanel('');

    expect(comprobacionDeRed('network.admin', 'en')->summary)
        ->toContain('not filtered by network')
        ->toContain('accepted risk');
})->group('RF-PD-13', 'RS-06');
