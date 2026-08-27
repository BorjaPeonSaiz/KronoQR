<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Exception;

use RuntimeException;

/**
 * Ese `scan_id` ya estaba en `scan_events`: el escaneo es un **reenvio**
 * (RF-AT-07, regla dura 8).
 *
 * **No es un error y no sale nunca del caso de uso.** Es la senal interna que
 * deshace la transaccion en curso —el tramo que se acababa de escribir en ella
 * no debe quedarse— para despues reconstruir y devolver la respuesta original.
 * Un reenvio desde la cola offline es funcionamiento normal: el quiosco
 * reintenta con backoff ante fallo de red (RF-KI-04) y no puede saber si su
 * peticion llego antes de que se cortara.
 *
 * Se lanza y no se devuelve porque hace falta abortar una transaccion abierta
 * desde dentro de su clausura, que es exactamente para lo que sirve una
 * excepcion. La captura esta a tres lineas de distancia, en el mismo fichero
 * que la lanza.
 */
final class ScanAlreadyRecorded extends RuntimeException
{
    public function __construct(public readonly string $scanId)
    {
        parent::__construct('Scan '.$scanId.' was already recorded (RF-AT-07).');
    }
}
