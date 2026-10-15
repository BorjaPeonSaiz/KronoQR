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

    // El job que arma el paquete depende del que empuja las imagenes Y del que las
    // firma (B18): no se entrega un paquete con imagenes sin firmar.
    expect($workflow)->toMatch('/\n  paquete:.*?needs:\s*\[resolver,\s*imagenes,\s*firmar\]/s');

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

it('firma las tres imagenes con cosign keyless por digest y comprueba la firma con el codigo de los instaladores', function (): void {
    // B18 (2.2.1). El certificado lo emite Sigstore a la identidad del workflow:
    // sin `id-token: write` no hay firma, y sin `--yes` cosign se para a preguntar.
    $workflow = Repo::contents('.github/workflows/release.yml');
    $lib = Repo::contents('infra/scripts/lib/image-signature.sh');

    preg_match('/\n  firmar:(.*?)\n  paquete:/s', $workflow, $job);
    $firmar = $job[1] ?? '';

    expect($firmar)->not->toBe('')
        ->and($firmar)->toContain('id-token: write')
        ->and($firmar)->toContain('needs: [resolver, imagenes]')
        ->and($firmar)->toContain('sigstore/cosign-installer@')
        ->and($firmar)->toContain('cosign sign --yes "${ref}"')
        // Por digest, no por etiqueta.
        ->and($firmar)->toContain('ref="${REGISTRO}/${imagen}@${digest}"')
        // Y verifica con la funcion de los instaladores (la identidad que casa con el certificado real).
        ->and($firmar)->toContain('. infra/scripts/lib/image-signature.sh')
        ->and($firmar)->toContain('kq_cosign_verify_ref "${ref}"');

    // La version de cosign esta fijada en los dos jobs de firma (cosign 3 escribe el
    // bundle nuevo) y coincide con lo que el cliente exige como minimo.
    expect(substr_count($workflow, 'cosign-release: "v3.'))->toBe(2)
        ->and($lib)->toContain('KQ_COSIGN_MIN_VERSION="3.0.0"');

    // SHA256SUMS tambien se firma, en un job aparte que nace del que lo genera, se
    // autoverifica con la funcion de los clientes y publica el bundle en la release.
    preg_match('/\n  firmar-sumas:(.*?)\n  publicar:/s', $workflow, $sumas);
    $firmarSumas = $sumas[1] ?? '';

    expect($firmarSumas)->toContain('needs: [resolver, paquete]')
        ->and($firmarSumas)->toContain('id-token: write')
        ->and($firmarSumas)->toContain('cosign sign-blob --yes --bundle firmadas/SHA256SUMS.bundle entrega/SHA256SUMS')
        ->and($firmarSumas)->toContain('kq_cosign_verify_blob entrega/SHA256SUMS firmadas/SHA256SUMS.bundle')
        ->and($workflow)->toMatch('/\n  publicar:.*?needs:\s*\[resolver,\s*imagenes,\s*paquete,\s*firmar-sumas\]/s')
        ->and($workflow)->toContain("            SHA256SUMS.bundle\n");

    // `id-token: write` solo en los dos jobs de firma: el resto de la publicacion no emite certificados.
    expect(substr_count($workflow, "\n      id-token: write"))->toBe(2)
        ->and($workflow)->not->toMatch('/^permissions:\n(?:  .*\n)*?  id-token:/m');

    // La identidad esperada es el workflow de publicacion de ESTE repositorio y el emisor de GitHub.
    expect($lib)->toContain('https://token.actions.githubusercontent.com')
        ->and($lib)->toContain('/\.github/workflows/release\.yml@refs/');
})->group('RS-10', 'RF-PD-02');

