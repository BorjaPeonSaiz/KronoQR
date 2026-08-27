<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;

/*
 * **El orden del lote** — nivel unitario de la fila «escritura del quiosco» del
 * doc 02 §9.5, y el escenario ineludible del §9.4: *«lote desordenado: entrada y
 * salida encoladas, enviadas en orden inverso, procesadas correctamente por
 * `occurred_at`»*.
 *
 * Por que esto es una prueba unitaria y no una de feature: la regla no necesita
 * base de datos, ni HTTP, ni un empleado. Es una lista y un criterio. Si el orden
 * se decidiera dentro del controlador o del bucle del caso de uso, comprobarlo
 * exigiria levantar el mundo entero, y una regla que solo se puede probar caro es
 * una regla que se acaba probando poco.
 *
 * Lo que hay detras: la cola offline reintenta con retroceso exponencial
 * (RF-KI-04), asi que sus elementos NO llegan en el orden en que ocurrieron.
 * Procesados en orden de llegada, una salida antes que su entrada produce un
 * cierre sin turno abierto —que `WorkDay` lee como una entrada nueva— seguido de
 * otra apertura: una jornada inventada con dos horas reales, que es la peor clase
 * de dato incorrecto porque nada en el resultado parece raro.
 */

/**
 * Un escaneo del lote, con lo minimo para poder ordenarlo.
 */
function escaneoDeLote(string $scanId, string $occurredAt): RegisterScanCommand
{
    return new RegisterScanCommand(
        scanId: $scanId,
        qrPayload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        occurredAt: new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        deviceId: 1,
        deviceUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        origin: ScanOrigin::QR_KIOSK,
        intent: ScanIntent::AUTO,
    );
}

/**
 * @return list<string>
 */
function idsEnOrden(ScanBatch $batch): array
{
    return array_map(static fn (RegisterScanCommand $scan): string => $scan->scanId, $batch->scans);
}

it('ordena el lote por occurred_at y no por orden de llegada', function (): void {
    // El caso literal del §9.4: la salida llega primero porque su reintento gano
    // la carrera, y la entrada detras.
    $salida = escaneoDeLote('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T14:03:12Z');
    $entrada = escaneoDeLote('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31Z');

    $batch = ScanBatch::of([$salida, $entrada]);

    expect(idsEnOrden($batch))->toBe([$entrada->scanId, $salida->scanId]);
})->group('RF-KI-04', 'RF-AT-09');

it('mantiene el orden cuando el lote ya venia ordenado', function (): void {
    // El control positivo: si ordenar cambiara un lote correcto, la prueba de
    // arriba pasaria igual con una implementacion que simplemente invierte.
    $primero = escaneoDeLote('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31Z');
    $segundo = escaneoDeLote('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T14:03:12Z');

    expect(idsEnOrden(ScanBatch::of([$primero, $segundo])))
        ->toBe([$primero->scanId, $segundo->scanId]);
})->group('RF-KI-04');

it('ordena un lote de cincuenta barajado', function (): void {
    // Cincuenta es el tamano real del lote (doc 02 §6). Un lote de dos puede
    // ordenarlo por accidente casi cualquier cosa; uno de cincuenta barajado, no.
    $scans = [];

    for ($minute = 0; $minute < 50; $minute++) {
        $scans[] = escaneoDeLote(
            sprintf('0199f0c2-1f4a-7c3e-9b21-%012d', $minute),
            sprintf('2026-08-14T%02d:%02d:00Z', intdiv($minute, 60) + 5, $minute % 60),
        );
    }

    $barajado = $scans;
    shuffle($barajado);

    $ordenados = idsEnOrden(ScanBatch::of($barajado));

    expect($ordenados)->toBe(array_map(
        static fn (RegisterScanCommand $scan): string => $scan->scanId,
        $scans,
    ));
})->group('RF-KI-04');

it('desempata por scan_id cuando dos escaneos comparten instante', function (): void {
    // Dos escaneos pueden compartir `occurred_at` al milisegundo —dos personas en
    // dos quioscos, o un reloj de poca resolucion—. Sin desempate, el orden lo
    // decidiria la implementacion de `usort` y el MISMO envio podria procesarse de
    // dos formas distintas. Se desempata por `scan_id`, que es un UUID v7 y por
    // tanto ordenable por el momento en que el quiosco lo genero.
    $tarde = escaneoDeLote('0199f0c2-ffff-7fff-bfff-ffffffffffff', '2026-08-14T05:58:31Z');
    $pronto = escaneoDeLote('0199f0c2-0000-7000-8000-000000000000', '2026-08-14T05:58:31Z');

    expect(idsEnOrden(ScanBatch::of([$tarde, $pronto])))
        ->toBe([$pronto->scanId, $tarde->scanId])
        // Y es estable: el mismo lote en el orden contrario da el mismo resultado.
        ->and(idsEnOrden(ScanBatch::of([$pronto, $tarde])))
        ->toBe([$pronto->scanId, $tarde->scanId]);
})->group('RF-KI-04');

it('distingue instantes que solo difieren en microsegundos', function (): void {
    // El reloj del quiosco tiene resolucion de milisegundos y dos escaneos
    // consecutivos de la misma persona pueden caer en el mismo segundo. Comparar
    // por segundos enteros los dejaria en manos del desempate por `scan_id`, que
    // es correcto pero no es el criterio: el instante manda.
    $despues = escaneoDeLote('0199f0c2-0000-7000-8000-000000000000', '2026-08-14T05:58:31.900000Z');
    $antes = escaneoDeLote('0199f13a-ffff-7fff-bfff-ffffffffffff', '2026-08-14T05:58:31.100000Z');

    expect(idsEnOrden(ScanBatch::of([$despues, $antes])))
        ->toBe([$antes->scanId, $despues->scanId]);
})->group('RF-KI-04', 'RF-AT-09');

it('expone el escaneo mas antiguo, que es el que mide el retraso de sincronizacion', function (): void {
    // `sync_delay_seconds` (§8.2) se mide sobre el elemento mas antiguo: es lo que
    // dice si la cola esta drenando o lleva media jornada atascada.
    $batch = ScanBatch::of([
        escaneoDeLote('0199f13a-7c22-7b41-9e88-0c4d5e6f7a81', '2026-08-14T14:03:12Z'),
        escaneoDeLote('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', '2026-08-14T05:58:31Z'),
    ]);

    expect($batch->earliest()->occurredAt->format('H:i:s'))->toBe('05:58:31')
        ->and($batch->size())->toBe(2);
})->group('RF-KI-04');

it('rechaza un lote vacio', function (): void {
    // Un envio sin elementos no es «un lote que no cambia nada»: es un cliente
    // roto. El contrato lo declara con `minItems: 1` y el `FormRequest` lo para
    // antes; esto cierra la puerta tambien para quien construya el lote desde
    // codigo.
    expect(static fn (): ScanBatch => ScanBatch::of([]))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-KI-04');
