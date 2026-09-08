<?php

declare(strict_types=1);

use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Application\UseCase\CheckKioskHealth;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthReason;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthRow;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthThresholds;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthVerdict;
use App\Modules\Kiosk\Domain\ValueObject\ProvisionedDevice;
use Tests\Support\Time\FixedClock;

/*
 * El veredicto de `php artisan kiosk:health` (**RF-PA-07**, doc 02 Anexo C).
 *
 * ## Por que estas pruebas son unitarias y con reloj fijo
 *
 * Porque lo unico que hay que fijar aqui son **las dos fronteras**: los 120 s a
 * partir de los cuales un quiosco pasa de correcto a aviso, y los 600 s a partir
 * de los cuales pasa de aviso a fallo. Al segundo. Con `now()` dentro del caso de
 * uso, «a los 120 exactos todavia esta correcto» solo se podria comprobar
 * esperando dos minutos, que es la razon por la que existe el puerto `Clock`
 * (regla dura 2, ADR-021).
 *
 * Los dos plazos no son inventados y esa es la mitad del valor de este fichero:
 * 120 s es lo que el runbook `alta-nuevo-quiosco.md` §4.2 promete por escrito al
 * cliente, y 600 s es el umbral de la alerta «Quiosco sin latido > 10 min,
 * Critica» del doc 01 §9.3. Si alguien cambia uno de los dos, estas pruebas se
 * caen y le obligan a mirar el otro documento.
 */

/** Los plazos de serie: los mismos que `config/kiosk.php`. */
function umbralesDeSalud(): KioskHealthThresholds
{
    return new KioskHealthThresholds(freshWithinSeconds: 120, silentAfterSeconds: 600);
}

/**
 * Un quiosco a medida, con el minimo que hay que decir en cada prueba.
 */
function quioscoDePrueba(
    string $name = 'Recepcion',
    string $status = 'active',
    ?string $lastSeenAt = null,
    int $pendingQueueSize = 0,
    ?string $pairedAt = '2026-09-09 08:00:00',
): DeviceSummary {
    return new DeviceSummary(
        id: 1,
        uuid: '0199a1f0-0000-7000-8000-00000000000'.substr(md5($name), 0, 1),
        name: $name,
        status: $status,
        appVersion: '1.4.0',
        lastSeenAt: $lastSeenAt === null ? null : new DateTimeImmutable($lastSeenAt, new DateTimeZone('UTC')),
        pendingQueueSize: $pendingQueueSize,
        pairedAt: $pairedAt === null ? null : new DateTimeImmutable($pairedAt, new DateTimeZone('UTC')),
    );
}

/**
 * El caso de uso con una flota fija y el reloj detenido.
 *
 * El doble implementa el puerto entero porque `all()` es lo unico que este caso
 * de uso usa: lo demas es el alta, que no se toca aqui.
 *
 * @param  list<DeviceSummary>  $devices
 */
function saludDeLaFlota(array $devices, string $now = '2026-09-09 12:00:00'): CheckKioskHealth
{
    $registry = new class($devices) implements DeviceRegistry
    {
        /** @param  list<DeviceSummary>  $devices */
        public function __construct(private array $devices) {}

        public function provision(int $siteId, string $name, ?string $appVersion, DateTimeImmutable $now): ?ProvisionedDevice
        {
            throw new LogicException('kiosk:health no da de alta nada: es de solo lectura.');
        }

        public function all(): array
        {
            return $this->devices;
        }

        public function findByUuid(string $deviceUuid): ?DeviceSummary
        {
            return null;
        }

        public function findById(int $deviceId): ?DeviceSummary
        {
            return null;
        }
    };

    return new CheckKioskHealth($registry, FixedClock::at($now), umbralesDeSalud());
}

// --- Las dos fronteras, al segundo ------------------------------------------

it('juzga el latido en las dos fronteras exactas, y no una a cada lado', function (
    string $lastSeenAt,
    KioskHealthVerdict $verdict,
    KioskHealthReason $reason,
): void {
    $report = saludDeLaFlota([quioscoDePrueba(lastSeenAt: $lastSeenAt)])->handle();

    expect($report->devices[0]->verdict)->toBe($verdict)
        ->and($report->devices[0]->reason)->toBe($reason);
})->with([
    // El limite superior de «al dia» es INCLUSIVO: a los 120 s exactos el
    // segundo latido acaba de perderse y todavia no hay nada que mirar.
    'un latido recien llegado' => ['2026-09-09 11:59:58', KioskHealthVerdict::Ok, KioskHealthReason::Beating],
    '119 s, al dia' => ['2026-09-09 11:58:01', KioskHealthVerdict::Ok, KioskHealthReason::Beating],
    '120 s exactos, todavia al dia' => ['2026-09-09 11:58:00', KioskHealthVerdict::Ok, KioskHealthReason::Beating],
    '121 s, ya atrasado' => ['2026-09-09 11:57:59', KioskHealthVerdict::Warning, KioskHealthReason::Late],
    // Y el de «atrasado» tambien: a los 600 s exactos la alerta del doc 01 §9.3
    // dice «> 10 min», asi que el minuto diez todavia no es critico.
    '599 s, atrasado' => ['2026-09-09 11:50:01', KioskHealthVerdict::Warning, KioskHealthReason::Late],
    '600 s exactos, atrasado' => ['2026-09-09 11:50:00', KioskHealthVerdict::Warning, KioskHealthReason::Late],
    '601 s, callado' => ['2026-09-09 11:49:59', KioskHealthVerdict::Failure, KioskHealthReason::Silent],
    'tres horas, callado' => ['2026-09-09 09:00:00', KioskHealthVerdict::Failure, KioskHealthReason::Silent],
])->group('RF-PA-07');

