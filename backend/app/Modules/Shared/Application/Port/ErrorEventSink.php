<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\ErrorReport;

/**
 * Donde acaban los errores: el historico agrupado por huella de `error_events`
 * (RF-PD-15, tarea 5.12).
 *
 * ## Por que es un puerto y por que vive en `Shared`
 *
 * La tabla, el saneado y la huella son de `Product`. Pero quien reporta no es
 * solo `Product`: el latido del quiosco lo procesa `Kiosk`, y el enganche del
 * manejador de excepciones atiende a cualquier modulo. Ninguno de ellos puede
 * importar `Product` (doc 02 §1.6, Deptrac). Lo transversal va a `Shared` y su
 * adaptador al modulo que tiene la tabla: la regla de ADR-025, la misma que
 * `FeatureGate` y `BrandingProvider`.
 *
 * ## NUNCA lanza, y eso es parte del contrato
 *
 * Un error al guardar el error no puede convertirse en un segundo error
 * (regla dura 19: el latido de un quiosco no puede fallar porque la tabla no
 * responda; una peticion que ya ha fallado no puede fallar «mas»). El
 * adaptador envuelve la escritura, deja constancia en el log tecnico si no
 * pudo, y devuelve `false`. Quien llama decide que hacer con eso: el latido
 * responde `client_errors_accepted: 0` y la tablet conserva su buffer.
 *
 * ## Es sincrono a proposito
 *
 * No hay cola de por medio: la cola es uno de los cuatro origenes que este
 * historico tiene que captar, y un error que se encola para guardarse en la
 * cola que esta fallando no se guarda nunca. La escritura es un `INSERT … ON
 * CONFLICT` por una conexion propia y cuesta lo que cuesta un fichaje.
 */
interface ErrorEventSink
{
    /**
     * Sanea, calcula la huella y persiste (o incrementa) el grupo.
     *
     * @return bool `true` si quedo persistido; `false` si no se pudo, sin lanzar.
     */
    public function record(ErrorReport $report): bool;

    /**
     * Varios de golpe, en orden. Se detiene en el primero que no se pudo
     * guardar para que el recuento devuelto sea un prefijo: el cliente vacia
     * de su buffer exactamente los `n` mas antiguos (`acknowledge(n)`).
     *
     * @param  list<ErrorReport>  $reports
     * @return int Cuantos quedaron persistidos, contando desde el primero.
     */
    public function recordAll(array $reports): int;
}
