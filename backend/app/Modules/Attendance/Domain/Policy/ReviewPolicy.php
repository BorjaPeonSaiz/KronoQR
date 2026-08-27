<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\InvalidSkewTolerance;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;

/**
 * Que fichajes **pide validar** a una persona (`scan_events.flagged_for_review`).
 *
 * Es una regla de negocio y por eso vive en `Domain/` y no en el handler: quien
 * la lea tiene que poder ver las dos razones juntas, porque son la misma
 * pregunta —«¿me fio de esta marca tal como llego?»— por dos caminos distintos.
 *
 * ## Razon 1: el origen (RF-AT-11)
 *
 * *«Entrada alternativa por PIN de 6 digitos... Misma traza, marcada como
 * `origen = PIN` y señalada para revision del responsable»*. El PIN identifica
 * con algo que se sabe, no con algo que se tiene: es el unico camino del
 * producto donde un compañero puede fichar por otro sin quitarle nada.
 *
 * ## Razon 2: el desfase (RN-15)
 *
 * *«El horario de un fichaje offline es el `occurred_at` del dispositivo,
 * marcado con su retraso de sincronizacion. **Si supera el umbral, requiere
 * validacion del responsable**»*. La segunda frase es esta politica. Sin ella,
 * `clock_skew_seconds` se guardaba y nadie lo miraba: una marca retrodatada
 * entraba en el registro legal indistinguible de un fichaje normal.
 *
 * ## Marcar no es rechazar, y la diferencia es el producto entero
 *
 * Superar el umbral **nunca** impide el fichaje (regla dura 19, RF-AT-10:
 * *«nunca se rechaza un fichaje por desfase de reloj»*). El tramo se registra
 * con la hora del dispositivo y ademas queda señalado. Rechazarlo dejaria una
 * jornada sin registrar por un problema tecnico ajeno al empleado, que es
 * precisamente el desenlace que el art. 34.9 ET no perdona.
 *
 * La marca es el dato; la **incidencia** `clock_skew` con su bandeja y su aviso
 * en el quiosco es la tarea 3.5 (ADR-032), y se construye leyendo hacia atras
 * justo esta columna.
 *
 * ## El umbral llega resuelto (regla dura 14)
 *
 * No hay ninguna constante aqui. El valor de serie —15 min— vive en
 * `installation_settings.ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` y lo sirve
 * `OperationalSettingsProvider`.
 *
 * **No conoce el reloj** (regla dura 2): el desfase ya viene medido en un
 * {@see ClockSkew}.
 */
final readonly class ReviewPolicy
{
    private function __construct(
        /** RF-AT-10: desfase tolerado, en segundos, antes de pedir validacion. */
        public int $skewToleranceSeconds,
    ) {}

    public static function toleratingSkewOfMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            throw InvalidSkewTolerance::ofMinutes($minutes);
        }

        return new self($minutes * 60);
    }

    /**
     * Si este escaneo tiene que llegar marcado a quien revisa.
     *
     * El umbral es **estricto**: con 15 minutos de tolerancia, un desfase de
     * 900 s exactos no pide validacion y uno de 901 s si. Es la misma semantica
     * de limite abierto que usa {@see DebouncePolicy} y que la restriccion de
     * exclusion de RN-02, y por el mismo motivo: un limite que pertenece a los
     * dos lados se comporta distinto segun quien lo evalue.
     */
    public function requiresReview(ScanOrigin $origin, ClockSkew $skew): bool
    {
        return $origin === ScanOrigin::PIN_KIOSK
            || $skew->magnitudeSeconds() > $this->skewToleranceSeconds;
    }
}
