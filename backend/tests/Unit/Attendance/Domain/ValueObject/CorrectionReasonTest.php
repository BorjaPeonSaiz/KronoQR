<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InvalidCorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;

/*
 * RF-PA-04 y Anexo C del doc 01 — el motivo de una correccion.
 *
 * Lo que se comprueba aqui no es que una validacion devuelva `false`: es que el
 * objeto **no llega a existir**. La diferencia importa porque nadie que reciba
 * un `CorrectionReason` vuelve a mirarlo, ni el agregado, ni el caso de uso, ni
 * quien escribe `shift_corrections`. Si el estado invalido fuera construible,
 * cada uno de esos tres sitios tendria que acordarse.
 *
 * El limite de `OTROS` se prueba por los dos lados —19 y 20— porque un umbral
 * probado solo por dentro pasa igual con `>` que con `>=`, y son cosas
 * distintas.
 */

it('rechaza OTROS con 19 caracteres', function (): void {
    expect(fn (): CorrectionReason => CorrectionReason::of(
        CorrectionReasonCode::OTROS,
        str_repeat('a', 19),
    ))->toThrow(InvalidCorrectionReason::class);
})->group('RF-PA-04');

it('acepta OTROS con 20 caracteres', function (): void {
    $reason = CorrectionReason::of(CorrectionReasonCode::OTROS, str_repeat('a', 20));

    expect($reason->code)->toBe(CorrectionReasonCode::OTROS)
        ->and($reason->text)->toBe(str_repeat('a', 20));
})->group('RF-PA-04');

it('rechaza OTROS sin ninguna explicacion', function (): void {
    expect(fn (): CorrectionReason => CorrectionReason::of(CorrectionReasonCode::OTROS))
        ->toThrow(InvalidCorrectionReason::class);
})->group('RF-PA-04');

it('no cuenta como explicacion veinte espacios', function (): void {
    // El minimo se mide sobre el texto ya recortado: si se midiera antes, la
    // barra de espacio bastaria para saltarse el Anexo C.
    expect(fn (): CorrectionReason => CorrectionReason::of(CorrectionReasonCode::OTROS, str_repeat(' ', 25)))
        ->toThrow(InvalidCorrectionReason::class);
})->group('RF-PA-04');

it('cuenta caracteres y no bytes', function (): void {
    // Veinte caracteres acentuados son cuarenta bytes en UTF-8. Contando bytes,
    // esta explicacion pasaria con la mitad de contenido del que exige el Anexo
    // C, y solo en castellano.
    $veinteAcentuadas = str_repeat('á', 20);

    $reason = CorrectionReason::of(CorrectionReasonCode::OTROS, $veinteAcentuadas);

    expect($reason->text)->toBe($veinteAcentuadas)
        ->and(mb_strlen($veinteAcentuadas))->toBe(20);
})->group('RF-PA-04');

it('rechaza diecinueve caracteres acentuados', function (): void {
    expect(fn (): CorrectionReason => CorrectionReason::of(CorrectionReasonCode::OTROS, str_repeat('á', 19)))
        ->toThrow(InvalidCorrectionReason::class);
})->group('RF-PA-04');

it('no exige explicacion a los codigos que ya explican algo', function (CorrectionReasonCode $code): void {
    $reason = CorrectionReason::of($code);

    expect($reason->code)->toBe($code)
        ->and($reason->text)->toBeNull();
})->with([
    CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA,
    CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA,
    CorrectionReasonCode::FALLO_TECNICO_QUIOSCO,
    CorrectionReasonCode::TARJETA_NO_DISPONIBLE,
    CorrectionReasonCode::CREDENCIAL_NO_ENTREGADA,
    CorrectionReasonCode::ERROR_DE_ESCANEO_DUPLICADO,
    CorrectionReasonCode::AJUSTE_ACORDADO_CON_RRHH,
    CorrectionReasonCode::ALTA_RETROACTIVA,
])->group('RF-PA-04');

it('admite texto libre junto a un codigo que no lo exige', function (): void {
    $reason = CorrectionReason::of(CorrectionReasonCode::FALLO_TECNICO_QUIOSCO, '  Sin corriente en el hall  ');

    // Recortado una sola vez, aqui, para que `reason_text` no acabe con la
    // mitad de sus filas con espacios de sobra.
    expect($reason->text)->toBe('Sin corriente en el hall');
})->group('RF-PA-04');

it('normaliza a nulo un texto en blanco', function (): void {
    $reason = CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA, '   ');

    expect($reason->text)->toBeNull();
})->group('RF-PA-04');

it('construye el motivo desde el codigo del catalogo', function (): void {
    $reason = CorrectionReason::fromCode('AJUSTE_ACORDADO_CON_RRHH');

    expect($reason->code)->toBe(CorrectionReasonCode::AJUSTE_ACORDADO_CON_RRHH);
})->group('RF-PA-04');

it('rechaza un codigo que no esta en el Anexo C', function (): void {
    expect(fn (): CorrectionReason => CorrectionReason::fromCode('PORQUE_SI'))
        ->toThrow(InvalidCorrectionReason::class);
})->group('RF-PA-04');

it('mantiene el catalogo del Anexo C completo y sin invenciones', function (): void {
    // El Anexo C es autoridad #3 y el enum es la copia ejecutable. Si alguien
    // añade un codigo aqui sin tocar el documento, esta prueba lo dice.
    $codes = array_map(
        static fn (CorrectionReasonCode $code): string => $code->value,
        CorrectionReasonCode::cases(),
    );

    expect($codes)->toBe([
        'OLVIDO_FICHAJE_ENTRADA',
        'OLVIDO_FICHAJE_SALIDA',
        'FALLO_TECNICO_QUIOSCO',
        'TARJETA_NO_DISPONIBLE',
        'CREDENCIAL_NO_ENTREGADA',
        'ERROR_DE_ESCANEO_DUPLICADO',
        'AJUSTE_ACORDADO_CON_RRHH',
        'ALTA_RETROACTIVA',
        'OTROS',
    ]);
})->group('RF-PA-04');
