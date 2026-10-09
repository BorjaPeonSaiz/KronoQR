# Runbook — la CI está en rojo

**Modo de fallo:** una etapa de `.github/workflows/ci.yml` falla, o una puerta de
`.github/workflows/release.yml` bloquea una etiqueta de versión.

**Destinatario:** quien hizo el *push* o puso la etiqueta. No se escala a nadie
más: no hay impacto en producción y la rama no llega a `main` hasta que está
verde.

**Impacto en el fichaje: ninguno.** La CI verifica código que todavía no se ha
desplegado en el servidor de ningún cliente. Si estás aquí a las 06:30 porque
alguien no puede fichar, este no es el runbook: mira
[`README.md`](README.md), el índice por alerta y por situación.

## Qué comprueba cada etapa y qué significa que falle

La cabecera de `ci.yml` es la fuente de verdad de las etapas y de su
presupuesto de tiempo (doc 02 §10.1); aquí, lo que significa un rojo en cada una.

| Etapa | Comprueba | Un fallo aquí significa |
|---|---|---|
| ① Lint + Tipos · backend | Pint, PHPStan 9, ShellCheck, `shfmt -i 2 -d`, robustez de los scripts, `@redocly/cli` sobre `docs/api/openapi.yaml` | Estilo, tipado, un script de shell que no cumple el §3.5, o un contrato OpenAPI inválido |
| ① Lint + Tipos · *frontend* | ESLint (con `eslint-plugin-vue`) y `vue-tsc` de cada SPA y de `packages/web-kit` | Estilo de Vue, `any`, tipos que no cuadran |
| ② Arquitectura | Deptrac y la suite `Architecture` de Pest | **Se ha roto una frontera**: reglas duras 1 o 2 de `CLAUDE.md`. También viven aquí las pruebas que leen la documentación y la infraestructura (alertas ↔ runbooks, cuadros de Grafana, documentación de cliente) |
| ③ Unitarias + Mutación | Suites `Unit`, `Contract` y `Feature` de Pest y MSI ≥ 80 % sobre los ficheros de `Modules/*/Domain` **que cambia ese push** (`make mutate-changed`); la mutación **completa** corre de noche (job `mutation`) y en el disparo manual | Una regla de negocio se comporta distinto de lo que dice su prueba |
| ③b Trazabilidad | `qa:traceability --check` y `docs:consistency --check`, al final del job ③ | Un requisito implementado sin prueba etiquetada (RQ-13), o dos documentos que se contradicen |
| ④ Integración | `Pest --testsuite=Integration` con PostgreSQL real: invariantes SQL, `MigrationsRoundTripTest` y los caminos de `install.sh`/`update.sh` que no necesitan Docker ni root | Un esquema, una restricción o un script de operación se comportan distinto con una base de verdad |
| ⑤ Seguridad | `composer audit` y `npm audit` (bloquea solo producción), Semgrep propio y comunitario, gitleaks, Trivy fs/imagen, SBOM | Una dependencia vulnerable, un secreto o un hallazgo de SAST. Los de Semgrep comunitario y Trivy en modo informe se atienden con [`triaje-hallazgos-seguridad.md`](triaje-hallazgos-seguridad.md) |
| ⑥ Frontend unitarias | Vitest de los cuatro paquetes del workspace | Un componente o un almacén se comporta distinto de su prueba |
| ⑦ E2E | Playwright con cámara simulada y axe | Un recorrido de usuario roto o una violación de accesibilidad |
| ⑧ Instalación limpia · ⑧b Actualización | `clean-install` (los cuatro escenarios de RQ-11) y `update` (idempotencia, vuelta atrás con fallo inyectado, restauración de la copia previa). Corren en `main`, en cada etiqueta y a mano, no en cada push | El instalador, el actualizador o el paquete de entrega no hacen lo que dice [`actualizacion-cliente.md`](actualizacion-cliente.md). **Es el que más importa antes de publicar** |
| Nocturnas | `coverage` (dominio ≥ 90 %, global ≥ 75 %), `mutation` completa y `secrets-history` | Una regresión que el push no cubre; se ve a la mañana siguiente |

Los presupuestos de ①–③ (menos de 15 min de reloj) y del resto los mide cada job
con una anotación `::warning`: es un termómetro, no una puerta. Si se pasa, se
mide y se arregla; no se sube el presupuesto.

La distinción entre ① y ② es deliberada. Que un `use Illuminate\Support\...`
dentro de `Modules/*/Domain/` rompa la etapa ② y no la ① es lo que permite leer
el fallo sin abrir el log: *se ha roto la arquitectura*, no *falta un espacio*.

## Diagnóstico: reproducir el fallo en tu máquina

Cada paso de la CI es una orden del `Makefile`, así que se reproduce entera en
local. Con el entorno levantado (`make up`):

```bash
make sh-lint            # ①  ShellCheck + shfmt + robustez de los scripts
make api-lint           # ①  OpenAPI 3.1 (docs/api/openapi.yaml)
make php-lint           # ①  Pint + PHPStan nivel 9
make rector             # ①  informativo, nunca bloquea
make deptrac            # ②  fronteras entre capas y módulos
make test-arch          # ②  Pest Arch y pruebas de documentación/infraestructura
make test-unit          # ③  suite unitaria
make test-contract      # ③  contrato OpenAPI y Feature
make mutate-changed     # ③  mutación acotada a lo que cambia frente a origin/main
make traceability-check # ③b requisitos implementados sin prueba
make docs-consistency   # ③b coherencia entre documentos
make test-integration   # ④  PostgreSQL real
make observability-check # reglas de Prometheus, pruebas de umbral y Alertmanager
make e2e                # ⑦  Playwright con cámara simulada
make mutate             # mutación COMPLETA (nocturna y disparo manual)
make changelog-check VERSION=1.2.3   # puerta de versión
```

