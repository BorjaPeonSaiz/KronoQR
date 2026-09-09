<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Un quiosco con su veredicto, tal y como sale en `kiosk:health` (RF-PA-07).
 *
 * ## Es donde vive la regla, y por eso esta en `Domain`
 *
 * «Cuando esta bien un quiosco» es una decision del producto y no un detalle de
 * como se pinta una tabla: la misma respuesta la necesitan la consola, el panel
 * (RF-PA-07) y la alerta del doc 01 §9.3. Escrita aqui se prueba con un reloj
 * fijo y sin base de datos, que es lo unico que permite fijar las fronteras de
 * los dos plazos al segundo.
 *
 * ## Ni un dato personal (regla dura 21)
 *
 * Un quiosco es un aparato en una pared y su nombre es el del sitio
 * —«Recepcion»—. **No lleva token ni su hash** ni los llevara, ni la clave
 * interna de la fila: lo mismo que ya decide `DeviceSummary`, del que sale.
 */
final readonly class KioskHealthRow
{
    private const string ACTIVE = 'active';

    public function __construct(
        public string $uuid,
        public string $name,
        /** `active` o `revoked`, el catalogo de `devices.status`. */
        public string $status,
        /** `null` mientras la tablet no haya latido nunca. */
        public ?string $appVersion,
        public ?DateTimeImmutable $lastSeenAt,
        /** Segundos desde el ultimo latido; `null` si no ha latido nunca. */
        public ?int $secondsSinceLastSeen,
        public int $pendingQueueSize,
        public KioskHealthVerdict $verdict,
        public KioskHealthReason $reason,
    ) {}

    /**
     * Juzga un quiosco contra el instante que le pasan (regla dura 2: el reloj
     * llega resuelto, aqui no se pregunta la hora a nadie).
     *
     * El orden de las ramas **es** la regla, y va de lo que hay que atender antes
     * a lo que puede esperar:
     *
     * 1. **Revocado** — no late porque no debe. No cuenta para el codigo de salida.
     * 2. **Sin latido nunca** — `aviso` mientras dura el plazo de gracia desde que
     *    se vinculo, y `fallo` despues. La gracia existe porque el runbook §4.2
     *    manda ejecutar este comando justo despues de emparejar, y en esos
     *    segundos la tablet aun no ha recogido su token: un `fallo` ahi enseñaria
     *    a ignorar el comando. Se mide con el **mismo** plazo de silencio para no
     *    inventar un tercer numero.
     * 3. **Callado** — pasa del plazo de silencio: es la alerta critica del doc 01
     *    §9.3 dicha en la consola.
     * 4. **Atrasado** — entre los dos plazos. Lo primero que hay que mirar es la red.
     * 5. **Con cola** — late al dia pero declara fichajes sin enviar. `aviso` y no
     *    `ok` porque esos fichajes son registro horario de personas reales y se
     *    pierden si alguien revoca el token antes de que drenen (runbook §5.1).
     * 6. **Correcto**.
     *
     * `pending_queue_size` lo declara el dispositivo y nadie lo comprueba: es
     * informacion de operacion, no autoridad, y no cambia ni un fichaje.
     */
    public static function of(DeviceSummary $device, DateTimeImmutable $now, KioskHealthThresholds $thresholds): self
    {
        $elapsed = self::elapsed($device->lastSeenAt, $now);

        [$verdict, $reason] = self::judge($device, $elapsed, $now, $thresholds);

        return new self(
            uuid: $device->uuid,
            name: $device->name,
            status: $device->status,
            appVersion: $device->appVersion,
            lastSeenAt: $device->lastSeenAt,
            secondsSinceLastSeen: $elapsed,
            pendingQueueSize: $device->pendingQueueSize,
            verdict: $verdict,
            reason: $reason,
        );
    }

    /**
     * @return array{KioskHealthVerdict, KioskHealthReason}
     */
    private static function judge(
        DeviceSummary $device,
        ?int $elapsed,
        DateTimeImmutable $now,
        KioskHealthThresholds $thresholds,
    ): array {
        if ($device->status !== self::ACTIVE) {
            return [KioskHealthVerdict::Revoked, KioskHealthReason::Revoked];
        }

        if ($elapsed === null) {
            $sincePaired = self::elapsed($device->pairedAt, $now);

            return $sincePaired !== null && $sincePaired <= $thresholds->silentAfterSeconds
                ? [KioskHealthVerdict::Warning, KioskHealthReason::AwaitingFirstHeartbeat]
                : [KioskHealthVerdict::Failure, KioskHealthReason::NeverSeen];
        }

        if ($elapsed > $thresholds->silentAfterSeconds) {
            return [KioskHealthVerdict::Failure, KioskHealthReason::Silent];
        }

        if ($elapsed > $thresholds->freshWithinSeconds) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::Late];
        }

        if ($device->pendingQueueSize > 0) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::QueuePending];
        }

        return [KioskHealthVerdict::Ok, KioskHealthReason::Beating];
    }

    /**
     * Segundos transcurridos, nunca negativos.
     *
     * El suelo en cero no es cosmetico: `last_seen_at` lo escribe el servidor con
     * su propio reloj, pero un `pg_restore` de una copia hecha en otra maquina, o
     * un salto de NTP hacia atras, pueden dejar un instante en el futuro. Un
     * numero negativo aqui saldria en la consola como «hace -12 s» y haria dudar
     * del comando entero justo cuando se ejecuta para diagnosticar algo.
     */
    private static function elapsed(?DateTimeImmutable $instant, DateTimeImmutable $now): ?int
    {
        return $instant === null ? null : max(0, $now->getTimestamp() - $instant->getTimestamp());
    }
}
