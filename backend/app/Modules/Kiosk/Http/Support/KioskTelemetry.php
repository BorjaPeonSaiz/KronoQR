<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Support;

use App\Modules\Kiosk\Application\Query\DeviceFleetView;
use App\Modules\Kiosk\Application\UseCase\HeartbeatOutcome;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de los dos endpoints de operacion del quiosco: el latido y
 * la lista de dispositivos del panel (doc 02 §8.1, **RF-PA-07**).
 *
 * Mismo sitio y mismo motivo que el resto de las telemetrias del producto: en el
 * borde, para que el `trace_id` de `traceparent` sea el padre del span y para
 * que el caso de uso se quede orquestando reglas sin un `try/finally` de
 * medicion alrededor.
 *
 * ## Que se registra: `device_id` y cifras. Nada mas
 *
 * **Ni un nombre** (regla dura 21). Un quiosco se llama «Recepcion» y eso es el
 * nombre de un sitio del hotel, pero estos logs viajan a Loki y al paquete de
 * diagnostico (ADR-020), asi que se identifica por `device_id` —el UUID
 * publico— exactamente igual que en `scan_events` y en `error_events`.
 *
 * **Ni la huella del codigo de servicio, ni si hay codigo.** Un log que dijera
 * `service_code: configured` en cada latido seria un inventario de que
 * instalaciones tienen la pantalla de diagnostico protegida, escrito en el sitio
 * que sale de la instalacion. Que lo hay o no lo dice `product:doctor`, donde
 * sirve para algo (tarea 3.3, decision 6).
 *
 * La bateria si entra: es una cifra de un aparato, no de una persona, y es la
 * mitad del diagnostico de «la tablet se apago a media tarde».
 *
 * ## Los dos niveles, y por que son distintos
 *
 * El latido es `debug`: ocurre cada minuto y por cada tablet del hotel, y a
 * `info` seria el mayor productor de lineas de la instalacion sin decir nada
 * nuevo. La lista del panel es `info`, que es lo que hace una pantalla al
 * abrirse.
 *
 * ## Medir no puede romper un latido (regla dura 19)
 *
 * {@see SpanScope} envuelve todo en `try/catch` y devuelve un proveedor inerte
 * si el SDK no esta configurado, que es la instalacion de la mayoria de los
 * clientes. Un exportador de trazas caido no puede apagar la unica senal de que
 * una tablet sigue viva.
 */
final readonly class KioskTelemetry
{
    private const string TRACER = 'kronoqr.kiosk';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * `POST /api/v1/kiosk/heartbeat`.
     *
     * @param  callable(): HeartbeatOutcome  $beat
     */
    public function measureHeartbeat(string $deviceUuid, ?int $batteryLevel, callable $beat): HeartbeatOutcome
    {
        $span = SpanScope::start(self::TRACER, 'kiosk.record_heartbeat', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $outcome = $beat();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $span->end([
            'device.id' => $deviceUuid,
            'kiosk.client_errors_accepted' => $outcome->clientErrorsAccepted,
            'kiosk.battery_level' => $batteryLevel,
        ]);

        $this->logger->debug('kiosk.heartbeat_recorded', [
            'trace_id' => $span->traceId(),
            'device_id' => $deviceUuid,
            'battery_level' => $batteryLevel,
            'client_errors_accepted' => $outcome->clientErrorsAccepted,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $outcome;
    }

    /**
     * `GET /api/v1/devices`.
     *
     * Cuenta cuantos quioscos salen y cuantos de ellos no estan correctos, que
     * es lo que permite ver en el log cuando una instalacion empezo a tener
     * tablets caidas sin tener que reconstruirlo desde las metricas. **Ni un
     * nombre ni un `uuid`**: aqui se habla de la flota, no de un quiosco.
     *
     * @param  callable(): DeviceFleetView  $list
     */
    public function measureDeviceList(callable $list): DeviceFleetView
    {
        $span = SpanScope::start(self::TRACER, 'kiosk.list_devices', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $fleet = $list();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $problems = \count($fleet->health->problems());

        $span->end([
            'kiosk.devices' => \count($fleet->devices),
            'kiosk.devices_with_findings' => $problems,
        ]);

        $this->logger->info('kiosk.devices_listed', [
            'trace_id' => $span->traceId(),
            'devices' => \count($fleet->devices),
            'devices_with_findings' => $problems,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $fleet;
    }
}
