<?php

declare(strict_types=1);

/*
 * Textos del quiosco y de su emparejamiento (RF-PD-06, tarea 5.6).
 *
 * QUE ENTRA AQUI Y QUE NO. Solo los mensajes que una PERSONA lee en un
 * formulario del panel. Los rechazos genericos —`PairingRejected` y
 * `PairingCodeRejected`— NO se traducen y no deben traducirse: su `detail` esta
 * fijado campo a campo en el contrato y cualquier variacion, incluido el idioma,
 * seria un canal por el que distinguir causas (regla dura 17, RS-03). El cliente
 * muestra su propio texto de i18n a partir de `type`.
 *
 * Por eso aqui hay un solo mensaje: el del nombre de quiosco en uso, que es la
 * unica cosa que el `confirm` si distingue, porque le cambia a quien lo lee lo
 * que tiene que hacer.
 */

return [

    'errors' => [
        /*
         * Ya hay un quiosco ACTIVO con ese nombre.
         *
         * Dice tambien la alternativa —desvincular el anterior— porque es el
         * escenario real: quien llega aqui casi siempre esta sustituyendo una
         * tablet averiada y lo que quiere es reutilizar el nombre. Si el quiosco
         * anterior estuviera revocado, este mensaje no habria salido: la
         * confirmacion habria reactivado su fila (ADR-028).
         */
        'device_name_taken' => 'Ya hay un quiosco activo con ese nombre. Elige otro nombre o desvincula antes el anterior.',
    ],

];
