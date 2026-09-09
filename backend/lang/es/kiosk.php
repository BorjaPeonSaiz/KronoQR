<?php

declare(strict_types=1);

/*
 * Textos del quiosco y de su emparejamiento (RF-PD-06, tarea 5.6) y del informe
 * de `php artisan kiosk:health` (RF-PA-07, tarea 5.11).
 *
 * QUE ENTRA AQUI Y QUE NO. Solo los mensajes que una PERSONA lee en un
 * formulario del panel o en la consola. Los rechazos genericos
 * —`PairingRejected` y `PairingCodeRejected`— NO se traducen y no deben
 * traducirse: su `detail` esta fijado campo a campo en el contrato y cualquier
 * variacion, incluido el idioma, seria un canal por el que distinguir causas
 * (regla dura 17, RS-03). El cliente muestra su propio texto de i18n a partir de
 * `type`.
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

    /*
     * El informe de `php artisan kiosk:health` (RF-PA-07, doc 02 Anexo C).
     *
     * PARA QUIEN ESTA ESCRITO. Para la persona del hotel que tiene una tablet
     * delante que no responde, o que acaba de colgar una nueva en la pared. No
     * conoce el sistema por dentro y no tiene por que: cada linea con hallazgo
     * dice QUE MIRAR, no solo que algo va mal. El fabricante no accede a este
     * servidor (ADR-016), asi que un mensaje que no diga que hacer solo deja la
     * opcion de llamar por telefono.
     *
     * VIVE AQUI Y NO EN EL COMANDO por el mismo motivo medido que el informe de
     * `product:doctor`: con el marco escrito en PHP, la tabla salia en español y
     * el resto en el idioma de la instalacion en cuanto `APP_LOCALE` y
     * `LOCALE_DEFAULT` no coincidian, que es el caso normal.
     */
    'health' => [

        'title' => 'Salud de los quioscos — :moment (:zone)',

        /*
         * El formato de la fecha absoluta es un TEXTO TRADUCIBLE, no una
         * constante: `09/09/2026` y `2026-09-09` son el mismo dia para una
         * maquina y dias distintos para dos personas.
         */
        'absolute_format' => 'd/m/Y H:i:s',

        'column' => [
            'name' => 'Quiosco',
            'status' => 'Estado',
            'version' => 'Version',
            'last_seen' => 'Ultimo contacto',
            'queue' => 'Cola',
            'verdict' => 'Veredicto',
        ],

        // El catalogo real de `devices.status` (doc 01 §5.5): dos valores.
        'status' => [
            'active' => 'activo',
            'revoked' => 'revocado',
        ],

        'verdict' => [
            'ok' => 'ok',
            'warning' => 'aviso',
            'failure' => 'FALLO',
            'revoked' => '—',
        ],

        /*
         * Una duracion, en abreviaturas y sin plurales: cabe en una celda y se
         * lee de un vistazo, que es para lo que esta.
         *
         * SEPARADA DE `relative` a proposito: la misma duracion se dice «hace
         * 3 h 4 min» en la columna y «lleva 3 h 4 min sin dar señales» en el
         * consejo, y en ingles el «ago» va detras. Con las dos cosas en la misma
         * clave, un idioma de los dos acaba con el orden cambiado.
         */
        'duration' => [
            'seconds' => ':count s',
            'minutes' => ':count min',
            'hours' => ':hours h :minutes min',
            'days' => ':count d',
        ],

        'relative' => [
            'never' => 'nunca',
            'ago' => 'hace :duration',
        ],

        'advice_header' => 'Que hay que mirar',

        /*
         * Una frase por causa, y la accion es distinta en cada una. Es la razon
         * de que el veredicto no baste: «aviso» no dice si hay que esperar o
         * mirar la red.
         */
        'advice' => [
            'queue_pending' => 'Late con normalidad, pero declara :queue fichaje(s) sin enviar. '
                .'Se envian solos en cuanto haya red. NO LO DESVINCULES hasta que la cola sea 0: '
                .'los fichajes sin enviar se pierden al revocar el token, y son registro horario de personas reales.',
            'late' => 'Lleva :elapsed sin dar señales y el latido va cada 60 s. '
                .'Lo primero que hay que mirar es la red del punto donde esta colgado: wifi, VLAN de quioscos y que la tablet siga encendida.',
            'silent' => 'Lleva :elapsed sin dar señales. Ve a verlo: pantalla encendida, aplicacion abierta y wifi. '
                .'Mientras tanto sigue fichando y encolando en local (regla dura 19), pero nadie ve esos fichajes hasta que vuelva a hablar.',
            'awaiting_first_heartbeat' => 'Recien vinculado y todavia sin su primer latido. Es normal durante unos segundos: '
                .'la tablet recoge su token sola. Vuelve a ejecutar este comando en un minuto.',
            'never_seen' => 'Vinculado y sin haber latido NUNCA. La tablet no llego a recoger su token o no llega al servidor: '
                .'comprueba que la aplicacion esta abierta y que la URL del servidor es la correcta.',
        ],

        'fleet_empty' => 'Todavia no hay ningun quiosco vinculado. Vincula el primero desde el panel, '
            .'en «Quioscos», o con: php artisan kiosk:pairing-code {codigo} --name="Recepcion"',

        'fleet_all_revoked' => 'Ningun quiosco esta activo: ahora mismo nadie puede fichar con la tarjeta. '
            .'Si estas sustituyendo una tablet averiada, vincula la nueva para cerrar el hueco.',

        'result' => 'Resultado: :label (codigo de salida :code)',

        'status_ok' => 'CORRECTO',
        'status_warning' => 'CON AVISOS',
        'status_failure' => 'CON FALLOS',

        'meaning_ok' => 'Todos los quioscos activos responden y no tienen nada encolado.',
        'meaning_warning' => 'Nada esta roto y se sigue fichando. Lo de arriba conviene mirarlo cuando puedas.',
        'meaning_failure' => 'Hay algun quiosco que no responde. Sigue el «Que hay que mirar» de cada uno. '
            .'Si necesitas ayuda, genera el paquete de diagnostico con `php artisan product:diagnostics` y enviaselo a soporte.',
    ],

];
