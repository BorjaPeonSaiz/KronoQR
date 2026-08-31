<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Los umbrales **legales** ya resueltos, tal como los entrega el perfil de
 * cumplimiento del centro (`compliance_profiles`, RF-PD-07).
 *
 * Los fija la jurisdiccion, no el hotel. Por eso son otra cosa que
 * {@see OperationalSettings} y llegan por otro puerto: doc 01 §4, nota sobre
 * RN-08 y RN-16.
 *
 * **Ningun valor por defecto vive aqui** (regla dura 14, ADR-017). Todos son
 * obligatorios y los sirve `CompliancePolicyProvider`; la fila semilla del
 * perfil `ES-hosteleria` —12 h, 9 h, 6 h, 40 h, lunes, sin festivos y 4 años— la
 * escribe la migracion de la tarea 1.3, que es donde una constante puede
 * cambiarse sin tocar el repositorio.
 *
 * Se expresa en minutos y no en horas porque es la unidad del calculo (RN-06,
 * `duration_minutes`), y en `int` y no en un objeto de valor de Attendance
 * porque Shared no puede depender de un modulo (doc 02 §1.6). Quien evalua la
 * regla lo convierte a `WorkedDuration` al recibirlo.
 *
 * ## Tres campos sin consumidor todavia, y por que estan
 *
 * `maximumWeeklyMinutes`, `weekStartsOn` y `holidayCalendar` completan el perfil
 * del doc 01 §5 y **los estrena la tarea 3.4** (vista de cumplimiento, RF-PA-06),
 * que es la que agrega por semana y necesita saber por que dia empieza y que dias
 * son festivos. Se añaden aqui en la 5.2, con el resto del perfil, en lugar de
 * esperar: la alternativa era que la 3.4 tuviera que ampliar a la vez el esquema,
 * el puerto, el contrato y la pantalla — y hasta entonces el panel enseñaria un
 * perfil incompleto que el cliente no podria ajustar a su convenio.
 *
 * **Que no haya consumidor no los hace decorativos**: los tres se guardan, se
 * validan, se auditan y se sirven. Lo unico que falta es la regla que los lea.
 */
final readonly class CompliancePolicy
{
    /**
     * Festivos del centro, en orden y sin repetidos, como fechas ISO
     * `YYYY-MM-DD`.
     *
     * **Se normaliza con tolerancia y no se valida** (tarea 5.2, revision): lo
     * que no tenga forma de fecha se descarta en lugar de lanzar. La razon es un
     * fallo real que encontro la revision: este objeto lo construye el adaptador
     * **dentro de la pasada nocturna de deteccion**, que resuelve la politica una
     * sola vez antes del bucle y sin `try`; un `'["navidad"]'` escrito a mano en
     * la columna hacia estallar la pasada entera y dejaba sin evaluar RN-10 y
     * RN-11 de toda la instalacion, y tumbaba tambien la purga por retencion. Un
     * dato que hoy no lee ninguna regla no puede apagar dos que si.
     *
     * El descarte no es silencioso: quien construye la politica compara con
     * {@see HolidayCalendar::isClean()} y deja un aviso. Y el camino de
     * **escritura** sigue siendo estricto, en `Product`, donde hay alguien
     * delante a quien decirselo con un `422`.
     *
     * @var list<string>
     */
    public array $holidayCalendar;

    /**
     * @param  list<string>  $holidayCalendar  festivos en formato ISO `YYYY-MM-DD`; lo que no lo sea se descarta
     */
    public function __construct(
        /** RN-10: descanso minimo entre el fin de un turno y el inicio del siguiente. */
        public int $minimumRestMinutes,
        /** RN-11: jornada diaria ordinaria por encima de la cual se alerta. */
        public int $maximumDailyMinutes,
        /** RN-12: tramo continuo maximo sin pausa registrada. */
        public int $breakRequiredAfterMinutes,
        /** RL-02: anos que se conserva el registro horario antes de la purga. */
        public int $retentionYears,
        /** Jornada semanal ordinaria (art. 34.1 ET). Sin consumidor hasta la tarea 3.4. */
        public int $maximumWeeklyMinutes,
        /**
         * Dia en que empieza la semana, en numeracion **ISO-8601**: 1 es lunes y
         * 7 domingo. Es la misma que usan los informes por periodo, y por eso se
         * guarda como numero y no como el nombre del dia: un nombre habria que
         * traducirlo y volver a interpretarlo en cada consumidor. Sin consumidor
         * hasta la tarea 3.4.
         */
        public int $weekStartsOn,
        array $holidayCalendar,
    ) {
        $this->positive($minimumRestMinutes, 'el descanso minimo entre jornadas (RN-10)');
        $this->positive($maximumDailyMinutes, 'la jornada diaria ordinaria (RN-11)');
        $this->positive($breakRequiredAfterMinutes, 'el tramo continuo sin pausa (RN-12)');
        $this->positive($retentionYears, 'los anos de retencion (RL-02)');
        $this->positive($maximumWeeklyMinutes, 'la jornada semanal ordinaria');

        if ($weekStartsOn < 1 || $weekStartsOn > 7) {
            throw new InvalidArgumentException(
                'El perfil de cumplimiento no puede empezar la semana en el dia '.$weekStartsOn.
                ': la numeracion ISO-8601 va de 1 (lunes) a 7 (domingo).'
            );
        }

        // No se puede trabajar mas en un dia que en una semana. La misma
        // invariante la sostiene un CHECK del esquema; aqui esta porque el
        // dominio no puede confiar en que quien lo construya venga de la base de
        // datos.
        if ($maximumWeeklyMinutes < $maximumDailyMinutes) {
            throw new InvalidArgumentException(
                'El perfil de cumplimiento fija una jornada semanal ('.$maximumWeeklyMinutes.
                ' min) por debajo de la diaria ('.$maximumDailyMinutes.' min).'
            );
        }

        $this->holidayCalendar = HolidayCalendar::of($holidayCalendar)->days;
    }

    private function positive(int $value, string $what): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException('El perfil de cumplimiento no puede fijar '.$what.' en '.$value.'.');
        }
    }
}
