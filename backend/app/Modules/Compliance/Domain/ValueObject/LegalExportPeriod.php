<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\InvalidLegalExportRequest;

/**
 * El periodo de una exportacion legal, **en fechas de jornada** (RF-IN-05,
 * RN-05).
 *
 * **No son instantes y no llevan hora.** `work_date` es una fecha civil, no un
 * momento: un turno que entra el 31 a las 22:00 y sale el 1 a las 06:00
 * pertenece al 31 y entra en un periodo que termina el 31 (regla dura 4,
 * ADR-006). Si el periodo se expresara con instantes, ese turno se partiria por
 * la puerta de atras —la mitad en un fichero y la mitad en otro— y el total de
 * horas de un mes dejaria de cuadrar con el del siguiente.
 *
 * Los dos extremos son **inclusivos**, que es como lo entiende quien redacta un
 * requerimiento: «del 1 al 31 de enero» son los 31 dias.
 *
 * Se guarda como texto `YYYY-MM-DD` y no como `DateTimeImmutable` a proposito:
 * un objeto de fecha arrastra una hora y una zona que aqui no significan nada, y
 * la primera vez que alguien las lea se equivocara. La validacion de la forma se
 * hace al construir, para que un periodo mal escrito no llegue nunca a la
 * consulta.
 */
final readonly class LegalExportPeriod
{
    private const string ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private function __construct(
        /** Primer dia, inclusive, en forma `YYYY-MM-DD`. */
        public string $from,
        /** Ultimo dia, inclusive, en forma `YYYY-MM-DD`. */
        public string $to,
    ) {}

    public static function between(string $from, string $to): self
    {
        self::assertIsoDate('from', $from);
        self::assertIsoDate('to', $to);

        // Comparacion de cadenas y no de fechas: `YYYY-MM-DD` ordena
        // lexicograficamente igual que cronologicamente, y eso ahorra construir
        // dos objetos de fecha para responder a una pregunta que no los
        // necesita.
        if ($to < $from) {
            throw InvalidLegalExportRequest::invertedPeriod($from, $to);
        }

        return new self($from, $to);
    }

    /**
     * Fragmento estable para el nombre del fichero. Sin datos personales.
     *
     * Va con guion bajo entre las dos fechas y no con nada mas exotico: el
     * nombre acaba en el disco de quien lo descarga y en un correo a una
     * asesoria, y los dos sitios tienen manias distintas con los caracteres.
     */
    public function slug(): string
    {
        return $this->from.'_'.$this->to;
    }

    private static function assertIsoDate(string $field, string $value): void
    {
        if (preg_match(self::ISO_DATE, $value) !== 1) {
            throw InvalidLegalExportRequest::malformedDate($field, $value);
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        // `preg_match` acepta 2026-02-31, que es una fecha con la forma correcta
        // y que no existe. Dejarla pasar produciria un periodo que PostgreSQL
        // rechaza a mitad de la consulta, con un mensaje que no dice nada a
        // quien lo pidio.
        if (! checkdate($month, $day, $year)) {
            throw InvalidLegalExportRequest::malformedDate($field, $value);
        }
    }
}
