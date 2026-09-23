<?php

declare(strict_types=1);

/*
 * El aviso de que un informe en diferido esta listo (RF-IN-06, tarea 3.9).
 *
 * LO QUE ESTE CORREO NO LLEVA, y es lo primero: el enlace de descarga. Es de un
 * solo uso y caduca en quince minutos (ADR-041), asi que uno metido en un buzon
 * —donde puede tardar mas en llegar, o donde un antivirus de correo sigue los
 * enlaces de todo lo que entra— seria un enlace que no funciona cuando la
 * persona lo pulsa. Lo que lleva es donde mirar.
 *
 * TAMPOCO LLEVA NI UN NOMBRE DE EMPLEADO NI UNA HORA TRABAJADA (regla dura 21):
 * un correo sale hacia un servidor que puede ser de un tercero, y el contenido
 * del informe se queda en el fichero, detras del enlace.
 */

return [
    'mail' => [
        'subject' => 'Tu informe ya se puede descargar',
        'greeting' => 'Hola, :name:',
        'intro' => 'El informe de horas del :from al :to ya esta generado, en formato :format.',
        'rows' => 'Lleva :rows filas de datos.',
        'expires' => 'Podras descargarlo hasta el :date (hora UTC). Despues se borra solo del servidor.',
        'where' => 'Entra en el panel, apartado «Informes», y pulsa «Descargar» en la lista de exportaciones en segundo plano.',
        'single_use' => 'Cada descarga usa un enlace de un solo uso: si lo necesitas otra vez, vuelve a esa pantalla y pulsa de nuevo.',
        'footer' => 'Este aviso es automatico. No hace falta que respondas.',
    ],
];
