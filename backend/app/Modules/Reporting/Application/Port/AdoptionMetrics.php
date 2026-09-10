<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use DateTimeImmutable;

/**
 * `workdays_complete_ratio{site}` (doc 02 §8.2, RF-IN-08).
 *
 * Puerto y no llamada directa por lo mismo que `PresenceMetrics`: quien
 * publica no sabe si detras hay un fichero para el colector *textfile*, un
 * contador o nada, y en pruebas es un doble que permite afirmar sobre lo
 * publicado sin escribir en disco.
 */
interface AdoptionMetrics
{
    /**
     * Publica el ratio de una jornada ya cerrada.
     *
     * **Es un `gauge` que se recalcula entero, nunca un contador que se
     * incrementa** (regla dura 7 aplicada a la instrumentacion): se deriva de
     * los datos, asi que una pasada repetida da el mismo numero y una pasada
     * perdida no lo desvia para siempre.
     *
     * **Un centro sin jornadas ese dia no debe publicar serie.** Ni `0` ni
     * `NaN`: cero significaria «ese dia nadie cerro su jornada» —una alarma— y
     * lo que pasa de verdad es que ese dia el centro estaba cerrado. La
     * ausencia de la serie es la unica forma honesta de decir «no hay nada que
     * medir», igual que con `websocket_connections_active` (ADR-011).
     *
     * @param  array<int, array{complete: int, total: int}>  $bySite  Por centro; los
     *                                                                centros con `total` a
     *                                                                cero no se publican.
     * @param  string  $workDate  La jornada medida, en `Y-m-d`, para poder publicarla
     *                            como serie auxiliar: sin ella no se distingue «el ratio
     *                            de ayer» de «la tarea programada lleva una semana caida».
     */
    public function publish(array $bySite, string $workDate, DateTimeImmutable $at): void;
}
