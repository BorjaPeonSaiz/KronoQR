<?php

declare(strict_types=1);

/*
 * Textos de la licencia (RF-PD-04, RF-PD-05, tarea 5.3).
 *
 * ESTAN ESCRITOS PARA EL CLIENTE, NO PARA NOSOTROS. Los lee la persona de
 * informatica del hotel, probablemente con prisa y probablemente con un
 * problema, y por eso cada mensaje dice tres cosas: que pasa, que sigue
 * funcionando y que hacer. ADR-019 lo exige por escrito («degradacion honesta»)
 * y no es una formula de cortesia: sin la segunda frase, un aviso de licencia
 * caducada se lee como «el sistema esta roto» y genera una llamada.
 *
 * `unavailable` son los textos del `402` de una funcionalidad accesoria;
 * `errors` los de una clave que no se puede activar.
 */

return [

    /*
     * Formato de fecha del idioma: 31/12/2026.
     *
     * La fecha del aviso de degradacion la formatea el BORDE en el idioma
     * negociado, no el dominio: si la formateara el dominio, el mensaje ingles
     * saldria con la fecha en formato español.
     */
    'since_format' => 'd/m/Y',

    'attributes' => [
        'signed_key' => 'clave de licencia',
    ],

    /*
     * El `402` de una funcionalidad accesoria. Se elige por MOTIVO y no por
     * funcionalidad: lo que hay que hacer depende de por que no esta disponible,
     * no de cual sea. Los cuatro primeros nombran ademas lo que sigue estando,
     * porque quien lee esto necesita sacar las horas de este mes de todas formas.
     */
    'unavailable' => [
        'license_expired' => 'Esta función no está disponible porque la licencia caducó el :since. '
            .'El fichaje, la consulta de jornadas, el portal del empleado y la exportación para la '
            .'Inspección de Trabajo siguen funcionando con normalidad. Renueva la licencia con tu '
            .'proveedor y actívala en Configuración › Licencia para recuperarla.',
        'license_absent' => 'Esta función no está disponible porque todavía no se ha activado ninguna '
            .'licencia. El fichaje, la consulta de jornadas, el portal del empleado y la exportación '
            .'para la Inspección de Trabajo funcionan con normalidad. Activa la clave que te entregó '
            .'tu proveedor en Configuración › Licencia.',
        'license_unverifiable' => 'Esta función no está disponible porque la licencia guardada no se '
            .'puede verificar. El fichaje, la consulta de jornadas, el portal del empleado y la '
            .'exportación para la Inspección de Trabajo funcionan con normalidad. Revisa el estado en '
            .'Configuración › Licencia: ahí se explica qué hacer.',
        'license_not_yet_valid' => 'Esta función estará disponible a partir del :since, que es cuando '
            .'empieza la vigencia de la licencia activada. Hasta entonces no hay que hacer nada: el '
            .'resto del sistema funciona con normalidad.',
        'not_in_plan' => 'Esta función no está incluida en el plan contratado. Habla con tu proveedor '
            .'si quieres ampliarlo.',
        'unknown' => 'Esta función no está disponible con la licencia actual. Revisa su estado en '
            .'Configuración › Licencia.',
    ],

    'errors' => [
        /*
         * Una clave que no se ha podido activar. Cuatro motivos y cuatro
         * acciones distintas: copiar otra vez, pedir una clave nueva, avisar de
         * un fallo de emision, o revisar el despliegue. Un unico mensaje
         * generico obligaria a llamar por telefono para saber cual de las
         * cuatro.
         */
        'rejected' => [
            'malformed' => 'La clave está incompleta o cortada, que es lo que suele pasar al copiarla '
                .'de un correo. Cópiala entera —empieza por «KQL1.» y no lleva espacios ni saltos de '
                .'línea— e inténtalo otra vez. La licencia anterior sigue intacta.',
            'bad_signature' => 'Esta clave no la emitió el fabricante de esta versión, o se ha '
                .'modificado por el camino. Pide una clave nueva a tu proveedor. La licencia anterior '
                .'sigue intacta.',
            'invalid_payload' => 'La clave está firmada, pero le falta información. Es un fallo de '
                .'emisión, no del copiado: avisa a tu proveedor y pide una clave nueva. La licencia '
                .'anterior sigue intacta.',
            'no_public_key' => 'Esta instalación no puede verificar ninguna licencia porque no lleva '
                .'la clave pública del fabricante. No es un problema de tu clave, es del despliegue: '
                .'avisa a tu proveedor indicando la versión que devuelve GET /api/v1/health.',
        ],

        // Fallos de EMISION: la firma cuadraba y el contenido no sirve. El
        // cliente no puede arreglarlos, y por eso los cuatro terminan igual.
        'missing_field' => 'A la clave le falta el campo «:field». Es un fallo de emisión: pide una clave nueva a tu proveedor.',
        'field_not_text' => 'El campo «:field» de la clave no tiene un valor válido. Es un fallo de emisión: pide una clave nueva a tu proveedor.',
        'field_not_integer' => 'El campo «:field» de la clave tendría que ser un número. Es un fallo de emisión: pide una clave nueva a tu proveedor.',
        'limit_not_positive' => 'El campo «:field» de la clave vale :value y tendría que ser mayor que cero. Es un fallo de emisión: pide una clave nueva a tu proveedor.',
        'field_not_a_date' => 'El campo «:field» de la clave no es una fecha válida. Es un fallo de emisión: pide una clave nueva a tu proveedor.',
        'validity_inverted' => 'La vigencia de la clave termina (:until) antes de empezar (:from). Es un fallo de emisión: pide una clave nueva a tu proveedor.',
        'features_not_a_list' => 'La lista de funcionalidades de la clave no tiene el formato esperado. Es un fallo de emisión: pide una clave nueva a tu proveedor.',
    ],
];
