<?php

declare(strict_types=1);

/*
 * Modulo Workforce — hoy, la importacion masiva de plantilla (RF-GP-05).
 *
 * OJO A LA DISTINCION, LA MISMA QUE EN `config/product.php`: aqui va lo que no
 * tiene sentido editar sin reiniciar y no se audita. Lo que el cliente cambia a
 * diario —marca, idiomas, umbrales operativos— vive en `installation_settings`
 * y se edita desde el panel; los umbrales LEGALES viven en
 * `compliance_profiles`.
 *
 * TRES PARAMETROS Y NI UNO MAS. Cada uno es superficie que documentar, probar y
 * soportar, asi que los tres tienen que justificar por que un cliente real los
 * necesita distintos. Lo que **no** esta aqui, y es deliberado:
 *
 *   - **El delimitador del CSV**. Se detecta. Un Excel espanol exporta con `;` y
 *     uno ingles con `,`; preguntarselo a quien importa es pedirle que acierte a
 *     ciegas un detalle que el fichero ya dice.
 *   - **La codificacion**. Se detecta igual. `Windows-1252` es lo que sale de
 *     «Guardar como CSV» en un Windows en espanol, y el sintoma de no
 *     detectarlo son los apellidos con la ñ rota, que nadie relaciona con un
 *     parametro de configuracion.
 *   - **La columna del codigo de empleado**. No existe: el codigo lo genera el
 *     servidor y es opaco (doc 01 §5.5).
 */

return [

    'import' => [

        /*
         * Lineas de datos que se admiten en un fichero.
         *
         * POR QUE HAY UN TOPE. El informe de RF-GP-05 es **linea a linea**: se
         * construye entero en memoria para poder devolverlo, asi que el fichero
         * se lee en streaming pero el informe no es infinito. Sin tope, un CSV
         * de un millon de filas —el export completo de un ERP, que es
         * exactamente lo que alguien acabara arrastrando aqui— tumba el proceso
         * de PHP con un mensaje que no se parece a su causa.
         *
         * 500 ES EL TECHO DOCUMENTADO de una instalacion (doc 02, Anexo A: hasta
         * 500 empleados), y **se bajo desde 1000 en la revision de la 5.5** por
         * una razon medida y no estetica: cada alta calcula un hash bcrypt de su
         * PIN, que con el coste 12 de produccion cuesta unos 160 ms. Mil altas
         * eran 160 segundos, casi el triple del `max_execution_time` de 60 s, asi
         * que el tope anterior prometia un tamaño que el endpoint **no podia
         * cumplir**. El calculo se saco de la transaccion (ver
         * `ApplyEmployeeImport`), y aun asi el tope honesto es el que la
         * instalacion declara soportar.
         *
         * Si un cliente lo necesita mayor, lo sube — y si se pasa del tiempo de
         * ejecucion, lo que recibe es un fallo antes de escribir, no una
         * plantilla a medias. Lo que recibe al pasarse del tope **no es un
         * recorte silencioso** sino un informe con `truncated: true` que se niega
         * a aplicar nada.
         */
        'max_rows' => (int) env('WORKFORCE_IMPORT_MAX_ROWS', 500),

        /*
         * Tamano maximo del fichero, en kilobytes.
         *
         * POR QUE NO BASTA EL LIMITE DE NGINX. El borde corta en 8 MB y devuelve
         * un `413` sin cuerpo: quien lo recibe ve «error de red» y no tiene ni
         * idea de que su fichero es demasiado grande. Este limite se comprueba
         * en la aplicacion y produce un `422` que **dice que hacer**.
         *
         * 4096 KB es dos ordenes de magnitud por encima de lo que ocupa la
         * plantilla de un hotel (un XLSX de 500 personas ronda los 60 KB): el
         * peor fallo posible aqui seria rechazar un fichero legitimo.
         */
        'max_file_kilobytes' => (int) env('WORKFORCE_IMPORT_MAX_FILE_KILOBYTES', 4096),

        /*
         * Nombres de columna que el importador reconoce, por campo.
         *
         * ES CONFIGURACION Y NO CODIGO (regla dura 13,
         * [ADR-017](../../docs/adr/ADR-017-toda-diferencia-entre-clientes-es-configuracion.md)):
         * el fichero que un hotel saca de su sistema anterior trae las columnas
         * que trae, y si adaptarse a un cliente exigiera tocar el repositorio,
         * este importador seria una consultoria encubierta.
         *
         * EL VALOR POR DEFECTO **ES** EL PRODUCTO. Trae los nombres habituales
         * en espanol y en ingles, asi que la mayoria de los clientes no toca
         * nada. Quien tenga una exportacion con nombres propios añade los suyos
         * con `WORKFORCE_IMPORT_COLUMN_ALIASES` en su `.env`, en formato
         * `campo=cabecera` separado por `;`:
         *
         *     WORKFORCE_IMPORT_COLUMN_ALIASES="first_name=nombre_pila;national_id=documento"
         *
         * SE AÑADEN, NO SUSTITUYEN. Un alias propio no apaga los de serie: el
         * fichero de la semana que viene puede venir del otro sistema.
         *
         * La comparacion es insensible a mayusculas, acentos y espacios: `Fecha
         * de alta`, `fecha_de_alta` y `FECHA DE ALTA` son la misma columna.
         */
        'column_aliases' => [
            'first_name' => ['nombre', 'first_name', 'firstname', 'given_name'],
            'last_name' => ['apellidos', 'apellido', 'last_name', 'lastname', 'surname', 'family_name'],
            'email' => ['email', 'correo', 'correo_electronico', 'e_mail'],
            'national_id' => ['dni', 'nif', 'nie', 'documento', 'national_id', 'id_number'],
            'department' => ['departamento', 'department', 'seccion', 'section'],
            'hired_at' => ['fecha_alta', 'fecha_de_alta', 'alta', 'hired_at', 'start_date', 'hire_date'],
            'locale' => ['idioma', 'locale', 'language'],
        ],

        /*
         * Alias adicionales del cliente. Cadena vacia de serie: la mayoria no
         * necesita ninguno.
         */
        'extra_column_aliases' => (string) env('WORKFORCE_IMPORT_COLUMN_ALIASES', ''),
    ],

];
