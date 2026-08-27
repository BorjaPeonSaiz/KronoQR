<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RS-03 y regla dura 17: **los cuatro rechazos son indistinguibles desde
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
 * Los cuatro rechazos del §5.2, cada uno con su payload.
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

    return [
        'firma invalida' => $firmaInvalida->toString(),
        'key_id desconocido' => Credentials::signedWithUnknownKey()->toString(),
        'credencial revocada' => $revocada->toString(),
        'empleado de baja' => $deBaja->toString(),
        // Y el que no tiene ni forma de payload: tiene que costar lo mismo.
        'token inexistente' => Credentials::payloadFor()->toString(),
    ];
}

it('devuelve exactamente el mismo desenlace en los cinco rechazos', function (): void {
    $resolver = app(CredentialResolver::class);

    foreach (fourRejections() as $caso => $payload) {
        $resolution = $resolver->resolve($payload);

        expect($resolution)->toBeInstanceOf(CredentialResolution::class)
            ->and($resolution->isResolved())->toBeFalse("El caso «{$caso}» no deberia resolverse.")
            // Lo unico que sale hacia arriba es «no». El empleado no se filtra
            // ni siquiera cuando el servidor sabe de quien era la tarjeta.
            ->and($resolution->employeeUuid())->toBeNull("El caso «{$caso}» ha filtrado el empleado.");
    }
})->group('RS-03', 'RF-QR-02');

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
})->group('RS-03', 'RF-QR-02');

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

it('consume el mismo tiempo en los cinco rechazos', function (): void {
    // La comprobacion que exige el §9.4: «medir que los cuatro rechazos consumen
    // el mismo tiempo». El suelo configurado —25 ms de serie— absorbe la
    // varianza de PostgreSQL, que es lo que sin el hace que un acierto de indice
    // y un fallo de indice se distingan con suficientes muestras.
    $resolver = app(CredentialResolver::class);
    $casos = fourRejections();
    $suelo = Config::integer('identity.credentials.rejection_floor_ms');

    expect($suelo)->toBeGreaterThan(0, 'La suite tiene el suelo de RS-03 desactivado.');

    /** @var array<string, list<float>> $muestras */
    $muestras = array_fill_keys(array_keys($casos), []);

    // Intercaladas y no en bloques: si la maquina se ralentiza a mitad de la
    // prueba —otra prueba, el recolector, la CI— el sesgo cae por igual sobre
    // los cinco casos en vez de sobre el que se estuviera midiendo.
    for ($ronda = 0; $ronda < 12; $ronda++) {
        foreach ($casos as $caso => $payload) {
            $inicio = hrtime(true);
            $resolver->resolve($payload);
            $muestras[$caso][] = (hrtime(true) - $inicio) / 1_000_000;
        }
    }

    /** @var non-empty-array<string, float> $medianas */
    $medianas = array_map(median(...), $muestras);

    foreach ($medianas as $caso => $mediana) {
        expect($mediana)->toBeGreaterThanOrEqual(
            $suelo * 0.9,
            "El caso «{$caso}» ha tardado {$mediana} ms, por debajo del suelo de {$suelo} ms."
        );
    }

    // Banda de 10 ms entre la mediana mas rapida y la mas lenta. No es cero
    // —esto corre en un contenedor compartido con PostgreSQL— pero es un orden
    // de magnitud menor que lo que hace falta para distinguir dos casos con un
    // numero razonable de muestras a traves de la red.
    $dispersion = max($medianas) - min($medianas);

    expect($dispersion)->toBeLessThan(
        10.0,
        'Los rechazos se distinguen por tiempo: '.json_encode(array_map(
            static fn (float $ms): float => round($ms, 2),
            $medianas,
        ))
    );
})->group('RS-03', 'RF-QR-02');

/**
 * Mediana y no media: una sola muestra lenta —el planificador del contenedor, un
 * `checkpoint` de PostgreSQL— desplazaria la media y haria fallar la prueba sin
 * que hubiera ningun canal de tiempo.
 *
 * @param  list<float>  $valores
 */
function median(array $valores): float
{
    sort($valores);

    $mitad = \count($valores) >> 1;

    return \count($valores) % 2 === 1
        ? $valores[$mitad]
        : ($valores[$mitad - 1] + $valores[$mitad]) / 2;
}
