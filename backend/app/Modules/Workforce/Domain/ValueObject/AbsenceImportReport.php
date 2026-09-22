<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * El informe linea a linea de la carga de ausencias, en los dos modos
 * (**RF-GP-04**).
 *
 * **Misma forma que {@see ImportReport}** —huella, filas, truncado y avisos del
 * fichero— para que el panel reutilice el mismo componente de revision en dos
 * pasos. Lo unico que cambia son los desenlaces posibles: aqui no hay `update`,
 * porque corregir una ausencia es crear una version con motivo y eso no se hace
 * en una carga masiva.
 *
 * **Un informe no es un error.** Sale con `200` aunque haya lineas rechazadas: el
 * resultado esperado de este endpoint es el informe, y quien lo recibe tiene que
 * poder verlo entero para corregir el fichero.
 *
 * **`truncated` se dice, no se recorta en silencio.** Un fichero con mas lineas
 * de las admitidas deja de leerse y **no se aplica nada**: aplicar medio
 * cuadrante de vacaciones es el fallo que nadie detecta hasta que alguien no
 * aparece en el informe del mes.
 */
final readonly class AbsenceImportReport
{
    /**
     * @param  list<AbsenceImportRow>  $rows
     * @param  list<AbsenceImportMessage>  $warnings
     */
    private function __construct(
        public string $sha256,
        public array $rows,
        public bool $truncated,
        /** Avisos que son **del fichero entero**: hoy, las cabeceras no reconocidas. */
        public array $warnings = [],
    ) {}

    /**
     * @param  list<AbsenceImportRow>  $rows
     * @param  list<AbsenceImportMessage>  $warnings
     */
    public static function of(string $sha256, array $rows, bool $truncated, array $warnings = []): self
    {
        return new self($sha256, $rows, $truncated, $warnings);
    }

    /** Lineas de datos leidas, sin contar la cabecera. */
    public function rowCount(): int
    {
        return \count($this->rows);
    }

    public function countOf(AbsenceImportOutcome $outcome): int
    {
        return \count(array_filter(
            $this->rows,
            static fn (AbsenceImportRow $row): bool => $row->outcome === $outcome,
        ));
    }

    /**
     * ¿Se puede aplicar este fichero?
     *
     * **No, si esta truncado.** Y si, aunque haya lineas rechazadas: tumbar el
     * lote entero por una celda con una fecha mal escrita obligaria a repetir la
     * revision de las otras treinta y nueve, y en la practica lleva a que alguien
     * borre la linea problematica en vez de corregirla.
     */
    public function isApplicable(): bool
    {
        return ! $this->truncated;
    }

    /**
     * Las lineas que de verdad escriben algo. `unchanged` no esta: no hay nada
     * que hacer con ellas.
     *
     * @return list<AbsenceImportRow>
     */
    public function writableRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (AbsenceImportRow $row): bool => $row->outcome === AbsenceImportOutcome::CREATE,
        ));
    }

    /**
     * @param  list<AbsenceImportRow>  $rows
     */
    public function withRows(array $rows): self
    {
        return new self($this->sha256, $rows, $this->truncated, $this->warnings);
    }
}
