# Runbook — triaje de un hallazgo de Semgrep o Trivy

**No es un incidente.** Este runbook no responde a una alerta de producción:
responde a un hallazgo del job `security` de `ci.yml` — Semgrep comunitario,
`trivy fs` o `trivy image`. **Los cuatro controles del job son bloqueantes**
desde el 29 de agosto de 2026: Semgrep comunitario tras triar sus 9 hallazgos
(4 `dependabot-missing-cooldown` corregidos con `cooldown` de 7 días; 5 de
Nginx justificados con `# nosemgrep` y motivo), y los dos Trivy tras corregir
en la propia imagen de PostgreSQL lo que señalaban (`USER postgres` y sin
`gosu`, §5). Sirve para dos cosas: decidir qué hacer con un hallazgo concreto,
y saber cuándo un control nuevo puede entrar en modo informe y cuándo tiene
que dejar de estarlo.

**Modo informe no es `continue-on-error: true`.** Ese mecanismo se retiró
(IMPORTANTE 5 de la auditoría del pipeline): ocultaba por igual un hallazgo
real y que la herramienta ni siquiera hubiera podido ejecutarse. Hoy cada paso
en modo informe fuerza a la herramienta a devolver 0 cuando el único motivo de
un código distinto de cero serían los HALLAZGOS (`--exit-code 0` en Trivy;
en Semgrep, el objetivo del Makefile absorbe el 1 «hay hallazgos» —`make` lo
convertiría en 2, indistinguible de un fallo real— y la CI cuenta los
hallazgos en el JSON; 2+ significa que la herramienta no pudo terminar, y
**eso sí** rompe el paso).
El recuento por severidad se imprime en el resumen de la ejecución (`jq` sobre
la salida JSON). Y el modo informe **caduca**: el último paso del job
`security` compara la fecha de hoy con una lista de plazos (`PLAZOS` en
`ci.yml`) y **falla el job** si alguno de los controles en informe sigue así
después de su fecha — no es una nota que alguien tenga que acordarse de leer.

**Destinatario:** quien introduce el cambio, con revisión de
`revisor-codigo` o de una persona antes de fusionar la excepción — nunca la
decide en solitario quien la escribe (doc 02 §3.5: *"lo que solo puede
verificar una persona pertenece a la lista de `revisor-codigo`"*, y una
excepción de seguridad es exactamente eso).

---

## 1. El árbol de decisión

Ante cualquier hallazgo nuevo, en este orden:

1. **¿Es un problema real?** Corrígelo. Es casi siempre la opción más barata:
   un `no-cache`, una versión de paquete, una línea de configuración. Y antes
   de descartar esta vía, pregunta si el componente señalado **hace falta en
   absoluto**: el caso de referencia es `gosu` en la imagen de PostgreSQL, que
   parecía un falso positivo (punto 2) y una CVE no alcanzable (punto 3) a la
   vez, y resultó que la imagen no lo ejecutaba nunca. Eliminarlo y añadir
   `USER postgres` cerró 22 hallazgos sin una sola excepción.

   **Caso frecuente en `trivy image`: el parche ya está publicado.** Si la
   columna *Fixed Version* trae una versión que Alpine ya sirve y el paquete
   lo instala un `apk add` de nuestro Dockerfile, el hallazgo no es del código:
   es la **caché de Actions**, que reutiliza la capa de paquetes mientras el
   texto de la instrucción no cambie. Las tres imágenes llevan el sello semanal
   `APK_INDEX_STAMP` (`make build-ci-images`, semana ISO) precisamente para
   eso; si el hallazgo aparece a mitad de semana, basta con relanzar el job la
   semana siguiente o forzar el refresco con
   `make build-ci-images APK_INDEX_STAMP=$(date -u +%s)`. **Nunca se resuelve
   con una excepción**: el CVE es real, solo que la imagen reconstruida ya no
   lo tiene. Caso de referencia: `libexpat` 2.8.3-r0 → 2.8.4-r0 en
   `kronoqr/app:ci`, septiembre de 2026.
