<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingResolution;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use DateTimeImmutable;

/**
 * **Que hace este escaneo con la jornada**: entrada, salida, pausa o vuelta de
 * pausa (RF-AT-02, RF-AT-03, RF-AT-12, ADR-024).
 *
 * Es una regla de negocio y por eso vive en `Domain/` y no en el handler
 * (reglas duras 1 y 2). Hasta la tarea 3.5 la decision cabia en un `if
 * (hasOpenEntry())` dentro del caso de uso, porque solo habia dos desenlaces
 * posibles. Con la pausa declarada hay cuatro, la respuesta depende de cuatro
 * hechos y no de uno, y **una equivocacion aqui no da un error: da una nomina
 * mal pagada**. Eso es exactamente lo que se escribe como politica del dominio,
 * con su tabla y una prueba por fila.
 *
 * ## Los cuatro hechos que entran
 *
 * 1. **La intencion declarada** ({@see DeclaredIntent}), que es lo que el
 *    quiosco pidio. El servidor **la honra siempre que pueda**, este o no
 *    activado el fichaje de pausa en la instalacion: el ajuste gobierna la
 *    pantalla de la tablet y la evaluacion de RN-12, no la verdad de lo que la
 *    persona declaro.
 * 2. **Si la jornada tiene tramo abierto**, que es el mismo hecho de siempre
 *    (RF-AT-02 / RF-AT-03) y el que decide entre abrir y cerrar.
 * 3. **Cuando ocurre este escaneo** (`occurred_at`, regla dura 9). No es el
 *    reloj: es un hecho del escaneo, y es lo que permite medir cuanto ha durado
 *    la pausa sin preguntarle la hora a nadie (regla dura 2).
 * 4. **El ultimo escaneo aceptado de la persona** ({@see AcceptedScan},
 *    `ScanLog::lastAcceptedScanOf()`), que es lo unico que distingue «vuelve de
 *    la pausa» de «empieza la jornada»: los dos llegan sin tramo abierto.
 *
 * ## La pausa tiene techo, y el techo es RN-10
 *
 * **Sin techo, una pausa que nadie continua se come la jornada siguiente.** Si
 * alguien pulsa «Pausa» a las 15:00 del lunes y se va a casa, el escaneo de
 * entrada del martes es `auto` sin tramo abierto y con un `break_start` como
 * ultimo aceptado: se resolveria `BREAK_END`, las ocho horas del martes se
 * cargarian a la jornada del lunes y el martes quedaria a cero. Sin incidencia,
 * sin aviso y con el registro legal de dos dias falseado.
 *
 * El techo **no es una constante nueva**: es el **descanso minimo entre
 * jornadas del perfil de cumplimiento** (RN-10,
 * `CompliancePolicy::minimumRestMinutes`, servido por
 * `CompliancePolicyProvider`, regla dura 14). El razonamiento es el del art.
 * 34.3 ET leido al derecho: si entre el inicio de la pausa y el escaneo que
 * llega median **menos** minutos que el descanso minimo, eso no ha podido ser
 * un descanso entre jornadas y sigue siendo la misma jornada; si median el
 * umbral o mas, **por definicion legal ya es otra**. Un hotel con un convenio de
 * 10 h y otro con uno de 12 h obtienen el techo de su propio convenio sin tocar
 * el binario (ADR-017).
 *
 * El limite es **estricto**, como el resto del dominio (`TimeRange`, RN-02,
 * `DebouncePolicy`): con 12 h de descanso, 11 h 59 continua la pausa y 12 h 00
 * exactas abren jornada nueva.
 *
 * **Lo que esto NO hace:** no detecta la «pausa sin vuelta» —la jornada que
 * quedo cerrada por un `break_start` y nadie continuo— como incidencia. Eso
 * exigiria un tipo nuevo en `incidents.type`, su migracion y su sitio en la
 * bandeja, y esta explicitamente fuera del alcance de la tarea 3.5 (punto 14 de
 * la ficha). Lo que hace este techo es impedir que ese olvido contamine la
 * jornada siguiente; la jornada del olvido se queda corta y se corrige con
 * RN-13 como cualquier otra.
 *
 * ## La tabla de resolucion, fila a fila
 *
 * | Tramo abierto | Intencion | Ultimo aceptado | Decision | Por que |
 * |---|---|---|---|---|
 * | Si | `break_start` | — | `BREAK_START` | Lo pidio y encaja: cierra el tramo sin cerrar la jornada |
 * | Si | `auto` | — | `CLOCK_OUT` | Sin declaracion, cerrar es lo que significa pasar la tarjeta con turno abierto (RF-AT-03) |
 * | Si | `break_end` | — | `CLOCK_OUT` | Contradice el estado: **se cierra sin inventar una pausa que nadie declaro** |
 * | No | `break_end` | `break_start` **dentro del techo** | `BREAK_END` | Vuelve de la pausa: continua la jornada de aquel tramo (RN-05) |
 * | No | `break_end` | `break_start` en el techo o mas alla | `CLOCK_IN` | Ha pasado el descanso minimo: por RN-10 ya es otra jornada |
 * | No | `break_end` | `break_start` **posterior** a este escaneo | `CLOCK_IN` | Cola desordenada (regla dura 9): no se puede volver de una pausa que aun no empezo |
 * | No | `break_end` | cualquier otro | `CLOCK_IN` | No hay pausa que continuar; contradice el estado y se abre jornada |
 * | No | `auto` | `break_start` dentro del techo | `BREAK_END` | **La vuelta no anade ningun paso**: pasar la tarjeta basta |
 * | No | `auto` | cualquier otro | `CLOCK_IN` | El camino de siempre (RF-AT-02) |
 * | No | `break_start` | — | `CLOCK_IN` | Contradice el estado: no hay nada que cerrar, asi que se abre |
 *
 * Con tramo abierto el ultimo escaneo aceptado **no se mira**: lo que decide es
 * el estado del agregado, que es el hecho fuerte. Mirar los dos podria hacer
 * que dos lecturas distintas de la misma base discreparan.
 *
 * ## Una intencion contradictoria no se rechaza, y esto es deliberado
 *
 * Las filas «contradice el estado» se resuelven **por la estructura** y el
 * escaneo se registra tal cual: `intent` guarda lo que se pidio y `result` lo
 * que se hizo (doc 01 §5.5). No se rechaza —regla dura 19: el quiosco nunca
 * bloquea al empleado—, no se marca `flagged_for_review` y no se abre
 * incidencia. Abrir una exigiria un tipo nuevo en `incidents.type`, su
 * migracion y su sitio en la bandeja para describir algo que casi siempre es un
 * boton mal pulsado, y la divergencia ya queda escrita en `scan_events` para
 * quien la revise (decision 2 de la ficha 3.5).
 *
 * El feedback del quiosco enseña **la accion decidida**, nunca la declarada:
 * quien ficho la pausa y ve «salida registrada» pensara que ha cerrado su
 * jornada (ADR-024, consecuencias).
 *
 * **No conoce el reloj** (regla dura 2), **no conoce el agregado** y **no
 * conoce el estado del tramo**: no sabe si aquel tramo sigue vigente, si una
 * correccion lo retiro o si existe siquiera. Decide con el `uuid` que el
 * escaneo dejo escrito, y de encontrar su jornada se encarga
 * `WorkDayRepository::findWorkDayOfAnyShiftEntry()`, que resuelve tambien sobre
 * un tramo retirado por una correccion. Es lo que permite probar la tabla entera
 * sin base de datos.
 */
