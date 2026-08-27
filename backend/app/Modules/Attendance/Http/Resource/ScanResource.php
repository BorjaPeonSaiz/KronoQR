<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resource;

use App\Modules\Attendance\Application\UseCase\RegisterScanResult;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `POST /api/v1/scan`: los esquemas `ScanAccepted` y
 * `ScanDebounced` del contrato.
 *
 * ## Solo lo que cabe en la pantalla de confirmacion
 *
 * RF-AT-05 pide nombre, accion, hora y acumulado, y eso es **todo** lo que sale
 * de aqui. No hay `employee_uuid`, ni `employee_code`, ni departamento, ni
 * identificador de tramo: un token de quiosco vive en una tablet colgada de una
 * pared, y si esta respuesta devolviera identificadores, robar el token seria
 * una forma de reconstruir la plantilla del hotel (RS-04, doc 02 §7.3). El
 * contrato lo blinda con `additionalProperties: false` y hay una prueba de
 * contrato que enumera los campos permitidos.
 *
 * **El nombre va en su forma minima** —nombre de pila e inicial del primer
 * apellido—, que es exactamente lo que devuelve `roster:read`. Lo decide
 * `Workforce` al construir el `EmployeeSnapshot`, en un solo sitio.
 *
 * ## Lo que este Resource NO puede dejar salir
 *
 * `RegisterScanResult` lleva dentro el desenlace detallado —`rejected_revoked`,
 * `rejected_signature`— porque la metrica y el log lo necesitan (doc 02 §8.2).
 * **Ese campo no se serializa nunca.** El camino de rechazo ni siquiera pasa por
 * aqui: tiene su propia respuesta, generica y sin hueco donde alojar una causa
 * (regla dura 17, RS-03).
 *
 * ## Dos formas y no una con bandera
 *
 * El anti-rebote no lleva `work_date` —no se creo ningun tramo que atribuir a
 * una jornada— y si lleva `last_accepted_at`, que es lo que permite al quiosco
 * decir «ya has fichado hace unos segundos». Una sola forma con un booleano
 * `debounced` habria dejado que un cliente que no lo lee enseñara «Entrada
 * 07:02» por un fichaje que no ocurrio, y el empleado se iria convencido de
 * haber fichado dos veces (ADR-031).
 *
 * @property-read RegisterScanResult $resource
 */
final class ScanResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RegisterScanResult $scan */
        $scan = $this->resource;

        $body = [
            'scan_id' => $scan->scanId,
            'action' => $scan->isDebounced() ? 'debounced' : $scan->result->value,
            'employee_display_name' => $scan->employeeDisplayName,
        ];

        if (! $scan->isDebounced()) {
            // No es «la fecha de occurred_at»: un turno de 22:00 a 06:00 es un
            // unico tramo atribuido a la jornada de su hora de inicio (RN-05,
            // ADR-006, regla dura 4). El quiosco la muestra para que el
            // acumulado no resulte incomprensible en el turno de noche.
            $body['work_date'] = $scan->workDate;
        }

        $body['occurred_at'] = $this->instant($scan->occurredAt);
        $body['recorded_at'] = $this->instant($scan->recordedAt);
        $body['worked_minutes'] = $scan->workedMinutes;

        if ($scan->isDebounced() && $scan->lastAcceptedAt instanceof DateTimeImmutable) {
            $body['last_accepted_at'] = $this->instant($scan->lastAcceptedAt);
        }

        return $body;
    }

    /**
     * Sufijo `Z` y milisegundos, la forma del esquema `UtcTimestamp`.
     *
     * `recorded_at` conserva los decimales porque el quiosco los usa para medir
     * su propio desfase de reloj y avisar, que es la mitad de cliente de
     * RF-AT-10; redondear al segundo perderia precision justo en el dato que
     * sirve para diagnosticar un reloj que se va.
     */
    private function instant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.v\Z');
    }
}
