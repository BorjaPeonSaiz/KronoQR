<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\Samples;
use Tests\Support\Time\WallClockBudget;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RS-03, RS-02 y regla dura 17: **los siete rechazos son indistinguibles desde
 * fuera**, en respuesta y en tiempo.
 *
 * `php artisan test --filter=ConstantTimeRejection` es una de las dos ordenes de
 * verificacion de la tarea 1.5, y el criterio de terminado del prompt §6.3:
 * «prueba que demuestre que "codigo inexistente", "credencial revocada" y "firma
 * invalida" son indistinguibles desde fuera».
 *
 * **Lo que se mide y por que hacen falta las tres cosas.**
 *
 * 1. **El desenlace.** Los cuatro producen una `CredentialResolution` rechazada
 *    y ninguno filtra nada mas. El motivo interno existe para
 *    `scan_events.result` y para las metricas, y se queda del lado del servidor.
 * 2. **El trabajo.** Los cuatro caminos ejecutan **el mismo numero de
 *    consultas**. Es la mitad estructural del control y es deterministica: si
 *    manana alguien anade un `return` temprano para «ahorrar una consulta
 *    cuando no hay credencial», esta prueba falla aunque el reloj de la maquina
 *    de la CI no de para distinguirlo.
 * 3. **El reloj.** Las cuatro medianas caen dentro de la misma banda. Es la
 *    comprobacion que de verdad exige el requisito, y la que sin el suelo de
 *    tiempo del §5.2 seria intermitente.
 */

uses(RefreshDatabase::class);

/**
 * Los rechazos del §5.2, cada uno con su payload. Son seis desde la tarea
 * 2.12, que añadio el de la tarjeta cuya clave se retiro (RF-QR-07), y siete
 * desde que la persona suspendida tiene motivo propio (RN-25).
 *
 * @return array<string, string>
 */
function fourRejections(): array
{
    $site = WorkforceFixtures::site();

    // 1. Firma manipulada: la tarjeta existe, el ultimo byte no cuadra.
    $conTarjeta = WorkforceFixtures::employee($site);
    /** @var int $conTarjetaId */
    $conTarjetaId = DB::table('employees')->where('uuid', $conTarjeta)->value('id');
    $firmaInvalida = Credentials::tampered(Credentials::issueFor($conTarjetaId));

    // 2. Credencial revocada.
    $revocado = WorkforceFixtures::employee($site);
    /** @var int $revocadoId */
    $revocadoId = DB::table('employees')->where('uuid', $revocado)->value('id');
    $revocada = Credentials::issueFor($revocadoId, revokedReason: 'Perdida');

    // 3. Empleado dado de baja con la tarjeta todavia activa.
    $baja = WorkforceFixtures::employee($site, null, 'terminated');
    /** @var int $bajaId */
    $bajaId = DB::table('employees')->where('uuid', $baja)->value('id');
    $deBaja = Credentials::issueFor($bajaId);

    // 4. Tarjeta real de una rotacion que se cerro: la fila EXISTE y esta
    //    activa, pero su clave ya no esta en el llavero (RF-QR-07). Es distinto
    //    de «key_id desconocido»: alli no hay fila y el paso 4 falla el indice;
    //    aqui la hay, y si el codigo se ahorrara la consulta cuando la clave no
    //    resuelve, este caso costaria menos que los demas y se distinguiria
    //    desde fuera quien lleva una tarjeta caducada de quien lleva una
    //    falsificada.
    $rotado = WorkforceFixtures::employee($site);
    /** @var int $rotadoId */
    $rotadoId = DB::table('employees')->where('uuid', $rotado)->value('id');
    $claveRetirada = Credentials::issueFor($rotadoId, Credentials::retiredKey());

    // 5. Persona suspendida con la tarjeta vigente (RN-25): motivo propio en
    //    `scan_events`, el mismo «no» y el mismo trabajo hacia fuera.
    $suspendido = WorkforceFixtures::employee($site, null, 'suspended');
    /** @var int $suspendidoId */
    $suspendidoId = DB::table('employees')->where('uuid', $suspendido)->value('id');
    $deSuspendido = Credentials::issueFor($suspendidoId);

    return [
        'firma invalida' => $firmaInvalida->toString(),
        'key_id desconocido' => Credentials::signedWithUnknownKey()->toString(),
        'clave retirada' => $claveRetirada->toString(),
        'credencial revocada' => $revocada->toString(),
        'empleado de baja' => $deBaja->toString(),
        'persona suspendida' => $deSuspendido->toString(),
        // Y el que no tiene ni forma de payload: tiene que costar lo mismo.
        'token inexistente' => Credentials::payloadFor()->toString(),
    ];
}

