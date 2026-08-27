<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\InvalidDebounceWindow;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use DateTimeImmutable;

/**
 * El **periodo de gracia anti-rebote** de RF-AT-06: *«un segundo escaneo del
 * mismo empleado dentro de la ventana no crea evento y muestra aviso
 * informativo»*.
 *
 * Es una regla de negocio y por eso vive en `Domain/` y no en el handler. Lo
 * que decide no es tecnico: dice que dos lecturas de la misma tarjeta separadas
 * por segundos son **un solo gesto de una persona**, no una entrada seguida de
 * una salida. Sin ella, quien pasa la tarjeta dos veces por costumbre —o el
 * lector que decodifica el mismo QR dos veces— cierra el turno que acaba de
 * abrir y se va con una jornada de cero minutos.
 *
 * **Recibe la ventana por constructor, ya resuelta** (regla dura 14). No hay
 * ninguna constante aqui: el valor de serie —60 s— vive en
 * `installation_settings.ATTENDANCE_DEBOUNCE_SECONDS`, lo siembra la migracion
 * de la tarea 1.3 y lo sirve `OperationalSettingsProvider`. Un hotel puede
 * subirlo, bajarlo o **apagarlo** poniendolo a cero (ADR-017).
 *
 * **No rechaza: suprime.** El escaneo se registra igual, con
 * `scan_events.result = rejected_debounce`, y la respuesta HTTP es un `200` con
 * `action: debounced` (ADR-031). El nombre del enum de dominio dice «rechazo»
 * porque describe el desenlace del *escaneo*; lo que el empleado ve es un aviso,
 * y lo que la cola offline recibe es un exito, para que no reintente contra una
 * ventana que ya paso (RF-KI-04, regla dura 19).
 *
 * **La ventana se mide en valor absoluto y ese es el detalle que importa.** La
 * cola offline puede sincronizar un escaneo cuyo `occurred_at` es **anterior**
 * al del ultimo aceptado (regla dura 9, RF-AT-09): con una comparacion con
 * signo, cualquier escaneo del pasado caeria dentro de la ventana y se
 * suprimiria el historico entero de un lote atrasado. Lo que RF-AT-06 mide es
 * la **distancia** entre dos escaneos, no su orden de llegada.
 *
 * **No conoce el reloj** (regla dura 2): compara los dos instantes que recibe,
 * y los dos son `occurred_at`, nunca la hora de recepcion. Medir sobre
 * `recorded_at` haria que un lote offline de tres horas se autosuprimiera
 * entero al llegar de golpe.
 */
final readonly class DebouncePolicy
{
    private function __construct(
        /** RF-AT-06: ventana de gracia en segundos. Cero desactiva la regla. */
        public int $windowSeconds,
    ) {
        if ($windowSeconds < 0) {
            throw InvalidDebounceWindow::ofSeconds($windowSeconds);
        }
    }

    public static function ofSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    /**
     * Anti-rebote desactivado: todo escaneo se procesa.
     *
     * Existe como constructor con nombre para que la intencion se lea en la
     * prueba y en la configuracion, en lugar de un `0` suelto que parece un
     * olvido.
     */
    public static function disabled(): self
    {
        return new self(0);
    }

    public function isDisabled(): bool
    {
        return $this->windowSeconds === 0;
    }

    /**
     * El escaneo aceptado que suprime a este, o `null` si ninguno lo hace.
     *
     * Devuelve el instante y no un booleano porque el quiosco tiene que poder
     * decir «ya has fichado hace unos segundos» (doc 01 §11, escenario
     * *Anti-rebote*): es el `last_accepted_at` del esquema `ScanDebounced`. Con
     * un booleano habria que volver a buscarlo fuera, y esa segunda busqueda
     * podria no coincidir con la que tomo la decision.
     *
     * Se elige el **mas cercano** de los candidatos, no el primero que entre en
     * la ventana: quien decide es el escaneo que de verdad esta al lado.
     *
     * @param  DateTimeImmutable  ...$acceptedScans  Instantes `occurred_at` de escaneos ya
     *                                               aceptados del mismo empleado. Basta con los
     *                                               adyacentes: uno mas lejano no puede ganar.
     */
    public function suppressorOf(DateTimeImmutable $scanAt, DateTimeImmutable ...$acceptedScans): ?DateTimeImmutable
    {
        TimeRange::assertUtc('scanAt', $scanAt);

        if ($this->isDisabled()) {
            return null;
        }

        $closest = null;
        $closestDistance = null;

        foreach ($acceptedScans as $accepted) {
            TimeRange::assertUtc('acceptedScan', $accepted);

            $distance = abs($accepted->getTimestamp() - $scanAt->getTimestamp());

            if ($distance >= $this->windowSeconds) {
                continue;
            }

            if ($closestDistance === null || $distance < $closestDistance) {
                $closest = $accepted;
                $closestDistance = $distance;
            }
        }

        return $closest;
    }

    /**
     * Si este escaneo cae dentro del periodo de gracia de alguno de los ya
     * aceptados.
     *
     * El umbral es **estricto**: con la ventana en 60 s, un escaneo a los 59 s
     * se suprime y uno a los 60 s exactos se procesa. Es la misma semantica
     * `[inicio, fin)` con la que el resto del dominio trata los intervalos
     * —`TimeRange`, la restriccion de exclusion de RN-02— y por el mismo
     * motivo: un limite que pertenece a los dos lados es un limite que se
     * comporta distinto segun quien lo evalue.
     */
    public function suppresses(DateTimeImmutable $scanAt, DateTimeImmutable ...$acceptedScans): bool
    {
        return $this->suppressorOf($scanAt, ...$acceptedScans) instanceof DateTimeImmutable;
    }
}
