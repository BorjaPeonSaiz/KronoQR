# ADR-053 — Una versión publicada es inmutable y el paquete fija sus imágenes por digest

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. **Enmendada en la 2.2.1 (bloque 18): firma con cosign y Redis por digest.** Implementada en el bloque 14 de la 2.2.0 (`release.yml`, `.github/scripts/assert-image-tags-free.sh`, `infra/compose.prod.yaml`, `infra/scripts/package.sh`, `install.sh`, `update.sh`, `doctor.sh`); la CI la ejercita con un registro local (etapa ⑧b, escenarios P0 a P3) |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo A6-2 de la [tanda 6 de la 2.1.0](../verificacion/2.1.0-tanda-6.md), abierto y ampliado con Redis en la [re-verificación](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md)) · implementa `devops-observabilidad` · revisa `seguridad-cumplimiento` |
| **Afecta a** | [ADR-016](ADR-016-producto-licenciado-on-premise.md) (N instalaciones en M versiones) · [ADR-054](ADR-054-la-vuelta-atras-de-una-actualizacion-es-restaurar-la-copia.md) (la versión a la que se vuelve tiene que ser la misma que se instaló) · `.github/workflows/release.yml` (jobs `etiquetas-libres`, `imagenes` y `paquete`) · `.github/scripts/assert-image-tags-free.sh` · `infra/scripts/package.sh` (`KQ_IMAGES_LOCK`) · `infra/compose.prod.yaml` (`IMAGE_DIGEST_*`) · `infra/scripts/install.sh`, `infra/scripts/update.sh` · doc 02 §11.6.1 |
| **Requisitos** | RQ-11, RS-08, RS-10, RF-PD-10 |

## Contexto

Doc 02 §11.6.1 prometía «etiquetas de versión inmutables», y no lo eran. La verificación de la 2.1.0 (A6-2) encontró tres huecos:

1. **La etiqueta se podía reescribir.** `release.yml` hacía `docker tag` y `docker push` sobre `registro/<imagen>:<versión>` sin comprobar si ya existía. Un `workflow_dispatch` repetido volvía a publicar la misma versión con otros bytes.
2. **La instalación resolvía por etiqueta.** `compose.prod.yaml` pedía `${IMAGE_REGISTRY}/php:${IMAGE_TAG}`. Dos clientes con la «2.1.0», instalados en días distintos, podían correr imágenes distintas sin que nadie lo supiera.
3. **Nada va firmado.** `SHA256SUMS` viaja en la misma release que el paquete, así que quien pudiera cambiar uno cambiaría también el otro.

La re-verificación de la 2.2.0 lo confirmó en `main` y añadió un cuarto hueco: `redis:7-alpine` es una etiqueta que se mueve, y la guía la guarda tal cual para el modo sin internet.

Para un producto que se instala en el servidor de cada cliente y se soporta en N versiones a la vez (ADR-016), «la versión X» tiene que nombrar unos bytes concretos. Sin eso, el diagnóstico de un fallo de un cliente no se puede reproducir con la misma versión, y la vuelta atrás de una actualización (ADR-054) puede relanzar una versión «anterior» que ya no es la que había.

## Decisión

**Una versión publicada no se vuelve a publicar. Lo que se corrige sale en otra versión. El paquete de entrega fija cada imagen del producto por su digest, y la instalación corre esos bytes aunque la etiqueta del registro cambiara.**

### 1. La publicación falla si la versión ya existe en el registro

`release.yml` comprueba **dos veces** que ninguna de las tres imágenes (`php`, `nginx`, `postgres`) tiene ya la etiqueta de la versión: en el job `etiquetas-libres`, antes de esperar a la CI, y en `imagenes`, justo antes de empujar. Las dos comprobaciones las hace `.github/scripts/assert-image-tags-free.sh`, y valen también para un `workflow_dispatch`. `workflow_dispatch` sigue existiendo, pero solo para publicar sobre una etiqueta Git cuya ejecución no llegó a empujar nada. Si `imagenes` falla a medias, la versión no se repite: se publica un parche. Si falla después de las imágenes, se relanza la misma ejecución, que reutiliza los digests y no empuja nada. El `docker push` se reintenta hasta tres veces sobre la misma imagen local (mismos bytes, mismo digest). La release de GitHub no es lo inmutable: `publicar` la completa si una ejecución repetida la encuentra ya creada; lo inmutable son las imágenes y sus digests.

