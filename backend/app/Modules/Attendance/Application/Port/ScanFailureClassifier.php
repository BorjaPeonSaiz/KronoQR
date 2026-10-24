<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use Throwable;

/**
 * **Si un fallo al procesar un fichaje cuenta para descartarlo** (RN-22,
 * ADR-061 §2).
 *
 * Es una **lista de exclusion**: cuenta todo lo que no se reconoce como
 * transitorio o como un desenlace. Una lista de inclusion dejaria atascado para
 * siempre el veneno de una clase no prevista, que es justo el caso que motiva
 * ADR-061; el riesgo contrario —contar algo transitorio que no se previo— lo
 * cubren la ventana de tiempo y el cupo por quiosco de
 * {@see UnprocessableScanPolicy}.
 *
 * **No cuenta** (devuelve `false`), recorriendo toda la cadena `previous`:
 *
 * - una dependencia caida segun ADR-059 §1 (PostgreSQL o Redis), **con la misma
 *   clasificacion** que el `503` de la instalacion, no una copia;
 * - la contencion (`40001`, `40P01`, `55P03`) y las clases SQLSTATE `08`
 *   (conexion), `53` (recursos), `57` (cancelacion) y `58` (E/S);
 * - un rechazo de negocio (`BusinessRejection`): la carrera de RN-01 tras sus
 *   reintentos, el reenvio idempotente (`ScanAlreadyRecorded`) y cualquier
 *   otra regla que dice que no;
 * - el `23505` del `UNIQUE` de `scan_events.scan_id`, que es la carrera de la
 *   idempotencia (regla dura 8).
 *
 * Los rechazos de RS-03, RN-18 y RN-20, el anti-rebote y el reenvio idempotente
 * no llegan aqui: son resultados, no excepciones.
 *
 * Es conocimiento de infraestructura (SQLSTATE, clases de Redis), por eso es un
 * puerto y vive detras de un adaptador.
 */
interface ScanFailureClassifier
{
    public function counts(Throwable $failure): bool;
}
