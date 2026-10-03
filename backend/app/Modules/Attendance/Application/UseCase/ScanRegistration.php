<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterScanCommand;

/**
 * Registrar **un** escaneo: lo que el lote de la cola offline repite elemento a
 * elemento (RF-KI-04).
 *
 * Existe solo para que {@see RegisterScanBatchHandler} dependa de un contrato y
 * no de la clase concreta {@see RegisterScanHandler}, que es `final`: la regla
 * de RN-21 —tras el primer elemento no procesado, el resto del lote **no llega
 * al caso de uso**— es una regla del orquestador, y se prueba sin base de datos
 * con un doble que cuenta cuantas veces se le llamo (doc 02 §9.5).
 */
interface ScanRegistration
{
    public function handle(RegisterScanCommand $command): RegisterScanResult;
}
