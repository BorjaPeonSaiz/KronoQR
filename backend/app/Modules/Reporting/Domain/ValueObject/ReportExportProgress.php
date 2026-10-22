<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Por donde va una exportacion en curso (**V5-PA-4**, RF-IN-06).
 *
 * ## Una indicacion, no un registro
 *
 * No vive en `report_exports`: la fila dice el estado, y el estado es lo que
 * cuenta. Esto es lo que la pantalla enseña mientras espera, y puede faltar
 * —la cache no responde, el trabajador aun no ha dicho nada— sin que la
 * exportacion deje de estar viva.
 *
 * ## El porcentaje se MIDE o no se da
 *
 * Solo con un total conocido de antemano —las filas que va a escribir la
 * exportacion para la Inspeccion, que se cuentan antes de recorrerlas— hay
 * porcentaje. En el resto es nulo, y la pantalla pinta una barra
 * indeterminada: un 50 % «porque ya va por la mitad de las fases» seria una
 * cifra inventada, y quien espera un fichero legal no merece una.
 *
 * **Y nunca llega a 100** mientras la fila no este `completed`: queda la huella,
 * el asiento y el cierre, y una barra llena que se queda quieta diez segundos
 * se lee como «se ha colgado».
 */
final readonly class ReportExportProgress
{
    /** Lo mas alto que se enseña antes de cerrar la fila. */
    public const int CEILING_BEFORE_COMPLETION = 99;

    private function __construct(
        public ReportExportStage $stage,
        /** De 0 a {@see self::CEILING_BEFORE_COMPLETION}, o nulo si no se puede medir. */
        public ?int $percent,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** Una fase sin porcentaje: la barra indeterminada. */
    public static function at(ReportExportStage $stage, DateTimeImmutable $updatedAt): self
    {
        return new self($stage, null, $updatedAt);
    }

    /**
     * Una fase con las filas hechas de un total conocido.
     *
     * Con un total de cero no hay nada que medir —un periodo sin jornadas se
     * escribe en un instante— y el porcentaje es nulo, no una division por cero.
     * Lo hecho por encima del total —el recuento previo y el recorrido no se
     * leen en el mismo instante— se recorta al techo en vez de pasar de 100.
     */
    public static function ofRows(ReportExportStage $stage, int $done, int $total, DateTimeImmutable $updatedAt): self
    {
        if ($done < 0 || $total < 0) {
            throw new InvalidArgumentException('El avance de una exportacion no puede ser negativo.');
        }

        if ($total === 0) {
            return self::at($stage, $updatedAt);
        }

        return new self(
            $stage,
            min(self::CEILING_BEFORE_COMPLETION, intdiv($done * 100, $total)),
            $updatedAt,
        );
    }

    /**
     * La forma que se guarda y la que sale en la API.
     *
     * @return array{stage: string, percent: int|null, updated_at: int}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'percent' => $this->percent,
            'updated_at' => $this->updatedAt->getTimestamp(),
        ];
    }

    /**
     * Reconstruye lo guardado, o nada si no se reconoce: un avance ilegible se
     * enseña como «sin avance», nunca rompe la lista de exportaciones.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $stage = \is_string($stored['stage'] ?? null) ? ReportExportStage::tryFrom($stored['stage']) : null;
        $updatedAt = $stored['updated_at'] ?? null;
        $percent = self::storedPercent($stored['percent'] ?? null);

        if ($stage === null || ! \is_int($updatedAt) || $percent === false) {
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat('U', (string) $updatedAt);

        return $instant === false ? null : new self($stage, $percent, $instant);
    }

    /**
     * Nulo, o un entero entre 0 y el techo —lo unico que {@see self::ofRows()}
     * produce—; `false` para cualquier otra cosa.
     */
    private static function storedPercent(mixed $percent): int|false|null
    {
        if ($percent === null) {
            return null;
        }

        return \is_int($percent) && $percent >= 0 && $percent <= self::CEILING_BEFORE_COMPLETION ? $percent : false;
    }
}
