<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Una pagina de la bandeja, con su sitio dentro del total (RF-PA-05).
 *
 * **`total` es el total DENTRO DEL ALCANCE de quien pregunta** (RF-ID-03), no el
 * de la instalacion: lo cuenta la misma consulta que trae las filas, con los
 * mismos predicados. Contar sin acotar y filtrar despues daria una cifra que
 * describe a personas que quien pregunta no puede ver, que es una fuga por si
 * misma.
 *
 * `totalPages()` se calcula y no se guarda, por lo mismo que el total de una
 * jornada se suma en vez de almacenarse: dos formas de decir lo mismo acaban
 * discrepando.
 */
final readonly class IncidentBoardPage
{
    /**
     * @param  list<IncidentBoardRow>  $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        return $this->perPage < 1 ? 0 : (int) ceil($this->total / $this->perPage);
    }
}