2. **¿Es un falso positivo verificable?** —la herramienta no puede ver algo
   que sí es cierto en tiempo de ejecución—. Documenta la excepción (§2) con
   fecha de caducidad. Solo si el punto 1 no era posible: una excepción por
   falso positivo hay que renovarla; una corrección, no.
3. **¿Es cierto pero no alcanzable en este uso concreto?** —una CVE de una
   biblioteca cuya función vulnerable el producto no invoca nunca—. Misma vía
   que el punto 2: excepción con justificación y caducidad, y la misma
   pregunta previa: si la biblioteca entera sobra, se elimina.
4. **¿Ninguna de las anteriores, pero corregirlo hoy no es proporcionado?**
   Dilo en el PR, dilo en el TODO fechado del workflow, y que quede en la lista
   de pendientes de la siguiente sesión (`HANDOFF.md`). **Esto no se resuelve
   con una excepción**: una excepción afirma "esto no es un problema", y aquí
   sí lo es, solo que no toca hoy.

**Una excepción sin justificación la rechaza la revisión.** No hay atajo: un
`# nosemgrep` o una entrada de `.trivyignore.yaml` sin motivo escrito es
indistinguible de silenciar la herramienta, y la review existe precisamente
para esa distinción.

---

## 2. Cómo se justifica una excepción

### 2.1 Semgrep — `# nosemgrep`

```php
// nosemgrep: kronoqr-domain-lee-el-reloj-del-sistema
// Motivo: SystemClock es el UNICO adaptador legitimo que construye la hora
// real (ADR-021); es la implementacion del puerto Clock, no una violacion.
// Revisado: 2026-08-28.
$now = new DateTimeImmutable();
```

- El identificador de la regla va **en la misma línea o en la línea anterior**
  al hallazgo (`// nosemgrep: <rule-id>`), nunca un `// nosemgrep` a secas: eso
  suprime *cualquier* regla en esa línea, incluida una que se añada después y
  de la que nadie sabe nada.
- El motivo y la fecha van en un comentario aparte, inmediatamente antes.
- Para las reglas propias de `.semgrep/kronoqr-php.yaml`, plantéate primero si
  el hallazgo no estará señalando que la regla necesita un caso más en su
  `paths.exclude` — una excepción puntual que se repite en el mismo módulo es
  una regla mal acotada, no dos excepciones.

### 2.2 Trivy — `.trivyignore.yaml`

Formato de
[`infra/docker/.trivyignore.yaml`](../../infra/docker/.trivyignore.yaml)
(hoy sin entradas vigentes; la última fue `CVE-2025-68121` en `gosu`, retirada
al eliminar el binario):

```yaml
vulnerabilities:
  - id: CVE-2026-XXXXX
    paths:
      - usr/lib/<biblioteca-de-la-imagen-base>
    statement: >-
      Motivo completo: de donde viene, por que no es alcanzable en este uso,
      y que condicion hace que deje de aplicar (que se actualice la imagen
      base, que se elimine el binario, etc.).
    expired_at: 2026-11-30
```

- **`expired_at` es obligatorio.** Sin fecha de caducidad, `trivy` sigue
  ignorando el hallazgo para siempre, incluida la versión parcheada que
  algún día exista. Seis a doce meses vista es razonable; nunca "sin fecha".
- Un fichero de excepciones por imagen (`infra/docker/.trivyignore.yaml` es de
  `postgres:ci`); si `kronoqr/app:ci` necesitara el suyo, va junto al
  Dockerfile que construye esa imagen, no en un fichero compartido.
- `misconfigurations` sigue la misma forma que `vulnerabilities`, con `id` del
  chequeo (p. ej. `DS-0002`) en vez de un CVE.

### 2.3 gitleaks — `.gitleaks.toml`

No se reescribe el historial para borrar un falso positivo (§7.7 doc 02): se
añade a la `allowlist` de [`.gitleaks.toml`](../../.gitleaks.toml), **una
entrada por cada patrón verificado uno a uno**, nunca una ruta completa "por si
acaso". Si el hallazgo es un secreto real, no hay excepción posible: es
[`rotacion-secretos.md`](rotacion-secretos.md) y, si ya se ha publicado el
repositorio o llegó a `main`, valorar con seguridad si aplica
`brecha-de-seguridad.md`.

