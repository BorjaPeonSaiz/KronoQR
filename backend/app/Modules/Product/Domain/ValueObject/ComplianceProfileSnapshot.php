<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidComplianceProfileValue;
use App\Modules\Shared\Domain\ValueObject\HolidayCalendar;
use DateTimeImmutable;

/**
 * El perfil de cumplimiento vigente para el centro, tal como se lee y como se
 * deja escrito (RF-PD-07, doc 01 §5.5).
 *
 * ## Por que existe teniendo ya `CompliancePolicy`
 *
 * Son dos objetos distintos porque responden a dos preguntas distintas.
 * `Shared\Domain\ValueObject\CompliancePolicy` es lo que el **nucleo** recibe:
 * umbrales en minutos, sin nombre, sin identificador y sin saber de donde
 * salieron — porque a la regla que decide si una jornada es anomala no le
 * importa como se llama el convenio. Esto es lo que el **panel** edita: horas
 * enteras, con el nombre, con el origen de la resolucion y con lo que hace falta
 * para escribir un asiento de auditoria. Fundirlos obligaria al dominio de
 * Attendance a conocer `is_default`.
 *
 * ## Horas, no minutos
 *
 * El perfil se enuncia en horas enteras porque asi lo dice el convenio —«12 h de
 * descanso»— y asi lo nombra el esquema. La conversion a minutos la hace el
 * adaptador del puerto, en el borde.
 *
 * ## Inmutable, con `with()`
 *
 * Un cambio produce un objeto nuevo y **valida el resultado completo**, no el
 * campo suelto: la invariante «la semanal no puede quedar por debajo de la
 * diaria» habla de dos campos que pueden no viajar juntos en la misma peticion.
 * Comprobarla sobre el resultado es lo que impide que el orden en que se
 * escriban deje el perfil en un estado imposible.
 */
