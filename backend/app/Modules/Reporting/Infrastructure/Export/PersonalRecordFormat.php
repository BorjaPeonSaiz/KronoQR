<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

/**
 * Los dos formatos del registro propio (`GET /api/v1/me/export?format=`,
 * RF-ID-05, RL-05).
 *
 * No decide nada por si mismo: es la clave con la que {@see PersonalRecordLayout}
 * elige, sobre **una sola** lista de columnas y valores, que columnas enseña cada
 * formato, con que rotulos y como se escribe el tipo de fila.
 */
enum PersonalRecordFormat
{
    /** La descarga para una maquina o una hoja de calculo: lleva todas las columnas. */
    case Csv;

    /** El documento sellado que una persona presenta ante un tercero. */
    case Pdf;
}
