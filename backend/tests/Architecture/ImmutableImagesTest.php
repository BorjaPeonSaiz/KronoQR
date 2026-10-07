<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * Una version publicada es inmutable (A6-2, doc 02 §11.6.1).
 *
 * Fijan la CONFIGURACION: que release.yml rechaza reescribir una version, que
 * el paquete fija las imagenes por digest y que el compose lo admite. Que la
 * publicacion funcione de verdad lo dice el propio release.yml: el paso «El
 * compose entregado resuelve las tres imagenes por digest» comprueba, con
 * `docker compose config`, que el paquete que sale lleva los tres digests.
 */

it('hace fallar la publicacion si la version ya existe en el registro, antes y justo antes de empujar', function (): void {
    $workflow = Repo::contents('.github/workflows/release.yml');
    $guard = Repo::contents('.github/scripts/assert-image-tags-free.sh');

    // La comprobacion corre dos veces: al principio (falla rapido, antes de
    // esperar a la CI) y en el job que empuja, justo antes del `docker push`.
    expect(substr_count($workflow, 'assert-image-tags-free.sh'))->toBeGreaterThanOrEqual(2)
        ->and($workflow)->toContain('etiquetas-libres');

    $imagenes = strpos($workflow, "\n  imagenes:");
    expect($imagenes)->not->toBeFalse();
    $tramo = substr($workflow, (int) $imagenes);
    $comprobacion = strpos($tramo, 'assert-image-tags-free.sh');
    $empuje = strpos($tramo, 'docker push');
    expect($comprobacion)->not->toBeFalse()
        ->and($empuje)->not->toBeFalse()
        ->and((int) $comprobacion)->toBeLessThan((int) $empuje);

    // Y el job que espera a la CI no empieza hasta que la etiqueta esta libre.
    expect($workflow)->toMatch('/esperar-ci:.*?needs:\s*\[resolver,\s*etiquetas-libres\]/s');

    // Falla cerrado: solo un «no existe» inequivoco deja pasar.
    expect($guard)->toContain('docker buildx imagetools inspect')
        ->and($guard)->toContain('not found|no such manifest|manifest unknown|name unknown')
        ->and($guard)->toContain('No se puede comprobar la etiqueta');
})->group('RF-PD-02', 'RQ-11');

it('fija en el paquete las tres imagenes por digest', function (): void {
    $workflow = Repo::contents('.github/workflows/release.yml');
    $package = Repo::contents('infra/scripts/package.sh');
    $compose = Repo::contents('infra/compose.prod.yaml');

    // release.yml lee el digest del registro y se lo da a package.sh.
    expect($workflow)->toContain("--format '{{.Manifest.Digest}}'")
        ->and($workflow)->toContain('KQ_IMAGES_LOCK=')
        ->and($workflow)->toContain('bash infra/scripts/package.sh paquete');

    // El job que arma el paquete depende del que empuja las imagenes.
    expect($workflow)->toMatch('/\n  paquete:.*?needs:\s*\[resolver,\s*imagenes\]/s');

    // package.sh exige un digest bien formado para las tres.
    expect($package)->toContain('KQ_IMAGES_LOCK')
        ->and($package)->toContain('sha256:[0-9a-f]{64}')
        ->and($package)->toContain('images.lock');

    // El compose lleva el hueco de digest en las tres lineas `image:` de la
    // entrega, y en ninguna otra imagen del producto.
    foreach (['PHP', 'NGINX', 'POSTGRES'] as $imagen) {
        expect(substr_count($compose, '${IMAGE_DIGEST_'.$imagen.':-}'))->toBe(1);
    }
    expect(preg_match_all('/\$\{IMAGE_TAG:\?[^}]*\}\$\{IMAGE_DIGEST_[A-Z]+:-\}/', $compose))->toBe(3);
})->group('RF-PD-02', 'RS-10');
