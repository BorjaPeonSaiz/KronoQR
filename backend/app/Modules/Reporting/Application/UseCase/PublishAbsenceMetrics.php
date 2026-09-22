<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\AbsenceCensusReader;
use App\Modules\Reporting\Application\Port\AbsenceMetrics;
use App\Modules\Reporting\Domain\ValueObject\AbsenceCategory;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use DateTimeZone;

/**
 * Publica `absences_current{type}` del cuadro «Negocio» (doc 02 §8.2, decision 8
 * de la ficha 3.10, RF-GP-04).
 *
 * ```
 * absences_current{type}   gauge
 * ```
 *
 * ## HOY, en la fecha civil del centro
 *
 * No «los que empiezan hoy» ni «los de la semana»: quien tiene **ahora** una
 * ausencia activa que cubre el dia de hoy. Y el dia lo decide la zona del centro
 * (ADR-040), no la del servidor: la tarea corre de madrugada en UTC, y en Madrid
 * a esa hora ya es el dia siguiente en invierno y en verano. Con `CURRENT_DATE`
 * la serie contaria unas noches un dia y otras el anterior.
 *
 * El instante entra por el puerto `Clock` (regla dura 2). Sin eso, la prueba de
 * esta metrica dependeria del dia en que se ejecute la suite.
 *
 * ## Las cuatro etiquetas, tambien las que valen cero
 *
 * Lo completa {@see AbsenceCategory::completeCounts()}. Una serie que desaparece
 * es indistinguible de una que nunca tuvo nada, y «hoy no hay ninguna baja
 * medica» es justo lo que se mira.
 *
 * ## No es una divulgacion de datos personales
 *
 * Al contrario que {@see PublishComplianceMetrics}, esto **no** reutiliza una
 * consulta de pantalla ni escribe asiento en `audit_log`: lo que lee son cuatro
 * recuentos sin sujeto, y ni el puerto ni la serie pueden nombrar a nadie (RS-05
 * mide accesos a datos de personas identificadas; regla dura 21). Escribir un
 * asiento diario por contar cuantas personas hay de vacaciones seria ruido en el
 * trail que la Inspeccion tiene que poder leer.
 *
 * ## Sin centro no hay metrica
 *
 * Antes de la puesta en marcha (RF-PD-03) no hay centro con el que resolver la
 * zona. Se devuelve `false` y quien llama lo dice por su salida.
 */
final readonly class PublishAbsenceMetrics
{
    public function __construct(
        private AbsenceCensusReader $census,
        private AbsenceMetrics $metrics,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    /**
     * @return bool `false` si todavia no hay centro de trabajo.
     */
    public function handle(): bool
    {
        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            return false;
        }

        $now = $this->clock->now();
        $today = $now->setTimezone(new DateTimeZone($site->timezone))->format('Y-m-d');

        $this->metrics->publish(
            byType: AbsenceCategory::completeCounts($this->census->activeOn($today)),
            onDate: $today,
            at: $now,
        );

        return true;
    }
}
