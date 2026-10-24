<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use InvalidArgumentException;

/**
 * Los tres numeros con los que {@see UnprocessableScanPolicy} decide cuando el
 * servidor deja de intentar un fichaje que no consigue procesar (RN-22,
 * ADR-061), **ya resueltos**.
 *
 * **Son tecnicos, no legales**: no regulan descanso, jornada ni retencion, asi
 * que no viven en el perfil de cumplimiento (regla dura 14) sino en la
 * configuracion del servidor (regla dura 13, ADR-029). Ninguno esta escrito
 * aqui: los sirve el puerto `ScanDiscardSettings` al caso de uso, que es quien
 * construye esto. El dominio no consulta configuracion; la recibe.
 *
 * ## Los suelos no se configuran
 *
 * - **Al menos 3 intentos.** Con uno o dos, cualquier parpadeo que la
 *   clasificacion de lo transitorio no reconociera bastaria para sacar un
 *   fichaje valido del registro automatico.
 * - **Al menos 15 minutos** entre el primer intento contado y el actual. El
 *   quiosco reintenta a 1 s, 2 s, 4 s…: diez intentos llegan en 8,5 minutos, y
 *   sin ventana el numero de intentos no separa un fallo pasajero de uno
 *   permanente.
 * - **El cupo no pasa de 20** en 24 h: por encima, el cupo dejaria de proteger
 *   de un defecto general. El adaptador de la configuracion aplica el techo y
 *   avisa en `product:doctor`, para que un `.env` alto nunca impida arrancar.
 * - **El cupo no es negativo.** El cero si vale y tiene significado propio:
 *   apaga la funcionalidad entera, y el servidor vuelve a conservar para
 *   siempre, como antes de ADR-061.
 *
 * Un umbral por debajo de un suelo **no se puede construir**: el error salta al
 * leer la configuracion, no el dia que un fichaje se descarta antes de tiempo.
 */
final readonly class ScanDiscardThreshold
{
    /** Suelo de {@see $minAttempts}. Estructural, no se configura. */
    public const int ATTEMPTS_FLOOR = 3;

    /** Suelo de {@see $minSpanMinutes}. Estructural, no se configura. */
    public const int SPAN_FLOOR_MINUTES = 15;

    /**
     * Techo de {@see $dailyBudget} (ADR-061, A6 del 24-10-2026). Estructural, no
     * se configura: el cupo existe para que un defecto general no convierta todos
     * los fichajes de un quiosco en descartes, y un cupo de cientos lo anularia.
     */
    public const int BUDGET_CEILING = 20;

    public function __construct(
        /** Intentos contados del mismo fichaje, el actual incluido, a partir de los cuales puede descartarse. */
        public int $minAttempts,
        /** Minutos que tienen que separar el primer intento contado del actual. */
        public int $minSpanMinutes,
        /** Descartes del servidor por quiosco en la ventana de {@see UnprocessableScanPolicy::BUDGET_WINDOW_HOURS}. `0` apaga. */
        public int $dailyBudget,
    ) {
        if ($minAttempts < self::ATTEMPTS_FLOOR) {
            throw new InvalidArgumentException(
                'Un fichaje no se descarta con menos de '.self::ATTEMPTS_FLOOR.' intentos (RN-22), y la configuracion fija '
                .$minAttempts.'.'
            );
        }

        if ($minSpanMinutes < self::SPAN_FLOOR_MINUTES) {
            throw new InvalidArgumentException(
                'Un fichaje no se descarta antes de '.self::SPAN_FLOOR_MINUTES.' minutos de intentos (RN-22), y la configuracion fija '
                .$minSpanMinutes.'.'
            );
        }

        if ($dailyBudget < 0) {
            throw new InvalidArgumentException(
                'El cupo de descartes por quiosco no puede ser negativo, y la configuracion fija '.$dailyBudget
                .'. Para apagar el descarte, ponlo a cero.'
            );
        }

        if ($dailyBudget > self::BUDGET_CEILING) {
            throw new InvalidArgumentException(
                'El cupo de descartes por quiosco no pasa de '.self::BUDGET_CEILING.' en 24 h (RN-22), y la configuracion fija '
                .$dailyBudget.'.'
            );
        }
    }

    /** Con cupo cero el servidor no descarta nunca: conserva y reintenta, como antes de ADR-061. */
    public function isDisabled(): bool
    {
        return $this->dailyBudget === 0;
    }
}
