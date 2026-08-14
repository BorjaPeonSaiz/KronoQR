# ADR-030 — Se adopta Laravel 13 antes de escribir el dominio

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 14 de agosto de 2026 |
| **Decide** | Responsable de producto, a propuesta de `arquitecto-dominio` |
| **Afecta a** | Tarea 0.2 · doc 02 §3.1 y §1.4 · `CLAUDE.md` · toda la Fase 1 |
| **Requisitos** | RNF-M-02, RS-10, §11.6.5 (matriz de versiones soportadas) |

> Este ADR no procede de la tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4. Nace de ejecutar la instrucción que el propio §3.1 daba y que **no tenía ADR donde aterrizar**: *«verificar la versión mayor vigente al arrancar y actualizar el ADR si procede»*. Ninguna de las 29 filas registraba la elección de framework, así que la instrucción no tenía destinatario. Este ADR es ese destinatario.

## Contexto

El §3.1 fijaba **Laravel 12.x** y la tarea 0.2 se ejecutó sobre `v12.66.0`. Al escribir los ADR de la tarea 0.6 se comprobó la vigencia de esa versión, y el resultado no era el esperado:

| Dato | Valor verificado el 14 de agosto de 2026 |
|---|---|
| Última mayor publicada | **Laravel 13**, disponible desde marzo de 2026; última `v13.25.0` |
| Instalado en el proyecto | `v12.66.0`, restricción `^12.0` |
| Laravel 12 · correcciones de fallos | **terminadas** |
| Laravel 12 · correcciones de seguridad | hasta **febrero de 2027** |

Es decir: la instalación era correcta y estaba soportada, pero acababa de entrar en modo solo-seguridad, con una fecha de caducidad a menos de seis meses.

**Por qué eso importa más aquí que en otro proyecto.** KronoQR se vende como producto instalado en el servidor de cada cliente, con conservación obligatoria de cuatro años (RL-02) y una matriz de versiones soportadas (§11.6.5). Una migración de versión mayor deja de ser un `composer update` en cuanto hay instalaciones en producción: pasa a ser una versión mayor del producto, con ventana de migración anunciada a clientes que pueden no tener salida a internet.

Y el momento en que se detectó es el más favorable posible: **el repositorio era esqueleto puro**. Cero dominio, cero endpoints, cero esquema, cero migraciones, cero clientes. 28 pruebas y ocho módulos con las carpetas creadas.

## Decisión

**Se adopta Laravel 13 ahora, antes de escribir la primera línea del dominio.**

Restricción `^13.12` y no `^13.0`: las versiones 13.0.0 a 13.11.x están afectadas por tres avisos de seguridad —dos de inyección CRLF en la regla de validación de correo y una confusión de rutas en URL firmadas temporales—, todos corregidos a partir de **13.12.0**. Composer se niega a instalar las versiones afectadas, y hace bien.

El salto arrastra a cinco paquetes más, porque sus versiones compatibles con Laravel 13 lo exigen:

| Paquete | Antes | Después | Por qué |
|---|---|---|---|
| `laravel/framework` | `^12.0` | **`^13.12`** | La decisión |
| `laravel/tinker` | `^2.10.1` | `^3.0` | 2.x no declara compatibilidad con `illuminate/*` 13 |
| `spatie/laravel-pdf` | `^1.0` | `^2.12` | 1.9.0 exige `illuminate/contracts ^10\|^11\|^12` |
| `pestphp/pest` | `^3.0` | `^5.0` | `pest-plugin-laravel` 5 lo exige, y es el que soporta Laravel 13 |
| `pestphp/pest-plugin-laravel` | `^3.0` | `^5.0` | Compatibilidad con Laravel 13 |
| `phpunit/phpunit` | `^11.5.50` | `^13.3` | Lo exige Pest 5 |

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Permanecer en 12 con fecha límite antes de febrero de 2027** | Traslada el mismo trabajo a un momento en que cuesta más. La migración se encarece con cada tarea de las Fases 1, 2 y 5; si se solapa con la 5, coincide con tener clientes instalados y obliga a una ventana de migración anunciada (§11.6.5) por una decisión que se pudo tomar cuando el repositorio estaba vacío |
| **Permanecer en 12 indefinidamente** | Febrero de 2027 es una fecha, no una opinión. Vender un producto de registro horario con valor probatorio sobre un framework sin soporte contradice RS-10, que prohíbe que una vulnerabilidad alta llegue a una versión publicada |
| **Subir solo `laravel/framework` y congelar los demás** | No es posible: Pest, PHPUnit, Tinker y `laravel-pdf` bloquean la resolución. Forzarlo con `--ignore-platform-reqs` produciría un `composer.lock` que la CI no puede reproducir |
| **Aceptar `^13.0` en lugar de `^13.12`** | Permitiría instalar versiones con tres avisos de seguridad conocidos, uno de severidad alta. Es exactamente lo que el umbral de `composer audit` del §9.2 existe para impedir |

## Consecuencias

- **El §3.1 pasa a decir 13.x**, y su nota de verificación apunta ahora a este ADR, que es lo que le faltaba.
- **`spatie/laravel-pdf` salta de mayor** (1.x → 2.x). Ninguna tarea lo usa todavía —las tarjetas de credencial son de la 1.10 y los informes sellados de la 2.9—, así que el cambio se paga hoy a coste cero. Quien escriba esas tareas debe leer la documentación de la 2.x y no la de la 1.x.
- **Pest salta dos mayores** (3 → 5). Las 28 pruebas existentes pasaron sin tocar una línea, y los dos complementos que el proyecto necesita siguen disponibles: `pest-plugin-arch` (pruebas de arquitectura de la tarea 0.3) y `pest-plugin-mutate` (MSI ≥ 80 %, RQ-10). Es lo que había que comprobar antes de aceptar el salto.
- **La migración se hizo sin cambiar código de aplicación.** Ni un fichero de `app/`, ni de `tests/`, ni de `config/`. Es la prueba de que el momento era el correcto.
- **Se pierde el conocimiento acumulado sobre 12.x** en documentación de terceros y respuestas de foros, que tardará en reponerse para 13. Es coste real y se asume.
- **La matriz de versiones soportadas (§11.6.5) nace directamente en 13**, sin arrastrar una versión 1.x del producto publicada sobre un framework sin correcciones de fallos.

## Verificación

Ejecutada al aplicar la decisión, no prometida:

- `php artisan --version` → **Laravel Framework 13.25.0** sobre PHP 8.4.24.
- `composer audit` → **sin avisos de seguridad**.
- `make test` → **28 pruebas, 42 aserciones, todas en verde**, sin modificar ninguna.
- `make quality` → Pint 40 ficheros, **PHPStan nivel 9 sin errores**, Deptrac **0 violaciones**, Rector informativo. Exit 0.
- `make sast` → 0 hallazgos.
- `make test-unit` → **0,21 s**, dentro del presupuesto de 2 s de `CLAUDE.md`.
- Ninguna prueba de arquitectura de [ADR-021](ADR-021-clock-en-shared.md) ni de [ADR-025](ADR-025-frontera-de-dependencias-del-nucleo.md) se vio afectada: las fronteras no dependen de la versión del framework, que es justo lo que la arquitectura hexagonal promete.

**Vigilancia futura.** La instrucción del §3.1 sigue viva y ahora tiene dónde aterrizar: al arrancar cada fase se comprueba la mayor vigente y, si ha cambiado, se decide aquí. La lección de este ADR es que el coste de esa decisión crece con el tamaño del código, no con el tiempo.
