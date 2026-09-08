<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Quien pone la generacion en la cola (**RF-PD-14**, decision 4 de la ficha
 * 5.10).
 *
 * ## Por que la generacion es asincrona desde el panel
 *
 * Porque el volumen que exige la ficha —cuatro años de fichajes de una plantilla
 * entera— no cabe ni en los 60 s de `fastcgi_read_timeout` ni en la memoria de
 * un navegador que recibe la respuesta de un `fetch`. Y **una descarga que se
 * corta a la mitad es peor que ninguna**: el cliente cree que tiene su copia.
 *
 * Asi que el `POST` encola y responde `202` con la fila en `pending`; el panel
 * sondea la lista y descarga cuando termina.
 *
 * ## Por consola es sincrona, y no pasa por aqui
 *
 * `php artisan product:export-all` llama al caso de uso de generacion
 * directamente: quien esta delante de la terminal quiere ver la ruta del fichero
 * antes de irse, y ahi no hay ningun tiempo de espera que agotar.
 *
 * ## El puerto recibe un `uuid` y nada mas
 *
 * Ni el modelo ni la fila: lo unico que el trabajador necesita es saber **cual**
 * generar, y volver a leerla al arrancar es lo que hace que un reintento vea el
 * estado real —y no rehaga una exportacion que ya termino—.
 */
interface DataExportQueue
{
    public function enqueue(string $uuid): void;
}
