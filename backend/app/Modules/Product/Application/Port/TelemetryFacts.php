<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Los tres datos del informe que solo estan en la base de datos y que ningun
 * puerto existente da (**RF-PD-12**).
 *
 * ## Por que solo tres, y por que agregados
 *
 * La plantilla activa y los quioscos activos ya los cuenta
 * {@see PlanUsageCounter} (ADR-028) y se reutiliza tal cual. Lo que falta son
 * los departamentos, las incidencias abiertas y la version del motor. Los dos
 * primeros son `count(*)` sin `WHERE` sobre nada personal —un numero, sin
 * nombres ni identificadores— y el tercero es un `SHOW`.
 *
 * ## La version del motor va RECORTADA
 *
 * `SELECT version()` de PostgreSQL devuelve tambien el compilador y **rutas de
 * compilacion del servidor**, y las rutas del servidor no salen de la
 * instalacion (ficha 5.10 punto 8). De ahi sale `17.2` y nada mas.
 *
 * ## Nunca lanza
 *
 * Un fallo devuelve `0` o `null`. La telemetria es accesoria: no puede ser la
 * causa de que un comando termine mal.
 */
interface TelemetryFacts
{
    /** `17.2`, o `null` si no se puede leer. Sin compilador y sin rutas. */
    public function databaseVersion(): ?string;

    public function departments(): int;

    /** Incidencias en `open` **ahora mismo**: es un nivel, no un flujo del periodo. */
    public function openIncidents(): ?int;
}
