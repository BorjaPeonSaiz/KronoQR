<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * De que tipo es el valor de una clave de configuracion (RF-PD-01).
 *
 * `installation_settings.value` es `JSONB` precisamente porque el tipo forma
 * parte del dato —un umbral es numero, un idioma es cadena, los idiomas activos
 * son una lista—, y JSON no distingue lo que el negocio si distingue. Este enum
 * es lo que convierte «lo que hubiera en la columna» en un valor con tipo antes
 * de que nadie lo use.
 *
 * Un `enum` y no constantes de clase (doc 02 §3.5). Los valores respaldados
 * viajan en la respuesta de `GET /api/v1/settings` para que el panel sepa que
 * control dibujar sin llevar el catalogo duplicado en TypeScript.
 *
 * **Solo tres casos, y no hay booleano.** Ninguna clave del catalogo lo necesita
 * hoy, y un caso sin clave que lo use es una rama que ninguna prueba recorre
 * (doc 02 §3.5: no se añade superficie «por si acaso»). Añadirlo cuando aparezca
 * la primera clave booleana es aditivo.
 */
enum SettingType: string
{
    /** Un entero. Todos los umbrales operativos lo son (RN-08, RN-16, RF-AT-06, RF-AT-10). */
    case INTEGER = 'integer';

    /** Una cadena: el nombre de la aplicacion, la ruta del logotipo, un idioma. */
    case TEXT = 'text';

    /** Una lista de cadenas sin repetidos: los idiomas activos de la instalacion. */
    case TEXT_LIST = 'text_list';
}
