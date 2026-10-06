<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Cache\CacheMutex;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Sleep;

/*
 * El candado que ordenan los dos contadores de fallos sobre la cache: el del
 * PIN por empleado y puerta y el del portal por origen (RS-12, ADR-050, regla
 * dura 19).
 *
 * Se prueba UNA vez aqui y no en cada adaptador: lo que cada adaptador prueba
 * es que cuenta bien con el candado y sin el. Lo que se fija:
 *
 *  - con el candado libre, el trabajo corre con el cogido y lo suelta al acabar,
 *    tambien si el trabajo lanza;
 *  - con el candado retenido por otro, se espera un numero FIJO de intentos —por
 *    intentos y no por reloj, que las pruebas detienen— y se trabaja sin el;
 *  - con un almacen que lanza al dar o al soltar el candado, se trabaja igual:
 *    nunca un `500` en el portal ni un fichaje de respaldo que no llega.
 */

const CACHE_MUTEX_LOCK = 'kronoqr-test:cache-mutex';

beforeEach(function (): void {
    Sleep::fake();
});

it('trabaja con el candado cogido y lo suelta al terminar', function (): void {
    $almacen = new ArrayStore;
    $candado = new CacheMutex(new Repository($almacen), attempts: 3, retryMicroseconds: 1_000);

    $retenidoDentro = $candado->guarded(
        CACHE_MUTEX_LOCK,
        static fn (): bool => ! $almacen->lock(CACHE_MUTEX_LOCK, 10)->get(),
    );

    expect($retenidoDentro)->toBeTrue()
        ->and($almacen->lock(CACHE_MUTEX_LOCK, 10)->get())->toBeTrue();

    Sleep::assertNeverSlept();
})->group('RS-12');

it('suelta el candado aunque el trabajo lance, y deja subir la excepcion', function (): void {
    $almacen = new ArrayStore;
    $candado = new CacheMutex(new Repository($almacen), attempts: 3, retryMicroseconds: 1_000);

    expect(fn (): never => $candado->guarded(CACHE_MUTEX_LOCK, static function (): never {
        throw new DomainException('del trabajo');
    }))->toThrow(DomainException::class, 'del trabajo')
        ->and($almacen->lock(CACHE_MUTEX_LOCK, 10)->get())->toBeTrue();
})->group('RS-12');

it('trabaja sin el candado si otro no lo suelta, tras un numero fijo de intentos', function (): void {
    $almacen = new ArrayStore;
    $almacen->lock(CACHE_MUTEX_LOCK, 10)->get();

    $candado = new CacheMutex(new Repository($almacen), attempts: 7, retryMicroseconds: 2_500);

    expect($candado->guarded(CACHE_MUTEX_LOCK, static fn (): string => 'contado'))->toBe('contado');

    // Siete intentos, siete esperas de 2,5 ms: con el reloj detenido de las
    // pruebas, una espera medida por tiempo no terminaria nunca.
    Sleep::assertSleptTimes(7);
    Sleep::assertSequence(array_fill(0, 7, Sleep::usleep(2_500)));
})->group('RS-12');

it('no suelta el candado de otro cuando trabaja sin el', function (): void {
    $almacen = new ArrayStore;
    $ajeno = $almacen->lock(CACHE_MUTEX_LOCK, 10);
    $ajeno->get();

    (new CacheMutex(new Repository($almacen), attempts: 1, retryMicroseconds: 1))
        ->guarded(CACHE_MUTEX_LOCK, static fn (): null => null);

    expect($almacen->lock(CACHE_MUTEX_LOCK, 10)->get())->toBeFalse();
})->group('RS-12');

it('trabaja sin el candado si el almacen no puede darlo', function (): void {
    $almacen = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): never
        {
            throw new RuntimeException('Connection refused');
        }
    };

    $candado = new CacheMutex(new Repository($almacen), attempts: 3, retryMicroseconds: 1_000);

    expect($candado->guarded(CACHE_MUTEX_LOCK, static fn (): string => 'contado'))->toBe('contado');

    Sleep::assertNeverSlept();
})->group('RS-12');

it('trabaja igual si el almacen no puede soltarlo', function (): void {
    $almacen = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function release(): bool
                {
                    throw new RuntimeException('Connection reset');
                }
            };
        }
    };

    $candado = new CacheMutex(new Repository($almacen), attempts: 3, retryMicroseconds: 1_000);

    expect($candado->guarded(CACHE_MUTEX_LOCK, static fn (): string => 'contado'))->toBe('contado');
})->group('RS-12');
