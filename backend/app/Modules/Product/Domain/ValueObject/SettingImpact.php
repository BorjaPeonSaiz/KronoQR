<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Sobre que actua una clave de configuracion cuando cambia.
 *
 * **Para que existe.** El asiento de auditoria de `PATCH /api/v1/settings` tiene
 * que decir si la clave modificada **afecta al calculo de horas** (doc 01 §5,
 * nota de `installation_settings`: *«todo cambio queda auditado, porque algunos
 * afectan al calculo de horas»*). Quien lea ese asiento dentro de dos años, con
 * una discrepancia de nomina delante, necesita separar «esto pudo cambiar las
 * horas» de «esto cambio un color».
 *
 * **Por que un enum de cuatro casos y no un booleano.** Con un booleano habria que
 * mentir en tres de los cuatro umbrales operativos: el maximo de tramo (RN-08),
 * la tolerancia de desfase (RF-AT-10) y el transito minimo (RN-16) **no cambian
 * ni un minuto del registro** —ninguno cierra, corrige ni descarta nada
 * (doc 01 §4, regla dura 19)—, pero cambian que incidencias se abren. Marcarlos
 * `true` diluye la señal justo donde importa; marcarlos `false` pierde que
 * alteran el expediente de cumplimiento. El booleano que el asiento necesita
 * sigue existiendo: {@see self::affectsWorkedHours()}.
 */
enum SettingImpact: string
{
    /**
     * Cambiarla puede cambiar los **minutos** que quedan registrados.
     *
     * Caso claro: la ventana anti-rebote (RF-AT-06). Un escaneo que la ventana
     * se traga no cierra el tramo, y el total de la jornada sale distinto.
     */
    case WORKED_HOURS = 'worked_hours';

    /**
     * Cambiarla no altera los minutos, pero si **que incidencias se abren** para
     * revision humana (RN-08, RN-16, RF-AT-10).
     */
    case COMPLIANCE_REVIEW = 'compliance_review';

    /**
     * Cambiarla solo altera lo que se ve: marca e idiomas (RF-PD-08).
     *
     * **No es el cajon de «todo lo demas».** Una clave que no mueve minutos y no
     * abre incidencias puede seguir teniendo consecuencias graves —encender una
     * salida de datos personales lo es—, y para eso esta el caso de abajo. Aqui
     * entra lo que de verdad solo se ve.
     */
    case PRESENTATION = 'presentation';

    /**
     * Cambiarla **enciende o apaga una salida de datos personales de la
     * instalacion**, sin tocar el registro horario (tarea 3.12).
     *
     * Hoy la lleva `WEEKLY_SUMMARY_EMAIL` (RF-PR-05), y por eso existe el caso:
     * activarla hace que cada lunes salgan por SMTP los nombres y las horas de
     * la plantilla hacia el buzon de cada responsable de departamento —una copia
     * fuera del producto, reenviable, cuyo plazo lo fija ese buzon y no la
     * retencion del sistema—. Es el cambio con mas consecuencias en privacidad
     * que un administrador puede hacer desde el panel, y clasificarlo como
     * `PRESENTATION` lo dejaba en el asiento de `installation_setting.changed`
     * en el mismo cajon que un logotipo: quien revise ese trail —una auditoria
     * de proteccion de datos, la respuesta a una brecha (RL-15)— tiene que poder
     * separarlos con una sola consulta.
     *
     * **No enciende `affectsWorkedHours()`**: no mueve ni un minuto del
     * registro. Lo que cambia es a donde van los datos, no cuanto suman.
     */
    case DATA_DISCLOSURE = 'data_disclosure';

    /**
     * El booleano del asiento de auditoria (paso 8 de la tarea 5.1).
     */
    public function affectsWorkedHours(): bool
    {
        return $this === self::WORKED_HOURS;
    }
}
