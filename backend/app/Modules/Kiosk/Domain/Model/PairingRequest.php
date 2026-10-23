<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Model;

use App\Modules\Kiosk\Domain\ValueObject\ClaimOutcome;
use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;
use App\Modules\Kiosk\Domain\ValueObject\PairingStatus;
use DateTimeImmutable;

/**
 * La solicitud con la que una tablet se convierte en quiosco (**RF-PD-06**,
 * tarea 5.6). Raiz de agregado de su propio ciclo de vida.
 *
 * ## Que decide y que no
 *
 * Decide **si** una solicitud se puede confirmar y **que** se le devuelve a la
 * tablet que sondea. No decide como se guarda, ni con que algoritmo se hashea un
 * secreto, ni quien gana cuando dos peticiones llegan a la vez: eso ultimo lo
 * arbitra PostgreSQL con un `UPDATE ... WHERE status = ?` en el adaptador, y si
 * afecta a cero filas el caso de uso **degrada a `Rejected`**. Este agregado
 * responde a la pregunta con la foto que tiene delante; la escritura condicional
 * es la que hace que dos fotos iguales no produzcan dos tokens.
 *
 * ## Nunca lee el reloj (regla dura 2)
 *
 * `confirm()` y `claim()` reciben el instante ya resuelto del puerto `Clock`. Sin
 * eso no se puede probar el borde exacto de la caducidad, que es el unico sitio
 * donde esta clase se puede equivocar de forma silenciosa.
 *
 * ## El secreto se comprueba SIEMPRE Y PRIMERO
 *
 * `claim()` recibe `secretMatches` ya resuelto y lo mira **antes que nada, en
 * todas las ramas**. No es una preferencia de estilo: si el estado se mirara
 * primero, una solicitud inexistente devolveria `Rejected` y una pendiente con el
 * secreto equivocado devolveria tambien `Rejected`, pero **por otro camino y con
 * otro coste**, y la diferencia se mide desde fuera. Con la comprobacion delante,
 * «no existe», «no es tuya» y «ya no vale» son el mismo camino (regla dura 17,
 * RS-03).
 *
 * ## La caducidad NO aplica al `claim` una vez confirmada
 *
 * Es la decision menos obvia de esta clase. `expires_at` acota lo que puede
 * esperar un codigo **sin confirmar**; despues del `confirm` la fila de `devices`
 * ya existe y esta activa, y negar la recogida solo dejaria un quiosco dado de
 * alta que ninguna tablet puede usar — un callejon sin salida que solo se
 * arreglaria entrando por consola, que es exactamente lo que RF-PD-06 existe para
 * evitar (regla dura 19). El limite de una solicitud confirmada es la purga a las
 * 24 h, no su caducidad.
 *
 * ## Un rechazo no muta nada
 *
 * Ni un `confirm` con un codigo equivocado ni un `claim` con el secreto
 * equivocado consumen la solicitud, la caducan o cuentan intentos. Si lo
 * hicieran, teclear mal tres veces dejaria sin emparejar una tablet que estaba
 * perfectamente, y bastaria sondear con un secreto inventado para tumbar el alta
 * de un quiosco ajeno.
 *
 * ## La recogida repetida (V6-KI-02, 2.3.0)
 *
 * Si la respuesta `paired` se pierde por el camino, la tablet se quedaria con un
 * dispositivo dado de alta y sin token. Por eso una solicitud ya `claimed` admite
 * **repetir la recogida** —se reemite el token del MISMO dispositivo y se retira
 * el anterior— solo si se cumple todo a la vez:
 *
 * - el secreto coincide (se mira primero, como siempre);
 * - llega **desde el mismo origen** que la primera recogida, comparado sin
 *   cortocircuito (`hash_equals`);
 * - han pasado **al menos {@see self::RECLAIM_NOT_BEFORE_SECONDS} segundos** desde
 *   la primera: dos sondeos simultaneos de la misma tablet no son una respuesta
 *   perdida, y reemitir entre ellos dejaria a la tablet con el token retirado
 *   del que procesara primero;
 * - y **menos de {@see self::RECLAIM_WINDOW_SECONDS}**, contados desde la
 *   primera recogida y no desde la ultima: repetir no alarga la ventana.
 *
 * Fuera de eso, el `Rejected` de siempre. Es estructural, no se configura: una
 * ventana mas ancha seria un hueco mas ancho para quien tuviera el secreto.
 */
