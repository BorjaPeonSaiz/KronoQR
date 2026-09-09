<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * El historico de errores agrupado por huella (RF-PD-15, 90 dias).
 *
 * El almacen es la tabla `error_events`, que crea la migracion de la tarea 5.12:
 * en una instalacion con las migraciones aplicadas esta siempre. El adaptador
 * conserva la respuesta «no instalado» —{@see
 * \App\Modules\Compliance\Domain\ValueObject\RetentionTally::unavailable()}—
 * porque **sin migraciones** el informe se puede pedir igual, y ahi decir «0
 * filas» afirmaria que el ciclo corto esta corriendo sobre una tabla que no
 * existe. Este informe se archiva: no puede contener esa afirmacion.
 *
 * El plazo se declara en el ciclo de retencion y no junto a este puerto porque la
 * politica de RL-11 la fija Compliance para todos los almacenes a la vez.
 */
interface ErrorHistoryArchive extends ShortCycleArchive {}
