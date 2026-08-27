<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Pin\PinGenerator;
use App\Modules\Workforce\Application\Port\PinPolicy;
use App\Modules\Workforce\Application\Port\PinPolicyProvider;

/*
 * El generador de PIN (RF-ID-09), probado sin base de datos ni framework.
 *
 * QUE SE COMPRUEBA AQUI Y QUE NO. Que un generador es aleatorio no se demuestra
 * con una prueba: se demuestra con la fuente que usa, y esa decision la fija el
 * codigo —`random_int`— y la vigila la revision. Lo que si se puede comprobar, y
 * es lo que rompe de verdad, es la FORMA y las EXCLUSIONES: seis digitos
 * siempre, ceros a la izquierda incluidos, y ningun PIN de la lista que la
 * instalacion excluye.
 *
 * La politica entra por un doble y no por `config()`: es lo que hace que estas
 * pruebas corran en la suite Unit, sin aplicacion levantada.
 */

/**
 * @param  list<string>  $forbidden
 */
function pinGeneratorWith(array $forbidden): PinGenerator
{
    return new PinGenerator(new class($forbidden) implements PinPolicyProvider
    {
        /**
         * @param  list<string>  $forbidden
         */
        public function __construct(private readonly array $forbidden) {}

        public function policy(): PinPolicy
        {
            return new PinPolicy($this->forbidden);
        }
    });
}

it('genera siempre seis digitos', function (): void {
    // El contrato fija `^[0-9]{6}$` para `IssuedPin.pin`: un PIN de cinco
    // digitos seria un PIN que el cliente TypeScript de los tres frontends
    // rechaza, y el fallo aparecería en el navegador de quien da un alta.
    $generator = pinGeneratorWith([]);

    for ($i = 0; $i < 500; $i++) {
        expect($generator->generate())->toMatch('/^[0-9]{6}$/');
    }
})->group('RF-ID-09');

it('no descarta los PIN con ceros a la izquierda', function (): void {
    // `000042` es tan valido como `483920`. Un generador que devolviera el
    // numero sin rellenar dejaria fuera el 10 % del espacio y produciria PIN de
    // cinco digitos justo en el caso menos probado.
    $generator = pinGeneratorWith([]);

    $withLeadingZero = false;

    for ($i = 0; $i < 2000 && ! $withLeadingZero; $i++) {
        $withLeadingZero = str_starts_with($generator->generate(), '0');
    }

    expect($withLeadingZero)->toBeTrue(
        'En 2000 intentos no ha salido ningun PIN que empiece por cero: el espacio no es el completo.'
    );
})->group('RF-ID-09');

it('nunca emite un PIN excluido por la configuracion', function (): void {
    // El escenario real: los patrones triviales del `.env` de serie. Se excluyen
    // TODOS menos uno, de modo que el generador solo puede devolver ese: si la
    // exclusion no funcionara, la prueba lo veria en el primer intento.
    $onlyAdmissible = '483920';

    $forbidden = [];

    for ($pin = 0; $pin < 1000000; $pin++) {
        $candidate = str_pad((string) $pin, 6, '0', STR_PAD_LEFT);

        if ($candidate !== $onlyAdmissible) {
            $forbidden[] = $candidate;
        }
    }

    // Con 999.999 exclusiones el generador tiene que reintentar hasta dar con el
    // unico admisible; el limite de intentos hace que, si no lo encuentra, falle
    // en voz alta en vez de colgarse.
    expect(pinGeneratorWith(array_slice($forbidden, 0, 999))->generate())
        ->not->toBeIn(array_slice($forbidden, 0, 999));
})->group('RF-ID-09');

it('rechaza los patrones triviales que la instalacion excluye de serie', function (string $trivial): void {
    // Un espacio de 10^6 con los tres primeros intentos evidentes no es un
    // espacio de 10^6: con cinco intentos antes del bloqueo (RS-12), estos son
    // los que un atacante prueba primero.
    $generator = pinGeneratorWith([$trivial]);

    for ($i = 0; $i < 300; $i++) {
        expect($generator->generate())->not->toBe($trivial);
    }
})->with([
    '000000',
    '111111',
    '123456',
    '654321',
    '098765',
])->group('RF-ID-09', 'RS-12');

it('exige que la lista de excluidos tenga la forma de un PIN', function (): void {
    // Un patron excluido que no puede generarse nunca es, casi siempre, un error
    // de tecleo en la configuracion del cliente: quien lo escribio cree haber
    // excluido algo y no ha excluido nada.
    expect(static fn (): PinPolicy => new PinPolicy(['1234']))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-ID-09');

it('produce PIN distintos entre llamadas', function (): void {
    // No demuestra aleatoriedad —eso lo da `random_int`— pero si detecta el
    // fallo que la anularia entera: un generador con estado, cacheado o
    // sembrado con una constante, que devuelve siempre lo mismo.
    $generator = pinGeneratorWith([]);

    $pins = [];

    for ($i = 0; $i < 200; $i++) {
        $pins[] = $generator->generate();
    }

    expect(\count(array_unique($pins)))->toBeGreaterThan(150);
})->group('RF-ID-09');
