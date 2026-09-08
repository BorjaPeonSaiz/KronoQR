<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;

/**
 * El informe completo de `product:doctor` (contrato `DoctorReport`, **RF-PD-13**).
 *
 * ## Una sola estructura para las tres superficies
 *
 * El texto que imprime el comando, el JSON de `--json` y la seccion `doctor` del
 * paquete de diagnostico salen de este mismo objeto. Es deliberado: si cada
 * superficie compusiera lo suyo, el paquete que llega a soporte podria decir
 * algo distinto de lo que el cliente ve en su terminal, y la conversacion
 * empezaria con las dos partes mirando informes que no coinciden.
 *
 * ## El codigo de salida no se calcula fuera
 *
 * Lo decide {@see DoctorStatus} a partir del peor hallazgo. `install.sh` y
 * `update.sh` traducen **solo el `2`** al `6` de su tabla comun; el `1` se
 * enseña y no bloquea nada.
 */
final readonly class DoctorReport
{
    /**
     * @param  list<DoctorCheck>  $checks
     */
    private function __construct(
        public DoctorStatus $status,
        public DateTimeImmutable $checkedAt,
        public string $productVersion,
        public array $checks,
    ) {}

    /**
     * @param  list<DoctorCheck>  $checks
     */
    public static function of(DateTimeImmutable $checkedAt, string $productVersion, array $checks): self
    {
        return new self(
            DoctorStatus::worstOf(array_map(
                static fn (DoctorCheck $check): DoctorStatus => $check->status,
                $checks,
            )),
            $checkedAt,
            $productVersion,
            $checks,
        );
    }

    public function exitCode(): int
    {
        return $this->status->exitCode();
    }

    /**
     * Las comprobaciones que no salieron bien, en el orden en que se ejecutaron.
     *
     * Es lo que el comando imprime primero: quien ejecuta `doctor` casi siempre
     * ya tiene un problema, y hacerle recorrer treinta lineas verdes para llegar
     * a la roja es una forma de esconderla.
     *
     * @return list<DoctorCheck>
     */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (DoctorCheck $check): bool => $check->status !== DoctorStatus::Ok,
        ));
    }

    /**
     * @return array{status: string, exit_code: int, checked_at: string, product_version: string, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'exit_code' => $this->exitCode(),
            'checked_at' => UtcInstant::of($this->checkedAt),
            'product_version' => $this->productVersion,
            'checks' => array_map(
                static fn (DoctorCheck $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }
}
