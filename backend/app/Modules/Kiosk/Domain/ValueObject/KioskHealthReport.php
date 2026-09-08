<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;

/**
 * El informe completo de `php artisan kiosk:health` (**RF-PA-07**, doc 02
 * Anexo C; se ejecuta tambien como verificacion de RF-PD-06).
 *
 * ## Los codigos de salida son los de `product:doctor`, a proposito
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Todos los quioscos activos al dia y sin nada encolado. |
 * | `1` | **Avisos.** Algo que mirar: un latido atrasado, una cola sin drenar, o ningun quiosco activo. |
 * | `2` | **Fallos.** Al menos un quiosco activo lleva mas del plazo de silencio sin aparecer. |
 *
 * Un unico convenio para los dos comandos de consola del producto: quien
 * escribe un script no tiene que recordar cual usa cual escala (RF-PD-13).
 *
 * ## Sin ningun quiosco activo el resultado es AVISO, no correcto y no fallo
 *
 * Una instalacion sin ningun quiosco activo es una instalacion en la que **nadie
 * puede fichar**, y decir «correcto» de eso seria mentir: la fila 20 de la lista
 * de comprobacion de `docs/cliente/endurecimiento.md` —«quioscos vinculados =
 * puestos que existen»— se ejecuta cada trimestre justo para descubrirlo.
 *
 * Y **aviso y no fallo** porque hay dos momentos legitimos en los que ocurre: una
 * instalacion recien puesta en marcha, antes de emparejar la primera tablet, y el
 * hueco entre desvincular un quiosco averiado y vincular su sustituto (runbook
 * `alta-nuevo-quiosco.md` §5). Un `2` en esos dos momentos es una lista de
 * comprobacion que falla cuando todo va bien, que es como se enseña a ignorarla.
 *
 * ## Reconstruible y sin estado
 *
 * No guarda nada ni cachea nada: se calcula de `devices` y de un instante. Dos
 * ejecuciones seguidas pueden dar resultados distintos porque el mundo cambio,
 * nunca porque el informe recuerde algo.
 */
final readonly class KioskHealthReport
{
    /**
     * @param  list<KioskHealthRow>  $devices
     */
    private function __construct(
        public array $devices,
        public DateTimeImmutable $generatedAt,
        public KioskHealthThresholds $thresholds,
        public KioskHealthVerdict $status,
    ) {}

    /**
     * @param  list<DeviceSummary>  $devices  La MISMA lista que sirve `GET /api/v1/devices`.
     */
    public static function of(array $devices, DateTimeImmutable $now, KioskHealthThresholds $thresholds): self
    {
        $rows = array_map(
            static fn (DeviceSummary $device): KioskHealthRow => KioskHealthRow::of($device, $now, $thresholds),
            $devices,
        );

        return new self($rows, $now, $thresholds, self::overall($rows));
    }

    public function exitCode(): int
    {
        return $this->status->severity();
    }

    /** Cuantos quioscos hay en la instalacion, activos y revocados. */
    public function total(): int
    {
        return \count($this->devices);
    }

    /** Cuantos estan activos, que son los unicos que deben latir. */
    public function active(): int
    {
        return \count(array_filter(
            $this->devices,
            static fn (KioskHealthRow $row): bool => $row->verdict !== KioskHealthVerdict::Revoked,
        ));
    }

    /**
     * Las filas que hay que mirar, en el orden en que hay que mirarlas.
     *
     * Los fallos primero: recorrer diez lineas verdes hasta la roja es una forma
     * de esconderla, y es el mismo criterio con el que `product:doctor` ordena su
     * informe.
     *
     * @return list<KioskHealthRow>
     */
    public function problems(): array
    {
        $problems = array_values(array_filter(
            $this->devices,
            static fn (KioskHealthRow $row): bool => $row->verdict->severity() > 0,
        ));

        usort(
            $problems,
            static fn (KioskHealthRow $a, KioskHealthRow $b): int => $b->verdict->severity() <=> $a->verdict->severity(),
        );

        return $problems;
    }

    /**
     * El informe para maquinas (`--json`).
     *
     * **Los instantes salen en UTC con sufijo `Z`** (regla dura 3), igual que en
     * la API: la conversion a la zona del centro es de la presentacion, y quien
     * consume esto es un script. `seconds_since_last_seen` viaja ademas del
     * instante porque es lo que se compara con un umbral sin tener que hacer
     * aritmetica de fechas en `bash`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => self::utc($this->generatedAt),
            'status' => $this->status->value,
            'exit_code' => $this->exitCode(),
            'thresholds' => [
                'fresh_within_seconds' => $this->thresholds->freshWithinSeconds,
                'silent_after_seconds' => $this->thresholds->silentAfterSeconds,
            ],
            'fleet' => [
                'total' => $this->total(),
                'active' => $this->active(),
            ],
            'devices' => array_map(static fn (KioskHealthRow $row): array => [
                'uuid' => $row->uuid,
                'name' => $row->name,
                'status' => $row->status,
                'app_version' => $row->appVersion,
                'last_seen_at' => self::utc($row->lastSeenAt),
                'seconds_since_last_seen' => $row->secondsSinceLastSeen,
                'pending_queue_size' => $row->pendingQueueSize,
                'verdict' => $row->verdict->value,
                'reason' => $row->reason->value,
            ], $this->devices),
        ];
    }

    /**
     * El veredicto del conjunto: el peor de sus filas.
     *
     * Sin ninguna fila **activa** es `Warning`, y el docblock de la clase explica
     * por que ese caso no es ni correcto ni fallo.
     *
     * @param  list<KioskHealthRow>  $rows
     */
    private static function overall(array $rows): KioskHealthVerdict
    {
        $active = array_filter(
            $rows,
            static fn (KioskHealthRow $row): bool => $row->verdict !== KioskHealthVerdict::Revoked,
        );

        if ($active === []) {
            return KioskHealthVerdict::Warning;
        }

        $worst = KioskHealthVerdict::Ok;

        foreach ($active as $row) {
            if ($row->verdict->severity() > $worst->severity()) {
                $worst = $row->verdict;
            }
        }

        return $worst;
    }

    private static function utc(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
