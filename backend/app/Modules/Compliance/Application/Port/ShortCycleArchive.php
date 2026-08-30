<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;

/**
 * Un almacen del **ciclo corto** de RL-11: 90 dias, no cuatro anos.
 *
 * Son el log tecnico y el historico de errores (doc 02 §8.2.1). Se separan del
 * registro de jornada por tres razones, y las tres importan:
 *
 * - **Plazo distinto**, que ademas no es legal sino operativo: lo fija la
 *   instalacion (`ERROR_HISTORY_RETENTION_DAYS`), no el convenio.
 * - **Sin valor probatorio.** Nada de lo que hay aqui sostiene una nomina ni una
 *   inspeccion; si se pierde, no se pierde un derecho.
 * - **Sin datos personales** (regla dura 21). Es la condicion para que el
 *   historico de errores pueda viajar en el paquete de diagnostico.
 *
 * Por eso su purga no exige la confirmacion del responsable ni deja asiento
 * propio en `audit_log`: se ejecuta en su propio ciclo y sus recuentos van al
 * informe.
 */
interface ShortCycleArchive
{
    public function scope(): RetentionScope;

    /** Que se purgaria, sin tocar nada. */
    public function inspect(DateTimeImmutable $cutoff): RetentionTally;

    /** Lo purga y devuelve lo que se llevo. */
    public function purge(DateTimeImmutable $cutoff, int $batchSize): RetentionTally;
}
