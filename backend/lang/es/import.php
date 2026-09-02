<?php

declare(strict_types=1);

/*
 * Textos de la importacion masiva de plantilla (RF-GP-05, tarea 5.5).
 *
 * LOS LEE QUIEN ESTA CARGANDO LA PLANTILLA DE UN HOTEL, normalmente el día antes
 * de abrir y con cuarenta líneas delante. Por eso cada mensaje dice **qué hacer
 * con esa línea**, no solo qué tiene mal: un «formato inválido» obliga a
 * adivinar, y adivinar cuarenta veces es lo que convierte una importación en una
 * llamada a soporte.
 *
 * NINGUNO NOMBRA EL VALOR DE LA CELDA. Quien lee el informe tiene el fichero
 * delante y el número de línea; repetir aquí el correo o el documento sería
 * meter un dato personal en un texto que puede acabar copiado en un correo.
 */

return [

    'unreadable_file' => 'No se ha podido leer el fichero. Comprueba que es un CSV o un XLSX, que la '
        .'primera fila son los nombres de las columnas y que no está vacío. Si lo has exportado desde '
        .'otro programa, vuelve a exportarlo como CSV y súbelo otra vez.',

    'too_many_rows' => 'El fichero tiene más de :max líneas y no se ha importado nada. Pártelo en varios '
        .'ficheros más pequeños e impórtalos uno a uno. Si tu plantilla es realmente mayor, sube el '
        .'límite con WORKFORCE_IMPORT_MAX_ROWS en el fichero .env del servidor.',

    'messages' => [

        'missing_identity' => 'Esta línea no trae ni documento de identidad ni correo, así que no habría '
            .'forma de reconocerla si vuelves a importar el fichero y se duplicaría. Añade la columna del '
            .'documento (DNI, NIE o pasaporte) o la del correo.',

        'missing_first_name' => 'Falta el nombre.',
        'missing_last_name' => 'Faltan los apellidos.',

        'missing_hired_at' => 'Falta la fecha de alta. Es obligatoria: marca desde cuándo se le pueden '
            .'imputar jornadas y desde cuándo cuenta la conservación del registro.',

        'invalid_email' => 'El correo de esta línea no tiene forma de correo. Corrígelo, o déjalo vacío: '
            .'el correo es opcional y el acceso al portal es con código de empleado y PIN.',

        'invalid_hired_at' => 'La fecha de alta no se entiende. Escríbela como 2026-03-15 o como '
            .'15/03/2026. No se aceptan fechas en formato mes/día/año: 03/04/2026 se lee siempre como 3 de '
            .'abril.',

        'invalid_national_id' => 'El documento de identidad es demasiado corto. Escríbelo completo, con '
            .'la letra si la tiene.',

        'unknown_department' => 'Ese departamento no existe todavía. Créalo antes en el panel, o corrige '
            .'el nombre de la celda; se compara sin distinguir mayúsculas ni tildes.',

        'duplicate_in_file' => 'Esta persona ya aparece en una línea anterior del mismo fichero. Se '
            .'importa la primera y esta se descarta: borra la repetida o unifica las dos líneas.',

        'email_taken' => 'Ese correo ya es de otra persona de la plantilla. Comprueba de quién es antes '
            .'de seguir: o hay una errata, o son dos fichas de la misma persona.',

        'hired_at_not_updated' => 'La fecha de alta guardada NO se modifica desde una importación, así '
            .'que la de esta línea se ignora. Si de verdad hay que cambiarla, hazlo en la ficha de la '
            .'persona: mueve el punto desde el que cuenta la conservación de su registro.',

        'unknown_column' => 'La columna «:column» no se usa. Si esperabas que sí, revisa cómo se llama: '
            .'los nombres que el sistema reconoce están en la guía de configuración, y puedes añadir los '
            .'tuyos sin tocar nada del programa.',
    ],
];
