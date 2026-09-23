<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Si la instalacion quiere el **resumen semanal por correo** (`WEEKLY_SUMMARY_EMAIL`,
 * RF-PR-05, tarea 3.12).
 *
 * ## Por que un puerto de una sola pregunta
 *
 * Por lo mismo que {@see KioskServiceCodeProvider}: la clave vive en
 * `installation_settings`, que es de `Product`, y quien la consume es
 * `Reporting`, que no puede importarlo (doc 02 §1.6, verificado por Deptrac). El
 * consumidor declara el puerto y quien tiene la maquinaria lo implementa
 * (ADR-025).
 *
 * **No entra en `OperationalSettings`** aunque tambien sea un ajuste del centro:
 * aquel objeto lo resuelve el camino de fichaje en **cada** escaneo, y una clave
 * que solo mira un comando programado los lunes no tiene por que viajar en el
 * dato mas caliente del producto.
 *
 * ## El valor de serie es «apagado», y lo decide el catalogo
 *
 * El doc 05 §5.7 lo vende como «correo opcional», asi que una instalacion recien
 * puesta en marcha no manda correos con nombres de la plantilla a nadie hasta
 * que alguien lo active en el panel. El valor de serie vive en el catalogo de
 * `SettingKey` (regla dura 14) y no aqui.
 */
interface WeeklySummaryPreference
{
    /** Si esta instalacion tiene encendido el resumen semanal por correo. */
    public function weeklySummaryEnabled(): bool;
}
