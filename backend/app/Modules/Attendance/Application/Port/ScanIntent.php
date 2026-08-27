<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * La **intencion declarada** por el empleado en el quiosco: los tres valores de
 * `scan_events.intent` y del esquema `ScanIntent` del contrato (ADR-024,
 * RF-AT-12).
 *
 * **En la Fase 1 se registra y no se interpreta.** El fichaje de pausa es de la
 * tarea 3.5; hasta entonces el servidor deduce la accion por el estado de la
 * jornada y `break_start`/`break_end` se comportan como `auto`. La columna
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
 * puerto {@see ScanLog}, que es quien escribe la columna. Cuando la 3.5 haga que
 * la intencion **decida** algo, sera un concepto de dominio y su sitio lo dira
 * `arquitecto-dominio`.
 */
enum ScanIntent: string
{
    /** El servidor deduce la accion por el estado de la jornada. Es el comportamiento de siempre. */
    case AUTO = 'auto';

    case BREAK_START = 'break_start';

    case BREAK_END = 'break_end';
}
