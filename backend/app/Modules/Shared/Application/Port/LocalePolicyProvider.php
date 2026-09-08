<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\LocalePolicy;

/**
 * Entrega los idiomas de la instalacion ya resueltos (RF-PD-01, RF-PD-08).
 *
 * ## Por que existe, teniendo `app.locale` a mano
 *
 * Porque los idiomas son configuracion **de la instalacion** y no del despliegue
 * (regla dura 13): el cliente los cambia desde el panel, el cambio queda
 * auditado y surte efecto en la peticion siguiente, sin reiniciar nada. Con la
 * lectura hecha contra `config()`, cambiar el idioma seguiria siendo editar un
 * `.env` y reiniciar, que es justo lo que ADR-017 saca del producto.
 *
 * `APP_LOCALE` y `APP_SUPPORTED_LOCALES` no desaparecen: se quedan como
 * **respaldo**, que es lo que se aplica si la base de datos no responde. Ver
 * `DbLocalePolicyProvider`.
 *
 * ## Nunca devuelve `null` ni falla
 *
 * Sin ninguna fila rige el valor de serie del catalogo; con la base de datos
 * caida, el respaldo de configuracion. Quien negocia el idioma de una respuesta
 * esta en el camino de **todas** las peticiones, incluida la que devuelve un
 * error: un puerto que lanzara convertiria un problema de configuracion en un
 * 500 en cada endpoint del producto.
 */
interface LocalePolicyProvider
{
    public function current(): LocalePolicy;
}
