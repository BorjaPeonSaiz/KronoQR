<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\CredentialScan;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Los usos de credencial **en un quiosco** de una ventana (RF-PR-06, RN-16,
 * tarea 3.11).
 *
 * **Lee hacia atras lo que el fichaje ya escribio**, como {@see FlaggedScans} y
 * {@see OutOfOrderScans}: no hay proceso nuevo ni evento nuevo en el camino de
 * fichaje. La deteccion de patrones es nocturna a proposito (decision fuera de
 * alcance de la ficha 3.11), y lo que mira son filas de `scan_events`.
 *
 * ## Que entrega, y que deja fuera (decision 4 de la ficha)
 *
 * Solo `origin` `qr_kiosk` o `pin_kiosk` —el PIN de emergencia tambien es una
 * credencial personal— y solo los desenlaces **aceptados**: un `rejected_*` no
 * es un uso de credencial y muchos ni siquiera resuelven a una persona.
 * `manual_admin` e `import` no pasan por ningun quiosco. Ese filtro es una
 * condicion de la consulta y no una regla de negocio: por eso esta aqui y no en
 * el dominio.
 *
 * **Ningun umbral se aplica en SQL.** Quien decide si dos escaneos coinciden o
 * si un transito es imposible es `CredentialPatternPolicy`, con los valores
 * vigentes del centro (regla dura 14). Filtrarlo en la consulta dejaria la regla
 * escrita donde ni se prueba ni se ve.
 *
 * ## Por que recibe la zona horaria
 *
 * Porque `CredentialScan` lleva la **jornada** del escaneo, y una fecha civil no
 * existe sin la zona en la que es civil (RN-05, {@see WorkDate}).
 * La resuelve el caso de uso —que es quien alcanza `InstallationSiteProvider`—
 * y la pasa; el adaptador no la busca por su cuenta.
 */
interface CredentialScans
{
    /**
     * Los escaneos de quiosco aceptados cuyo `occurred_at` cae en la ventana.
     *
     * Se filtra por `occurred_at` —el momento real— y no por `recorded_at`,
     * porque lo que la regla mide es la distancia entre dos hechos, no entre dos
     * recepciones (regla dura 9). Una cola offline que drena tres dias despues
     * no convierte dos fichajes separados por horas en una coincidencia.
     *
     * @return list<CredentialScan>
     */
    public function kioskScansBetween(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $timezone): array;
}
