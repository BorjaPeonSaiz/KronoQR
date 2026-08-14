# Registro de cambios

Todas las novedades relevantes de KronoQR. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el producto se
versiona con [SemVer](https://semver.org/lang/es/) (doc 02 §10.5).

**Este fichero se genera**, no se edita a mano:

    make changelog          # regenera la seccion [Unreleased]

La fuente son los mensajes de commit con formato convencional. Un commit que no
lo siga no aparece aqui, y el generador lo avisa por la salida de error.
Ninguna version se publica sin su entrada: `make changelog-check VERSION=1.2.3`
falla si no la encuentra, y la CI ejecuta esa comprobacion al etiquetar.

## [Unreleased]

### Interno

- conservar las suites Contract e Integration vacias (0.2)
- corregir la ubicacion del puerto Clock y documentar el bind mount
- cadena de calidad y pruebas de arquitectura de ADR-021 y ADR-025 (0.3)
- esqueleto Laravel 12 con los 8 modulos y el puerto Clock (0.2)
- entorno de desarrollo con los 14 servicios y make de arranque (0.1)