it('fija por digest toda imagen de compose.prod.yaml, salvo las siete de observabilidad que ADR-053 deja pendientes', function (): void {
    // B18 (2.2.1). Una etiqueta de terceros se puede mover: un Redis sustituido
    // alimenta la cola de trabajos de `horizon`. Cada `image:` es una de:
    //   · una imagen del producto (registro + IMAGE_TAG + hueco IMAGE_DIGEST_*, que
    //     package.sh rellena con el digest de la publicacion), o su alias YAML
    //     (`*php-image`: `horizon` y `scheduler` reutilizan el ancla de `app`);
    //   · una imagen de terceros con `@sha256:<64 hex>` literal.
    // Las siete del perfil `observability` van por version (ADR-053, «Pendiente»):
    // la lista es cerrada, y una imagen nueva sin digest que no este en ella rompe
    // esta prueba. Cuando se fijen, la lista se vacia.
    $pendientes = [
        'prom/prometheus', 'prom/node-exporter', 'prom/alertmanager', 'grafana/grafana',
        'grafana/loki', 'grafana/tempo', 'prom/blackbox-exporter',
    ];
    $compose = Repo::contents('infra/compose.prod.yaml');

    preg_match_all('/^\s*(?:- )?image:\s*(?:&\S+\s+)?(\S.*?)\s*$/m', $compose, $matches);
    $imagenes = $matches[1];
    expect(count($imagenes))->toBeGreaterThanOrEqual(11);

    $sinDigest = [];
    $propias = 0;

    foreach ($imagenes as $imagen) {
        if (str_starts_with($imagen, '*')) {
            // Alias del ancla de la imagen de la aplicacion.
            expect($imagen)->toBe('*php-image');

            continue;
        }

        if (preg_match('/^\$\{IMAGE_REGISTRY:-[^}]*\}\/(?:php|nginx|postgres):\$\{IMAGE_TAG:\?[^}]*\}\$\{IMAGE_DIGEST_(?:PHP|NGINX|POSTGRES):-\}$/', $imagen) === 1) {
            $propias++;

            continue;
        }

        // Redis: el digest es el valor por defecto de REDIS_IMAGE_DIGEST (`${VAR-valor}`:
        // sin definir, fijada; definida y vacia, la etiqueta; opt-out sin internet).
        if (preg_match('/^redis:7-alpine\$\{REDIS_IMAGE_DIGEST-@sha256:[0-9a-f]{64}\}$/', $imagen) === 1) {
            continue;
        }

        if (preg_match('/^[a-z0-9._\/-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$/', $imagen) === 1) {
            continue;
        }

        $sinDigest[] = $imagen;
    }

    // Tres imagenes del producto (la de `app` y su ancla cuentan una vez).
    expect($propias)->toBe(3);

    // Lo que queda sin digest es exactamente la lista cerrada, ni mas ni menos.
    $nombres = array_map(static fn (string $imagen): string => explode(':', $imagen)[0], $sinDigest);
    sort($nombres);
    $esperadas = $pendientes;
    sort($esperadas);
    expect($nombres)->toBe($esperadas);

    // Y Redis, la prioritaria, lleva etiqueta Y digest.
    expect($compose)->toMatch('/image:\s*redis:7-alpine\$\{REDIS_IMAGE_DIGEST-@sha256:[0-9a-f]{64}\}\s*$/m');

    // Dependabot no lee la linea de produccion (lleva interpolacion): lee la de
    // desarrollo, que lleva el MISMO digest literal. Si la PR de Dependabot lo
    // sube solo alli, esta prueba falla hasta copiarlo a produccion.
    $dev = Repo::contents('infra/compose.dev.yaml');
    expect(preg_match('/image:\s*redis:7-alpine@(sha256:[0-9a-f]{64})\s*$/m', $dev, $enDesarrollo))->toBe(1)
        ->and(preg_match('/REDIS_IMAGE_DIGEST-@(sha256:[0-9a-f]{64})\}/', $compose, $enProduccion))->toBe(1)
        ->and($enProduccion[1] ?? '')->toBe($enDesarrollo[1] ?? 'x', 'El digest de Redis de compose.prod.yaml difiere del de compose.dev.yaml: copia el nuevo a produccion.');
})->group('RF-PD-02', 'RS-10');

it('la vuelta atras in-place anula los digests, la primera publicacion es explicita y la CI ejercita el paquete fijado', function (): void {
    $update = Repo::contents('infra/scripts/update.sh');
    $guard = Repo::contents('.github/scripts/assert-image-tags-free.sh');
    $release = Repo::contents('.github/workflows/release.yml');
    $ci = Repo::contents('.github/workflows/ci.yml');

    // Vuelta atras in-place: las tres variables, vacias y con marca, en la copia
    // del .env; al completarse, esa copia pasa a ser el .env (no queda una
    // segunda copia de los secretos) y el reintento retira la marca.
    $envFile = Repo::contents('infra/scripts/lib/env-file.sh');
    foreach (['PHP', 'NGINX', 'POSTGRES'] as $imagen) {
        expect($envFile)->toContain('IMAGE_DIGEST_'.$imagen);
    }
    expect($update)->toContain('kq_env_mark_rollback_digests "${ROLLBACK_ENV}"')
        ->and($update)->toContain('kq_env_drop_rollback_digests "${ENV_FILE}"')
        ->and($update)->toContain('mv -f "${ROLLBACK_ENV}" "${ENV_FILE}"');

    // El 403 de GHCR solo se acepta con la variable explicita y solo la pone la entrada manual.
    expect($guard)->toContain('KQ_FIRST_PUBLICATION')
        ->and($release)->toContain('first_publication')
        ->and(substr_count($release, 'KQ_FIRST_PUBLICATION:'))->toBe(2);

    // ⑧b arma un paquete CON images.lock y hace la vuelta atras in-place sobre el.
    expect($ci)->toContain('KQ_IMAGES_LOCK=')
        ->and($ci)->toContain('registry:2')
        ->and($ci)->toContain('P2 · Vuelta atras IN PLACE');
})->group('RF-PD-02', 'RS-10');
