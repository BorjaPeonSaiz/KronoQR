<?php

declare(strict_types=1);

/*
 * Informes por periodo (RF-IN-01, RF-IN-02, RF-IN-03, tarea 2.8).
 *
 * LOS CRITERIOS DE INCLUSION SON PARTE DEL INFORME, NO DE LA DOCUMENTACION.
 * `/informe-nuevo` lo exige por escrito: un informe de horas sin ellos es una
 * tabla de numeros que cada persona interpreta a su manera —¿cuenta el turno
 * que sigue abierto? ¿y el tramo que se anulo?— y esa interpretacion acaba
 * discutiendose en una reunion de nomina, no en el codigo.
 *
 * Las claves las decide `GeneratePeriodReport`, que las transporta SIN traducir
 * porque el dominio no tiene idioma. La traduccion es del `Resource`, y la
 * misma lista la escribira la tarea 2.9 en la cabecera del CSV y del PDF.
 *
 * Cada linea se escribe para alguien de RRHH, no para quien programo esto:
 * dice QUE se ha contado, no como se ha implementado.
 */

return [

    'criteria' => [

        'source' => 'Los totales salen del registro horario ya consolidado (proyección de jornadas), no se recalculan para este informe.',

        'work_date' => 'Cada turno se atribuye entero a la jornada en la que empezó, en la zona horaria del centro: un turno de 22:00 a 06:00 cuenta en el día de entrada y no se parte a medianoche.',

        'voided' => 'No se cuentan los tramos anulados ni las versiones sustituidas por una corrección: solo la versión vigente de cada tramo.',

        'incidents' => 'Una incidencia sin resolver no descuenta horas. Los días con incidencia se cuentan aparte, en la columna correspondiente, para que se revisen.',

        'empty_days' => 'Los días sin actividad aparecen con cero y no se omiten. En los agregados por departamento o centro, los contadores de días son días-persona.',

        'contracted' => 'Las horas contratadas del periodo se prorratean por día natural de vigencia del contrato: días de vigencia × horas semanales ÷ 7. Los días sin contrato vigente no suman y se informan aparte.',

        'scope' => 'El informe solo incluye a las personas que están dentro del alcance de quien lo pide, incluidas las que causaron baja durante el periodo.',

        'open_shifts_excluded' => 'Los días con un turno todavía abierto no aportan minutos, porque la jornada aún no ha terminado. Cuentan como día con actividad y se indican aparte.',

        'open_shifts_included' => 'Los días con un turno todavía abierto aportan los minutos que ya tienen cerrados; el turno en curso no suma nada hasta que se cierre.',

        'iso_week' => 'Las semanas empiezan en lunes (semana ISO 8601) y se recortan al rango pedido.',
    ],
];
