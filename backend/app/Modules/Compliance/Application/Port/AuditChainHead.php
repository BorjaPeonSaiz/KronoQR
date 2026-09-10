<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditChainHeadSnapshot;

/**
 * Lectura de la **punta** de la cadena: la huella del ultimo eslabon escrito
 * (RS-07, RF-PD-10, tarea 5.7).
 *
 * **Por que un puerto propio y no un metodo mas en `AuditChainReader`.** Aquel
 * recorre millones de filas para recalcular la cadena entera; este lee una. Los
 * dos usos no se parecen en nada: el verificador se ejecuta una vez al dia y
 * puede tardar minutos, y esta lectura la hace `update.sh` con la instalacion en
 * mantenimiento, tres veces en una actualizacion, y tiene que responder al
 * instante. Un doble de prueba que tuviera que implementar los dos metodos para
 * usar uno seria la senal de que estan mal juntos.
 *
 * **Y no en `AuditTrail`**, aunque el escritor sepa donde esta la punta —la
 * necesita para encadenar—: escribir es una potestad y leer la huella no. Quien
 * quiera saber por donde va la cadena no tiene por que recibir de paso la
 * capacidad de anadirle un eslabon.
 *
 * La implementacion de produccion si es la misma clase, y eso es deliberado: la
 * respuesta a «cual es la punta» tiene que ser una sola, y duplicarla en dos
 * adaptadores seria la via mas silenciosa de que un dia dejen de coincidir.
 */
interface AuditChainHead
{
    /**
     * La punta de la cadena **ahora**, incluidas las anclas de las particiones
     * ya purgadas (ADR-027). Nunca devuelve nulo: si no hay ni una entrada ni un
     * ancla, la punta es la genesis.
     */
    public function snapshot(): AuditChainHeadSnapshot;
}
