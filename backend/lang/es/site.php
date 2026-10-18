<?php

declare(strict_types=1);

/*
 * Textos del centro de trabajo: el `409` de la zona horaria ya fija y la orden
 * `workforce:correct-site-timezone` (RN-23, ADR-056).
 *
 * La orden la ejecuta la persona de informatica del hotel en el servidor, casi
 * siempre a peticion de RRHH. Cada frase dice que pasa y que no pasa: lo que
 * esta en juego es como se presenta el registro horario de toda la plantilla.
 */

return [

    'errors' => [
        'timezone_locked' => 'Ya hay fichajes registrados con :timezone; la zona no se cambia desde la API.',
    ],

    'correct_timezone' => [
        'invalid_timezone' => 'La zona horaria «:value» no existe. Usa un identificador IANA como Europe/Madrid. No se ha cambiado nada.',
        'no_site' => 'La instalación todavía no tiene centro: la zona se elige en el asistente de puesta en marcha. No se ha cambiado nada.',
        'same_timezone' => 'El centro ya tiene la zona :timezone. No hay nada que corregir y no se ha cambiado nada.',
        'no_work_record' => 'Todavía no hay ningún fichaje: la zona se cambia desde el panel, en Departamentos, apartado Centro. No se ha cambiado nada.',
        'missing_options' => 'Para corregir la zona faltan --reason y --responsible. No se ha cambiado nada.',
        'reason_too_short' => 'El motivo tiene que explicar el error de la puesta en marcha: al menos :min caracteres.',
        'reason_too_long' => 'El motivo admite como mucho :max caracteres.',
        'responsible_inactive' => 'La cuenta :id no es una cuenta de gestión activa con rol de administración o de RRHH. La corrección la firma quien puede modificar el centro desde el panel. No se ha cambiado nada.',
        'summary_current' => 'Zona actual del centro: :timezone',
        'summary_new' => 'Zona corregida:          :timezone',
        'summary_entries' => 'Fichajes registrados:    :count tramo(s), de la jornada :first a la :last',
        'warning' => 'No se mueve ninguna jornada ni se recalcula nada: cada tramo conserva el día al que se atribuyó. '
            .'Todo el registro, también lo ya fichado, pasará a mostrarse y exportarse con la zona corregida. '
            .'Si algún tramo de cerca de medianoche debería estar en otra jornada, se anula y se da de alta en la correcta, cada paso con su motivo.',
        'confirm' => '¿Corregir la zona horaria del centro?',
        'needs_force' => 'Sin terminal no se puede confirmar: repite la orden con --force si has revisado lo de arriba. No se ha cambiado nada.',
        'done' => 'Zona corregida de :previous a :timezone. Queda registrado en la auditoría (site.updated) con el motivo y la cuenta responsable.',
    ],

];