it('devuelve el mismo rechazo en los siete casos y solo atribuye la tarjeta autentica retirada', function (): void {
    // RS-03 y regla dura 17, reescrita para RN-20 (F3 del dictamen del bloque
    // 18). Hacia fuera, los siete son el mismo «no»: ninguno resuelve y ninguno
    // da `employeeUuid()`, tampoco cuando el servidor sabe de quien era la
    // tarjeta. Hacia dentro, desde la 2.2.0 dos de ellos —la tarjeta revocada y
    // la de la persona de baja, ambas con FIRMA VALIDA— llevan a su titular para
    // la fila de `scan_events`; los demas no pueden atribuir nada. La misma
    // respuesta HTTP la comprueba `Tests\Feature\Attendance\ScanBeforeRevocationTest`.
    $resolver = app(CredentialResolver::class);

    $conTitular = [];

    foreach (fourRejections() as $caso => $payload) {
        $resolution = $resolver->resolve($payload);

        expect($resolution)->toBeInstanceOf(CredentialResolution::class)
            ->and($resolution->isResolved())->toBeFalse("El caso «{$caso}» no deberia resolverse.")
            ->and($resolution->employeeUuid())->toBeNull("El caso «{$caso}» ha filtrado el empleado.");

        $holder = $resolution->holder();

        if ($holder !== null) {
            $conTitular[$caso] = $holder->withdrawnAt?->format('Y-m-d H:i:s');
        }
    }

    // La revocada lleva su `revoked_at`; la de la persona de baja, ninguno: la
    // tarjeta seguia vigente y el caso de uso compara con la recepcion.
    expect($conTitular)->toBe([
        'credencial revocada' => '2026-08-15 06:00:00',
        'empleado de baja' => null,
    ]);
})->group('RS-03', 'RS-02', 'RF-QR-02', 'RF-QR-07', 'RN-20', 'RN-25');

it('no atribuye una tarjeta real revocada si su firma no verifica', function (): void {
    // F3: el resolutor busca la credencial aunque la firma falle (para igualar
    // el trabajo). El titular solo viaja con la firma VERIFICADA: con el token
    // de una tarjeta real revocada y el ultimo byte de la firma alterado, nadie.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);
    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

    $alterada = Credentials::tampered(Credentials::issueFor($employeeId, revokedReason: 'lost'))->toString();

    $resolution = app(CredentialResolver::class)->resolve($alterada);

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->employeeUuid())->toBeNull()
        ->and($resolution->holder())->toBeNull();
})->group('RN-20', 'RS-03', 'RF-QR-02');

it('ejecuta el mismo numero de consultas en todos los rechazos', function (): void {
    // La mitad estructural del tiempo constante. Un `return` temprano que
    // ahorrara una consulta en el caso «no existe» seria un canal de tiempo
    // medible, y esta prueba lo caza sin depender del reloj.
    $resolver = app(CredentialResolver::class);
    $casos = fourRejections();

    $consultas = [];

    foreach ($casos as $caso => $payload) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolver->resolve($payload);

        $consultas[$caso] = \count(DB::getRawQueryLog());
        DB::disableQueryLog();
    }

    expect(array_unique(array_values($consultas)))
        ->toHaveCount(1, 'Los rechazos no cuestan lo mismo: '.json_encode($consultas));
})->group('RS-03', 'RF-QR-02', 'RN-25');

