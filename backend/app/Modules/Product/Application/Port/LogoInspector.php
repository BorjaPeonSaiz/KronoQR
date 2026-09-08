<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\LogoInspection;

/**
 * Dice si un fichero del servidor sirve como logotipo de la instalacion
 * (RF-PD-08, tarea 5.8).
 *
 * ## Por que es un puerto y no una clase que se instancie donde haga falta
 *
 * Porque sus dos consumidores estan en capas que **no pueden alcanzar
 * `Infrastructure`**: la validacion de `PATCH /api/v1/settings` vive en
 * `Http/Request` y el lector del logotipo se compone en el proveedor del modulo.
 * Mirar el disco es infraestructura por definicion —depende del sistema de
 * ficheros y del volumen que Docker haya montado—, asi que lo que cruza la
 * frontera es el contrato, y la implementacion se queda al otro lado.
 *
 * ## Que hay al otro lado, y por que importa que sea UNO
 *
 * `LogoFileInspector`, y solo el. Es la misma comprobacion al GUARDAR la ruta
 * —donde un rechazo es un `422` con una persona delante— y al LEER el fichero
 * para dibujarlo —donde el mismo rechazo es «sigue sin logotipo»—. Con dos
 * comprobaciones distintas, el panel aceptaria un fichero que el endpoint publico
 * no sirve y nadie sabria por que la cabecera sale vacia.
 *
 * ## Nunca lanza
 *
 * Ni por I/O ni por nada: devuelve la imagen o el motivo enumerado. Esto esta en
 * el camino de la tarjeta impresa y del informe sellado, y ahi una excepcion deja
 * a un cliente sin documentos por culpa de una imagen.
 */
interface LogoInspector
{
    /**
     * Mira el fichero de esa ruta absoluta y dice si sirve.
     *
     * La cadena vacia **no se le pasa**: significa «el logotipo del producto» y
     * quien llama la trata antes.
     */
    public function inspect(string $path): LogoInspection;
}
