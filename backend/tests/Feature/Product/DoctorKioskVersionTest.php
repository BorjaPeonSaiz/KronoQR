<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorReport;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\KioskAppVersions;
use App\Modules\Shared\Domain\ValueObject\KioskAppVersionSurvey;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La sonda `kiosk.app_version` de `product:doctor` (**RF-PD-13**, **RF-KI-07**;
 * bloque 1 de la 2.2.1).
 *
 * Lo que se afirma:
 *
 * - una tablet por detras de la version del servidor sale como **aviso**, con
 *   su `uuid`, la version que declara y el estado de su cola;
 * - el remedio depende de la cola: con la cola duradera y vacia, «se actualiza
 *   sola en cuanto nadie ficha (2.2.1+) o en su franja (anteriores), y si no,
 *   desregistra el service worker»;
 *   con fichajes pendientes o la cola en memoria, **«espera, no recargues»**,
 *   porque recargar con la cola en memoria borra fichajes (ADR-047);
 * - **nunca** como fallo: `doctor.sh` lo convertiria en su codigo 6;
 * - una tablet por delante (vuelta atras del servidor) solo se informa;
 * - un servidor sin version juzgable no juzga a nadie en desarrollo, y en
 *   produccion avisa de que el aviso esta apagado;
 * - solo cuentan los quioscos activos con latido reciente;
 * - ni un nombre de quiosco en el informe, que viaja en el paquete de
 *   diagnostico (ADR-020, regla dura 21).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::grantAll();
    FrozenTime::at('2026-06-15 09:00:00');
    config()->set('app.version', '2.2.1');
});

/**
 * Un quiosco con la version, el ultimo latido y la cola que se le digan.
 *
 * @param  string|null  $lastSeenAt  Instante UTC del ultimo latido, o `null` si nunca latio.
 */
function quioscoConVersion(
    string $name,
    ?string $appVersion,
    ?string $lastSeenAt = '2026-06-15 08:59:30+00',
    string $status = 'active',
    ?int $pending = 0,
    string $queueStorage = 'durable',
): string {
    $device = AttendanceFixtures::device(WorkforceFixtures::site(), $name);

    DB::table('devices')->where('id', $device['id'])->update([
        'name' => $name,
        'status' => $status,
        'app_version' => $appVersion,
        'last_seen_at' => $lastSeenAt,
        'pending_queue_size' => $pending,
        'queue_storage' => $queueStorage,
    ]);

    return $device['uuid'];
}

function informeDeVersion(string $locale = 'es'): DoctorReport
{
    return resolve(RunDoctorHandler::class)->handle($locale);
}

/** La comprobacion `kiosk.app_version`; falla con el nombre si no aparece. */
function comprobacionDeVersion(DoctorReport $report): DoctorCheck
{
    foreach ($report->checks as $check) {
        if ($check->id === 'kiosk.app_version') {
            return $check;
        }
    }

    throw new RuntimeException('El informe no trae la comprobacion kiosk.app_version.');
}

/**
 * Como describe `details` a una tablet.
 *
 * @return array{device: string, app_version: string|null, queue_storage: string, pending_queue_size: int|null, ready: bool}
 */
function tabletEnDetalle(string $uuid, ?string $version, string $storage = 'durable', ?int $pending = 0): array
{
    return [
        'device' => $uuid,
        'app_version' => $version,
        'queue_storage' => $storage,
        'pending_queue_size' => $pending,
        'ready' => $storage === 'durable' && $pending === 0,
    ];
}

