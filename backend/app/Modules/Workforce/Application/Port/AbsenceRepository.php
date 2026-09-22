<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\Exception\OverlappingAbsence;
use App\Modules\Workforce\Domain\Model\Absence;
use DateTimeImmutable;

/**
 * Las ausencias registradas (**RF-GP-04**).
 *
 * **Habla en modelos de dominio y nunca en filas** (ADR-025, restriccion 2): el
 * caso de uso recibe {@see Absence} y no un modelo Eloquent, porque con el
 * modelo tendria tambien `->delete()` y la tentacion de usarlo.
 *
 * ## No hay `delete()`, y no es un olvido
 *
 * **En ninguna capa** (decision 4 de la ficha 3.10, regla dura 5). Corregir es
 * {@see self::add()} de una version nueva mas {@see self::markSuperseded()} de
 * la anterior; quitar una ausencia es {@see self::markVoided()}, que es un hecho
 * con autor, momento y motivo. Un metodo de borrado aqui seria la unica pieza
 * que hace falta para que alguien reescriba el absentismo de un mes sin dejar
 * rastro.
 *
 * ## Las dos unicas escrituras sobre una fila anterior
 *
 * `status` + `superseded_by_id` al corregir, y `status` + `voided_*` al anular.
 * Ni el tipo, ni las fechas, ni la nota, ni el autor, ni el momento de una fila
 * ya escrita se tocan nunca.
 */
interface AbsenceRepository
{
    /**
     * La ausencia con ese UUID publico, o `null`.
     *
     * **Devuelve tambien las supersedidas y las anuladas**: el caso de uso
     * necesita distinguir «no existe» de «existe y ya no es la vigente» para
     * responder `404` o `409`, y con un filtro aqui las dos serian lo mismo.
     */
    public function findByUuid(string $uuid): ?Absence;

    /**
     * La misma ausencia, **con su fila tomada hasta que la transaccion
     * confirme** (`SELECT … FOR UPDATE`).
     *
     * ## Por que corregir y anular tienen que leer por aqui
     *
     * Sin candado, dos peticiones simultaneas sobre la misma version leen las
     * dos que esta `active`, las dos deciden que pueden corregirla y las dos
     * escriben: el resultado son **dos filas `version = 2` colgando de la misma
     * v1**, con `superseded_by_id` apuntando solo a una de ellas. El historial
     * se bifurca, el informe cuenta los dias dos veces y nada lo delata. Es
     * exactamente el mismo motivo por el que la proyeccion de `daily_totals`
     * toma su fila antes de recalcular (RN-06).
     *
     * Con el candado, la segunda peticion **espera** y, al soltarse, vuelve a
     * leer la fila ya en `superseded`: responde `409` en lugar de bifurcar.
     *
     * **Devuelve la version en el estado en que este**, no solo las activas: el
     * caso de uso necesita distinguir «no existe» —`404`— de «existe y ya no es
     * la vigente» —`409`—, y con un filtro aqui las dos serian lo mismo.
     *
     * **Solo tiene sentido dentro de una transaccion.** Fuera de ella el candado
     * se suelta en el acto y no protege de nada; quien llama abre la suya.
     */
    public function findForUpdate(string $uuid): ?Absence;

    /**
     * La misma ausencia con el codigo, el nombre y el departamento de la
     * persona, para poder pintarla.
     */
    public function viewOf(string $uuid): ?AbsenceView;

    /**
     * Las versiones **anteriores** a esa, de la mas antigua a la mas reciente y
     * sin incluirla (RN-13).
     *
     * Se recorre la cadena `supersedes_id` hacia atras. No hay columna de
     * «cadena» y no hace falta: la cadena es un camino y su longitud es el
     * numero de veces que alguien ha corregido una ausencia, que no llega a dos
     * en la practica.
     *
     * Lista vacia si nunca se corrigio.
     *
     * @return list<AbsenceView>
     */
    public function historyOf(string $uuid): array;

    /**
     * Cuantas ausencias casan con el filtro, **con el alcance dentro del
     * `WHERE`** (RF-ID-03).
     *
     * Es la otra mitad de {@see self::search()} y comparten el mismo
     * {@see AbsenceFilter} a proposito: un recuento que no aplicara el alcance
     * diria a un responsable cuanta gente hay fuera de su departamento.
     */
    public function countMatching(AbsenceFilter $filter): int;

    /**
     * Una pagina de ausencias, de la mas reciente a la mas antigua.
     *
     * @return list<AbsenceView>
     */
    public function search(AbsenceFilter $filter, int $limit, int $offset): array;

    /**
     * Las ausencias **activas** de una persona que tocan ese periodo.
     *
     * Existe para la fase de comprobacion de la carga por fichero, que necesita
     * decir en que linea esta el choque **antes** de escribir nada. No sustituye
     * a `absences_no_overlap`: aquella es la que manda, porque una consulta
     * previa es una carrera.
     *
     * @return list<Absence>
     */
    public function activeWithin(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Inserta la ausencia y devuelve la misma con su clave interna.
     *
     * @param  int|null  $registeredByUserId  Cuenta de gestion que la registra; `null` en una
     *                                        semilla o una carga sin sesion detras.
     *
     * @throws OverlappingAbsence si choca con `absences_no_overlap`
     */
    public function add(Absence $absence, ?int $registeredByUserId): Absence;

    /**
     * Sustituye una version por la siguiente: **las dos mitades de una
     * correccion, en una sola operacion** (RN-13, regla dura 5).
     *
     * Inserta `$corrected` y cierra `$previous` apuntando a ella. Sobre la fila
     * anterior se escriben **solo** `status` y `superseded_by_id`; su tipo, sus
     * fechas, su nota, su autor y su momento no se tocan.
     *
     * **Es un solo metodo y no dos por una razon que no es de estilo.** Las dos
     * mitades no caben en ningun orden por separado —la vieja necesita apuntar a
     * una fila que todavia no existe, y las dos activas a la vez chocarian con
     * `absences_no_overlap`—, asi que el adaptador tiene que poder diferir esa
     * restriccion entre las dos sentencias. Partirlo en dos llamadas obligaria a
     * que el caso de uso supiera de `SET CONSTRAINTS`, que es un detalle de
     * PostgreSQL.
     *
     * @param  Absence  $previous  La version vigente, ya persistida.
     * @param  Absence  $corrected  La version nueva, con `supersedesUuid` apuntando a la anterior.
     * @return Absence La version nueva con su clave interna.
     *
     * @throws OverlappingAbsence si el periodo nuevo pisa otra ausencia activa
     * @throws AbsenceNotActive si la version anterior dejo de estar activa entre la lectura y la escritura
     */
    public function supersedeWith(Absence $previous, Absence $corrected, ?int $correctedByUserId): Absence;

    /**
     * Marca una fila como anulada, con su autor, su momento y su motivo.
     *
     * **No crea version**: no hay una version posterior de un hecho que no paso.
     *
     * @throws AbsenceNotActive si la fila dejo de estar activa entre la lectura y la escritura
     */
    public function markVoided(Absence $absence, ?int $voidedByUserId): void;
}
