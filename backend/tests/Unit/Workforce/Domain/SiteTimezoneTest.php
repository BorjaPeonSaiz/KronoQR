<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;

/*
 * La zona horaria del centro es el dato del que depende RN-05.
 *
 * Sin ella no se puede decir a que jornada pertenece un tramo, y su error no da
 * ningun sintoma: los turnos de noche se atribuyen al dia equivocado durante
 * meses, hasta que alguien cuadra una nomina.
 */

it('acepta un identificador IANA', function (string $identifier): void {
    expect(SiteTimezone::fromString($identifier)->identifier)->toBe($identifier);
})->with([
    'peninsula' => 'Europe/Madrid',
    'canarias' => 'Atlantic/Canary',
    'portugal' => 'Europe/Lisbon',
])->group('RN-05');

it('rechaza una zona que no existe', function (string $invalid): void {
    // Una errata aqui es una jornada mal atribuida, no un error de formulario.
    expect(fn () => SiteTimezone::fromString($invalid))->toThrow(UnknownTimezone::class);
})->with([
    'errata' => 'Europe/Madird',
    'vacia' => '',
    'inventada' => 'Hotel/Recepcion',
])->group('RN-05');

it('rechaza un desfase en horas', function (): void {
    // Regla dura 3 y RN-09: un desfase no sabe de cambios de hora, asi que
    // produciria una hora mal calculada dos veces al ano.
    expect(fn () => SiteTimezone::fromString('+02:00'))->toThrow(UnknownTimezone::class);
})->group('RN-09', 'RN-05');

it('se convierte en la zona horaria que usara el calculo', function (): void {
    expect(SiteTimezone::fromString('Europe/Madrid')->toDateTimeZone()->getName())->toBe('Europe/Madrid');
})->group('RN-05');