final readonly class PairingRequest
{
    /** Segundos desde la primera recogida antes de los cuales no se repite. */
    public const int RECLAIM_NOT_BEFORE_SECONDS = 10;

    /** Segundos desde la primera recogida durante los cuales se puede repetir. */
    public const int RECLAIM_WINDOW_SECONDS = 60;

    private function __construct(
        /** Identificador **publico** (`device_pairing_requests.uuid`): el `pairing_id` del contrato. */
        public string $uuid,
        public PairingStatus $status,
        public DateTimeImmutable $expiresAt,
        /** La fila de `devices`, que existe solo a partir del `confirm`. */
        public ?int $deviceId,
        /** Cuando se recogio el token por primera vez; nulo hasta entonces. */
        public ?DateTimeImmutable $claimedAt = null,
        /**
         * Huella opaca del origen de la primera recogida, para la recogida
         * repetida. Nula en las solicitudes consumidas antes de la 2.3.0.
         */
        public ?string $claimedOrigin = null,
        /** Recogidas repetidas ya hechas: la que se gana la carrera la incrementa. */
        public int $reclaims = 0,
    ) {}

    /**
     * Rehidrata la solicitud desde su fila. **La unica via de construccion**: no
     * hay `new` publico ni `open()`, porque crear la solicitud es una escritura
     * del adaptador —necesita el sorteo del codigo y el UNIQUE parcial que
     * arbitra las colisiones— y no una decision de dominio.
     */
    public static function reconstitute(
        string $uuid,
        PairingStatus $status,
        DateTimeImmutable $expiresAt,
        ?int $deviceId = null,
        ?DateTimeImmutable $claimedAt = null,
        ?string $claimedOrigin = null,
        int $reclaims = 0,
    ): self {
        return new self($uuid, $status, $expiresAt, $deviceId, $claimedAt, $claimedOrigin, $reclaims);
    }

    /**
     * Si la solicitud ya no admite confirmacion por haber pasado su plazo.
     *
     * **El borde es `>=` y no `>`**: `expires_at` es el primer instante en que el
     * codigo ya no vale, no el ultimo en que valia. Con `>`, una solicitud
     * confirmada exactamente en su microsegundo de caducidad pasaria, y ese caso
     * —imposible de reproducir a mano y trivial en una prueba— es el que decide
     * si la ventana de diez minutos son diez minutos o diez minutos y un
     * instante.
     */
    public function hasExpiredAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * El administrador teclea el codigo (`POST /api/v1/kiosk/pair/confirm`).
     *
     * Solo desde `pending` y solo dentro de plazo. Un `confirm` sobre una
     * solicitud ya confirmada tambien se rechaza: dos personas tecleando el mismo
     * codigo no pueden dar de alta dos quioscos, y la segunda no tiene por que
     * enterarse de cual de las dos causas fue.
     */
    public function confirm(DateTimeImmutable $now): ConfirmOutcome
    {
        if ($this->status !== PairingStatus::Pending) {
            return ConfirmOutcome::Rejected;
        }

        if ($this->hasExpiredAt($now)) {
            return ConfirmOutcome::Rejected;
        }

        return ConfirmOutcome::Confirmed;
    }

    /**
     * La tablet sondea (`POST /api/v1/kiosk/pair/claim`).
     *
     * @param  bool  $secretMatches  Resultado del `hash_equals` que hace el adaptador. Se
     *                               resuelve **siempre**, exista o no la fila, y con el mismo
     *                               trabajo: es lo que impide que la respuesta delate que
     *                               `pairing_id` son reales.
     * @param  string|null  $origin  Huella opaca del origen de esta peticion. Solo cuenta
     *                               para la recogida repetida: ver el docblock de la clase.
     */
    public function claim(DateTimeImmutable $now, bool $secretMatches, ?string $origin = null): ClaimOutcome
    {
        // Primero y en todas las ramas. Ver el docblock de la clase.
        if (! $secretMatches) {
            return ClaimOutcome::Rejected;
        }

        return match ($this->status) {
            // Todavia sin confirmar: se sigue esperando mientras haya plazo.
            PairingStatus::Pending => $this->hasExpiredAt($now)
                ? ClaimOutcome::Rejected
                : ClaimOutcome::Pending,

            // Confirmada. **Sin comprobar la caducidad**: ver el docblock.
            PairingStatus::Confirmed => ClaimOutcome::Paired,

            // Un solo uso: el token se emitio y no se vuelve a emitir, salvo la
            // recogida repetida de la ventana corta (V6-KI-02).
            PairingStatus::Claimed => $this->canBeReclaimedAt($now, $origin)
                ? ClaimOutcome::Reclaimed
                : ClaimOutcome::Rejected,
        };
    }

    /**
     * Si una solicitud ya consumida admite repetir la recogida ahora y desde
     * este origen. Ver el docblock de la clase.
     */
    private function canBeReclaimedAt(DateTimeImmutable $now, ?string $origin): bool
    {
        if (! $this->claimedAt instanceof DateTimeImmutable || $this->claimedOrigin === null || $origin === null) {
            return false;
        }

        if (! hash_equals($this->claimedOrigin, $origin)) {
            return false;
        }

        $elapsed = $now->getTimestamp() - $this->claimedAt->getTimestamp();

        return $elapsed >= self::RECLAIM_NOT_BEFORE_SECONDS && $elapsed < self::RECLAIM_WINDOW_SECONDS;
    }
}
