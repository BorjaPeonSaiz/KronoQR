<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;
use DateTimeImmutable;

/**
 * El estado de la licencia **en un instante concreto**, y la unica pieza que
 * decide si una funcionalidad accesoria esta disponible (RF-PD-04, RF-PD-05,
 * ADR-018, ADR-019, ADR-023).
 *
 * ## El instante se recibe, no se consulta
 *
 * Regla dura 2: aqui no se pregunta la hora a nadie. El instante llega de fuera, resuelto por el
 * puerto `Clock`, y por eso se puede probar el dia exacto de la caducidad, el
 * siguiente, y el ultimo segundo del ultimo dia sin tocar el reloj de la
 * maquina. Una licencia que caduca de forma distinta segun quien ejecute la
 * prueba no es comprobable, y la caducidad de una licencia es justo lo que
 * alguien discutira algun dia.
 *
 * ## La caducidad se compara por instante, no por dia
 *
 * `valid_until` es un instante UTC y la clave se emite con `23:59:59Z` del
 * ultimo dia. La licencia esta vigente **mientras el instante actual no sea
 * posterior** a esa marca; el segundo siguiente ya es `Expired`. Comparar por
 * fecha civil obligaria a elegir una zona horaria, y con eso la caducidad de un
 * hotel de Canarias caeria un dia antes que la del de al lado.
 *
 * ## `daysUntilExpiry`
 *
 * Dias **completos** que faltan, redondeados hacia abajo, y `0` el ultimo dia.
 * Nunca negativo: pasada la caducidad, la cifra que interesa es
 * {@see self::daysSinceExpiry()}, y mezclar las dos en un entero con signo
 * produce inevitablemente un mensaje que dice «caduca en -3 dias».
 *
 * ## Lo que este objeto NO decide
 *
 * No decide nada del registro legal. No hay ningun metodo que acepte una cadena
 * ni que hable de fichajes: solo {@see self::availabilityOf()}, que toma un
 * {@see Feature}, y en ese enum el conjunto legal no tiene caso (ADR-023).
 */
