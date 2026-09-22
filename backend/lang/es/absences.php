<?php

declare(strict_types=1);

/*
 * Textos de las ausencias (RF-GP-04, tarea 3.10).
 *
 * LOS LEE QUIEN ESTÁ CARGANDO UN CUADRANTE DE VACACIONES, normalmente con la
 * hoja de cálculo abierta al lado. Por eso cada mensaje dice **qué hacer con esa
 * línea**, no solo qué tiene mal: un «formato inválido» obliga a adivinar, y
 * adivinar cuarenta veces es lo que convierte una carga en una llamada a
 * soporte.
 *
 * NINGUNO NOMBRA EL VALOR DE LA CELDA, ni el código de empleado, ni la nota.
 * Quien lee el informe tiene el fichero delante y el número de línea; repetir
 * aquí el contenido sería meter un dato personal —y, si es una baja, un dato de
 * salud— en un texto que puede acabar copiado en un correo (regla dura 21).
 */

return [

    'import' => [

        'messages' => [

            'missing_employee_code' => 'Falta el código de empleado. Es la columna con la que se sabe de '
                .'quién es la ausencia: lo encuentras en la ficha de la persona y en su tarjeta.',

            'unknown_employee' => 'No hay ninguna persona con ese código de empleado. Compruébalo en su '
                .'ficha; no se busca por el nombre, porque dos personas pueden llamarse igual y la '
                .'ausencia acabaría en el registro de quien no es.',

            'missing_type' => 'Falta el tipo de ausencia. Escribe vacaciones, baja, permiso u otro.',

            'unknown_type' => 'Ese tipo de ausencia no existe. Los admitidos son vacaciones, baja, '
                .'permiso y otro; se comparan sin distinguir mayúsculas ni tildes.',

            'missing_starts_on' => 'Falta el primer día de la ausencia.',
            'missing_ends_on' => 'Falta el último día de la ausencia. Si dura un solo día, repite la '
                .'misma fecha en las dos columnas.',

            'invalid_starts_on' => 'El primer día no se entiende. Escríbelo como 2026-03-15 o como '
                .'15/03/2026. No se aceptan fechas en formato mes/día/año: 03/04/2026 se lee siempre '
                .'como 3 de abril.',

            'invalid_ends_on' => 'El último día no se entiende. Escríbelo como 2026-03-15 o como '
                .'15/03/2026. No se aceptan fechas en formato mes/día/año: 03/04/2026 se lee siempre '
                .'como 3 de abril.',

            'inverted_period' => 'La ausencia terminaría antes de empezar. Revisa las dos fechas: son '
                .'días completos y los dos extremos cuentan.',

            'note_required' => 'El tipo «otro» necesita una nota que lo explique; sin ella la ausencia '
                .'no dice nada en el informe. No escribas un diagnóstico médico.',

            'note_too_long' => 'La nota pasa de 500 caracteres y no cabe. Resúmela: una nota es una '
                .'aclaración breve, no un parte. Y recuerda que no debe llevar un diagnóstico médico.',

            'overlapping_absence' => 'Esa persona ya tiene una ausencia registrada en alguno de esos '
                .'días. Revisa su historial en la pantalla de ausencias: si la registrada está mal, '
                .'corrígela o anúlala allí, que es donde queda constancia de quién lo hizo y por qué.',

            'duplicate_in_file' => 'Esta ausencia se pisa con otra línea anterior del mismo fichero. Se '
                .'carga la primera y esta se descarta: borra la repetida o unifica las dos líneas.',

            'unknown_column' => 'La columna «:column» no se usa. Si esperabas que sí, revisa cómo se '
                .'llama: los nombres que el sistema reconoce están en la guía de configuración, y '
                .'puedes añadir los tuyos sin tocar nada del programa.',
        ],
    ],
];
