<?php

declare(strict_types=1);

/*
 * Textos de la telemetria opcional (RF-PD-12, ADR-020, ADR-023, tarea 5.10).
 *
 * ESTAN ESCRITOS PARA EL CLIENTE. Los lee quien esta decidiendo si deja que su
 * instalacion envie algo al fabricante, y esa decision se toma con la lista de
 * campos delante — por eso el comando imprime el documento entero y por eso
 * estos textos remiten a `docs/cliente/configuracion.md` §3 quinquies.
 *
 * Ni una linea da a entender que activarla sea lo recomendable, ni que no
 * activarla tenga consecuencias: RF-PD-12 exige que el sistema funcione
 * IDENTICAMENTE sin ella, «sin degradacion, sin avisos, sin recordatorios
 * insistentes».
 */

return [

    'title' => 'Telemetria de la instalacion',

    'state' => [
        'enabled' => 'ACTIVADA. Se envia un documento como el de abajo una vez por semana.',
        'disabled' => 'NO ACTIVADA. No se envia nada, y el producto funciona exactamente igual.',
    ],

    'blocked' => [
        'disabled_by_configuration' => 'TELEMETRY_ENABLED esta en false, que es el valor de serie del producto.',
        'endpoint_not_configured' => 'TELEMETRY_ENDPOINT esta vacio: no hay ningun destino configurado.',
        'endpoint_not_https' => 'El destino tiene que empezar por https://. Un destino sin cifrar se rechaza como si no hubiera ninguno: el documento saldria en claro por tu red.',
        'not_in_license' => 'Tu licencia no incluye la telemetria, o esta caducada. No pasa nada mas: la telemetria es accesoria.',
    ],

    'document' => [
        'header' => 'Esto es EXACTAMENTE lo que se enviaria, campo a campo:',
        'footer' => 'Cada campo esta explicado en docs/cliente/configuracion.md, seccion «3 quinquies. Telemetria».',
        'never' => 'Nunca lleva nombres, correos, codigos de empleado, horas de fichaje, rutas de tu servidor, la URL de tu instalacion ni la razon social de tu licencia.',
    ],

    'identity' => [
        'label' => 'Identificador de esta instalacion',
        'explanation' => 'Es un numero aleatorio que genera tu propia instalacion. No sale de tu licencia ni de tu nombre. Si borras :path, se estrena otro.',
        'provisional' => 'ESTE IDENTIFICADOR ES PROVISIONAL: mirar no deja rastro, asi que todavia no se ha guardado nada. El definitivo se acuñara y quedara en :path la primera vez que se envie.',
    ],

    'history' => [
        'never' => 'Todavia no se ha intentado ningun envio.',
        'last_attempt' => 'Ultimo intento:   :at',
        'last_success' => 'Ultimo envio correcto: :at',
        'last_failure' => 'Ultimo fallo:     :failure',
    ],

    'send' => [
        'skipped' => 'No se ha enviado nada, y no se ha construido nada.',
        'delivered' => 'Enviado correctamente (codigo :status, intentos: :attempts).',
        'failed' => 'No se ha podido enviar (:failure, intentos: :attempts). No pasa nada: se reintentara la semana que viene y el producto funciona igual.',
    ],

    'how_to' => [
        'enable' => 'Para activarla, pon TELEMETRY_ENABLED=true y TELEMETRY_ENDPOINT=<direccion> en tu .env.',
        'disable' => 'Para desactivarla, pon TELEMETRY_ENABLED=false en tu .env. No hace falta nada mas.',
        'send' => 'Para enviarla ahora mismo:  php artisan product:telemetry --send',
    ],

];
