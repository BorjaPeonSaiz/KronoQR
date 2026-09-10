# Runbook — la cola offline de un quiosco no se vacía

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Cola offline de un dispositivo
| > 50 elementos o > 2 h | Alta»*), definidas en
[`infra/observability/prometheus/rules/kiosk.yml`](../../infra/observability/prometheus/rules/kiosk.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `ColaOfflineAtascada` | `kiosk_offline_queue_size > 50`, `for: 5m` | Alta | IT del cliente | [§2](#2-diagnóstico) |
| `ColaOfflineSinVaciar` | la cola no ha bajado a 0 en las últimas 2 h, `for: 5m` | Alta | IT del cliente | [§2](#2-diagnóstico) |

**A las 06:30, quien la reciba hace esto:** mira `kiosk:health` para ver de
qué quiosco es y cuánto lleva creciendo; si el resto de la instalación
funciona, es casi siempre red o certificado en ese punto concreto — sigue
§3; si el servidor entero no responde, es la sonda del borde
(`SondaDelBordeFallida`), y el procedimiento es otro (§3, última fila).

---

## 1. Qué significa esto, y qué no significa

`kiosk_offline_queue_size{device}` es el número de fichajes que un quiosco
tiene **guardados en su tablet** esperando a que el servidor los confirme. Lo
publica el propio latido (`pending_queue_size`, cada 60 s) y **no es una cola
del servidor**: vive dentro de la aplicación de la tablet, en su
almacenamiento local, exactamente igual que cuando no hay red en absoluto
(regla dura 19). Que este número crezca no dice que el quiosco esté fallando
al fichar — al contrario, dice que **está haciendo justo lo que tiene que
hacer** cuando algo le impide hablar con el servidor: seguir aceptando
tarjetas, confirmar en pantalla y guardar.

**Lo que sí dice es que ese algo lleva un rato sin resolverse.** Dos lecturas
del mismo hecho, con umbrales distintos a propósito:

- `ColaOfflineAtascada` — hay **mucho** acumulado (más de 50 elementos): algo
  se ha cortado hace poco pero de forma intensa, o lleva ya un rato.
- `ColaOfflineSinVaciar` — la cola **no ha bajado a cero ni una sola vez en
  dos horas**, aunque el número absoluto no sea alarmante. Es la lectura
  correcta de un problema intermitente: la tablet sincroniza un poco, se
  vuelve a cortar, sincroniza otro poco… y nunca llega a vaciarse del todo.

**Lo que no significa: que se esté perdiendo ningún fichaje.** Cada entrada
de la cola conserva su hora real (`occurred_at`), no la hora en la que por
fin llegue al servidor (`recorded_at`, regla dura 9). Cuando la tablet
recupera la conexión, los envía en lote y el registro legal queda exactamente
igual que si hubieran llegado al instante — con la salvedad de que, si la
ventana fue larga, aparecerá una incidencia de sincronización en la bandeja,
que no es un fallo: es el sistema diciendo que hubo una ventana.

---

## 2. Diagnóstico

### 2.1 De qué quiosco es, y desde cuándo

```bash
docker compose -f infra/compose.prod.yaml exec -T app php artisan kiosk:health
```

La columna **cola pendiente** y el **veredicto** (`aviso` con cola
distinta de cero) dan el dispositivo. El panel muestra lo mismo desde
**Quioscos**, si está accesible.

### 2.2 ¿Es solo ese quiosco, o son varios?

Si **un solo** quiosco tiene cola y el resto de la instalación va bien, el
problema es local a ese punto: red del sitio, certificado que dejó de
aceptar, o el propio dispositivo. Ve a §3.

Si **varios quioscos a la vez** acumulan cola, sospecha del servidor antes
que de las tablets: revisa si `SondaDelBordeFallida` también está sonando
(§1 de este documento, última fila de la tabla de alertas) o si hay un
despliegue o una migración en curso —`docker compose exec app php artisan
migrate:status`—. Durante una actualización (`update.sh`), **es lo
esperado**: los quioscos encolan mientras dura la ventana de mantenimiento y
vacían solos al final ([`actualizacion-cliente.md`](actualizacion-cliente.md)
§3).

### 2.3 Confirmar que sigue creciendo, o que ya está bajando

```bash
docker compose -f infra/compose.prod.yaml exec -T app php artisan kiosk:health --json | jq '.devices[] | {name, pendingQueueSize, verdict}'
```

Repite la orden un par de minutos después. Si el número **baja**, la tablet
ya está sincronizando y no hace falta ninguna acción sobre ella — solo
esperar y, si procede, cerrar la causa de raíz para que no se repita.

---

## 3. Causas, por frecuencia, y qué hacer con cada una

| Causa | Cómo se confirma | Qué hacer |
| --- | --- | --- |
| **Corte de red en ese punto** | Sin tráfico de esa tablet en `docker compose logs nginx` | Revisa el wifi de esa zona. Cuando vuelva, la tablet sincroniza sola — no hay nada que forzar |
| **Token de dispositivo caducado o revocado** | La tablet muestra la pantalla de emparejamiento en vez de la de fichaje | **Antes de volver a emparejar, comprueba la cola con `kiosk:health` desde la consola**: si es distinta de cero, no desvincules todavía — ver la advertencia de §4 |
| **Borde caído** (`SondaDelBordeFallida`) | Varios quioscos con cola a la vez; `/api/v1/ready` no responde | No es un problema de la tablet. Revisa `docker compose ps`, PostgreSQL y Redis; el runbook de esa alerta es [`errores-en-el-panel.md`](errores-en-el-panel.md) |
| **Reloj de la tablet desincronizado** | Los fichajes llegan con `occurred_at` muy distinto de la hora real al sincronizar, aunque la cola sí baje | No bloquea nada (regla dura 19: un desfase de reloj tampoco frena el fichaje) ni es esta alerta en sí, pero conviene corregir la hora del sistema Android de esa tablet para que los partes de incidencia no confundan a quien los lea después |
| **Certificado TLS que la tablet dejó de aceptar** | Aviso de sitio no seguro en la propia tablet | [`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) |
| **La tablet lleva sin latido más de 10 min** | `QuioscoSinLatido` también está activa | Es la misma causa raíz vista desde dos ángulos: sigue [`quiosco-no-responde.md`](quiosco-no-responde.md) primero — si recupera el latido, la cola empieza a vaciarse sola |

---

## 4. Qué NO hacer

- **No desvincules el quiosco con la cola sin vaciar.** Al revocar el token,
  la tablet **descarta lo que tenía pendiente**: son fichajes de personas
  reales que ya habían pasado su tarjeta, y se pierden de verdad — no quedan
  en ningún sitio a la espera. Espera a que `kiosk:health` marque la cola a
  cero, o asume conscientemente la pérdida y avisa a RRHH de que habrá que
  reconstruir esas horas por corrección manual.
- **No borres los datos de la aplicación en la tablet** («borrar caché»,
  «borrar almacenamiento», restablecer de fábrica) antes de confirmar que la
  cola está a cero. Es la misma pérdida que desvincular, por otra vía.
- **No reinicies la tablet repetidamente esperando que «se arregle sola»**
  sin mirar antes si es un problema de red del lado del servidor: un
  reinicio no cambia nada si el borde está caído, y cada reinicio es una
  ventana más sin punto de fichaje.
- **No subas el umbral de la alerta (50 elementos o 2 h)** para que deje de
  sonar en un quiosco con tráfico alto. Si un punto concreto genera de forma
  habitual más de 50 fichajes en cola durante una ventana normal, es una
  señal de que ese enlace de red necesita revisión, no de que el umbral esté
  mal puesto.

---

## 5. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Un quiosco con cola, causa de red identificada y en vías de resolverse | IT del cliente | Dentro de la jornada |
| Varios quioscos con cola a la vez, o `SondaDelBordeFallida` activa | IT del cliente — revisar el servidor antes que las tablets | Inmediato |
| Cola que no baja tras resolver la causa aparente | Soporte del fabricante, con el paquete de diagnóstico | El mismo día |
| Se desvinculó un quiosco con cola pendiente por error | RRHH: hay que reconstruir las horas perdidas por corrección manual | El mismo día |

**Relacionados:** [`quiosco-no-responde.md`](quiosco-no-responde.md) ·
[`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) ·
[`renovacion-certificado-tls.md`](renovacion-certificado-tls.md) ·
[`actualizacion-cliente.md`](actualizacion-cliente.md) ·
[`errores-en-el-panel.md`](errores-en-el-panel.md).
