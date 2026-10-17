<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\InvalidEmployeeSearch;
use App\Modules\Shared\Domain\ValueObject\NationalId;

/**
 * Como se busca a la persona que nombra un requerimiento de la Inspeccion, para
 * pedir despues su exportacion legal (RF-IN-05, V21-3-PA-1, ADR-037).
 *
 * ## Dos modos y nunca los dos a la vez
 *
 * - **Por texto** ({@see self::byText()}): casa con el nombre, los apellidos o el
 *   nombre completo por subcadena, sin distinguir mayusculas ni acentos, o con
 *   el codigo de empleado **exacto**. Exige al menos
 *   {@see self::MIN_TEXT_LENGTH} caracteres **una vez recortado**: con uno o dos,
 *   la busqueda es el directorio de plantilla por entregas, y el `auditor` no
 *   tiene el directorio (RF-ID-02).
 * - **Por documento** ({@see self::byNationalId()}): se normaliza como en el
 *   alta —{@see NationalId}— y se compara exacto contra su hash (RL-08). No hay
 *   busqueda parcial posible, porque un hash no la admite.
 *
 * ## El termino no sale de aqui hacia el trail
 *
 * Lo que el asiento de auditoria puede saber de una busqueda es su
 * {@see self::$criterion}; el texto y el documento solo los lee el adaptador
 * que consulta la base. Por eso este objeto no se serializa ni tiene
 * representacion en texto.
 */
final readonly class EmployeeSearch
{
    /** Caracteres minimos del termino libre una vez recortado (RF-IN-05). */
    public const int MIN_TEXT_LENGTH = 3;

    /** Caracteres maximos del termino libre: el `maxLength` del contrato. */
    public const int MAX_TEXT_LENGTH = 100;

    private function __construct(
        public EmployeeSearchCriterion $criterion,
        public ?string $text,
        public ?NationalId $nationalId,
    ) {}

    /**
     * @throws InvalidEmployeeSearch si el termino recortado tiene menos de
     *                               {@see self::MIN_TEXT_LENGTH} caracteres o mas
     *                               de {@see self::MAX_TEXT_LENGTH}
     */
    public static function byText(string $text): self
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_TEXT_LENGTH) {
            throw InvalidEmployeeSearch::textTooShort(self::MIN_TEXT_LENGTH);
        }

        if ($length > self::MAX_TEXT_LENGTH) {
            throw InvalidEmployeeSearch::textTooLong(self::MAX_TEXT_LENGTH);
        }

        return new self(EmployeeSearchCriterion::NAME_OR_CODE, $trimmed, null);
    }

    /**
     * @throws InvalidEmployeeSearch si, una vez normalizado, el documento tiene
     *                               menos de {@see NationalId::MIN_LENGTH}
     *                               caracteres
     */
    public static function byNationalId(string $nationalId): self
    {
        $normalised = NationalId::fromInput($nationalId);

        if (! $normalised instanceof NationalId || $normalised->isTooShort()) {
            throw InvalidEmployeeSearch::nationalIdTooShort(NationalId::MIN_LENGTH);
        }

        return new self(EmployeeSearchCriterion::NATIONAL_ID, null, $normalised);
    }

    public function isByNationalId(): bool
    {
        return $this->criterion === EmployeeSearchCriterion::NATIONAL_ID;
    }
}