**Primera publicación en un registro nuevo.** GHCR responde `403 Forbidden`/`denied` (no «not found») a un paquete que todavía no existe, y `assert-image-tags-free.sh` falla cerrado: esa respuesta no es un «no existe» inequívoco, así que la primera publicación **se para**. Se resuelve lanzando `release.yml` a mano con la entrada `first_publication` = true, que exporta `KQ_FIRST_PUBLICATION=1`; solo con esa variable un `denied`/`403` cuenta como libre (con aviso). Después los paquetes existen y la entrada no se vuelve a usar: un 403 posterior es un fallo de permisos real.

### 2. El paquete fija las imágenes por digest

`imagenes` recoge el digest de cada imagen empujada y lo entrega a `paquete` en el fichero `KQ_IMAGES_LOCK`. `infra/scripts/package.sh` reescribe las tres líneas `image:` del `docker-compose.yml` entregado a `registro/<imagen>:<versión>@sha256:…`, mediante el valor por defecto de `IMAGE_DIGEST_PHP`, `IMAGE_DIGEST_NGINX` e `IMAGE_DIGEST_POSTGRES`. Falla si el fichero falta, si un digest está mal formado o si al terminar no hay exactamente tres referencias `@sha256:`. En el repositorio, en desarrollo y en la CI, las variables van vacías y vale la etiqueta. `install.sh` y `update.sh` descargan por digest y no por etiqueta, y etiquetan después la imagen ya verificada con su nombre por etiqueta (`docker tag`; si falla, avisan) porque `restore-drill.sh --mode pitr` y la vuelta atrás nombran la imagen así.

**Vuelta atrás *in place*.** Cuando el paquete nuevo se descomprime sobre el directorio de la instalación, el `docker-compose.yml` nuevo lleva el digest de la versión nueva como valor por defecto y es el que usa la vuelta atrás con el `.env` viejo: sin más, la imagen resolvería a `php:<vieja>@<digest nuevo>` y Docker, que manda por el digest, levantaría la versión nueva. Por eso `update.sh` escribe `IMAGE_DIGEST_PHP=`, `IMAGE_DIGEST_NGINX=` e `IMAGE_DIGEST_POSTGRES=` vacías (definidas, no ausentes) en la copia `.kronoqr-pre-update` del `.env`, de modo que la vuelta atrás resuelve por la etiqueta antigua, que sigue en local. Al completarse la vuelta atrás, esa copia (con una marca que dice que las vacías las puso `update.sh`) pasa a ser el `.env` de la instalación: un `docker compose up` a mano levanta lo mismo que está en pie y no queda una segunda copia de los secretos; el reintento retira la marca y las tres vacías, y vuelve a correr las imágenes fijadas. Unas vacías sin marca son el *opt-out* de un servidor sin internet y no se tocan. En el modo lado a lado la vuelta atrás usa el `docker-compose.yml` y el `.env` viejos intactos y no necesita nada.

### 3. Firma con cosign: a la 2.2.x *(resuelto en la [Enmienda 2.2.1](#enmienda-221--firma-con-cosign-y-redis-por-digest-bloque-18))*

