<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use DateTimeImmutable;

/**
 * Publica lo que hay pendiente de purgar y cuando se purgo por ultima vez
 * (doc 02 §8.2).
 *
 * **La metrica interesante es la de la simulacion**, no la de la ejecucion: dice
 * cuanto dato vencido sigue en la base porque nadie ha confirmado la purga. Un
 * producto que solo publicara «purgas ejecutadas» dejaria en silencio el caso
 * normal —que es que no se ejecute ninguna— y RL-02 se incumpliria sin que
 * ninguna serie cambiara.
 *
 * `..._timestamp_seconds` permite que una regla `absent()` u `older_than`
 * descubra que la propuesta programada dejo de correr, que es el fallo que nadie
 * nota.
 */
interface RetentionMetrics
{
    public function recordRun(RetentionReport $report, DateTimeImmutable $at): void;
}
