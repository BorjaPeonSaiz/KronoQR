<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;

/**
 * Donde se anota y se lee por donde va cada exportacion en curso
 * (**V5-PA-4**).
 *
 * ## Fuera de la fila, a proposito
 *
 * La exportacion para la Inspeccion escribe dentro de **una sola transaccion**
 * —la lectura tiene que ser un instante y el cursor de servidor la exige—, y
 * un avance escrito en `report_exports` desde dentro no lo veria nadie hasta
 * el final. Ademas, el avance no es un hecho del registro: no tiene que
 * sobrevivir a nada ni ir en ninguna copia. Lo guarda la cache y caduca solo.
 *
 * ## Nunca rompe
 *
 * Quien implementa **se traga sus propios fallos**: una cache caida no puede
 * convertir una exportacion que se esta generando bien en una fallida, ni una
 * lista de exportaciones en un `503`. Lo peor que puede pasar es que la
 * pantalla enseñe la barra sin porcentaje.
 */
interface ReportExportProgressBoard
{
    public function record(string $uuid, ReportExportProgress $progress): void;

    /**
     * @param  list<string>  $uuids
     * @return array<string, ReportExportProgress> Solo las que tienen avance anotado.
     */
    public function of(array $uuids): array;

    public function forget(string $uuid): void;
}
