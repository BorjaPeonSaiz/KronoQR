<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * El informe linea a linea que exige **RF-GP-05**, en los dos modos.
 *
 * ## Un informe no es un error
 *
 * Sale con `200` aunque haya lineas rechazadas: el resultado esperado de este
 * endpoint es el informe, y quien lo recibe tiene que poder verlo entero para
 * corregir el fichero. Lo que no es `200` es un fichero que no se puede leer.
 *
 * ## `truncated` se dice, no se recorta en silencio
 *
 * Un fichero con mas lineas de las admitidas deja de leerse y **no se aplica
 * nada**. Recortarlo callando importaria media plantilla y nadie lo notaria
 * hasta que faltara gente a las 06:00; decirlo obliga a partir el fichero, que
 * es la accion correcta.
 */
final readonly class ImportReport
{
    /**
     * @param  list<ImportRow>  $rows
     * @param  list<ImportMessage>  $warnings
     */
    private function __construct(
        public string $sha256,
        public array $rows,
        public bool $truncated,
        /**
         * Avisos que son **del fichero entero**, no de una linea: hoy, las
         * cabeceras que el importador no reconoce.
         *
         * **Aqui y no repetidos en cada fila**: una cabecera con tres columnas
         * desconocidas y cuarenta filas produciria ciento veinte mensajes
         * identicos que sepultarian los rechazos de verdad — justo el ruido
         * que el aviso existe para evitar.
         */
        public array $warnings = [],
    ) {}

    /**
     * @param  list<ImportRow>  $rows
     * @param  list<ImportMessage>  $warnings
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

    public function countOf(ImportOutcome $outcome): int
    {
        return \count(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => $row->outcome === $outcome,
        ));
    }

    /**
     * ¿Se puede aplicar este fichero?
     *
     * **No, si esta truncado.** Y si, aunque haya lineas rechazadas: rechazar el
     * lote entero por una celda con una fecha mal escrita obligaria a repetir la
     * revision de las otras treinta y nueve, y en la practica lleva a que alguien
     * borre la linea problematica en vez de corregirla. Las rechazadas se
     * informan y se saltan.
     */
    public function isApplicable(): bool
    {
        return ! $this->truncated;
    }

    /**
     * Las lineas que de verdad escriben algo. `unchanged` no esta: no hay nada
     * que hacer con ellas, y meterlas obligaria a comprobar en la aplicacion que
     * el `UPDATE` no cambia nada.
     *
     * @return list<ImportRow>
     */
    public function writableRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => $row->outcome === ImportOutcome::CREATE
                || $row->outcome === ImportOutcome::UPDATE
                || $row->outcome === ImportOutcome::REHIRE,
        ));
    }

    /**
     * Huella de **a quien readmitiria** este informe (RN-27), o `null` si no
     * readmite a nadie: `sha256` de los `employee_uuid` de las filas `rehire`,
     * ordenados y sin repetir, unidos por saltos de linea. No depende del orden
     * de las filas del fichero ni de nada mas que de esas personas.
     *
     * La validacion la devuelve y la aplicacion la exige: `confirm_checksum`
     * prueba que el fichero es el mismo, y esto, que las readmisiones tambien lo
     * son aunque el estado de la plantilla haya cambiado entre las dos fases.
     */
    public function rehireDigest(): ?string
    {
        $uuids = [];

        foreach ($this->rows as $row) {
            if ($row->outcome === ImportOutcome::REHIRE && $row->employeeUuid !== null) {
                $uuids[$row->employeeUuid] = true;
            }
        }

        if ($uuids === []) {
            return null;
        }

        $sorted = array_keys($uuids);
        sort($sorted);

        return hash('sha256', implode("\n", $sorted));
    }

    /**
     * @param  list<ImportRow>  $rows
     */
    public function withRows(array $rows): self
    {
        return new self($this->sha256, $rows, $this->truncated, $this->warnings);
    }
}
