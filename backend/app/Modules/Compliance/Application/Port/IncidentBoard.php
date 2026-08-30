<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\Model\Incident;

/**
 * El lado de **lectura** de las incidencias: la bandeja del panel (RF-PA-05).
 *
 * ## Por que esta separado de `IncidentLedger`
 *
 * Aquel escribe —abre incidencias y registra su resolucion— y habla en
 * {@see Incident}, que es el agregado con
 * sus invariantes. Este solo lee, y lo que devuelve lleva ademas el nombre de la
 * persona afectada y el de las cuentas implicadas, que no son del agregado.
 * Juntarlos habria dado un puerto que sabe hacer de todo y una consulta de
 * bandeja capaz de escribir.
 *
 * ## No tiene ningun metodo que escriba, y no puede tenerlo
 *
 * Es la misma garantia estructural que `WorkDayLedger` en `Attendance`: la
 * bandeja no cierra turnos ni cambia horas (RN-08). Lo unico que se puede hacer
 * desde aqui es mirar.
 *
 * ## El alcance entra en la consulta
 *
 * RF-ID-03. Ninguno de los dos metodos filtra en memoria: {@see self::page()}
 * recibe el alcance y lo mete en el `WHERE`, y {@see self::row()} devuelve la
 * fila **sin acotar** a proposito, porque la comprobacion de alcance de un
 * recurso individual es un `403` con asiento y no una ausencia (docblock de
 * `ScopeGuard`). Si `row()` acotara, un responsable que pide una incidencia
 * ajena recibiria `404` y el intento no dejaria traza.
 */
interface IncidentBoard
{
    /**
     * La pagina de la bandeja que casa con los filtros, ordenada por severidad y
     * despues por deteccion mas reciente.
     *
     * Ese orden no es estetico: es el orden de trabajo, y coincide con el indice
     * parcial `incidents_open_by_assignee` para que la bandeja se resuelva sin
     * recorrer el historico de cuatro años (RL-02).
     */
    public function page(IncidentBoardQuery $query): IncidentBoardPage;

    /**
     * Una incidencia por su identificador, o `null` si no existe.
     *
     * `null` significa «no existe», nunca «no la puedes ver»: quien decide lo
     * segundo es `ScopeGuard`, y lo hace con `403` y con asiento en `audit_log`.
     */
    public function row(int $incidentId): ?IncidentBoardRow;
}