El digest fija **qué** bytes se instalan, pero no prueba **quién** los publicó. Firmar las imágenes y el paquete, y enseñar al cliente a verificarlo, exige decidir cómo custodia el fabricante esas claves (RS-08): firma *keyless* con la identidad del workflow o clave propia. Esa decisión **no entra en la 2.2.0 y se compromete para una 2.2.x**. Cuando se tome, irá en un ADR propio que enmiende este.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Confiar en una opción de inmutabilidad del registro** | Que el registro admita etiquetas inmutables depende del proveedor, y el registro es configurable (`vars.IMAGE_REGISTRY`). La garantía tiene que vivir en el flujo de publicación y en el paquete, no en una opción de un proveedor concreto. Si el registro la ofrece, se puede activar además, pero no sustituye a esta decisión |
| **Solo comprobar la etiqueta, sin digest en el paquete** | Protege de un error del propio flujo, pero no de un `docker push` manual con credenciales del registro. Con el digest, la instalación es inmune a eso |
| **Solo digest, sin impedir la republicación** | La instalación quedaría bien, pero el registro tendría dos «2.2.0» y la etiqueta dejaría de servir para hablar con el cliente |
| **Firmar ya en la 2.2.0** | La gestión de claves del fabricante es una decisión con consecuencias de operación, como la rotación, la custodia y lo que verifica el instalador sin internet. Hacerla con prisa dentro del cierre de una versión es peor que comprometerla para la siguiente |

## Consecuencias

- **Corregir una versión publicada es publicar otra.** Una versión con un fallo de empaquetado no se «arregla» en sitio: sale la `X.Y.Z+1`.
- **Doc 02 §11.6.1** deja de prometer etiquetas inmutables a secas y remite a este ADR.
- **Excepción sin internet.** Un IT sin salida a internet que carga las imágenes con `docker load` sin su digest puede vaciar `IMAGE_DIGEST_*` en el `.env` para volver a la etiqueta (`infra/compose.prod.yaml`, `docs/cliente/instalacion.md` §7). Esa instalación pierde la garantía del punto 2 y vuelve a depender de que la etiqueta no se haya reescrito, cosa que el punto 1 sí garantiza. `install.sh`, `update.sh` y `doctor.sh` avisan cuando el `.env` declara alguna `IMAGE_DIGEST_*` (vacía o con un digest distinto del de `images.lock`), y el informe de la actualización lo anota. **Queda para la 2.2.x** la alternativa más fuerte: guardar en `images.lock` el ID de imagen y compararlo sin red contra lo que corre el servidor, que daría integridad también a la instalación con `docker load`.
- **Pendiente (Redis hecho en la Enmienda 2.2.1; quedan las siete de observabilidad): las imágenes de terceros siguen por etiqueta.** `redis:7-alpine` es una etiqueta que se mueve, y las del perfil de observabilidad (`prom/prometheus:v3.1.0` y demás) son etiquetas de versión que su fabricante podría reescribir. Fijarlas también por digest en el paquete corresponde a `devops-observabilidad`, y Redis es la prioritaria: viaja en el camino del fichaje.
- **Hecho en la 2.2.1 (Enmienda 2.2.1, abajo). Texto original: pendiente para la 2.2.x:** la firma con cosign de las tres imágenes y del paquete, con la verificación documentada para el cliente y hecha por `install.sh` y `update.sh` cuando haya red. Mientras no exista, `SHA256SUMS` solo protege contra la corrupción en tránsito, no contra la sustitución.
- **Doc 07** («Etiqueta movible: CERRADO») tiene que reflejar el estado real: cerrado en cuanto a reescritura y digest, abierto en cuanto a firma y a imágenes de terceros. Lo actualiza `seguridad-cumplimiento`.

## Verificación

- Arquitectura: `backend/tests/Architecture/ImmutableImagesTest.php` comprueba que la publicación falla si la versión ya existe en el registro, antes y justo antes de empujar, y que el paquete fija las tres imágenes por digest.
- `package.sh` falla si `KQ_IMAGES_LOCK` falta o está mal formado, o si el `docker-compose.yml` resultante no tiene exactamente tres referencias `@sha256:`.
- Etapas ⑧ y ⑧b de la CI (`ci.yml`): los escenarios existentes corren sobre paquetes armados en esa misma ejecución **sin** `KQ_IMAGES_LOCK` (referencias por etiqueta). El camino fijado por digest lo ejercita ⑧b con un `registry:2` local: P0 empuja las imágenes, escribe `images.lock` con los digests reales y arma dos paquetes con `package.sh` (uno sano y otro con el borde sustituido por alpine); P1 es la instalación limpia desde el paquete fijado, con las imágenes descargadas del registro por digest; P2 es la vuelta atrás *in place* (paquete roto superpuesto sobre el directorio de la instalación anterior: salida 4 y el contenedor corre la imagen anterior); P3 es la actualización *in place* con el paquete sano. ⑧b corre en `main`, en etiquetas y a mano, no en cada rama.
- Lo que la CI **no** verifica: la publicación real en GHCR (el `403` de la primera publicación, los permisos del `GITHUB_TOKEN`) ni la firma. Eso solo se ve en `release.yml`.
- `backend/tests/Integration/Install/UpdateScriptTest.php` comprueba que `update.sh` deja las tres `IMAGE_DIGEST_*` vacías en la copia del `.env` de la vuelta atrás *in place* y que, en el modo lado a lado, no toca el `.env` vivo.

