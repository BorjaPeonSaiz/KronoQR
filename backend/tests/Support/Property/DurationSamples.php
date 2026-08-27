<?php

declare(strict_types=1);

namespace Tests\Support\Property;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Muestras para las pruebas basadas en propiedades del calculo de duraciones
 * (RQ-02, doc 02 §9.2, fila «Propiedades»: duraciones, DST y medianoche).
 *
 * **Deterministas.** El generador es una congruencia lineal con semilla fija, no
 * `random_int()` ni Faker: una prueba basada en propiedades que cambia de casos
 * en cada ejecucion es una prueba intermitente con otro nombre. Si un caso falla
 * hoy, falla igual manana y en la maquina de al lado, que es lo unico que
 * permite arreglarlo.
 *
 * **Los dos cambios de hora estan sembrados a mano** ademas de los casos
 * repartidos por el ano: dejar que el azar caiga en las cuatro horas del ano que
 * importan es una apuesta, no una prueba.
 */
final class DurationSamples
{
    /** Ultimo domingo de marzo de 2026: 02:00 CET pasan a ser 03:00 CEST. */
    private const string SPRING_FORWARD = '2026-03-29 01:00:00';

    /** Ultimo domingo de octubre de 2026: 03:00 CEST pasan a ser 02:00 CET. */
    private const string FALL_BACK = '2026-10-25 01:00:00';

    private const string YEAR_START = '2026-01-01 00:00:00';

    /** Segundos de 2026. Se reparte la muestra sobre ellos. */
    private const int YEAR_SECONDS = 31536000;

    private int $state;

    private function __construct(int $seed)
    {
        $this->state = $seed;
    }

    /**
     * Turnos repartidos por el ano, mas los que atraviesan cada cambio de hora.
     *
     * @return array<string, array{string, int}> nombre => [inicio UTC, minutos]
     */
    public static function shiftsAcrossTheYear(): array
    {
        $generator = new self(20260314);
        $cases = [];

        foreach ($generator->minuteStarts(30) as $index => $start) {
            $minutes = $generator->between(1, 780);
            $cases['turno '.$index.' del ano'] = [$start, $minutes];
        }

        return [...$cases, ...self::shiftsAcrossEachTransition()];
    }

    /**
     * Turnos que empiezan antes de cada salto y terminan despues.
     *
     * @return array<string, array{string, int}>
     */
    public static function shiftsAcrossEachTransition(): array
    {
        $generator = new self(20261025);
        $cases = [];

        foreach ([self::SPRING_FORWARD, self::FALL_BACK] as $transition) {
            $anchor = new DateTimeImmutable($transition, new DateTimeZone('UTC'));

            foreach (range(1, 8) as $index) {
                $minutesBefore = $generator->between(1, 300);
                $minutesAfter = $generator->between(1, 300);
                $start = $anchor->modify('-'.$minutesBefore.' minutes');

                $cases['salto de '.substr($transition, 5, 5).', caso '.$index] = [
                    $start->format('Y-m-d H:i:s'),
                    $minutesBefore + $minutesAfter,
                ];
            }
        }

        return $cases;
    }

    /**
     * Turnos partidos en dos por un instante intermedio.
     *
     * @return array<string, array{string, int, int}> nombre => [inicio UTC, primer tramo, segundo tramo]
     */
    public static function shiftsSplitInTwo(): array
    {
        $generator = new self(20260601);
        $cases = [];

        foreach ($generator->minuteStarts(20) as $index => $start) {
            $cases['corte '.$index] = [$start, $generator->between(1, 480), $generator->between(1, 480)];
        }

        return $cases;
    }

    /**
     * Turnos que empiezan cerca de la medianoche local de Madrid y se alargan
     * hasta cruzarla.
     *
     * @return array<string, array{string, int}> nombre => [inicio UTC, minutos]
     */
    public static function shiftsAcrossLocalMidnight(): array
    {
        $generator = new self(20260315);
        $madrid = new DateTimeZone('Europe/Madrid');
        $cases = [];

        foreach (range(1, 20) as $index) {
            $day = $generator->between(0, 364);
            $minutesBeforeMidnight = $generator->between(1, 240);
            $minutesAfterMidnight = $generator->between(1, 480);

            $midnight = (new DateTimeImmutable(self::YEAR_START, $madrid))
                ->modify('+'.$day.' days')
                ->setTimezone(new DateTimeZone('UTC'));

            $cases['medianoche '.$index] = [
                $midnight->modify('-'.$minutesBeforeMidnight.' minutes')->format('Y-m-d H:i:s'),
                $minutesBeforeMidnight + $minutesAfterMidnight,
            ];
        }

        return $cases;
    }

    /**
     * Jornadas partidas: tramos consecutivos sin solape, con su total ya sumado.
     *
     * @return array<string, array{list<array{string, string}>, int}> nombre => [tramos, total]
     */
    public static function splitWorkDays(): array
    {
        $generator = new self(20260404);
        $cases = [];

        foreach (range(1, 15) as $index) {
            $cases['jornada partida '.$index] = $generator->splitWorkDay();
        }

        return $cases;
    }

    /**
     * @return array{list<array{string, string}>, int}
     */
    private function splitWorkDay(): array
    {
        $cursor = new DateTimeImmutable('2026-06-15 03:00:00', new DateTimeZone('UTC'));
        $shifts = [];
        $total = 0;

        foreach (range(1, $this->between(2, 5)) as $ignored) {
            $gap = $this->between(1, 90);
            $length = $this->between(1, 240);
            $from = $cursor->modify('+'.$gap.' minutes');
            $to = $from->modify('+'.$length.' minutes');

            $shifts[] = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
            $total += $length;
            $cursor = $to;
        }

        return [$shifts, $total];
    }

    /**
     * Instantes UTC en minuto exacto, repartidos por el ano.
     *
     * @return array<int, string>
     */
    private function minuteStarts(int $count): array
    {
        $year = new DateTimeImmutable(self::YEAR_START, new DateTimeZone('UTC'));
        $starts = [];

        foreach (range(1, $count) as $index) {
            $minute = intdiv($this->between(0, self::YEAR_SECONDS), 60);
            $starts[$index] = $year->modify('+'.$minute.' minutes')->format('Y-m-d H:i:s');
        }

        return $starts;
    }

    /**
     * Congruencia lineal de Park y Miller. Sin estado global: dos generadores
     * con la misma semilla dan la misma serie aunque corran entrelazados.
     */
    private function between(int $min, int $max): int
    {
        $this->state = ($this->state * 48271) % 2147483647;

        return $min + $this->state % ($max - $min + 1);
    }
}