// --- La cola pendiente ------------------------------------------------------

it('avisa de un quiosco que late al dia pero arrastra cola sin enviar', function (): void {
    // Runbook §5.1: esos fichajes se PIERDEN si alguien revoca el token antes de
    // que drenen, y son registro horario de personas reales. Por eso una cola
    // llena no es «ok con un matiz».
    $report = saludDeLaFlota([quioscoDePrueba(lastSeenAt: '2026-09-09 11:59:30', pendingQueueSize: 7)])->handle();

    expect($report->devices[0]->verdict)->toBe(KioskHealthVerdict::Warning)
        ->and($report->devices[0]->reason)->toBe(KioskHealthReason::QueuePending)
        ->and($report->exitCode())->toBe(1);
})->group('RF-PA-07');

it('cuenta antes el silencio que la cola cuando se dan los dos a la vez', function (): void {
    // La cola de un quiosco que no habla no la va a drenar nadie: lo que hay que
    // atender es el silencio, y una celda con dos causas no la lee quien tiene
    // una tablet apagada delante.
    $report = saludDeLaFlota([quioscoDePrueba(lastSeenAt: '2026-09-09 09:00:00', pendingQueueSize: 40)])->handle();

    expect($report->devices[0]->reason)->toBe(KioskHealthReason::Silent);
})->group('RF-PA-07');

// --- Sin haber latido nunca -------------------------------------------------

it('da margen al quiosco recien vinculado que aun no ha latido', function (): void {
    // Es el escenario del runbook §4.2: se ejecuta este comando JUSTO despues de
    // confirmar el codigo, y la tablet recoge su token en el sondeo siguiente.
    // Un fallo ahi enseñaria a ignorar el comando el primer dia.
    $report = saludDeLaFlota([quioscoDePrueba(pairedAt: '2026-09-09 11:55:00')])->handle();

    expect($report->devices[0]->verdict)->toBe(KioskHealthVerdict::Warning)
        ->and($report->devices[0]->reason)->toBe(KioskHealthReason::AwaitingFirstHeartbeat)
        ->and($report->devices[0]->secondsSinceLastSeen)->toBeNull();
})->group('RF-PA-07', 'RF-PD-06');

it('marca como fallo el quiosco vinculado hace rato que nunca ha latido', function (): void {
    $report = saludDeLaFlota([quioscoDePrueba(pairedAt: '2026-09-09 08:00:00')])->handle();

    expect($report->devices[0]->verdict)->toBe(KioskHealthVerdict::Failure)
        ->and($report->devices[0]->reason)->toBe(KioskHealthReason::NeverSeen)
        ->and($report->exitCode())->toBe(2);
})->group('RF-PA-07');

it('marca como fallo el quiosco sin fecha de vinculacion que nunca ha latido', function (): void {
    // `paired_at` es nulo en los dispositivos dados de alta antes del
    // emparejamiento por codigo. Sin fecha no hay margen que conceder.
    $report = saludDeLaFlota([quioscoDePrueba(pairedAt: null)])->handle();

    expect($report->devices[0]->reason)->toBe(KioskHealthReason::NeverSeen);
})->group('RF-PA-07');

// --- Los revocados no tienen salud ------------------------------------------

it('enseña el quiosco revocado sin contarlo para el codigo de salida', function (): void {
    // Un desvinculado no late porque no debe. Contarlo como fallo llenaria de
    // rojo la consola de cualquier hotel que haya sustituido una tablet.
    $report = saludDeLaFlota([
        quioscoDePrueba(name: 'Recepcion', lastSeenAt: '2026-09-09 11:59:30'),
        quioscoDePrueba(name: 'Cocina vieja', status: 'revoked', lastSeenAt: '2026-08-01 10:00:00'),
    ])->handle();

    expect($report->devices[1]->verdict)->toBe(KioskHealthVerdict::Revoked)
        ->and($report->devices[1]->reason)->toBe(KioskHealthReason::Revoked)
        ->and($report->status)->toBe(KioskHealthVerdict::Ok)
        ->and($report->exitCode())->toBe(0)
        ->and($report->total())->toBe(2)
        ->and($report->active())->toBe(1);
})->group('RF-PA-07');