## Enmienda 2.2.1 — firma con cosign y Redis por digest (bloque 18)

Cierra lo que el punto 3 («Firma con cosign: a la 2.2.x») y el «Pendiente» de Redis dejaban abierto. **No contradice la decisión original**: añade una garantía (*quién*) a la que ya existía (*qué*) y acota con honestidad qué ataque cubre cada pieza. (La CSP por ubicación del mismo bloque no es de este ADR: vive en la línea de la CSP de [ADR-052](ADR-052-sesiones-con-token-bearer-de-sanctum-sin-cookies.md).)

### Decisión de la gestión de claves: firma *keyless*, sin clave del fabricante

Las imágenes `php`, `nginx` y `postgres` y el fichero `SHA256SUMS` se firman con cosign **sin clave propia**: la identidad es la del workflow `release.yml` de este repositorio, que GitHub acredita con un token OIDC (`id-token: write`) y Sigstore (Fulcio) convierte en un certificado de minutos; la firma queda en el registro de transparencia (Rekor). No hay clave privada que custodiar, rotar ni perder (RS-08). Alternativa descartada: **clave propia del fabricante**. Daría verificación sin red hacia Sigstore, pero convierte la custodia y la rotación de una clave en una obligación operativa permanente (si se pierde, no se puede firmar; si se filtra, hay que revocar en N instalaciones), y el producto ya tiene una clave así —la de licencias— cuya custodia es suficiente carga.

### Qué protege cada pieza, y qué no

Esta tabla corrige una lectura demasiado generosa del primer borrador: **la verificación automática que hacen `install.sh` y `update.sh` no añade seguridad frente a un paquete sustituido**, porque quien sustituye el paquete sustituye también `lib/image-signature.sh` y la identidad que esperan.

| Amenaza | Qué la cubre | Qué NO |
| --- | --- | --- |
| Registro comprometido o `docker push` manual con otros bytes bajo el mismo nombre | El **digest** del paquete (ya en la decisión original) | — |
| Un `IMAGE_DIGEST_*` del `.env` distinto del de `images.lock`, o un paquete con digests de otras imágenes | La **firma de las imágenes**: aunque el digest cuadre con lo que hay en el registro, cosign comprueba que lo firmó el proceso de publicación | Con las `IMAGE_DIGEST_*` vacías (servidor sin internet) no hay digest ni firma que mirar |
| Trazabilidad pública de lo publicado | **Rekor**: cada firma queda en un registro de transparencia que el fabricante y cualquiera pueden auditar | — |
| **Paquete sustituido** (scripts, `docker-compose.yml`, identidad esperada, `images.lock`) | **Solo** la verificación de `SHA256SUMS` con su `SHA256SUMS.bundle` **antes de descomprimir**, con la identidad **tomada de la guía de instalación y no del paquete nuevo** (`instalacion.md` §7.1, `actualizacion-cliente.md` §2) | La verificación automática de los scripts, que viaja dentro del paquete. Es un paso del IT: sin él, esta amenaza queda cubierta solo por la descarga desde la dirección oficial |

El `update.sh` ya instalado **no** puede verificar el paquete nuevo antes de usarlo: la actualización lado a lado se ejecuta con el `update.sh` **del paquete nuevo**, y el directorio anterior no se vuelve a invocar. Por eso la defensa frente a la sustitución es el paso manual previo a descomprimir, documentado en las dos guías, y no un chequeo del instalador.

