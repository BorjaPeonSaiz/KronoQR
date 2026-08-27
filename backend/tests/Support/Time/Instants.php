<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Instantes escritos como los lee una persona, resueltos siempre a UTC.
 *
 * El dominio solo acepta UTC (RN-04, regla dura 3), pero los escenarios del
 * documento 01 §11 estan enunciados en hora local del centro —«entrada el 25 de
 * octubre a las 01:30 CEST»—. Escribir ese caso directamente como `23:30Z`
 * obliga a quien lee la prueba a hacer la conversion de cabeza, que es
 * exactamente la aritmetica que la prueba viene a desconfiar.
 *
 * Asi que se ofrecen las dos formas y `InstantsTest` fija la equivalencia entre
 * ellas con valores escritos a mano. Ninguna prueba de duracion calcula su
 * resultado esperado con esta clase: los minutos se escriben como numero.
 */
final class Instants
{
    public const string MADRID = 'Europe/Madrid';

    /**
     * Un instante UTC a partir de su hora de reloj UTC: `2026-03-14 22:00`.
     */
    public static function utc(string $wallClock): DateTimeImmutable
    {
        return new DateTimeImmutable($wallClock, new DateTimeZone('UTC'));
    }

    /**
     * El instante UTC en el que los relojes de Madrid marcaban esa hora.
     *
     * No vale para las dos horas raras del ano: la que no existe en el salto de
     * marzo y la que ocurre dos veces en el de octubre. Esos dos casos se
     * escriben en UTC, que es donde no son ambiguos.
     */
    public static function inMadrid(string $wallClock): DateTimeImmutable
    {
        return (new DateTimeImmutable($wallClock, new DateTimeZone(self::MADRID)))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public static function madrid(): DateTimeZone
    {
        return new DateTimeZone(self::MADRID);
    }

    /**
     * La hora de reloj de Madrid de un instante UTC, para poder afirmar sobre
     * ella sin convertir dentro de la prueba.
     */
    public static function asMadridWallClock(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::madrid())->format('Y-m-d H:i');
    }
}
