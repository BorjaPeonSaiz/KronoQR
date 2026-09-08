<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\LogoImage;

/**
 * Entrega el logotipo de la instalacion ya leido y ya comprobado (RF-PD-08,
 * tarea 5.8).
 *
 * **Puerto aparte de {@see BrandingProvider} y no un metodo mas suyo.** Aquel
 * devuelve texto —nombre, color, ruta— y se pide en cada pantalla; este toca el
 * disco y devuelve hasta medio megabyte. Meterlos en el mismo puerto obligaria a
 * leer el fichero para pintar un color, y la exportacion legal, que solo
 * necesita el nombre, acabaria abriendo una imagen en cada peticion.
 *
 * ## Nunca lanza, y esa es su razon de ser
 *
 * Devuelve `null` cuando no hay logotipo configurado, cuando el fichero
 * desapareció, cuando no se puede leer o cuando ya no pasa la comprobacion. Las
 * cuatro situaciones son la misma para quien dibuja: **se sigue sin logotipo**.
 *
 * La comprobacion de verdad ocurre al GUARDAR la ruta (`PATCH /api/v1/settings`
 * responde `422` si no vale), que es cuando hay una persona delante a la que
 * decirle que arreglar. La lectura posterior es deliberadamente tolerante: si el
 * fichero se borra un martes, los documentos salen sin logotipo y **nadie se
 * queda sin fichar** (regla dura 19). Un puerto que lanzara convertiria un
 * borrado accidental en una jornada sin tarjetas impresas.
 *
 * ## Vive en `Shared`
 *
 * Lo consumen `Identity` (la tarjeta), `Reporting` (el informe sellado) y
 * `Product` (el endpoint publico). Su adaptador esta en
 * `Product/Infrastructure/Adapter/`, que es donde vive la configuracion que dice
 * donde mirar.
 */
interface BrandingLogoReader
{
    /**
     * El logotipo vigente, o `null` si no hay ninguno utilizable.
     */
    public function current(): ?LogoImage;
}
