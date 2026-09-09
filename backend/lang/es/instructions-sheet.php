<?php

declare(strict_types=1);

/*
 * Textos de la hoja de instrucciones que se entrega al empleado junto con la
 * tarjeta y el PIN (tarea 5.11b, RL-05; ficha, decisiones 1 y 2).
 *
 * POR QUE ESTAN AQUI. La hoja la produce el producto
 * (`GET /api/v1/credentials/instructions-sheet`), no el paquete de entrega:
 * lleva la marca de la instalacion, la direccion de SU portal y sale en
 * cualquiera de sus idiomas activos. Lo que lee una persona se traduce; los
 * identificadores del sistema siguen en ingles (doc 02 §3.5).
 *
 * TRES SITIOS LEEN ESTE FICHERO Y TIENEN QUE COINCIDIR:
 *
 *   1. La plantilla `resources/views/pdf/instructions-sheet.blade.php`, que lo
 *      pinta en una sola cara A4 (una prueba de integracion cuenta las paginas).
 *   2. `docs/cliente/hoja-empleado.md`, que reproduce estas frases para que
 *      RRHH sepa que entrega sin abrir el PDF. `ClientDocumentationTest` ata
 *      cada valor de este array (sin los marcadores) a esa guia: cambiar una
 *      frase aqui sin cambiarla alli rompe la CI.
 *   3. El quiosco. Las confirmaciones que se describen son las que muestra
 *      `frontend-kiosk` (`scan.pending.badge`, `scan.debounced.title`, `scan.rejected.title`
 *      y `pin.entryButton` de sus `locales`): «Pendiente de validar», «Código no
 *      válido», «Ficha con tu código y PIN». `InstructionsSheetTextsTest` lo ata. Si el quiosco cambia un
 *      texto, esta hoja tiene que decir lo mismo.
 *
 * LA ESTRUCTURA ES PLANA A PROPOSITO: `clave => frase`, sin anidar, para que la
 * prueba de la guia pueda recorrerlo sin conocer la plantilla. Marcadores
 * admitidos: `:app_name` (marca de la instalacion) y `:portal_url` (direccion
 * del portal). Ningun dato de persona: es la misma hoja para toda la plantilla.
 *
 * LO QUE NO DICE, Y ES DELIBERADO: nada de credencial en el movil ni de
 * biometria (reglas duras 11 y 20: no existen), nada de recuperar el PIN por
 * correo (ADR-015: se pide en mano a RRHH), y nada que se «bloquee» por licencia
 * (ADR-019).
 */

return [

    'title' => 'Cómo fichar con tu tarjeta',
    'intro' => 'Esta hoja va con tu tarjeta y tu PIN. Guárdala: explica lo que necesitas para fichar y para consultar tu registro horario.',

    'scan_title' => '1. Fichar',
    'scan_body' => 'Acerca la tarjeta a la cámara de la tablet, con el código hacia la pantalla, y espera la confirmación. El mismo gesto sirve para la entrada y para la salida.',

    'results_title' => '2. Qué significa lo que ves en pantalla',
    'result_ok' => '«Entrada» o «Salida» con la hora: tu fichaje ha quedado registrado. No hace falta nada más.',
    'result_pending' => '«Pendiente de validar»: la tablet está sin red en ese momento, pero tu fichaje ya está guardado y se enviará solo. No lo repitas.',
    'result_debounced' => '«Ya has fichado hace unos segundos»: no se ha anotado nada nuevo. Si querías fichar la salida, espera un momento y vuelve a intentarlo.',
    'result_rejected' => '«Código no válido»: la tablet no ha podido leer tu tarjeta. Ficha con tu código y tu PIN y avisa a tu responsable.',

    'no_card_title' => '3. Si no tienes la tarjeta',
    'no_card_body' => 'Pulsa «Ficha con tu código y PIN» en la tablet, escribe tu código de empleado y tu PIN de 6 dígitos. Tu fichaje cuenta igual. Si has perdido la tarjeta, díselo a tu responsable ese mismo día: se anula y te dan otra.',

    'portal_title' => '4. Consultar tu registro',
    'portal_body' => 'Puedes ver tus jornadas y descargar tu registro cuando quieras, desde un equipo conectado a la red del hotel o desde donde te indique tu empresa, en esta dirección:',
    'portal_credentials' => 'Entras con tu código de empleado y tu PIN. El PIN es el mismo que usas en la tablet.',
    'portal_pin_reset' => 'Si olvidas el PIN, pídele uno nuevo a tu responsable: se entrega en mano y nunca por correo.',

    'problems_title' => '5. Si algo no cuadra',
    'problems_body' => 'Si olvidaste fichar o ves un dato incorrecto en tu registro, avisa a tu responsable. Lo corrige y la corrección queda anotada con quién la hizo, cuándo y por qué; tu dato anterior se conserva.',

    'contact_title' => 'Persona de contacto',
    'contact_line' => 'Tu responsable o RRHH:',

    'footer' => ':app_name · Registro horario. Esta hoja no contiene datos personales.',

];