final readonly class ComplianceProfileSnapshot
{
    /**
     * @param  list<string>  $holidayCalendar  festivos en formato ISO `AAAA-MM-DD`
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $jurisdiction,
        public int $minRestHours,
        public int $maxDailyHours,
        public int $maxWeeklyHours,
        public int $breakRequiredAfterHours,
        public int $weekStartsOn,
        public array $holidayCalendar,
        public int $retentionYears,
        public bool $isDefault,
        public ComplianceProfileSource $source,
        /**
         * Cuando se cambio por ultima vez, o `null` si **nadie lo ha tocado
         * desde la instalacion**.
         *
         * El `null` es una afirmacion, no un hueco: la fila de serie la escribio
         * el producto, no una persona, y distinguir «tal como se instalo» de
         * «revisado el 3 de marzo» es lo primero que necesita quien abre la
         * pantalla y lo que permite avisar de una instalacion que nunca contrasto
         * su convenio. El «quien» con valor probatorio esta en `audit_log`.
         */
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * El valor de un campo, para componer la respuesta y el asiento sin repetir
     * un `match` en cada sitio.
     *
     * @return int|string|list<string>
     */
    public function valueOf(ComplianceProfileField $field): int|string|array
    {
        return match ($field) {
            ComplianceProfileField::Name => $this->name,
            ComplianceProfileField::MinRestHours => $this->minRestHours,
            ComplianceProfileField::MaxDailyHours => $this->maxDailyHours,
            ComplianceProfileField::MaxWeeklyHours => $this->maxWeeklyHours,
            ComplianceProfileField::BreakRequiredAfterHours => $this->breakRequiredAfterHours,
            ComplianceProfileField::WeekStartsOn => $this->weekStartsOn,
            ComplianceProfileField::HolidayCalendar => $this->holidayCalendar,
            ComplianceProfileField::RetentionYears => $this->retentionYears,
        };
    }

    /**
     * Los campos que de verdad cambian de valor.
     *
     * Guardar lo que ya regia no escribe fila ni asiento: sin esto, abrir la
     * pantalla y pulsar «guardar» llenaria el trail de entradas que solo dicen
     * «alguien miro el perfil», y enterraria la señal que importa.
     *
     * @param  array<string, mixed>  $changes  indexado por el valor del campo
     * @return list<ComplianceProfileField>
     */
    public function fieldsThatChange(array $changes): array
    {
        $changed = [];

        foreach ($changes as $name => $value) {
            $field = ComplianceProfileField::from($name);

            if (self::normalise($field, $value) !== $this->valueOf($field)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * El perfil resultante de aplicar los cambios, ya validado.
     *
     * @param  array<string, mixed>  $changes  indexado por el valor del campo
     *
     * @throws InvalidComplianceProfileValue
     */
    public function with(array $changes): self
    {
        $values = [];

        foreach (ComplianceProfileField::cases() as $field) {
            $values[$field->value] = array_key_exists($field->value, $changes)
                ? self::normalise($field, $changes[$field->value])
                : $this->valueOf($field);
        }

        /** @var string $name */
        $name = $values[ComplianceProfileField::Name->value];
        /** @var list<string> $calendar */
        $calendar = $values[ComplianceProfileField::HolidayCalendar->value];

        $updated = new self(
            id: $this->id,
            name: $name,
            jurisdiction: $this->jurisdiction,
            minRestHours: self::integer($values, ComplianceProfileField::MinRestHours),
            maxDailyHours: self::integer($values, ComplianceProfileField::MaxDailyHours),
            maxWeeklyHours: self::integer($values, ComplianceProfileField::MaxWeeklyHours),
            breakRequiredAfterHours: self::integer($values, ComplianceProfileField::BreakRequiredAfterHours),
            weekStartsOn: self::integer($values, ComplianceProfileField::WeekStartsOn),
            holidayCalendar: $calendar,
            retentionYears: self::integer($values, ComplianceProfileField::RetentionYears),
            isDefault: $this->isDefault,
            source: $this->source,
            // Se arrastra tal cual: quien decide la marca nueva es quien escribe,
            // con el reloj inyectado, y este objeto solo describe el resultado de
            // aplicar unos cambios.
            updatedAt: $this->updatedAt,
        );

        if ($updated->maxWeeklyHours < $updated->maxDailyHours) {
            throw InvalidComplianceProfileValue::weeklyBelowDaily($updated->maxWeeklyHours, $updated->maxDailyHours);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function integer(array $values, ComplianceProfileField $field): int
    {
        /** @var int $value */
        $value = $values[$field->value];

        return $value;
    }

    /**
     * Un valor de entrada convertido a su forma canonica y validado.
     *
     * @return int|string|list<string>
     *
     * @throws InvalidComplianceProfileValue
     */
    private static function normalise(ComplianceProfileField $field, mixed $value): int|string|array
    {
        return match ($field->type()) {
            ComplianceProfileFieldType::Integer => self::normaliseInteger($field, $value),
            ComplianceProfileFieldType::Text => self::normaliseText($field, $value),
            ComplianceProfileFieldType::DateList => self::normaliseDateList($field, $value),
        };
    }

    private static function normaliseInteger(ComplianceProfileField $field, mixed $value): int
    {
        if (! is_int($value)) {
            throw InvalidComplianceProfileValue::notAnInteger($field, get_debug_type($value));
        }

        if ($value < $field->minimum() || $value > $field->maximum()) {
            throw InvalidComplianceProfileValue::outOfRange($field, $value, $field->minimum(), $field->maximum());
        }

        return $value;
    }

    private static function normaliseText(ComplianceProfileField $field, mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidComplianceProfileValue::notText($field, get_debug_type($value));
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidComplianceProfileValue::notEmpty($field);
        }

        if (mb_strlen($trimmed) > $field->maximumLength()) {
            throw InvalidComplianceProfileValue::tooLong($field, mb_strlen($trimmed), $field->maximumLength());
        }

        return $trimmed;
    }

    /**
     * El calendario de festivos del camino de **escritura**, que es estricto.
     *
     * El parseo, el orden y la deteccion de repetidos son los mismos que usa el
     * nucleo —{@see HolidayCalendar}, un solo sitio— y lo que cambia es que aqui
     * un descarte **no se acepta**: hay una persona delante a la que decirle que
     * lo que envio no es una fecha, y guardar en silencio algo distinto de lo
     * enviado es como se acaba con un calendario que nadie sabe por que no
     * cuadra. Al leer, la politica es la contraria y esta escrita alli.
     *
     * @return list<string>
     */
    private static function normaliseDateList(ComplianceProfileField $field, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidComplianceProfileValue::notADateList($field, get_debug_type($value));
        }

        $calendar = HolidayCalendar::of($value);

        if ($calendar->rejected !== []) {
            throw InvalidComplianceProfileValue::notADateList($field, $calendar->rejected[0]);
        }

        if ($calendar->hadDuplicates) {
            throw InvalidComplianceProfileValue::duplicated($field);
        }

        return $calendar->days;
    }
}