// --- El veredicto del conjunto ----------------------------------------------

it('resuelve el conjunto con el peor de sus quioscos activos', function (): void {
    $report = saludDeLaFlota([
        quioscoDePrueba(name: 'Recepcion', lastSeenAt: '2026-09-09 11:59:30'),
        quioscoDePrueba(name: 'Cocina', lastSeenAt: '2026-09-09 11:55:00'),
        quioscoDePrueba(name: 'Almacen', lastSeenAt: '2026-09-09 09:00:00'),
    ])->handle();

    expect($report->status)->toBe(KioskHealthVerdict::Failure)
        ->and($report->exitCode())->toBe(2);
})->group('RF-PA-07');

it('pone los fallos delante de los avisos en lo que hay que mirar', function (): void {
    // Recorrer diez lineas verdes hasta la roja es una forma de esconderla: el
    // mismo criterio con el que `product:doctor` ordena su informe.
    $report = saludDeLaFlota([
        quioscoDePrueba(name: 'Cocina', lastSeenAt: '2026-09-09 11:55:00'),
        quioscoDePrueba(name: 'Almacen', lastSeenAt: '2026-09-09 09:00:00'),
    ])->handle();

    expect(array_map(static fn (KioskHealthRow $row): string => $row->name, $report->problems()))
        ->toBe(['Almacen', 'Cocina']);
})->group('RF-PA-07');

/*
 * Los dos casos siguientes son la misma decision vista desde sus dos momentos
 * legitimos: ni fallo ni correcto, y el docblock de `KioskHealthReport` dice por
 * que. En los dos NADIE PUEDE FICHAR, y decir «correcto» de eso seria mentirle a
 * la fila 20 de la lista de comprobacion de `endurecimiento.md`; decir «fallo»
 * haria que esa lista fallara el dia de la instalacion, que es como se enseña a
 * ignorarla.
 */

it('avisa, y no da por correcta, una instalacion sin ninguna fila de quiosco', function (): void {
    $report = saludDeLaFlota([])->handle();

    expect($report->status)->toBe(KioskHealthVerdict::Warning)
        ->and($report->exitCode())->toBe(1);
})->group('RF-PA-07');

it('avisa cuando todos los quioscos estan revocados y nadie puede fichar', function (): void {
    // Es el hueco entre desvincular una tablet averiada y vincular su sustituta
    // (runbook `alta-nuevo-quiosco.md` §5).
    $report = saludDeLaFlota([quioscoDePrueba(status: 'revoked', lastSeenAt: '2026-08-01 10:00:00')])->handle();

    expect($report->status)->toBe(KioskHealthVerdict::Warning)
        ->and($report->exitCode())->toBe(1);
})->group('RF-PA-07');

// --- Detalles que se notan cuando fallan ------------------------------------

it('no devuelve segundos negativos si el ultimo latido quedo en el futuro', function (): void {
    // Un `pg_restore` de una copia hecha en otra maquina, o un salto de NTP hacia
    // atras, dejan un instante por delante del reloj. «hace -12 s» en la consola
    // haria dudar del comando entero justo cuando se ejecuta para diagnosticar.
    $report = saludDeLaFlota([quioscoDePrueba(lastSeenAt: '2026-09-09 12:05:00')])->handle();

    expect($report->devices[0]->secondsSinceLastSeen)->toBe(0)
        ->and($report->devices[0]->verdict)->toBe(KioskHealthVerdict::Ok);
})->group('RF-PA-07');

it('exige que el plazo de silencio vaya despues del de latido fresco', function (): void {
    // Con los dos cruzados no habria zona de aviso: un quiosco pasaria de
    // correcto a fallo sin que nadie hubiera podido mirar la red antes.
    expect(fn (): KioskHealthThresholds => new KioskHealthThresholds(600, 120))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-PA-07');

it('no publica ni el token ni la clave interna de la fila', function (): void {
    // Regla dura 21 y el mismo criterio que `DeviceSummary`: quien viera el hash
    // del token tendria la mitad del trabajo hecho para suplantar un quiosco.
    $json = saludDeLaFlota([quioscoDePrueba(lastSeenAt: '2026-09-09 11:59:30')])->handle()->toArray();

    /** @var list<array<string, mixed>> $devices */
    $devices = $json['devices'];

    expect($devices[0])->toHaveKeys(['uuid', 'name', 'status', 'verdict', 'reason'])
        ->and($devices[0])->not->toHaveKey('id')
        ->and($devices[0])->not->toHaveKey('token_hash');
})->group('RF-PA-07');
