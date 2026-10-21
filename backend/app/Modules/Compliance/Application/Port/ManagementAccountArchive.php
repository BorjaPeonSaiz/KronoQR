<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;

/**
 * Las cuentas de gestion dadas de baja que han cumplido su plazo (ADR-060,
 * tarea 11.3 B; RL-11, RGPD art. 5.1.e).
 *
 * No borra: **minimiza en sitio**. La cuenta se conserva con su `id`, su `uuid`
 * y sus roles, porque otras filas la nombran (soporte, contratos, ausencias…),
 * y pierde lo que identifica a la persona. Quien decide que cuentas y con que
 * corte es la funcion `public.management_account_anonymize_batch` de la base de
 * datos, que la aplicacion solo puede ejecutar: el adaptador no tiene `UPDATE`
 * sobre `anonymized_at` y el *trigger* lo comprueba.
 */
interface ManagementAccountArchive
{
    /**
     * Cuantas cuentas se minimizarian con ese corte. Solo el numero: el informe
     * de la simulacion no dice de quien (regla dura 21).
     */
    public function inspect(DateTimeImmutable $cutoff): RetentionTally;

    /**
     * Minimiza **un lote** —como mucho `$batchSize` cuentas— y devuelve lo que
     * hizo con cada una, para que quien llama deje su asiento en la misma
     * transaccion.
     *
     * @return list<AnonymizedManagementAccount>
     */
    public function anonymize(DateTimeImmutable $cutoff, int $batchSize): array;
}
