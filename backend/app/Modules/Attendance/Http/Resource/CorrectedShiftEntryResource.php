<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resource;

use App\Modules\Attendance\Application\UseCase\CorrectedShift;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `CorrectedShiftEntry` del contrato (RF-PA-04,
 * ADR-035).
 *
 * **Los dos identificadores, siempre los dos.** `shift_entry_uuid` es la version
 * resultante y `superseded_shift_entry_uuid` la que acaba de dejar de serlo. En
 * un `PATCH` el primero es **distinto** del que el cliente envio, porque cada
 * version es una fila y `shift_entries.uuid` es unico: sin decirselo, el panel
 * seguiria enviando el identificador viejo y recibiria `409` sin entender por
 * que.
 *
 * **Ningun nombre de persona sale por aqui.** Solo `employee_uuid`, que es lo
 * que la API usa en todas partes: quien pinta el nombre lo resuelve con la ficha
 * del empleado, que tiene su propia autorizacion.
 *
 * **`daily_total_minutes` es el total recalculado, no un delta.** Es el unico
 * numero de esta respuesta que puede **bajar** respecto de antes de la peticion,
 * y por eso viaja: quien anula un tramo tiene que ver en el acto que el dia se
 * queda con menos minutos (RN-06, regla dura 7).
 *
 * @property-read CorrectedShift $resource
 */
final class CorrectedShiftEntryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CorrectedShift $corrected */
        $corrected = $this->resource;

        return [
            'employee_uuid' => $corrected->employeeUuid,
            'work_date' => $corrected->workDate,
            'action' => $corrected->action->value,
            'shift_entry_uuid' => $corrected->shiftEntryUuid,
            'superseded_shift_entry_uuid' => $corrected->supersededShiftEntryUuid,
            'version' => $corrected->version,
            'status' => $corrected->status->value,
            'clocked_in_at' => self::utc($corrected->clockedInAt),
            'clocked_out_at' => $corrected->clockedOutAt === null ? null : self::utc($corrected->clockedOutAt),
            'daily_total_minutes' => $corrected->dailyTotalMinutes,
        ];
    }

    /**
     * ISO-8601 en UTC con microsegundos, el formato del esquema `UtcTimestamp`.
     *
     * Los seis decimales no son adorno: `shift_entries` guarda con precision de
     * microsegundo y una respuesta redondeada al segundo no seria la hora
     * escrita, que en un registro con valor legal no es aceptable.
     */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.u\Z');
    }
}