final readonly class ScanIntentPolicy
{
    /**
     * @param  WorkedDuration  $breakContinuationCeiling  RN-10, ya resuelto desde el perfil del
     *                                                    centro (regla dura 14). Por debajo de el,
     *                                                    un hueco es una pausa; a partir de el, es
     *                                                    el descanso entre dos jornadas
     */
    public function __construct(public WorkedDuration $breakContinuationCeiling) {}

    /**
     * El techo de continuacion, escrito como lo que es: el descanso minimo entre
     * jornadas de RN-10.
     *
     * Constructor con nombre para que el punto de construccion se lea como la
     * regla y no como un numero: `allowingBreaksShorterThan($policy->minimumRest)`
     * dice de donde sale el limite, `new ScanIntentPolicy($x)` no.
     */
    public static function allowingBreaksShorterThan(WorkedDuration $minimumRestBetweenWorkDays): self
    {
        return new self($minimumRestBetweenWorkDays);
    }

    /**
     * La accion que corresponde a este escaneo.
     *
     * @param  DeclaredIntent  $intent  lo que el quiosco pidio (`auto` si no pidio nada)
     * @param  bool  $workDayHasOpenEntry  si la jornada cargada tiene un tramo sin cerrar
     * @param  DateTimeImmutable  $scanAt  `occurred_at` de ESTE escaneo, en UTC (regla dura 9)
     * @param  AcceptedScan|null  $lastAccepted  el ultimo escaneo del empleado que produjo tramo,
     *                                           o `null` si no ficho nunca
     */
    public function resolve(
        DeclaredIntent $intent,
        bool $workDayHasOpenEntry,
        DateTimeImmutable $scanAt,
        ?AcceptedScan $lastAccepted = null,
    ): ClockingResolution {
        TimeRange::assertUtc('scanAt', $scanAt);

        if ($workDayHasOpenEntry) {
            return $intent === DeclaredIntent::BREAK_START
                ? ClockingResolution::breakStart()
                : ClockingResolution::clockOut();
        }

        return $this->withoutOpenEntry($intent, $scanAt, $lastAccepted);
    }

    /**
     * Sin tramo abierto solo se puede abrir uno; lo que se decide aqui es **en
     * que jornada**.
     *
     * `auto` y `break_end` se comportan igual **a proposito** (decision 5 de la
     * ficha): quien vuelve del descanso no tiene que pulsar nada, y quien lo
     * pulsa obtiene lo mismo. Si un dia se separaran, volver de la pausa sin
     * pulsar abriria una jornada nueva y el turno de noche quedaria partido en
     * dos dias, que es el defecto que ADR-024 describe.
     *
     * `break_start` no abre pausa: no hay tramo que cerrar. Es una entrada
     * normal, y no se convierte en vuelta de pausa aunque el ultimo escaneo lo
     * permitiera — declarar «empiezo la pausa» no es declarar «vuelvo de ella».
     */
    private function withoutOpenEntry(
        DeclaredIntent $intent,
        DateTimeImmutable $scanAt,
        ?AcceptedScan $lastAccepted,
    ): ClockingResolution {
        if ($intent === DeclaredIntent::BREAK_START) {
            return ClockingResolution::clockIn();
        }

        $continues = $this->breakToContinue($scanAt, $lastAccepted);

        return $continues === null
            ? ClockingResolution::clockIn()
            : ClockingResolution::breakEnd($continues);
    }

    /**
     * El tramo cuya jornada continua este escaneo, o `null` si no continua
     * ninguna.
     *
     * Tres condiciones, y las tres tienen que darse:
     *
     * 1. El ultimo aceptado **dejo una pausa en curso** con su tramo escrito
     *    —`breakInProgressShiftEntry()`—: la jornada a la que volver es la de
     *    ese tramo, y sin el la unica forma de nombrarla seria la fecha civil,
     *    que es el error que ADR-024 existe para impedir.
     * 2. La pausa **empezo antes** que este escaneo. La cola offline puede
     *    sincronizar marcas desordenadas (regla dura 9) y no se vuelve de una
     *    pausa que todavia no ha empezado.
     * 3. La pausa **cabe bajo el techo de RN-10**. Ver el docblock de la clase:
     *    es lo que impide que un «Pausa» del lunes que nadie continuo absorba la
     *    jornada del martes.
     */
    private function breakToContinue(DateTimeImmutable $scanAt, ?AcceptedScan $lastAccepted): ?string
    {
        if (! $lastAccepted instanceof AcceptedScan) {
            return null;
        }

        $continues = $lastAccepted->breakInProgressShiftEntry();

        if ($continues === null || ! $lastAccepted->precedes($scanAt)) {
            return null;
        }

        // Estricto: con 12 h de descanso minimo, 11 h 59 sigue siendo pausa y
        // 12 h 00 exactas ya son dos jornadas. Misma semantica `[inicio, fin)`
        // que el resto del dominio.
        return $lastAccepted->distanceInSecondsTo($scanAt) < $this->breakContinuationCeiling->minutes * 60
            ? $continues
            : null;
    }
}
