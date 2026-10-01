<?php

declare(strict_types=1);

/*
 * Textos de la emision de credenciales que una persona lee en el panel o en la
 * consola (RF-QR-01, RN-14).
 */

return [

    /*
     * RV-2: el titulo del PDF de credenciales. Va al metadato del documento y
     * al historial de descargas del navegador, asi que nunca lleva nombres
     * (regla dura 21).
     */
    'document' => [
        'card_title' => 'Credencial',
        'sheet_title' => 'Credenciales',
    ],

    'errors' => [
        // RN-14: la baja revoca sus tarjetas y no se le emite otra.
        'holder_offboarded' => 'Esta persona esta de baja: no se le puede emitir ni reemitir una tarjeta.',
    ],

];
