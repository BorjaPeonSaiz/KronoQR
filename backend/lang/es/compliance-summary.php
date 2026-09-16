<?php

declare(strict_types=1);

/*
 * Vista de cumplimiento (RF-PA-06, tarea 3.4).
 *
 * LOS CRITERIOS DE EVALUACION SON PARTE DEL AVISO, NO DE LA DOCUMENTACION.
 * Un aviso cuyo criterio no se ve es un aviso que nadie defiende ante un
 * empleado: «te marca descanso insuficiente» sin decir que se compara la ultima
 * salida de un dia con la primera entrada del siguiente acaba discutiendose en
 * una reunion, no en el codigo.
 *
 * Las claves las decide `ReadComplianceSummary`, que las transporta SIN traducir
 * porque el dominio no tiene idioma. La traduccion es del `Resource`, con el
 * idioma de la peticion.
 *
 * Cada linea se escribe para alguien de RRHH, no para quien programo esto: dice
 * QUE se ha medido, no como se ha implementado. Y ninguna nombra un umbral
 * concreto: los umbrales van en `meta.rules[]`, que los lee del perfil del centro
 * (regla dura 14) — escribir «12 h» aqui seria meter un umbral legal en el
 * repositorio.
 */

return [

    'criteria' => [

        'rest_between_workdays' => 'El descanso se mide entre jornadas: la última salida de una jornada frente a la primera entrada de la siguiente. El hueco entre dos tramos del mismo día no se evalúa todavía, porque hoy no se puede distinguir una pausa para comer del descanso entre dos turnos.',

        'daily_total' => 'La jornada diaria es la suma de los tramos cerrados del día, tal como está en el registro horario consolidado. No se recalcula para esta vista.',

        'week' => 'La semana se compone de siete días naturales desde el día en que la empieza el perfil, y se evalúa completa aunque el periodo consultado la corte. La semana por encima de la jornada ordinaria es informativa: el cómputo legal es anual, así que se señala para contrastarla con el convenio y no abre incidencia.',

        'open_shift' => 'Una jornada con un tramo todavía abierto se marca y no alerta: ese tramo aún no aporta minutos y el total puede crecer.',

        'scope' => 'Solo aparecen las personas que están dentro del alcance de quien consulta, incluidas las que causaron baja durante el periodo.',
    ],
];