it('avisa, sin fallar, de la tablet desfasada con la cola vacia, y dice que se le puede aplicar el remedio', function (): void {
    $desfasada = quioscoConVersion('Tablet de Maria', '2.2.0');
    $alDia = quioscoConVersion('Recepcion', '2.2.1');

    $report = informeDeVersion();
    $check = comprobacionDeVersion($report);

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain($desfasada.' (2.2.0, queue=durable, pending=0)')
        ->and($check->summary)->toContain('2.2.1')
        ->and($check->summary)->not->toContain($alDia)
        ->and($check->details['minimum_app_version'] ?? null)->toBe('2.2.1')
        ->and($check->details['examined'] ?? null)->toBe(2)
        ->and($check->details['behind'] ?? null)->toBe([tabletEnDetalle($desfasada, '2.2.0')])
        ->and($check->details['ahead'] ?? null)->toBe([])
        // La verdad desde la 2.2.1: 2.2.1+ sola en cuanto nadie ficha, a
        // cualquier hora; anteriores en la franja; el plan B,
        // desregistrar con red y cola a 0, y SIN borrar los datos del sitio.
        ->and((string) $check->fix)->toContain('KIOSK_UPDATE_QUIET_MINUTES')
        ->and((string) $check->fix)->toContain('a cualquier hora')
        ->and((string) $check->fix)->toContain('operacion.md §11.1')
        // Una 2.1.0 tambien se actualiza sola en su franja: desregistrar es el plan B.
        ->and((string) $check->fix)->toContain('2.2.0, 2.1.0 o 0.0.0')
        ->and((string) $check->fix)->not->toContain('no se actualiza sola nunca')
        ->and((string) $check->fix)->toContain('KIOSK_UPDATE_WINDOW')
        ->and((string) $check->fix)->toContain('03:00 a 05:00')
        ->and((string) $check->fix)->toContain('SOLO con la tablet con red')
        ->and((string) $check->fix)->toContain('cola en memoria')
        ->and((string) $check->fix)->toContain('chrome://serviceworker-internals')
        ->and((string) $check->fix)->toContain('NO borres los datos del sitio')
        // Ni un nombre de quiosco: viaja en el paquete de diagnostico.
        ->and(json_encode($report->toArray(), JSON_THROW_ON_ERROR))->not->toContain('Tablet de Maria')
        ->and(json_encode($report->toArray(), JSON_THROW_ON_ERROR))->not->toContain('Recepcion');

    $en = comprobacionDeVersion(informeDeVersion('en'));

    expect($en->status)->toBe(DoctorStatus::Warning)
        ->and((string) $en->fix)->toContain('ONLY with the tablet online')
        ->and((string) $en->fix)->toContain('at any time')
        ->and((string) $en->fix)->toContain('operation.md §11.1')
        ->and((string) $en->fix)->toContain('Do NOT clear the site data');
})->group('RF-PD-13', 'RF-KI-07');

it('manda esperar, y no recargar, a la tablet desfasada con fichajes pendientes o la cola en memoria', function (
    ?int $pending,
    string $storage,
): void {
    $esperando = quioscoConVersion('Recepcion', '2.2.0', pending: $pending, queueStorage: $storage);

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain($esperando.' (2.2.0, queue='.$storage)
        ->and($check->details['behind'] ?? null)->toBe([tabletEnDetalle($esperando, '2.2.0', $storage, $pending)])
        ->and((string) $check->fix)->toContain('Espera')
        ->and((string) $check->fix)->toContain('NO recargues')
        ->and((string) $check->fix)->toContain('NO desregistres')
        ->and((string) $check->fix)->not->toContain('chrome://serviceworker-internals');

    $en = comprobacionDeVersion(informeDeVersion('en'));

    expect((string) $en->fix)->toContain('Do NOT reload these tablets');
})->with([
    'con fichajes pendientes' => [3, 'durable'],
    'con la cola en memoria' => [null, 'memory'],
    'sin almacenamiento' => [null, 'unavailable'],
])->group('RF-PD-13', 'RF-KI-07', 'RF-KI-04');

it('separa las tablets listas de las que tienen que esperar cuando hay de las dos', function (): void {
    $lista = quioscoConVersion('Recepcion', '2.2.0');
    $esperando = quioscoConVersion('Cocina', '0.0.0', pending: null, queueStorage: 'memory');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain('Con la cola vacia y en disco: '.$lista)
        ->and($check->summary)->toContain('que no hay que tocar todavia: '.$esperando)
        ->and((string) $check->fix)->toContain('NO las recargues')
        ->and((string) $check->fix)->toContain('chrome://serviceworker-internals');
})->group('RF-PD-13', 'RF-KI-07');

