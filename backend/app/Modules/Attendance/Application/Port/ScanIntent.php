<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;

/**
 * La **intencion declarada** por el empleado en el quiosco: los tres valores de
 * `scan_events.intent` y del esquema `ScanIntent` del contrato (ADR-024,
 * RF-AT-12).
 *
 * **Desde la tarea 3.5 el servidor la honra siempre que pueda** (decision 1 de
 * la ficha): este o no activado el fichaje de pausa en la instalacion, porque
 * ese ajuste gobierna la pantalla del quiosco y la evaluacion de RN-12, no la
 * verdad de lo que la persona declaro. Quien decide es `ScanIntentPolicy`; en
 * la Fase 1 la columna se escribia sin interpretarse. La columna
 * existe desde la tarea 1.3 por un motivo concreto: el mismo campo tiene que
 * existir en la cola offline del quiosco, y **cambiar el esquema de IndexedDB
 * con la cola cargada en produccion obliga a migrar peticiones pendientes de
 * fichaje, que son registro legal sin escribir**.
 *
 * Existe como enum y no como cadena para que el valor que llega del contrato,
 * el que se valida y el que se escribe en la columna sean el mismo dato: entre
 * el `FormRequest`, el comando y el `INSERT` hay tres sitios donde una cadena
 * puede escribirse mal y solo el CHECK de PostgreSQL lo veria.
 *
 * Vive junto a {@see ScanResult} y por el mismo motivo: es vocabulario del
 * puerto {@see ScanLog}, que es quien escribe la columna.
 *
 * **La tarea 3.5 hizo que la intencion decida** (ADR-024, RF-AT-12), asi que el
 * concepto subio al dominio como {@see DeclaredIntent} y este enum se queda
 * donde estaba, con un trabajo mas modesto y bien delimitado: es la forma que
 * tiene el valor **en el contrato y en la columna**, y {@see declared()} es el
 * unico puente entre las dos. Fundirlos en uno obligaria al dominio a nombrar
 * `Application\Port\`, que es la arista que ADR-025 no tiene y que Deptrac
 * rechaza.
 */
enum ScanIntent: string
{
    /** El servidor deduce la accion por el estado de la jornada. Es el comportamiento de siempre. */
    case AUTO = 'auto';

    case BREAK_START = 'break_start';

    case BREAK_END = 'break_end';

    /**
     * La misma intencion en el vocabulario del dominio, que es quien decide con
     * ella (`ScanIntentPolicy`, RF-AT-12).
     *
     * Un `match` explicito y no un `DeclaredIntent::from($this->value)`: si un
     * dia los dos enums dejaran de tener los mismos casos, esto no compilaria
     * en lugar de fallar en tiempo de ejecucion dentro del camino de fichaje.
     */
    public function declared(): DeclaredIntent
    {
        return match ($this) {
            self::AUTO => DeclaredIntent::AUTO,
            self::BREAK_START => DeclaredIntent::BREAK_START,
            self::BREAK_END => DeclaredIntent::BREAK_END,
        };
    }
}
