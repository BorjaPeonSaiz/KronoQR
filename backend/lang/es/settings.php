<?php

declare(strict_types=1);

/*
 * Configuracion de la instalacion (RF-PD-01, ADR-017).
 *
 * Los textos de usuario van en `i18n` y el codigo en ingles (doc 02 §3.5). Las
 * claves de `attributes` son los valores respaldados de `SettingKey`: anadir una
 * clave al catalogo obliga a traducirla o el `422` la enseñara tal cual, que es
 * exactamente el aviso que se quiere.
 *
 * ## Dos familias de mensajes, y las dos acaban aqui
 *
 * - Los de `attributes` y `unknown_key` los usa el `FormRequest`, que valida
 *   antes de llegar al dominio y puede señalar el campo exacto.
 * - Los de `errors` los produce el DOMINIO. Sus excepciones llevan un mensaje
 *   tecnico en ingles —para el log y para quien lee una traza— y ademas una
 *   clave de traduccion con sus parametros; el borde HTTP la resuelve aqui. Antes
 *   el `422` volcaba el mensaje del dominio tal cual, lo que metia literales
 *   castellanos dentro de `Domain/` y le respondia en castellano a un panel
 *   puesto en ingles.
 *
 * Los mismos `errors` se usan para explicar una fila DESCARTADA en la lectura
 * (`meta.invalid_keys` de `GET /api/v1/settings`), que es el otro sitio donde una
 * persona lee por que un valor guardado no se aplica.
 *
 * El idioma lo negocia `Accept-Language` (middleware `NegotiateLocale`), acotado
 * a los idiomas que la instalacion tiene activos.
 */

return [

    /*
     * Una clave que el catalogo no declara. Se dice cual es y que el catalogo es
     * cerrado, porque la reaccion util de quien lo lee es revisar el nombre, no
     * volver a intentarlo.
     */
    'unknown_key' => 'La clave de configuración «:key» no existe en esta instalación. '
        .'El catálogo de claves es cerrado: revisa el nombre en la documentación de configuración.',

    /*
     * Lo que el `FormRequest` no puede comprobar solo, y lo que explica una fila
     * descartada al leer. `:key` es siempre el nombre de la clave.
     */
    'errors' => [
        'not_integer' => 'La clave «:key» espera un número entero y ha recibido :received.',
        'not_text' => 'La clave «:key» espera una cadena de texto y ha recibido :received.',
        'not_list' => 'La clave «:key» espera una lista y ha recibido :received.',
        'not_list_of_text' => 'La clave «:key» espera una lista de cadenas y ha recibido :received.',
        'out_of_range' => 'La clave «:key» admite de :minimum a :maximum, y ha recibido :value.',
        'not_empty' => 'La clave «:key» no admite un valor vacío. Volver al valor de serie es escribirlo, no guardar una cadena vacía.',
        'too_long' => 'La clave «:key» admite hasta :maximum caracteres, y ha recibido :length.',
        'malformed' => 'El valor de la clave «:key» no tiene la forma esperada (:shape).',
        'duplicated' => 'La clave «:key» no admite valores repetidos.',
        'not_allowed' => 'La clave «:key» solo admite :allowed, y ha recibido «:value».',
        'default_locale_not_available' => 'El idioma por defecto «:default» no está entre los idiomas disponibles (:available).',
        'strict_integer' => 'El valor de :attribute debe ser un número entero, sin comillas.',
    ],

    /*
     * `strict_integer` se repite fuera de `errors` porque lo emite una regla de
     * validacion de Laravel, que sustituye `:attribute` por el nombre legible de
     * abajo y no conoce `:key`.
     */
    'strict_integer' => 'El valor de :attribute debe ser un número entero, sin comillas.',

    /*
     * Por que no vale el fichero de `BRANDING_LOGO_PATH` (RF-PD-08, tarea 5.8).
     *
     * CADA MENSAJE DICE QUE HACER, no solo que ha fallado. Quien lee esto es
     * personal de IT de un hotel al que no conocemos y que no tiene al fabricante
     * al lado: «el fichero no vale» obliga a adivinar; «está fuera del directorio
     * de marca, cópialo a :root» se arregla en un minuto.
     *
     * `:root` es el directorio de marca del servidor, `:max_kib` el tope de
     * tamaño y `:max_pixels` el de lado. Van como parámetros y no escritos en el
     * texto porque son configuración del despliegue: un mensaje con «512 KiB»
     * dentro mentiría el día que alguien monte otra cosa.
     *
     * NO SE DICE NUNCA LA RUTA QUE SE INTENTÓ GUARDAR: ya la tiene delante quien
     * la acaba de escribir, y este texto acaba en un log.
     */
    'logo' => [
        'not_absolute' => 'La ruta del logotipo tiene que ser absoluta y empezar por «/». '
            .'Escribe la ruta completa dentro del directorio de marca del servidor (:root).',
        'traversal' => 'La ruta del logotipo no puede contener «..». '
            .'Escribe la ruta completa, sin saltos a directorios superiores (:root).',
        'outside_root' => 'El logotipo tiene que estar dentro del directorio de marca del servidor (:root). '
            .'Copia el fichero ahí y vuelve a guardar la ruta.',
        'missing' => 'No hay ningún fichero en esa ruta. '
            .'Comprueba que el fichero está en :root dentro del servidor y que ese directorio está montado en el contenedor.',
        'unreadable' => 'El fichero existe pero la aplicación no puede leerlo. '
            .'Revisa los permisos: tiene que ser legible por el usuario del contenedor.',
        'too_large' => 'El logotipo ocupa más de :max_kib KiB. '
            .'Expórtalo con menos resolución o guárdalo en SVG.',
        'unsupported_format' => 'El fichero no es un PNG ni un SVG. '
            .'Se comprueba el contenido, no la extensión: renombrar el fichero no sirve.',
        'active_content' => 'El SVG contiene un «<script» y no se admite. '
            .'Un logotipo no necesita código: exporta el fichero sin guiones ni interactividad.',
        'too_many_pixels' => 'El PNG supera los :max_pixels píxeles de lado. '
            .'Redúcelo antes de volver a guardarlo.',
    ],

    'attributes' => [
        'ATTENDANCE_MAX_SHIFT_HOURS' => 'duración a partir de la cual un tramo es anómalo (horas)',
        'ATTENDANCE_DEBOUNCE_SECONDS' => 'ventana anti-rebote entre dos escaneos (segundos)',
        'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES' => 'desfase de reloj tolerado (minutos)',
        'ATTENDANCE_MIN_TRANSIT_SECONDS' => 'tránsito mínimo entre dos quioscos (segundos)',
        'BRANDING_APP_NAME' => 'nombre de la aplicación',
        'BRANDING_LOGO_PATH' => 'ruta del logotipo en el servidor',
        'BRANDING_ACCENT_COLOR' => 'color de acento',
        'LOCALE_DEFAULT' => 'idioma por defecto',
        'LOCALE_AVAILABLE' => 'idiomas disponibles',
    ],

];
