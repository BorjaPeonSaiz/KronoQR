<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Exception\InvalidCorrectionReason;

/**
 * Por que se rectifico un registro horario: un codigo del Anexo C y, cuando hace
 * falta, la explicacion escrita (doc 01 §5.3, RF-PA-04, RN-13).
 *
 * **El estado invalido no se puede construir.** `OTROS` con «error» dentro no
 * produce un objeto que alguien tenga que validar despues: no produce objeto. Es
 * la diferencia entre un tipo y un formulario, y aqui importa porque el motivo
 * es lo unico que hace defendible una correccion ante Inspeccion: sin el, el
 * registro dice que las horas cambiaron y no dice por que.
 *
 * **El minimo se cuenta en caracteres, no en bytes.** `mb_strlen` y no
 * `strlen`: veinte caracteres con eñes y tildes son mas de veinte bytes, y
 * contar bytes dejaria pasar en castellano textos que en ingles se rechazan.
 * Tambien se cuenta sobre el texto **ya recortado**, para que veinte espacios no
 * sean una explicacion.
 *
 * **El texto es opcional para los otros ocho codigos.** RF-PA-04 pide «motivo
 * obligatorio de un catalogo mas texto libre»: el catalogo es obligatorio
 * siempre, el texto solo cuando el codigo no explica nada por si mismo. Un texto
 * vacio o en blanco se normaliza a `null` aqui, una sola vez, para que
 * `shift_corrections.reason_text` no acabe con la mitad de sus filas a cadena
 * vacia y la otra mitad a nulo significando lo mismo.
 */
final readonly class CorrectionReason
{
    /**
     * Anexo C: «obliga a texto libre de al menos 20 caracteres».
     *
     * Es una constante del dominio y no un umbral de configuracion (regla dura
     * 14): no cambia de un cliente a otro, porque no depende del convenio ni de
     * la legislacion local, sino de que una explicacion de tres palabras no
     * explica una rectificacion del registro horario de nadie.
     */
    public const int MINIMUM_EXPLANATION_LENGTH = 20;

    private function __construct(
        public CorrectionReasonCode $code,
        /** Explicacion escrita, ya recortada. Nula cuando no la hay. */
        public ?string $text,
    ) {
        if ($code->needsExplanation() && ($text === null || mb_strlen($text) < self::MINIMUM_EXPLANATION_LENGTH)) {
            throw InvalidCorrectionReason::needsExplanation(
                $code->value,
                $text === null ? 0 : mb_strlen($text),
                self::MINIMUM_EXPLANATION_LENGTH,
            );
        }
    }

    public static function of(CorrectionReasonCode $code, ?string $text = null): self
    {
        return new self($code, self::normalize($text));
    }

    /**
     * Desde el codigo tal como llega de la API o de `shift_corrections`.
     *
     * Un codigo fuera del catalogo no es un 500: es un intento de escribir en el
     * registro legal un motivo que el Anexo C no reconoce, y se rechaza con el
     * mismo tipo de error que un `OTROS` sin explicacion.
     */
    public static function fromCode(string $code, ?string $text = null): self
    {
        return self::of(
            CorrectionReasonCode::tryFrom($code) ?? throw InvalidCorrectionReason::unknownCode($code),
            $text,
        );
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->text === $other->text;
    }

    /**
     * Espacios de sobra fuera y cadena vacia a nulo. Se hace una vez, al
     * construir, y no en cada capa que toque el motivo.
     */
    private static function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }
}
