<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\InvalidDebounceWindow;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
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
 *
 * ## Desde la tarea 3.5 mira tambien la intencion (ADR-024)
 *
 * El propio ADR-024 lo pide al introducir la pausa: *«un `break_end` inmediato
 * tras un `break_start` mal pulsado caeria hoy en `rejected_debounce` con los
 * 60 s del Anexo B»*. Con la ventana intacta, corregir una pausa mal fichada
 * exigiria pasar por el mecanismo de correcciones de RN-13 para algo que la
 * persona puede arreglar sola en el mismo minuto.
 *
 * La regla anadida es una sola y es **estrecha a proposito**: no se suprime un
 * escaneo cuya intencion **explicita** deshace lo que hizo el aceptado **mas
 * cercano** ({@see DeclaredIntent::reverses()}). Todo lo demas conserva la
 * ventana —`auto` tras cualquier cosa, dos `break_start` seguidos—, porque el
 * doble escaneo accidental sigue siendo lo que RF-AT-06 descarta y ampliar la
 * excepcion la convertiria en la regla. El valor de la ventana **no cambia**:
 * `ATTENDANCE_DEBOUNCE_SECONDS` se queda en 60 s (decision 4 de la ficha 3.5).
 *
 * El criterio de «si hay duda, se registra» es la regla dura 19: perder veinte
 * segundos de tramo es barato; tragarse una vuelta de pausa cuesta las horas
 * del tramo que deja de contar.
 *
 * **Cuidado con la asimetria de las dos mitades.** El quiosco del producto
 * **nunca envia `break_end` explicito**: la vuelta de la pausa es `auto` y la
 * resuelve `ScanIntentPolicy` (decision 5 de la ficha 3.5, «la vuelta no anade
 * ningun paso»). Asi que la mitad que de verdad se ejercita desde una tablet es
 * `break_start` tras `break_end` —el «Pausa» pulsado por error justo despues de
 * volver—, y la mitad `break_end` tras `break_start` solo la alcanza un cliente
 * de la API que declare la intencion. Se implementan las dos porque el contrato
 * admite las dos y porque una regla que solo vale en un sentido es una regla que
 * alguien tiene que recordar; pero quien lea una cobertura o una metrica no debe
 * esperar trafico real en esa rama. La vuelta que si llega del quiosco —`auto` a
 * los veinte segundos de un `break_start`— **se suprime**, y es correcto: sin
 * declaracion no hay forma de distinguirla del doble escaneo de RF-AT-06.
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
     * Devuelve el escaneo y no un booleano porque el quiosco tiene que poder
     * decir «ya has fichado hace unos segundos» (doc 01 §11, escenario
     * *Anti-rebote*): su `occurredAt` es el `last_accepted_at` del esquema
     * `ScanDebounced`. Con un booleano habria que volver a buscarlo fuera, y esa
     * segunda busqueda podria no coincidir con la que tomo la decision.
     *
     * Se elige el **mas cercano** de los candidatos, no el primero que entre en
     * la ventana: quien decide es el escaneo que de verdad esta al lado. Y es
     * tambien el unico contra el que se mide la intencion: si el vecino
     * inmediato es el que se esta deshaciendo, no hay rebote que descartar,
     * aunque mas atras haya otro escaneo dentro de la ventana.
     *
     * @param  DeclaredIntent  $intent  lo que el quiosco declaro en ESTE escaneo (`AUTO` si no
     *                                  declaro nada). Es lo que distingue una vuelta de pausa
     *                                  deliberada de un doble escaneo accidental (ADR-024)
     * @param  AcceptedScan  ...$acceptedScans  escaneos ya aceptados del mismo empleado. Basta con
     *                                          los adyacentes: uno mas lejano no puede ganar
     */
    public function suppressorOf(
        DateTimeImmutable $scanAt,
        DeclaredIntent $intent,
        AcceptedScan ...$acceptedScans,
    ): ?AcceptedScan {
        TimeRange::assertUtc('scanAt', $scanAt);

        if ($this->isDisabled()) {
            return null;
        }

        $closest = $this->closestWithinWindow($scanAt, ...$acceptedScans);

        // ADR-024: una intencion explicita que deshace al vecino inmediato no es
        // un rebote. El escaneo se procesa y la persona arregla su propia pausa
        // sin pasar por una correccion (RN-13).
        if ($closest instanceof AcceptedScan && $intent->reverses($closest->action)) {
            return null;
        }

        return $closest;
    }

    /**
     * El aceptado mas cercano que cae dentro de la ventana, sin mirar la
     * intencion.
     *
     * Separado para que la excepcion de ADR-024 se lea como lo que es —una sola
     * decision sobre el vecino ya elegido— y no mezclada con la busqueda.
     *
     * **Publico porque el reenvio tambien lo necesita** (RF-AT-07): reconstruir
     * la respuesta de un `rejected_debounce` ya registrado es responder «cual
     * fue el escaneo que lo suprimio», y esa es exactamente esta pregunta. La
     * otra —«¿hay que suprimir este?»— es {@see suppressorOf()}, que ademas mira
     * la intencion y por eso NO sirve para el reenvio: podria devolver `null`
     * para una fila que existe. Tenerlo dos veces escrito era tener dos
     * definiciones de «el mas cercano».
     */
    public function closestWithinWindow(DateTimeImmutable $scanAt, AcceptedScan ...$acceptedScans): ?AcceptedScan
    {
        $closest = null;
        $closestDistance = null;

        foreach ($acceptedScans as $accepted) {
            $distance = $accepted->distanceInSecondsTo($scanAt);

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
    public function suppresses(
        DateTimeImmutable $scanAt,
        DeclaredIntent $intent,
        AcceptedScan ...$acceptedScans,
    ): bool {
        return $this->suppressorOf($scanAt, $intent, ...$acceptedScans) instanceof AcceptedScan;
    }
}
