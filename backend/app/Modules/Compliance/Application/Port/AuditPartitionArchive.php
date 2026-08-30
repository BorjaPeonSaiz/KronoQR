<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;

/**
 * La purga de `audit_log`, que **nunca es un `DELETE`** (ADR-027, regla dura 6).
 *
 * Es la unica pieza del producto que corre con el **rol de mantenimiento**: el
 * de la aplicacion tiene `INSERT` y `SELECT` sobre la tabla y sobre cada
 * particion, y ni `UPDATE` ni `DELETE` ni DDL. Que la purga necesite otra
 * credencial no es una molestia: es la garantia.
 *
 * ## El orden, que es todo el procedimiento
 *
 * 1. **Verificar** la cadena completa de la particion. Si no verifica, se
 *    aborta: una particion con la cadena rota no se purga, se investiga.
 * 2. **Sellar** el ancla —ano, `first_hash`, `last_hash`, filas, momento y rol—.
 * 3. `DETACH PARTITION` y soltarla.
 *
 * Los pasos 2 y 3 los hace `sealAndDrop()` en **una sola transaccion**, y dentro
 * revalida que la particion sigue teniendo exactamente las filas que se
 * verificaron. Sin esa revalidacion, entre la verificacion y el `DROP` cabria
 * una entrada retrodatada —la cola offline del quiosco puede traerlas (regla
 * dura 9)— que se iria sin haber sido nunca verificada.
 */
interface AuditPartitionArchive extends AuditPartitionInventory
{
    /**
     * Las filas de la particion en orden de cadena (por `id`), en lotes.
     *
     * @return iterable<int, AuditEntry>
     */
    public function entriesOf(int $year, int $chunkSize = 1000): iterable;

    /**
     * Si toda la particion queda **delante** de cualquier entrada viva mas
     * nueva: ninguna fila de otra particion tiene un `id` menor que el mayor de
     * esta.
     *
     * Sin esta comprobacion, soltar la particion abriria un hueco **en medio**
     * de la cadena, y el ancla —que solo explica un hueco al principio— no lo
     * cubriria: el verificador denunciaria rotura para siempre. Ocurre si una
     * entrada llega retrodatada (regla dura 9) despues de que la cadena avanzara
     * al ano siguiente.
     */
    public function precedesEveryLiveEntry(int $year): bool;

    /**
     * Sella el ancla y suelta la particion, en una transaccion.
     *
     * Revalida dentro que el recuento y los hashes extremos siguen siendo los
     * del ancla; si no lo son, no sella ni suelta nada.
     */
    public function sealAndDrop(AuditChainAnchor $anchor): void;

    /**
     * Rol de base de datos con el que se esta operando. Es lo que queda en
     * `audit_chain_anchors.sealed_by`: un rol, no una persona.
     */
    public function role(): string;
}
