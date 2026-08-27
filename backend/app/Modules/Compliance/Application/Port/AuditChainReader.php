<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;

/**
 * Lectura de la cadena para el verificador de RS-07.
 *
 * Separado de `AuditTrail` a proposito: escribir y verificar son dos
 * responsabilidades con requisitos opuestos —una escribe una fila dentro de la
 * transaccion de otro, la otra recorre millones sin bloquear a nadie— y un solo
 * puerto con los dos metodos acabaria implementado a medias en las pruebas.
 */
interface AuditChainReader
{
    /**
     * Todas las entradas en **orden de cadena**, en lotes.
     *
     * El orden de cadena es el de `id`, que es el de la secuencia y por tanto el
     * de escritura. **No es el de `occurred_at`**: una entrada puede llegar de
     * la cola offline del quiosco con un `occurred_at` anterior al de la fila
     * que ya esta escrita (regla dura 9), y ordenar por el momento del hecho
     * romperia la cadena sin que nadie la hubiera tocado.
     *
     * Se devuelve como `iterable` y no como array para que la memoria no crezca
     * con el historico: cuatro años de retencion son cientos de miles de filas.
     *
     * @return iterable<int, AuditEntry>
     */
    public function inChainOrder(int $chunkSize = 1000): iterable;

    /**
     * El ancla cuyo `last_hash` es el hash dado, si existe (ADR-027).
     *
     * Es lo que distingue una purga sellada de una manipulacion cuando el
     * `prev_hash` de la primera fila no apunta a nada.
     */
    public function anchorSealedWith(string $hash): ?AuditChainAnchor;
}
