<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/*
 * RN-22 (F6): `attendance.discard_review_window_days` nunca baja de 1. Un cero
 * o un negativo en `ATTENDANCE_DISCARD_REVIEW_WINDOW_DAYS` haria que
 * `DetectAnomaliesCommand` lanzara y cayera la revision nocturna entera.
 */

/**
 * La configuracion leida con la variable de entorno indicada.
 *
 * @return array<string, mixed>
 */
function configuracionDeAsistenciaCon(?string $value): array
{
    $name = 'ATTENDANCE_DISCARD_REVIEW_WINDOW_DAYS';

    $value === null ? Env::getRepository()->clear($name) : Env::getRepository()->set($name, $value);

    try {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3).'/config/attendance.php';

        return $config;
    } finally {
        Env::getRepository()->clear($name);
    }
}

it('no deja que la ventana de revision de los descartes baje de un dia', function (?string $value, int $expected): void {
    expect(configuracionDeAsistenciaCon($value)['discard_review_window_days'])->toBe($expected);
})->with([
    'de serie' => [null, 31],
    'cero' => ['0', 1],
    'negativa' => ['-5', 1],
    'un dia' => ['1', 1],
    'noventa' => ['90', 90],
])->group('RN-22');
