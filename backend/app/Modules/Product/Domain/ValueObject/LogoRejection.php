<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Por que no se admite un fichero como logotipo (RF-PD-08, tarea 5.8).
 *
 * ## Enumerado, y no un mensaje suelto
 *
 * Porque el mismo motivo se cuenta en dos idiomas y en dos sitios distintos: el
 * `422` de `PATCH /api/v1/settings`, que lo lee una persona en el panel, y el
 * log de la instalacion, que lo lee quien administra el servidor. Con un texto
 * suelto habria que traducirlo dos veces y el log saldria en el idioma del
 * navegador de otra persona.
 *
 * ## Cada caso dice QUE HACER, no solo que fallo
 *
 * Es el criterio del §3.5 y la razon de que sean ocho y no uno: quien instala
 * esto no tiene al fabricante al lado. «El fichero no vale» obliga a adivinar;
 * «esta fuera del directorio de marca, copialo a /var/kronoqr/branding» se
 * arregla en un minuto. El texto de cada uno vive en `lang/{es,en}/settings.php`
 * bajo `logo.<motivo>`.
 */
enum LogoRejection: string
{
    /** La ruta no empieza por `/`. Una relativa se resolveria contra el directorio de trabajo del proceso. */
    case NOT_ABSOLUTE = 'not_absolute';

    /** La ruta lleva `..`. Se rechaza ANTES de resolverla, para que el mensaje diga la causa real. */
    case TRAVERSAL = 'traversal';

    /**
     * La ruta, ya resuelta, cae fuera del directorio de marca.
     *
     * Es la comprobacion que impide que `GET /api/v1/branding/logo` —que es
     * **publico**— se convierta en una lectura de cualquier fichero del
     * servidor. Se hace sobre el `realpath`, no sobre la cadena: un enlace
     * simbolico dentro del directorio que apunte a `/etc` la burlaria.
     */
    case OUTSIDE_ROOT = 'outside_root';

    /** No hay fichero en esa ruta. Lo mas frecuente: el volumen de marca no esta montado. */
    case MISSING = 'missing';

    /** Existe pero el proceso no puede leerlo. Casi siempre, permisos o propietario. */
    case UNREADABLE = 'unreadable';

    /** Pasa del limite de bytes. Un logotipo enorme rompe el presupuesto de la pantalla del quiosco. */
    case TOO_LARGE = 'too_large';

    /** No es PNG ni SVG **por su contenido**. La extension no se mira en ningun momento. */
    case UNSUPPORTED_FORMAT = 'unsupported_format';

    /**
     * Un SVG con `<script` dentro.
     *
     * El endpoint lo sirve con `Content-Security-Policy` y `sandbox`, asi que no
     * se ejecutaria; pero el mismo fichero se incrusta en los PDF, que dibuja un
     * Chromium, y ahi la defensa en profundidad se hace en la puerta. Un
     * logotipo no necesita guion.
     */
    case ACTIVE_CONTENT = 'active_content';

    /** PNG que pasa del maximo de pixeles de lado. Se lee del IHDR, sin descodificar la imagen. */
    case TOO_MANY_PIXELS = 'too_many_pixels';
}