// El codigo de salida de product:doctor depende de TODAS las sondas (en la CI,
// por ejemplo, no hay copias); lo que depende de esta es que nunca sea un fallo.
it('no convierte una tablet desfasada en un fallo de product:doctor', function (): void {
    quioscoConVersion('Recepcion', '0.0.0');

    expect(comprobacionDeVersion(informeDeVersion())->status)->toBe(DoctorStatus::Warning);
})->group('RF-PD-13', 'RF-KI-07');

it('da por correcta la flota al dia', function (): void {
    quioscoConVersion('Recepcion', '2.2.1');
    quioscoConVersion('Cocina', '2.2.1-dev');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->fix)->toBeNull()
        ->and($check->details['examined'] ?? null)->toBe(2)
        ->and($check->details['behind'] ?? null)->toBe([]);
})->group('RF-PD-13', 'RF-KI-07');

it('solo informa de la tablet por delante del servidor, sin pedir nada', function (): void {
    $porDelante = quioscoConVersion('Recepcion', '2.3.0');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->fix)->toBeNull()
        ->and($check->summary)->toContain($porDelante.' (2.3.0')
        ->and($check->details['ahead'] ?? null)->toBe([tabletEnDetalle($porDelante, '2.3.0')]);
})->group('RF-PD-13', 'RF-KI-07');

it('no juzga a ninguna tablet con un servidor de desarrollo o sin version', function (string $servidor): void {
    config()->set('app.version', $servidor);
    quioscoConVersion('Recepcion', '0.0.0');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->fix)->toBeNull()
        ->and($check->details)->toBe(['enforced' => false, 'app_env' => 'testing']);
})->with([
    'desarrollo' => ['2.2.1-dev'],
    'desconocida' => ['0.0.0'],
])->group('RF-PD-13', 'RF-KI-07');

it('avisa en produccion de que el aviso de tablets desfasadas esta apagado por una version no valida', function (string $servidor): void {
    // Una imagen construida sin `APP_VERSION` declara `0.0.0-dev`: en
    // produccion eso apaga en silencio el aviso, y hay que saberlo.
    config()->set('app.version', $servidor);
    config()->set('app.env', 'production');
    quioscoConVersion('Recepcion', '0.0.0');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain('apagado')
        ->and((string) $check->fix)->toContain('APP_VERSION')
        ->and($check->details)->toBe(['enforced' => false, 'app_env' => 'production']);
})->with([
    'build sin version' => ['0.0.0-dev'],
    'desconocida' => ['0.0.0'],
])->group('RF-PD-13', 'RF-KI-07');

it('solo cuenta los quioscos activos con latido reciente', function (): void {
    // Callado (mas alla de los 600 s de silencio), revocado y sin latido nunca:
    // de los tres ya avisan `kiosk:health` y el panel, y su version declarada
    // puede ser de hace semanas.
    quioscoConVersion('Almacen', '2.2.0', lastSeenAt: '2026-06-15 08:40:00+00');
    quioscoConVersion('Antigua', '2.2.0', status: 'revoked');
    quioscoConVersion('Nueva', null, lastSeenAt: null);

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->details['examined'] ?? null)->toBe(0);
})->group('RF-PD-13', 'RF-KI-07');

it('cuenta como desfasada la tablet que no declara version', function (): void {
    $sinVersion = quioscoConVersion('Recepcion', null);

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain($sinVersion.' (-, queue=durable, pending=0)')
        ->and($check->details['behind'] ?? null)->toBe([tabletEnDetalle($sinVersion, null)]);
})->group('RF-PD-13', 'RF-KI-07');

it('avisa sin reventar si no puede leer los quioscos', function (): void {
    app()->instance(KioskAppVersions::class, new class implements KioskAppVersions
    {
        #[Override]
        public function survey(): KioskAppVersionSurvey
        {
            throw new RuntimeException('base de datos caida');
        }
    });

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->details)->toBe(['failure' => RuntimeException::class])
        ->and($check->summary)->not->toContain('base de datos caida');
})->group('RF-PD-13', 'RF-KI-07');
