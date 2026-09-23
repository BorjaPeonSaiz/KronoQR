<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\KioskUpdateWindow;
use DateTimeImmutable;

/**
 * Lo que el servidor le devuelve al quiosco tras un latido: el esquema
 * `KioskHeartbeat` del contrato (RF-PA-07, RF-PD-15).
 *
 * ## Por que un objeto y no un instante suelto
 *
 * Hasta la tarea 5.12 {@see RecordHeartbeat} devolvia solo la hora del servidor.
 * Ahora el latido tambien acusa recibo de los errores que la tablet adjunto, y
 * los dos datos tienen que viajar juntos: `client_errors_accepted` es lo que la
 * PWA usa para vaciar de su buffer **solo lo confirmado** (`acknowledge(n)`), de
 * modo que lo que no se pudo guardar vuelve en el latido siguiente en vez de
 * perderse (decision 7 de la ficha).
 *
 * ## `clientErrorsAccepted` es un prefijo, no un total
 *
 * El puerto {@see ErrorEventSink} se detiene
 * en el primero que no pudo guardar, asi que el numero cuenta **desde el mas
 * antiguo y sin huecos**. Si fuera un recuento de aciertos sueltos, la tablet
 * descartaria errores que nadie persistio.
 */
final readonly class HeartbeatOutcome
{
    public function __construct(
        /** La hora del servidor: con ella la tablet mide su desfase de reloj y avisa (RF-AT-10). */
        public DateTimeImmutable $seenAt,
        /** Cuantos de los `client_errors` recibidos quedaron en el historico. `0` si no vino ninguno. */
        public int $clientErrorsAccepted,
        /**
         * La huella del codigo de servicio de la pantalla de diagnostico
         * (RF-KI-08, tarea 3.3), o `null` si la instalacion no tiene codigo.
         *
         * **Viaja en el latido y no en el padron** porque el padron es «dos
         * campos y ni uno mas» (RL-12) y el latido es el unico canal autenticado
         * que la tablet repite cada minuto: un codigo cambiado en el panel llega
         * a todas las tablets en sesenta segundos. La tablet la guarda y
         * comprueba el codigo **en local**, sin red.
         */
        public ?string $serviceCodeHash,
        /**
         * RF-AT-12: si la instalacion tiene activado el fichaje de pausa
         * (`ATTENDANCE_BREAK_CLOCKING`, ADR-024).
         *
         * Con `true` la tablet enseña el boton «Pausa» que arma la intencion
         * `break_start` del siguiente escaneo; con `false` lo oculta. **No
         * gobierna al servidor**: una intencion declarada se honra siempre
         * (decision 1 de la ficha 3.5). Viaja por el latido y no por el padron
         * por lo mismo que la huella del codigo de servicio, y la tablet lo
         * guarda en local para que el boton siga estando cuando no hay red.
         *
         * **Sin valor por defecto**, igual que el de abajo: no hay ninguno
         * razonable que este objeto pueda inventarse, porque el valor es de la
         * instalacion y quien lo tiene es el caso de uso.
         */
        public bool $breakClockingEnabled,
        /**
         * RF-AT-10: a partir de cuantos segundos de desfase la tablet avisa.
         *
         * Es `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` en segundos, y sustituye a la
         * constante de 15 min que el quiosco llevaba escrita: con un umbral
         * propio, la tablet podia avisar por un desfase que el servidor no marca
         * —o callarse ante uno que si— y las dos pantallas contaban historias
         * distintas del mismo reloj. **Nunca impide fichar** (regla dura 19).
         *
         * **Sin valor por defecto.** Lo tuvo —`0`— y era un `0` que el contrato
         * prohibe: `clock_skew_tolerance_seconds` declara `minimum: 60`, asi que
         * cualquier camino que se olvidara de pasarlo habria servido una
         * respuesta invalida y, peor, una tolerancia de cero segundos que dejaria
         * la banda de aviso encendida en todas las tablets del hotel. El valor de
         * serie vive donde tiene que vivir: en el catalogo de `SettingKey`.
         */
        public int $clockSkewToleranceSeconds,
        /**
         * RF-KI-07: la franja en la que la tablet **puede** aplicar una version
         * nueva de la PWA (`KIOSK_UPDATE_WINDOW`, tarea 3.12).
         *
         * Viaja por el latido por lo mismo que los dos de arriba: es el unico
         * canal autenticado que la tablet repite cada minuto, y la tablet la
         * persiste para que valga tambien sin red. **Es permiso y no bloqueo**
         * (regla dura 19): fuera de ella se sigue fichando y encolando; lo unico
         * que no ocurre es la recarga.
         *
         * La declara el cliente y no la infiere la tablet (regla dura 13): el
         * producto no adivina el cambio de turno, y equivocarse significa
         * recargar el quiosco con cola de gente delante.
         */
        public KioskUpdateWindow $updateWindow,
        /**
         * RF-KI-07: minutos sin ningun escaneo que la tablet exige ademas de la
         * franja antes de aplicar (`KIOSK_UPDATE_QUIET_MINUTES`).
         *
         * Cubre el turno que entra antes de lo previsto sin que el producto
         * tenga que saber cuando empieza. Cero lo desactiva.
         */
        public int $updateQuietMinutes,
    ) {}
}
