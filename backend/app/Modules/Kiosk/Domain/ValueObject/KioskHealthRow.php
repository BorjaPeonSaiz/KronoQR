<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use App\Modules\Kiosk\Domain\Policy\AppVersionPolicy;
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
        /** `null` = desconocido (ADR-047): nunca se convierte en cero. */
        public ?int $pendingQueueSize,
        public KioskHealthVerdict $verdict,
        public KioskHealthReason $reason,
        /** Nivel de bateria declarado en el ultimo latido; `null` si no lo informa. */
        public ?int $batteryLevel = null,
        /** Si estaba enchufada; `null` si no lo informa. */
        public ?bool $batteryCharging = null,
        public QueueStorage $queueStorage = QueueStorage::Durable,
        public int $unreportedDiscards = 0,
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
     * 5. **Bateria baja** — al dia, pero por debajo del umbral y **sin cargar**:
     *    alguien le ha quitado el cargador y se apagara durante el turno. Va
     *    detras de lo que ya no responde y delante de la cola porque una tablet
     *    que se apaga se lleva su cola por delante, y porque se arregla en
     *    treinta segundos con un cable (decision 5 de la ficha 3.3).
     * 6. **Con cola** — late al dia pero declara fichajes sin enviar. `aviso` y no
     *    `ok` porque esos fichajes son registro horario de personas reales y se
     *    pierden si alguien revoca el token antes de que drenen (runbook §5.1).
     * 7. **Aplicacion desfasada** — late al dia, sin nada pendiente, pero con una
     *    `app_version` anterior a la del servidor ({@see AppVersionPolicy}).
     *    El ultimo de los avisos porque la tablet se actualiza sola en cuanto
     *    su cola esta vacia: todo lo anterior es lo que se lo impide.
     * 8. **Correcto**.
     *
     * `pending_queue_size` lo declara el dispositivo y nadie lo comprueba: es
     * informacion de operacion, no autoridad, y no cambia ni un fichaje. Con
     * `app_version` pasa lo mismo: solo cambia un aviso.
     */
    public static function of(
        DeviceSummary $device,
        DateTimeImmutable $now,
        KioskHealthThresholds $thresholds,
        AppVersionPolicy $appVersions,
    ): self {
        $elapsed = $device->secondsSinceLastSeen($now);

        [$verdict, $reason] = self::judge($device, $elapsed, $now, $thresholds, $appVersions);

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
            batteryLevel: $device->batteryLevel,
            batteryCharging: $device->batteryCharging,
            queueStorage: $device->queueStorage,
            unreportedDiscards: $device->unreportedDiscards,
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
        AppVersionPolicy $appVersions,
    ): array {
        if (! $device->isActive()) {
            return [KioskHealthVerdict::Revoked, KioskHealthReason::Revoked];
        }

        if ($elapsed === null) {
            return self::judgeUnseen($device, $now, $thresholds);
        }

        return self::judgeSeen($device, $elapsed, $thresholds, $appVersions);
    }

    /**
     * Sin ningun latido: recien vinculado —dentro de su plazo de gracia— o una
     * tablet que no llego a arrancar.
     *
     * @return array{KioskHealthVerdict, KioskHealthReason}
     */
    private static function judgeUnseen(DeviceSummary $device, DateTimeImmutable $now, KioskHealthThresholds $thresholds): array
    {
        $sincePaired = self::elapsed($device->pairedAt, $now);

        return $sincePaired !== null && ! $thresholds->isSilentAfter($sincePaired)
            ? [KioskHealthVerdict::Warning, KioskHealthReason::AwaitingFirstHeartbeat]
            : [KioskHealthVerdict::Failure, KioskHealthReason::NeverSeen];
    }

    /**
     * Con latido: el resto de la escala, de lo mas grave a lo menos (ver
     * `DeviceHealth.reason` en el contrato).
     *
     * @return array{KioskHealthVerdict, KioskHealthReason}
     */
    private static function judgeSeen(
        DeviceSummary $device,
        int $elapsed,
        KioskHealthThresholds $thresholds,
        AppVersionPolicy $appVersions,
    ): array {
        if ($thresholds->isSilentAfter($elapsed)) {
            return [KioskHealthVerdict::Failure, KioskHealthReason::Silent];
        }

        // ADR-047: fallo, porque lo que se encola ahora se pierde al reiniciar
        // la tablet. Detras del silencio y delante del retraso.
        if (! $device->queueStorage->isDurable()) {
            return [KioskHealthVerdict::Failure, KioskHealthReason::QueueStorageDegraded];
        }

        if ($elapsed > $thresholds->freshWithinSeconds) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::Late];
        }

        // RN-22: fichajes que nadie revisara hasta que su aviso salga.
        if ($device->unreportedDiscards > 0) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::DiscardsUnreported];
        }

        if (self::batteryIsLow($device, $thresholds)) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::BatteryLow];
        }

        if (($device->pendingQueueSize ?? 0) > 0) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::QueuePending];
        }

        if ($appVersions->isBehind($device->appVersion)) {
            return [KioskHealthVerdict::Warning, KioskHealthReason::AppVersionBehind];
        }

        return [KioskHealthVerdict::Ok, KioskHealthReason::Beating];
    }

    /**
     * La bateria entra en el veredicto **solo por abajo y solo descargandose**.
     *
     * Los tres `null` son deliberados y significan lo mismo: *no lo se*. La
     * Battery Status API solo la ofrece Chrome en Android —que es la tablet del
     * producto— y un navegador que no la implementa no puede poner en aviso a la
     * flota entera el dia del despliegue. `charging === true` tampoco avisa: una
     * tablet al 8 % enchufada esta haciendo exactamente lo que tiene que hacer.
     *
     * El umbral es INCLUSIVO: el numero que el cliente escribe es el primero que
     * quiere ver avisado.
     */
    private static function batteryIsLow(DeviceSummary $device, KioskHealthThresholds $thresholds): bool
    {
        return $device->batteryLevel !== null
            && $device->batteryCharging === false
            && $device->batteryLevel <= $thresholds->batteryLowPercent;
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