### Dónde se firma y cómo se comprueba

- **`release.yml`, job `firmar`**, aparte de `imagenes` para que «Re-run failed jobs» lo repita con los mismos digests sin tocar etiquetas ya publicadas e inmutables. Firma **por digest** y verifica a continuación con `kq_cosign_verify_ref`, la misma función que usan los instaladores: si la identidad esperada no casa con el certificado real, la publicación falla antes de entregar nada.
- **`release.yml`, job `firmar-sumas`**, posterior a `paquete`: `cosign sign-blob --bundle` sobre `SHA256SUMS`, autoverificado con `kq_cosign_verify_blob`; el bundle (`SHA256SUMS.bundle`) viaja en la release. `paquete` depende de `firmar` y `publicar` de `firmar-sumas`: no se entrega nada sin firmar.
- **Versión de cosign fijada** (`cosign-release: v3.1.3`) en los dos jobs. cosign 3 escribe el formato de bundle nuevo; el cliente exige **cosign 3.0.0 o posterior** (`KQ_COSIGN_MIN_VERSION`) y trata uno anterior como «no instalado», con su aviso, en lugar de dar un falso «firma inválida». No se ha podido confirmar sin red hacia un registro firmado que un cosign 2.x lea el bundle nuevo; la versión mínima es deliberadamente conservadora.
- **Identidad aceptada** (`infra/scripts/lib/image-signature.sh`): emisor `https://token.actions.githubusercontent.com` e identidad `https://github.com/BorjaPeonSaiz/KronoQR/.github/workflows/release.yml@refs/tags/v…` (o `refs/heads/main`, para un `workflow_dispatch` desde la rama principal). Un fork, otro workflow u otra rama no casan. Si el repositorio cambia de propietario o de nombre, esa constante y la guía cambian en la misma versión.
- **`install.sh` y `update.sh`** verifican con `cosign verify` las imágenes **del producto** (las del `IMAGE_REGISTRY`) fijadas por digest, **antes de descargar nada**. `install.sh` lo hace ya en la fase 1 (a partir de `images.lock`, de modo que `--check-only` lo detecta y no se generan secretos para una instalación que no va a ocurrir) y otra vez en la fase 4, sobre las referencias que Compose resuelve.
  - **Firma inválida** (no existe o no casa con la identidad): **código de salida 7** («garantía de seguridad rota, nada aplicado»). `update.sh` sale en el paso 1 sin tocar nada; `install.sh`, en la fase 1, sin haber escrito nada, y en la fase 4 tras ejecutar su vuelta atrás.
  - **No se pudo consultar** (un fallo de **red** sin respuesta de nadie: `dial tcp`, `no such host`, `i/o timeout`, conexión rechazada o reiniciada, red inalcanzable, `tls handshake`; contra el registro o contra Sigstore): **aviso y sigue**, no 7. **Cualquier respuesta HTTP del registro** (401, 403, 429, 5xx, `denied`, `unauthorized`, `toomanyrequests`) cuenta como firma inválida y sale con 7: un registro comprometido puede servir la imagen sustituida y negar solo la firma. Se verifican **todas** las imágenes y manda la peor: un fallo inválido en cualquiera sale con 7 aunque otra imagen no se pudiera consultar. Justificación: la verificación es opcional y un cosign ausente ya sigue adelante; hacer que una caída de Sigstore pare una instalación o una actualización convertiría una comprobación opcional en una dependencia dura de un servicio externo, sin ganar seguridad (un atacante de red que corta Sigstore también podría simplemente no tener cosign). Queda anotado: `KQ_SIG_RESULT=unverifiable`.
  - **Sin cosign, o anterior a 3.0.0**: aviso y sigue; instalarlo es opcional (`instalacion.md` §7.1).
  - Sin digest (etiqueta suelta, o el *opt-out* de un servidor sin internet) no hay firma que verificar. Redis y la observabilidad son de terceros y no las firma este proyecto.
