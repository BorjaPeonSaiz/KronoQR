<?php

declare(strict_types=1);

/*
 * Textos del asistente de puesta en marcha (RF-PD-03, tarea 5.5).
 *
 * ESTAN ESCRITOS PARA QUIEN ESTA PONIENDO EN MARCHA EL SISTEMA, que casi nunca
 * es quien lo va a usar a diario: es la persona de informatica del hotel, con
 * prisa y sin conocer el producto. Cada mensaje dice **que hacer**, no solo que
 * ha fallado, porque quien lo lee no tiene la consola del servidor delante y no
 * puede llamarnos.
 */

return [

    'unknown_step' => 'El asistente de puesta en marcha no tiene ningún paso llamado «:step». '
        .'Consulta los que hay en GET /api/v1/setup/status.',

    'step_is_derived' => 'El paso «:step» no se marca a mano: se completa haciéndolo. '
        .'El del administrador se completa cuando hay una cuenta con su segundo factor activado, '
        .'y el del centro cuando el centro existe.',

    'step_is_not_skippable' => 'El paso «:step» no se puede omitir. Los umbrales del perfil de convenio '
        .'hay que contrastarlos con el convenio que os aplica antes de empezar a calcular horas: '
        .'revísalos y confírmalos aunque los dejes como vienen.',

    'steps_still_pending' => 'Faltan pasos por resolver: :steps. Complétalos, u omite los que sean '
        .'omitibles, antes de cerrar el asistente.',

    'already_completed' => 'El asistente de puesta en marcha ya está terminado y no se vuelve a abrir. '
        .'Lo que necesites cambiar se cambia en Configuración, y queda registrado con su autor y su fecha.',

    /*
     * NOMBRA LA RUTA DE ACCESO A PROPOSITO. El caso que ocurre de verdad es que
     * alguien creó la cuenta, se le cerró la pestaña antes de escanear el QR del
     * autenticador y vuelve a empezar: sin decirle por dónde entrar se queda
     * fuera de su propia instalación, con la cuenta creada y sin segundo factor.
     */
    'administrator_exists' => 'Esta instalación ya tiene una cuenta de gestión, así que el asistente no '
        .'vuelve a crear la primera. Entra con tu correo y tu contraseña en /api/v1/auth/login (o en la '
        .'pantalla de acceso del panel); si todavía no has activado tu segundo factor, la respuesta te lo pedirá.',

    /*
     * NO HAY CLAVE PARA «YA HAY CENTRO», y no es un olvido. Ese caso es
     * `SiteAlreadyConfigured`, que es un `WorkforceConflict` y lo renderiza el
     * manejador generico de ese tipo con el texto de la propia excepcion, igual
     * que los demas conflictos de plantilla. Duplicarlo aqui obligaria a
     * registrar un `render` mas especifico y a depender del orden en que Laravel
     * los resuelve, para ganar una traduccion en un mensaje que la persona ve
     * una sola vez.
     */
];