Para ejecutarlo **tal y como lo hace el runner** —herramientas sobre
`backend/`, sin contenedor— añade `CI=true`. Necesitas PHP 8.4 y
`composer install` en `backend/`:

```bash
make php-lint CI=true
```

**El E2E no corre en los contenedores `node-*`** (son Alpine y el Chromium de
Playwright no arranca ahí): valídalo en el job ⑦ de la CI o en el anfitrión.

## Resolución por síntoma

| Síntoma en el log | Qué hacer |
|---|---|
| `vendor/bin/pint --test` lista ficheros | `make shell` y `vendor/bin/pint` (sin `--test`). Corrige y vuelve a empujar |
| PHPStan: `Method ... has no return type` y similares | Se corrige, no se silencia. Un `@phpstan-ignore` solo vale con su motivo entre paréntesis en el propio comentario; Semgrep verifica que lo lleve |
| ShellCheck o `shfmt -i 2 -d` en un script | `shfmt -i 2 -w <script>` y corrige lo que diga ShellCheck. Los scripts de `infra/scripts/` llevan `set -euo pipefail`; los que corre la CI con `sh` corren con `dash` (sin `pipefail` ni `$'\n\t'`): pruébalos con `debian:stable-slim` |
| Deptrac: `App\Modules\X\Domain\... must not depend on Illuminate\...` | Regla dura 1. El dominio no importa el framework: lo que necesita entra por un puerto de `Application/Port/`. **No se añade a `skip_violations`**: ADR-021 y ADR-025 lo prohíben por escrito |
| Deptrac: `Uncovered` | Un fichero ha caído en `ModuleUnclassified`. Suele ser una carpeta nueva que no está en la estructura del §2: muévela a su capa, no relajes la regla |
| Pest Arch: `llama a now()` | Regla dura 2. Inyecta el puerto `Clock`. Sin eso no se pueden probar DST ni medianoche, que son la mitad del riesgo del dominio |
| Una prueba de `Architecture` de alertas, runbooks o documentación de cliente (`AlertCatalogueTest`, `BackupAndAlertingTest`, `ClientDocumentationTest`…) | Alguien ha añadido una alerta sin runbook, un `runbook_url` que no existe, o un documento que cita una orden o un fichero que ya no existe. **Se arregla el documento o la regla, no la prueba**: la norma del doc 02 §8.4 es que una alerta sin procedimiento es ruido |
| `qa:traceability --check`: requisito sin prueba | Etiqueta la prueba con `->group('RN-05', 'RF-AT-08')` o escríbela; el nivel lo decide la tabla del doc 02 §9.5 |
| `docs:consistency`: divergencia entre documentos | Arreglar la contradicción forma parte de la tarea; el orden de autoridad está en `CLAUDE.md` |
| `Missing script: lint` en un frontend | El `package.json` de ese frontend debe exponer `lint` (ESLint) y `type-check` (`vue-tsc --noEmit`). Es el contrato que espera la etapa ① |
| `npm audit` o `composer audit` en rojo | Solo bloquean las vulnerabilidades de **producción** (alta o crítica). Actualiza la dependencia; si no hay parche, se anota la excepción con fecha en el workflow y se revisa en cada bloque ([`triaje-hallazgos-seguridad.md`](triaje-hallazgos-seguridad.md)) |
| gitleaks encuentra algo | Si es un secreto real, **rótalo ya** ([`rotacion-secretos.md`](rotacion-secretos.md)) y reescribir el historial no basta. Si es un valor de ejemplo conocido, se añade a la lista de permitidos con su motivo |
| `la version X no tiene entrada en CHANGELOG.md` | `bash infra/scripts/changelog.sh generate --release X --write`, revisa el resultado, commitea y vuelve a etiquetar |
| `release.yml` · `etiquetas-libres`: la etiqueta de imagen ya existe | **Una versión publicada no se vuelve a publicar** (ADR-053). Lo que se corrige sale en otra versión (`X.Y.Z+1`). Solo en el primer despliegue sobre un registro nuevo (GHCR responde `403` a un paquete inexistente) se lanza a mano con `first_publication` = true |
| `release.yml` · `gates`: `VERSION no coincide con la etiqueta`, clave pública sin poner o `SECURITY.md` con marcadores | Corrige el fichero que dice el mensaje y vuelve a etiquetar; ninguna imagen se ha construido todavía |
| `release.yml` · `esperar-ci`: la etapa ⑧/⑧b de esa etiqueta no está en verde | No se publica. Arregla la causa en `ci.yml` (⑧ y ⑧b corren en la propia etiqueta) y repite con `workflow_dispatch` indicando la etiqueta, sin crear otra |
| `Presupuesto excedido` (aviso, no error) | La etapa ha tardado más de lo que fija el doc 02 §10.1. No rompe la ejecución, pero se mira: una CI lenta se acaba ignorando |

## Qué no hacer

- **No desactivar un paso para que pase la CI.** Si una comprobación estorba, o
  está mal planteada y se cambia con su justificación, o el código está mal.
- **No añadir un `baseline` de PHPStan ni `skip_violations` en Deptrac.** El
  umbral del doc 02 §9.2 es 0, y una excepción global convierte la cadena en
  decoración.
- **No subir secretos para "arreglar" una etapa.** Las etapas ①–③ no necesitan
  credenciales: no hay base de datos, ni registro de imágenes, ni API externa. Si
  una etapa parece necesitar un secreto, está mal diseñada.
- **No republicar una versión ya publicada** moviendo su etiqueta (ADR-053): los
  clientes la instalan por digest, y dos «2.2.0» distintas son un incidente de
  soporte.
