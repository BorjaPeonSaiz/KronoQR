<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;

/**
 * Las fichas de personas dadas de baja que han cumplido su plazo (ADR-060,
 * tarea 11.3 A; RL-11, RGPD art. 5.1.e).
 *
 * No borra la ficha: **la minimiza en sitio**. Lo que identifica a la persona
 * desaparece; su `uuid`, su centro, su departamento y sus fechas se quedan,
 * porque otras filas la apuntan. Sus contratos, ausencias y sesiones del portal
 * si se borran. Quien decide que fichas y con que corte es la funcion
 * `public.personnel_record_anonymize_batch` de la base de datos.
 */
interface PersonnelRecordArchive
{
    /** Cuantas fichas se minimizarian con ese corte. Solo el numero. */
    public function inspect(DateTimeImmutable $cutoff): RetentionTally;

    /**
     * Minimiza **un lote** y devuelve lo que hizo con cada ficha, para que
     * quien llama deje su asiento en la misma transaccion.
     *
     * @return list<AnonymizedPersonnelRecord>
     */
    public function anonymize(DateTimeImmutable $cutoff, int $batchSize): array;

    /**
     * Borra el fichero de la foto de una ficha ya minimizada, **despues** del
     * `COMMIT`. `false` si no se pudo: la ficha ya no la apunta y el fichero
     * queda huerfano, asi que quien llama lo deja visible.
     */
    public function forgetPhoto(string $path): bool;
}
