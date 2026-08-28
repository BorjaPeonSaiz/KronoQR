<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use App\Modules\Shared\Infrastructure\Metrics\RedisAuthenticationMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Tests\Support\Health\UnavailableRedis;

/*
 * `kronoqr_auth_attempts_total{channel,outcome}` sobre Redis de verdad (OWASP
 * A09, doc 02 §8.2).
 *
 * **Por que Integration y por que contra Redis y no contra un doble.** Lo que se
 * comprueba no es que el adaptador llame a un metodo: es **el nombre exacto de la
 * serie y la forma exacta de sus etiquetas**, que son las que las reglas de
 * alerta de A09 consultan y las que la tarea 3.1 publicara. Un doble daria por
 * bueno cualquier nombre.
 */

beforeEach(function (): void {
    app(Redis::class)->connection()->command('DEL', [RedisAuthenticationMetrics::AUTH_ATTEMPTS_TOTAL]);
});

it('publica la serie con el nombre y las etiquetas que usan las alertas', function (): void {
    $metrics = new RedisAuthenticationMetrics(app(Redis::class));

    $metrics->attempt(AuthChannel::MANAGEMENT, AuthOutcome::FAILURE);
    $metrics->attempt(AuthChannel::MANAGEMENT, AuthOutcome::FAILURE);
    $metrics->attempt(AuthChannel::KIOSK_PIN, AuthOutcome::LOCKOUT);

    /** @var array<string, string> $serie */
    $serie = app(Redis::class)->connection()->command('HGETALL', [
        RedisAuthenticationMetrics::AUTH_ATTEMPTS_TOTAL,
    ]);

    expect(RedisAuthenticationMetrics::AUTH_ATTEMPTS_TOTAL)
        // La invariante de la que depende la tarea 3.1: el sufijo de la clave es,
        // literalmente, el nombre de la metrica expuesta.
        ->toBe('kronoqr:metrics:kronoqr_auth_attempts_total')
        ->and($serie)->toBe([
            'channel=management,outcome=failure' => '2',
            'channel=kiosk_pin,outcome=lockout' => '1',
        ]);
})->group('RS-12');

it('no rompe un acceso cuando Redis no responde', function (): void {
    // Regla dura 19 y RL-05: perder un contador es infinitamente mas barato que
    // devolver un 500 a quien acaba de teclear bien su contrasena — o dejar a
    // alguien sin fichar.
    $metrics = new RedisAuthenticationMetrics(new UnavailableRedis);

    $metrics->attempt(AuthChannel::PORTAL, AuthOutcome::SUCCESS);
})->group('RS-12', 'RQ-06')->throwsNoExceptions();