it('hace el mismo trabajo en un rechazo que en una aceptacion', function (): void {
    // Que el rechazo cueste MENOS que la aceptacion tambien seria un canal:
    // diria si el token existe sin necesidad de mirar la respuesta. El suelo de
    // tiempo se aplica solo al rechazo justo porque el trabajo ya esta igualado.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);
    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

    $valido = Credentials::issueFor($employeeId)->toString();
    $invalido = Credentials::payloadFor()->toString();

    $resolver = app(CredentialResolver::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolver->resolve($valido);
    $aceptado = \count(DB::getRawQueryLog());

    DB::flushQueryLog();
    $resolver->resolve($invalido);
    $rechazado = \count(DB::getRawQueryLog());
    DB::disableQueryLog();

    expect($rechazado)->toBe($aceptado);
})->group('RS-03');

/**
 * La mediana de lo que tarda cada uno de los siete rechazos, en milisegundos.
 *
 * Intercaladas y no en bloques: si la maquina se ralentiza a mitad de la
 * prueba —otra prueba, el recolector, la CI— el sesgo cae por igual sobre los
 * siete casos en vez de sobre el que se estuviera midiendo.
 *
 * @return non-empty-array<string, float>
 */
function constantTimeRejectionMedians(): array
{
    $resolver = app(CredentialResolver::class);
    $casos = fourRejections();

    /** @var array<string, list<float>> $muestras */
    $muestras = array_fill_keys(array_keys($casos), []);

    for ($ronda = 0; $ronda < 12; $ronda++) {
        foreach ($casos as $caso => $payload) {
            $muestras[$caso][] = WallClockBudget::seconds(static fn () => $resolver->resolve($payload)) * 1000;
        }
    }

    /** @var non-empty-array<string, float> $medianas */
    $medianas = array_map(Samples::median(...), $muestras);

    return $medianas;
}

it('no deja ninguno de los siete rechazos por debajo del suelo de tiempo configurado', function (): void {
    // El suelo configurado —25 ms de serie— absorbe la varianza de PostgreSQL,
    // que es lo que sin el hace que un acierto de indice y un fallo de indice
    // se distingan con suficientes muestras. Es un SUELO: una maquina cargada
    // solo puede alejar la medida de el, asi que esta prueba no depende de la
    // carga y se queda en la suite de integracion.
    $suelo = Config::integer('security.rejection_floor_ms');

    expect($suelo)->toBeGreaterThan(0, 'La suite tiene el suelo de RS-03 desactivado.');

    $lentos = array_filter(constantTimeRejectionMedians(), static fn (float $mediana): bool => $mediana < $suelo * 0.9);

    expect($lentos)->toBe([], "Rechazos por debajo del suelo de {$suelo} ms: ".json_encode($lentos));
})->group('RS-03', 'RS-02', 'RF-QR-02', 'RF-QR-07');

it('consume el mismo tiempo en los siete rechazos', function (): void {
    // La comprobacion que exige el §9.4: «medir que los cuatro rechazos consumen
    // el mismo tiempo». Banda de 10 ms entre la mediana mas rapida y la mas
    // lenta. No es cero —esto corre en un contenedor compartido con
    // PostgreSQL— pero es un orden de magnitud menor que lo que hace falta para
    // distinguir dos casos con un numero razonable de muestras a traves de la
    // red. En la suite nocturna de rendimiento (D4): la banda es de reloj, y
    // una maquina cargada la ensancha sin que el producto haya cambiado.
    $medianas = constantTimeRejectionMedians();

    fwrite(STDERR, 'RS-03 rechazos (ms): '.json_encode(array_map(static fn (float $ms): float => round($ms, 2), $medianas))."\n");

    WallClockBudget::expectBelow(max($medianas) - min($medianas), 10.0, 'RS-03', 'ms');
})->group('RS-03', 'RS-02', 'RF-QR-02', 'RF-QR-07', 'performance');

// --- D15: el PIN correcto de una persona de baja (enmienda de ADR-043) ---------

/**
 * Los rechazos del PIN en el quiosco que el caso nuevo no puede distinguir,
 * cada uno con una persona distinta para que ninguno abra el bloqueo de RS-12.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function constantTimePinRejections(): array
{
    $site = WorkforceFixtures::site();
    $pinBueno = '481902';

    $persona = static function (string $status) use ($site, $pinBueno): string {
        $uuid = WorkforceFixtures::employee($site, null, $status);
        EmployeePins::issue($uuid, $pinBueno);

        return EmployeePins::codeOf($uuid);
    };

    return [
        'PIN correcto de una persona de baja' => [$persona('terminated'), $pinBueno],
        'PIN incorrecto de una persona de baja' => [$persona('terminated'), '481903'],
        'PIN incorrecto de una persona en alta' => [$persona('active'), '481903'],
        'PIN correcto de una persona suspendida' => [$persona('suspended'), $pinBueno],
        'codigo inexistente' => ['ENOEXISTE', $pinBueno],
    ];
}

it('hace las mismas consultas con el PIN correcto de una persona de baja que con cualquier otro rechazo del PIN', function (): void {
    // RS-03, regla dura 17 y la enmienda de ADR-043 (D15): las fechas del
    // periodo y la zona del centro salen de la misma consulta que el verificador
    // ya hacia para todos los codigos. Una lectura de mas solo para el acierto de
    // una persona de baja seria un oraculo para adivinar su PIN.
    $verifier = app(EmployeePinVerifier::class);
    $consultas = [];

    foreach (constantTimePinRejections() as $caso => [$codigo, $pin]) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $verification = $verifier->verify($codigo, $pin, PinOrigin::KIOSK);

        $consultas[$caso] = \count(DB::getRawQueryLog());
        DB::disableQueryLog();

        expect($verification->employeeUuid())->toBeNull("El caso «{$caso}» ha filtrado el empleado.");
    }

    expect(array_unique(array_values($consultas)))
        ->toHaveCount(1, 'Los rechazos del PIN no cuestan lo mismo: '.json_encode($consultas));
})->group('RS-03', 'RN-20', 'RN-25');
