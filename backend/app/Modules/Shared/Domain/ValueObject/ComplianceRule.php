<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Las reglas de cumplimiento cuyo umbral fija el perfil del centro
 * (doc 01 §4, RF-PD-07).
 *
 * Son **tres y solo tres**: las que el doc 01 declara «parametros del perfil de
 * cumplimiento, no constantes». RN-01 a RN-09 y RN-13 a RN-15 son estructurales
 * y no se configuran; RN-08 y RN-16 llevan umbral pero **operativo**, y viven en
 * `installation_settings`.
 *
 * ## Por que un enum en Shared y no una constante en cada modulo
 *
 * Dos modulos necesitan hablar de la misma regla sin conocerse (doc 02 §1.6):
 * `Attendance`, que la evalua y la nombra `AnomalyType`, y `Product`, que edita
 * su umbral y lo nombra `ComplianceProfileField`. Sin un vocabulario comun, la
 * unica forma de que el panel supiera si RN-12 esta suspendida seria repetir esa
 * lista —y una lista repetida es una lista que se queda desactualizada el dia
 * que la tarea 3.5 la vacie en un solo sitio—.
 *
 * El valor de cada caso es el identificador del documento 01, que es como lo
 * nombra quien lee un asiento de auditoria o una inspeccion.
 */
enum ComplianceRule: string
{
    /** Descanso minimo entre el fin de un turno y el inicio del siguiente. */
    case MinimumRestBetweenWorkDays = 'RN-10';

    /** Jornada diaria ordinaria por encima de la cual se alerta. */
    case MaximumDailyWorkingTime = 'RN-11';

    /** Tramo continuo maximo sin pausa registrada. */
    case BreakInContinuousShift = 'RN-12';
}
