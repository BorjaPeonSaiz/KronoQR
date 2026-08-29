<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use DateTimeImmutable;

/**
 * Publicacion de las dos metricas de credenciales del doc 02 §8.2:
 *
 * ```
 * employees_without_delivered_credential{site}
 * credentials_pending_print{site}
 * ```
 *
 * El §8.2 remata la primera: *«es la metrica operativa de la entrega: cuenta a
 * quienes estan de alta pero todavia no pueden fichar. Debe llegar a cero antes
 * del primer dia de cada incorporacion.»*
 *
 * **Se publica siempre, tambien a cero.** Un `gauge` de Prometheus necesita que
 * la serie que se ha quedado a cero se escriba como cero, y no que desaparezca:
 * una serie ausente y una serie en cero se ven igual en un panel y solo la
 * segunda dice «ya esta todo entregado». Es la razon de que el recuento lleve
 * el centro aunque no haya ni una fila que contar (ADR-040): la etiqueta
 * `site` describe el centro de la instalacion y no cambia de forma para quien
 * ya la consume.
 *
 * **Es un puerto por lo mismo que el puerto equivalente de auditoria en el
 * modulo Compliance** (`AuditMetrics`, sin importarlo aqui: Identity no puede
 * depender de Compliance): el caso de uso no sabe si detras hay un fichero para
 * el colector *textfile* de `node-exporter`, un registro de `promphp` o nada.
 * Hoy es lo primero —`/metrics` lo expone la aplicacion a partir de la tarea
 * 3.1—; en las pruebas es un doble que cuenta y permite afirmar sobre lo medido
 * sin tocar el disco.
 */
interface CredentialMetrics
{
    public function recordCoverage(SiteCredentialCoverage $coverage, DateTimeImmutable $at): void;
}
