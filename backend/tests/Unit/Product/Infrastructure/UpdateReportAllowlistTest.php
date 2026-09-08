<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\UpdateReportAllowlist;

/*
 * El filtro del informe de actualizacion que viaja en el paquete (RF-PD-09,
 * ADR-020, regla dura 21).
 *
 * `update.sh` manda los volcados largos al `.detalle.log` **porque pueden llevar
 * datos personales** —su propio comentario cita el `DETAIL: Failing row contains
 * (...)` de PostgreSQL— y declara limpio el informe. Es cierto hoy. Esta prueba
 * existe porque «es cierto hoy» no es una garantia: un `err` mal puesto en una
 * version futura, o un mensaje del motor que se cuele por ahi, acabaria en un
 * fichero que el cliente envia al fabricante y nada fallaria.
 */

/** Un informe realista, con las lineas que `close_report` escribe de verdad. */
function informeDeActualizacion(): string
{
    return implode("\n", [
        'INFORME DE ACTUALIZACION DE KRONOQR',
        'Inicio (UTC): 20260908T031500Z',
        'Version de origen: 2.1.0 · version de destino: 2.2.0',
        'Instalacion actual: /opt/kronoqr/current · paquete nuevo: /opt/kronoqr/2.2.0',
        '',
        'Cadena de versiones: 2.1.0 -> 2.1.4 -> 2.2.0',
        'Copia previa: /var/backups/fichaje/pre-update-20260908.dump (correcto)',
        'Ventana de mantenimiento: 94 s',
        'Punto de control 2.1.4: correcto (12 migraciones, 31 s, lote 1)',
        'Comprobacion · doctor: correcto, con avisos',
        'Estado final: version 2.2.0 · operativa',
        'Salida 0 (correcto)',
        '==================================================================',
        'Este informe no contiene secretos ni datos personales y se puede adjuntar al paquete de diagnostico.',
    ]);
}

it('deja pasar el informe real entero, sin omitir nada', function (): void {
    // La otra mitad de la prueba: un filtro que lo omitiera todo pasaria los
    // casos de fuga y dejaria el paquete sin la seccion que responde a «¿que
    // cambio antes de que empezara a pasar?».
    $filtered = UpdateReportAllowlist::apply(informeDeActualizacion());

    expect($filtered['omitted_lines'])->toBe(0)
        ->and($filtered['content'])->toBe(informeDeActualizacion());
})->group('RF-PD-09');

it('no deja salir un volcado de PostgreSQL con un nombre dentro, ni un correo', function (): void {
    // EL CASO QUE JUSTIFICA EL FILTRO, con las dos formas reales en que la fuga
    // ocurriria: el `DETAIL:` del motor y una direccion de correo en una linea
    // de contexto.
    $contaminado = implode("\n", [
        'INFORME DE ACTUALIZACION DE KRONOQR',
        'Inicio (UTC): 20260908T031500Z',
        'DETAIL: Failing row contains (Marta García, 49871234Z, marta@hotel.example).',
        'Aviso enviado a soporte-hotel@ejemplo.example tras el fallo.',
        'Salida 0 (correcto)',
    ]);

    $filtered = UpdateReportAllowlist::apply($contaminado);

    expect($filtered['omitted_lines'])->toBe(2)
        ->and($filtered['content'])->not->toContain('Marta')
        ->and($filtered['content'])->not->toContain('García')
        ->and($filtered['content'])->not->toContain('49871234Z')
        ->and($filtered['content'])->not->toContain('marta@hotel.example')
        ->and($filtered['content'])->not->toContain('soporte-hotel@ejemplo.example')
        ->and($filtered['content'])->not->toContain('DETAIL')
        // Y lo que si es del informe sigue estando.
        ->and($filtered['content'])->toContain('Inicio (UTC): 20260908T031500Z')
        ->and($filtered['content'])->toContain('Salida 0 (correcto)');
})->group('RF-PD-09', 'RL-19');

it('omite una linea desconocida aunque parezca inofensiva', function (): void {
    // La direccion del fallo importa: una linea nueva de `update.sh` que nadie
    // haya añadido aqui se OMITE y se cuenta. Molesto y visible, en lugar de
    // publicada en silencio.
    $filtered = UpdateReportAllowlist::apply("Inicio (UTC): X\nNota del operador: todo bien");

    expect($filtered['omitted_lines'])->toBe(1)
        ->and($filtered['content'])->toBe('Inicio (UTC): X');
})->group('RF-PD-09');

it('omite una linea larguisima aunque empiece por un comienzo conocido', function (): void {
    // La segunda red: un volcado pegado detras de una etiqueta valida.
    $filtered = UpdateReportAllowlist::apply('Salida 0 (correcto) '.str_repeat('x', 400));

    expect($filtered['omitted_lines'])->toBe(1)
        ->and($filtered['content'])->toBe('');
})->group('RF-PD-09');

it('conserva las lineas en blanco y los separadores, que dan forma al informe', function (): void {
    $filtered = UpdateReportAllowlist::apply("Salida 0 (correcto)\n\n===========\n-----");

    expect($filtered['omitted_lines'])->toBe(0);
})->group('RF-PD-09');

it('reconoce el informe en ingles', function (): void {
    // `update.sh` escribe en el idioma de la instalacion: si el filtro solo
    // conociera el español, un cliente con la instalacion en ingles enviaria un
    // paquete con la seccion vacia y `omitted_lines` a veinte.
    $ingles = implode("\n", [
        'KRONOQR UPDATE REPORT',
        'Start (UTC): 20260908T031500Z',
        'Source version: 2.1.0 · target version: 2.2.0',
        'Checkpoint 2.1.4: ok (12 migrations, 31 s, batch 1)',
        'Check · doctor: ok, with warnings',
        'Final state: version 2.2.0 · operational',
        'Exit 0 (ok)',
    ]);

    expect(UpdateReportAllowlist::apply($ingles)['omitted_lines'])->toBe(0);
})->group('RF-PD-09');
