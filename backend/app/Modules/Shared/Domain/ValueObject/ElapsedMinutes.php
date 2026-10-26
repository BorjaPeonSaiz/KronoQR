<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use App\Modules\Shared\Domain\Exception\InvalidElapsedTime;
use DateTimeImmutable;

/**
 * Minutos enteros transcurridos entre dos instantes: **la unica regla de minutos
 * del producto** (RN-09, RN-10).
 *
 * ## La regla
 *
 * Segundos enteros de diferencia entre los dos instantes (`getTimestamp()`, que
 * es UTC por definicion) divididos entre sesenta, **truncando**. Por eso:
 *
 *   - el cambio de hora no regala ni roba nada: se restan instantes, no horas de
 *     reloj de pared (RN-09);
 *   - 6 h 0 min 59 s son 360 minutos y no 361, asi que un limite abierto del doc
 *     01 no se convierte en cerrado por un redondeo;
 *   - la fraccion de segundo se descarta **antes** de restar: 08:00:00.900 →
 *     09:00:00.100 son 60 minutos, porque `getTimestamp()` trunca cada marca a
 *     su segundo. Cambiar eso es una decision del propietario, no un arreglo.
 *
 * ## Por que vive en `Shared`
 *
 * La aplicaban por separado `Attendance` (`TimeRange`, la deteccion de RN-10),
 * `Reporting` (`ComplianceShiftEntry`, `ComplianceFacts`) y `Compliance` (la
 * conciliacion del registro con la auditoria), con una prueba que ataba las
 * copias. Ninguno de esos modulos puede importar el dominio de otro (doc 02
 * §1.6), y la regla tiene que ser identica en todos: es el caso de ADR-055 §3.
 * Una guarda de arquitectura impide que vuelva a aparecer una copia.
 */
final readonly class ElapsedMinutes
{
    private function __construct(public int $minutes) {}

    /**
     * RN-09: la duracion de algo que empieza en `$from` y acaba en `$to`.
     *
     * Una duracion negativa no existe: es un error de quien llama, no un dato.
     *
     * @throws InvalidElapsedTime si `$to` es anterior a `$from`
     */
    public static function between(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        $seconds = self::elapsedSeconds($from, $to);

        if ($seconds < 0) {
            throw InvalidElapsedTime::endsBeforeItStarts($from, $to);
        }

        return new self(intdiv($seconds, 60));
    }

    /**
     * RN-10: el hueco entre el fin de algo (`$from`) y el inicio de lo siguiente
     * (`$to`), o `null` si lo siguiente empieza antes.
     *
     * Un hueco negativo no describe un descanso corto sino un solape, del que
     * responde RN-02; medirlo daria el peor incumplimiento posible por accidente.
     * **Cero si es un hueco**: empezar justo al terminar es el peor descanso que
     * se puede registrar sin solapar.
     */
    public static function gapBetween(DateTimeImmutable $from, DateTimeImmutable $to): ?self
    {
        $seconds = self::elapsedSeconds($from, $to);

        return $seconds < 0 ? null : new self(intdiv($seconds, 60));
    }

    /**
     * La diferencia en segundos enteros. El signo se decide **aqui** y no
     * comparando los objetos: dos marcas del mismo segundo con `$to` anterior
     * por microsegundos dan 0, no un negativo, igual que antes de que la regla
     * se unificara.
     */
    private static function elapsedSeconds(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