- **Constancia.** `update.sh` escribe el resultado (`verified`, `no-cosign`, `unverifiable`, `skipped`, `not-pinned`) en su informe y en el asiento `system.updated` de `audit_log` (`image_signature`, vocabulario cerrado en `SystemEventPayload`). `install.sh` lo deja en `.kronoqr-signature-check` (0600, junto al `.env`; se deshace con la instalación).

### El atajo de la CI, y por qué es una opción de la línea de órdenes

Las etapas ⑧ y ⑧b construyen imágenes locales sin firmar. ⑧ usa etiquetas (sin digest: no verifica nada) y ⑧b instala desde un `registry:2` local con digests reales (P1, P2, P2b y P3), que es donde fallaría. Se resuelve con **`--skip-signature-check`**, opción de `install.sh` y `update.sh` que ⑧b teclea en esas invocaciones. Se descartó una variable en el `.env` (persistiría en silencio meses) y una de entorno (se pierde con `sudo` y no deja huella). La opción hay que escribirla en cada ejecución, queda en el historial del shell y en la lista de procesos, y el resultado `skipped` queda anotado en la propia instalación (informe y asiento de `update.sh`; `.kronoqr-signature-check` de `install.sh`). **No impide** que quien es *root* en el servidor se salte la verificación (podría desinstalar cosign); lo que cambia es que ya no puede hacerlo sin que la instalación conserve la constancia. La verificación real la ejercita `ImageSignatureTest` (con un `cosign` y un `docker` falsos: los caminos de salida 7 de `install.sh` y `update.sh`, el aviso por no poder consultar, la versión mínima y el *opt-out*) y los jobs `firmar` y `firmar-sumas` al publicar.

### Redis por digest

`compose.prod.yaml` fija `redis:7-alpine${REDIS_IMAGE_DIGEST-@sha256:…}` (índice multi-arquitectura, etiqueta conservada). La semántica es la de `IMAGE_DIGEST_*`: sin definir, fijada por digest; **definida y vacía** en el `.env`, vuelve a la etiqueta, que es el *opt-out* de un servidor sin internet que carga las imágenes con `docker load` (`instalacion.md` §7). `install.sh`, `update.sh` y `doctor.sh` avisan cuando el `.env` declara `REDIS_IMAGE_DIGEST` (`check_image_digest_overrides`). `ImmutableImagesTest` exige digest en toda imagen del fichero **salvo las siete del perfil `observability`**, que siguen por versión (lista cerrada en la propia prueba).

**Mantenimiento.** Dependabot (ecosistema `docker-compose`) probablemente **no entienda** esa línea por llevar interpolación. Por eso `compose.dev.yaml` fija `redis:7-alpine@sha256:…` con el **mismo digest literal**, que Dependabot sí lee, y `ImmutableImagesTest` comprueba que el digest por defecto de producción es idéntico al de desarrollo: la PR de Dependabot falla hasta que alguien copie el digest nuevo a `compose.prod.yaml` a mano (`docker buildx imagetools inspect redis:7-alpine`). Se ignoran sus actualizaciones mayores (Redis 8 cambia de licencia: una mayor se decide, no se propone sola).

### Riesgos aceptados y lo que sigue abierto

- **Quien tenga permiso de escritura puede firmar con la identidad esperada.** Cualquiera que pueda empujar una etiqueta `v*` puede crearla sobre un commit con un `release.yml` modificado, y el certificado diría `release.yml@refs/tags/v…`, que es lo que se acepta. **Riesgo aceptado**: hay un único colaborador con permiso de escritura, y proteger las etiquetas con un *ruleset* de GitHub es decisión del propietario del repositorio. Si el equipo crece, el *ruleset* de etiquetas y la revisión obligatoria de los cambios en `release.yml` pasan a ser obligatorios. Anotado también en el doc 07 §6.
- La verificación automática de los scripts viaja dentro del paquete (ver la tabla): la defensa frente a un paquete sustituido es el paso manual previo a descomprimir.
- Las siete imágenes de observabilidad siguen por versión, sin digest.
- La verificación necesita salida a internet hacia Sigstore en el equipo del cliente.
