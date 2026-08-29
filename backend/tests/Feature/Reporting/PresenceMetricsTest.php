<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\RealtimeConnectionCounter;
use App\Modules\Reporting\Application\UseCase\PublishPresenceMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\PresenceFixtures;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las dos metricas de la presencia en vivo (doc 02 §8.2, doc 01 §9.2).
 *
 *   open_shifts_current{site,site_name,department}
 *   websocket_connections_active
 *
 * **Se comprueba el fichero que lee `node-exporter`**, no una llamada a un
 * doble. La metrica de negocio de un producto que se instala en el servidor del
 * cliente vale lo que valga el fichero `.prom`: si el formato de exposicion esta
 * mal —una etiqueta sin escapar, media metrica—, `node-exporter` **descarta el
 * fichero entero** y no avisa. Ese es el fallo que estas pruebas buscan.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El colector escribe de verdad, en un directorio temporal de la prueba: es
    // lo unico que hace significativa la comprobacion del formato.
    config()->set('observability.metrics.enabled', true);
    config()->set('observability.metrics.textfile_path', sys_get_temp_dir().'/kronoqr-presence-'.bin2hex(random_bytes(4)));

    app()->instance(Clock::class, FixedClock::at('2026-03-14 09:12:03'));
});

function ficheroDeMetricasDePresencia(): string
{
    return rtrim(config()->string('observability.metrics.textfile_path'), '/').'/kronoqr_presence.prom';
}

/**
 * Cuenta las conexiones que se le digan, o finge que Reverb no contesta.
 */
function contadorDeConexiones(?int $conexiones): void
{
    app()->instance(RealtimeConnectionCounter::class, new class($conexiones) implements RealtimeConnectionCounter
    {
        public function __construct(private readonly ?int $conexiones) {}

        public function activeConnections(): ?int
        {
            return $this->conexiones;
        }
    });
}

it('publica los turnos abiertos por departamento con el nombre del centro', function (): void {
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');
    $cocina = WorkforceFixtures::department($site, 'Cocina');

    $dentro = WorkforceFixtures::employee($site, $cocina);
    PresenceFixtures::openShift($dentro, $site);

    contadorDeConexiones(3);

    expect(app(PublishPresenceMetrics::class)->handle())->toBeTrue();

    $prom = (string) file_get_contents(ficheroDeMetricasDePresencia());

    expect($prom)->toContain('# TYPE open_shifts_current gauge')
        ->and($prom)->toMatch('/open_shifts_current\{site="'.$site.'",site_name="[^"]+",department="Cocina[^"]*"\} 1/')
        ->and($prom)->toContain('websocket_connections_active 3')
        // El sello de tiempo delata que la tarea programada dejo de ejecutarse.
        ->and($prom)->toContain('presence_metrics_timestamp_seconds ');
})->group('RF-PA-01', 'RQ-11');

it('publica un cero por departamento vacio en vez de hacer desaparecer la serie', function (): void {
    // En Prometheus, una serie que se esfuma es indistinguible de una que nunca
    // existio, y el cero es justo el valor que alguien mira a las 06:00.
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');
    WorkforceFixtures::department($site, 'Recepcion');

    contadorDeConexiones(0);

    app(PublishPresenceMetrics::class)->handle();

    $prom = (string) file_get_contents(ficheroDeMetricasDePresencia());

    expect($prom)->toMatch('/open_shifts_current\{site="'.$site.'",site_name="[^"]+",department="Recepcion[^"]*"\} 0/')
        // Y cero conexiones SI se publica: «nadie tiene el panel abierto» es un
        // dato, no una ausencia.
        ->and($prom)->toContain('websocket_connections_active 0');
})->group('RF-PA-01');

it('omite la serie del WebSocket cuando Reverb no contesta, en vez de escribir un cero', function (): void {
    // ADR-011: el panel de salud debe distinguir «WebSocket caido» de «sistema
    // caido». Un cero convertiria esa averia en una jornada tranquila.
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');
    WorkforceFixtures::department($site, 'Cocina');

    contadorDeConexiones(null);

    app(PublishPresenceMetrics::class)->handle();

    $prom = (string) file_get_contents(ficheroDeMetricasDePresencia());

    expect($prom)->not->toContain('websocket_connections_active')
        // Y el resto del fichero sigue publicandose: una metrica que se cae no se
        // lleva por delante a las demas.
        ->and($prom)->toContain('open_shifts_current');
})->group('RF-PA-01');

it('recalcula el gauge en vez de acumularlo', function (): void {
    // Regla dura 7 aplicada a la instrumentacion: un contador que subiera con
    // cada entrada y bajara con cada salida se desviaria en el primer mensaje
    // perdido y nadie lo notaria.
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');
    $cocina = WorkforceFixtures::department($site, 'Cocina');

    $dentro = WorkforceFixtures::employee($site, $cocina);
    PresenceFixtures::openShift($dentro, $site);

    contadorDeConexiones(1);

    app(PublishPresenceMetrics::class)->handle();
    app(PublishPresenceMetrics::class)->handle();
    app(PublishPresenceMetrics::class)->handle();

    $prom = (string) file_get_contents(ficheroDeMetricasDePresencia());

    expect(substr_count($prom, 'open_shifts_current{'))->toBe(2)
        ->and($prom)->toMatch('/department="Cocina[^"]*"\} 1/');
})->group('RF-PA-01', 'RN-06');

it('escapa el nombre de un departamento con comillas', function (): void {
    // Sin escapar, `node-exporter` descarta el fichero ENTERO y con el las series
    // de todos los demas departamentos, sin avisar.
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');
    WorkforceFixtures::department($site, 'Sala "El Faro"');

    contadorDeConexiones(0);

    app(PublishPresenceMetrics::class)->handle();

    $prom = (string) file_get_contents(ficheroDeMetricasDePresencia());

    expect($prom)->toContain('Sala \"El Faro\"');
})->group('RF-PA-01');

it('no publica nada antes de la puesta en marcha, cuando no hay centro', function (): void {
    // RF-PD-03. Publicar `site=""` seria inventar una serie que despues habria que
    // reconciliar con la de verdad.
    contadorDeConexiones(0);

    expect(app(PublishPresenceMetrics::class)->handle())->toBeFalse()
        ->and(file_exists(ficheroDeMetricasDePresencia()))->toBeFalse();
})->group('RF-PA-01');

it('cuenta el turno abierto de quien no tiene departamento', function (): void {
    // Sin el cubo de la etiqueta vacia, la suma del gauge no cuadraria con la
    // gente que hay dentro del hotel.
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');

    $huerfano = WorkforceFixtures::employee($site, null);
    PresenceFixtures::openShift($huerfano, $site);

    contadorDeConexiones(0);

    app(PublishPresenceMetrics::class)->handle();

    $prom = (string) file_get_contents(ficheroDeMetricasDePresencia());

    expect($prom)->toMatch('/department=""\} 1/');
})->group('RF-PA-01');