final readonly class LicenseStatus
{
    /** Segundos de un dia. Aqui no hay horario de verano: todo es UTC (regla dura 3). */
    private const int SECONDS_PER_DAY = 86_400;

    private function __construct(
        public LicenseState $state,
        public ?License $license,
        public ?LicenseRejection $rejection,
        public DateTimeImmutable $evaluatedAt,
        public int $expiryWarningDays,
    ) {}

    /**
     * No hay clave activada. El producto funciona; lo accesorio no.
     */
    public static function absent(DateTimeImmutable $now, int $expiryWarningDays): self
    {
        return new self(LicenseState::Absent, null, null, $now, $expiryWarningDays);
    }

    /**
     * Hay clave y no verifica. **Tampoco detiene nada** (regla dura 15): una
     * clave ilegible degrada exactamente igual que una ausente.
     */
    public static function unverifiable(
        LicenseRejection $rejection,
        DateTimeImmutable $now,
        int $expiryWarningDays,
    ): self {
        return new self(LicenseState::Unverifiable, null, $rejection, $now, $expiryWarningDays);
    }

    /**
     * Clave verificada: el estado sale de comparar su vigencia con el instante
     * recibido.
     *
     * `$expiryWarningDays` viene de `config/license.php` (30 de serie, decision
     * del responsable de producto del 01-09-2026) y **no es una constante de
     * dominio**: es la antelacion con la que el cliente quiere enterarse, y eso
     * es configuracion (regla dura 13). Llega ya resuelto, como los umbrales
     * legales de la regla dura 14.
     */
    public static function of(
        License $license,
        DateTimeImmutable $now,
        int $expiryWarningDays,
    ): self {
        return new self(self::stateOf($license, $now, $expiryWarningDays), $license, null, $now, $expiryWarningDays);
    }

    private static function stateOf(License $license, DateTimeImmutable $now, int $expiryWarningDays): LicenseState
    {
        if ($now < $license->validFrom) {
            return LicenseState::NotYetValid;
        }

        if ($now > $license->validUntil) {
            return LicenseState::Expired;
        }

        // El dia exacto de la caducidad sigue siendo `Valid`/`ExpiringSoon`: el
        // corte de arriba es estricto a proposito. Una licencia que caduca «el
        // 31 de marzo» vale el 31 de marzo entero.
        $remaining = self::fullDaysBetween($now, $license->validUntil);

        return $remaining <= $expiryWarningDays ? LicenseState::ExpiringSoon : LicenseState::Valid;
    }

    /**
     * Dias completos que faltan para la caducidad. `null` sin licencia
     * verificada, `0` cuando ya caduco.
     */
    public function daysUntilExpiry(): ?int
    {
        if (! $this->license instanceof License) {
            return null;
        }

        return $this->evaluatedAt > $this->license->validUntil
            ? 0
            : self::fullDaysBetween($this->evaluatedAt, $this->license->validUntil);
    }

    /**
     * Dias completos que han pasado desde la caducidad. `null` si no ha
     * caducado.
     *
     * Es la cifra del aviso posterior —«caduco hace 12 dias»— y la que hace que
     * el banner cambie de tono sin cambiar de sitio.
     */
    public function daysSinceExpiry(): ?int
    {
        if (! $this->license instanceof License || $this->state !== LicenseState::Expired) {
            return null;
        }

        return self::fullDaysBetween($this->license->validUntil, $this->evaluatedAt);
    }

    /**
     * **El unico punto donde se decide si una funcionalidad accesoria esta
     * disponible** (ADR-023).
     *
     * Dos preguntas, en este orden y no en el contrario:
     *
     *  1. ¿La licencia esta en un estado que concede? Si no, la degradacion es
     *     por licencia y lleva la fecha desde la que ocurre.
     *  2. ¿Esta la funcionalidad en `features`? Si no, no es una degradacion: es
     *     algo que el cliente no contrato, y renovar no lo arregla.
     *
     * El orden importa para el mensaje: a un cliente con la licencia caducada
     * hay que decirle «renuevala», no «esto no esta en tu plan».
     */
    public function availabilityOf(Feature $feature): FeatureAvailability
    {
        $restriction = $this->state->restriction();

        if ($restriction instanceof FeatureRestriction) {
            return FeatureAvailability::denied($feature, $restriction, $this->degradedSince());
        }

        // Si el estado concede, hay licencia: los dos estados que conceden solo
        // los produce `of()`.
        return $this->license instanceof License && $this->license->grants($feature)
            ? FeatureAvailability::granted($feature)
            : FeatureAvailability::denied($feature, FeatureRestriction::NotInPlan);
    }

    public function allows(Feature $feature): bool
    {
        return $this->availabilityOf($feature)->enabled;
    }

    /**
     * Las funcionalidades accesorias que hoy NO estan disponibles, en orden de
     * catalogo.
     *
     * Es lo que hace que la degradacion sea honesta en las tres superficies: el
     * panel la pinta, `GET /api/v1/license` la devuelve y `license:show` la
     * imprime, todas desde este mismo calculo.
     *
     * @return list<Feature>
     */
    public function degradedFeatures(): array
    {
        return array_values(array_filter(
            Feature::cases(),
            fn (Feature $feature): bool => ! $this->allows($feature),
        ));
    }

    /**
     * Desde cuando esta degradado lo accesorio, o `null` si no hay una fecha que
     * dar.
     *
     * Solo hay fecha cuando la licencia verifico y su vigencia explica la
     * degradacion. Sin clave, o con una clave ilegible, no se inventa un dia:
     * el aviso dice otra cosa.
     */
    public function degradedSince(): ?DateTimeImmutable
    {
        return match ($this->state) {
            LicenseState::Expired => $this->license?->validUntil,
            LicenseState::NotYetValid => $this->license?->validFrom,
            default => null,
        };
    }

    /**
     * Dias completos entre dos instantes, nunca negativo.
     */
    private static function fullDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return \intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), self::SECONDS_PER_DAY);
    }
}