---

## 3. Cuándo un control pasa de informe a bloqueante

Cada control nuevo se añadió con el criterio *"si hoy pasa limpio, bloqueante
desde el primer día; si tiene hallazgos, informe con fecha de caducidad"* (doc
02 §9.2). La fecha vive en dos sitios que tienen que decir lo mismo: el
comentario del paso correspondiente en `.github/workflows/ci.yml` y la entrada
de ese control en la variable `PLAZOS` de un último paso del job `security`
("Los pasos en modo informe no han caducado"). **Ese paso hoy no existe**
porque no queda ningún control en informe; si uno nuevo entra en ese modo, se
recupera del historial (commit `ci(security)`) junto con el control, nunca
el control solo. Para promoverlo:

1. Todos los hallazgos de esa ejecución están en alguno de estos tres estados:
   corregidos, con excepción justificada (§2), o con un TODO propio en
   `HANDOFF.md` explícitamente aceptado.
2. Se ejecuta el objetivo del Makefile correspondiente en modo bloqueante, tal
   cual lo hace un desarrollador (`make sast-community`, `make trivy-fs`,
   `make trivy-image`, sin `TRIVY_EXIT_CODE=0` ni las variables de formato que
   usa la CI) y **termina en verde sin la excepción de arriba silenciando nada
   nuevo**.
3. En `ci.yml` se quita `TRIVY_EXIT_CODE=0`/`TRIVY_FORMAT=json` (Trivy) o
   `SEMGREP_FORMAT=json` y la interpretación de código de salida (Semgrep) del
   paso correspondiente, dejando que el `make` bloquee como el resto de la
   cadena de calidad, y se borra la entrada de ese control en `PLAZOS` del
   último paso del job. Si solo se retira de `PLAZOS` sin volver el paso
   bloqueante, el control deja de tener fecha límite sin haber empezado
   realmente a bloquear — la promoción son las dos cosas juntas, nunca una
   sola.
4. Si la fecha se cumple sin que el control se haya podido promover ni
   justificar del todo, **no se deja pasar en silencio**: se renueva la fecha
   en los dos sitios a la vez, con el motivo de la prórroga en el mensaje del
   commit — la comprobación del paso 3 de este runbook fallará el job hasta
   que se haga.

**Estado a 29 de agosto de 2026** (para la siguiente sesión que revise esto).
Ningún control del job `security` está en modo informe, y por eso el paso de
caducidad (`PLAZOS`) ya no existe en `ci.yml`: si un control nuevo entra en
informe, se recupera ese paso del historial (commit `ci(security)`) con su
fecha, no se deja un TODO.

| Control | Cómo pasó a bloqueante |
| --- | --- |
| `secrets-scan` | Desde el primer día: pasó limpio con la allowlist mínima de `.gitleaks.toml` |
| `sast-community` | 4 `dependabot-missing-cooldown` corregidos con `cooldown`; 5 generic-nginx (dynamic-proxy-host ×3, request-host-used ×2) justificados con `# nosemgrep` y motivo: el `Host` de los backends internos no lo controla quien ataca |
| `trivy-fs` | El único hallazgo, `DS-0002` en `infra/docker/postgres/Dockerfile`, se corrigió: la imagen lleva `USER postgres` y no arranca como root |
| `trivy-image` | Los 21 HIGH de `kronoqr/postgres:ci` estaban todos en `usr/local/bin/gosu`; el binario se elimina de la imagen (no se ejecuta nunca sin root) y con él desaparecen los 21 y la excepción `CVE-2025-68121` de `infra/docker/.trivyignore.yaml`, que queda vacío. Se prefirió eso a ampliar la lista de excepciones: cada subida del digest de la base las habría reabierto |

Lo que enseña el caso de `gosu` para el próximo triaje: antes de excepcionar
un binario de la imagen base, comprobar si hace falta en absoluto. Un
componente que no se ejecuta en nuestro uso se elimina, no se justifica.
