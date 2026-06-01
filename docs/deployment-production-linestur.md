# Despliegue a producción — Linestur SGTE

> **Última verificación contra Dokploy en vivo:** 2026-05-28
>
> Este documento es la fuente de verdad del despliegue de SGTE en el VPS del cliente
> (Linestur). Está pensado para que cualquier sesión nueva de Claude (o cualquier
> dev) pueda retomar sin contexto previo. Toda afirmación marcada como "estado
> actual" fue verificada contra la API de Dokploy o el repositorio en el momento
> de escribir esto.
>
> El documento hermano `docs/deployment.md` cubre la arquitectura genérica del
> Dockerfile, Octane+FrankenPHP, supervisor, backups, etc. **No duplicar
> contenido**: aquí sólo va lo que es específico de esta instalación.

## Tabla de contenidos

1. [Estado actual (snapshot)](#estado-actual-snapshot)
2. [Decisiones clave](#decisiones-clave)
3. [Inventario de recursos](#inventario-de-recursos)
4. [Configuración en Dokploy](#configuración-en-dokploy)
5. [CI/CD: workflow de deploy](#cicd-workflow-de-deploy)
6. [Secretos y archivo `secrets/sgte-prod.env`](#secretos-y-archivo-secretssgte-prodenv)
7. [Bugs del primer deploy (resueltos)](#bugs-del-primer-deploy-resueltos)
8. [Procedimiento de deploy de un cambio](#procedimiento-de-deploy-de-un-cambio)
9. [Runbooks](#runbooks)
   - [Validar backups](#runbook-validar-backups-end-to-end)
   - [Cuando llegue el dominio](#runbook-conectar-dominio-sgtelinesturcomco)
   - [Cortar `v1.0.1`](#runbook-cortar-v101)
   - [Configurar secrets de GitHub](#runbook-configurar-secrets-de-github)
   - [Rollback / hotfix](#runbook-rollback--hotfix)
10. [Tareas pendientes (priorizadas)](#tareas-pendientes-priorizadas)
11. [Seguridad: deuda y riesgos conocidos](#seguridad-deuda-y-riesgos-conocidos)
12. [Referencia rápida: API de Dokploy](#referencia-rápida-api-de-dokploy)

---

## Estado actual (snapshot)

| Ítem | Valor |
|---|---|
| URL de la app | `http://167.86.74.55` (puerto 80 vía Traefik) |
| Reverb WebSocket | `ws://167.86.74.55:8080` (puerto 8080, bind directo) |
| MinIO S3 | `http://167.86.74.55:9000` (buckets `sgte` + `sgte-backups`) |
| Panel Dokploy | `http://167.86.74.55:3000` (HTTP plano — deuda de seguridad) |
| Versión en `main` | commit `a0c7437` (`merge: 🚀 promote develop backlog to main (136 commits)`) |
| Tag desplegado | `v1.0.0` — **144 commits atrás** de `main` tras la promoción del 2026-05-28. El tag dejó de reflejar lo que corre en prod; ver pendiente "cortar `v1.0.1`". |
| Último deploy | 2026-05-28 00:49 UTC, status `done` (build ~9 min). Promovió todo el backlog de `develop` y aplicó 3 migraciones nuevas (incluida la conversión de `billing_groups` enum → catálogo + pivot). Disparado manualmente vía `application.deploy` con `DOKPLOY_TOKEN` local, porque los GitHub secrets `DOKPLOY_PROD_*` todavía no están configurados. |
| `applicationStatus` | `done` |
| `autoDeploy` | `false` en ambos (app + compose) |

> El entorno Dokploy se llama **"production"** y el contenedor corre con
> `APP_ENV=staging` heredado del template, pero **esto sí es producción real
> para el cliente**. La etiqueta `staging` se mantiene únicamente porque
> `compose.staging.yaml` y los archivos asociados se reusan tal cual; no
> renombrar a menos que se actualicen también `BACKUP_NOTIFICATION_*` y los
> gates de `routes/console.php` (que disparan backups si `APP_ENV in
> [production, staging]`).

---

## Decisiones clave

| Decisión | Por qué | Trade-off |
|---|---|---|
| Dokploy separado del staging interno | El staging interno (`dokploy.codebranch.app`) ya tenía la app del equipo. El cliente necesita su propio panel para tener control y para que sus credenciales no se mezclen con otras. | Doble configuración. Cualquier mejora hay que aplicarla en ambos lados. |
| Branch `main` como fuente de prod | Git Flow: `develop` integra día a día, `main` lleva sólo releases. | Hay que hacer merge `develop → main` con tag para liberar. |
| `autoDeploy: false` en Dokploy + workflow disparado por tags | Audit trail: cada release genera un tag de git, un commit en `main` y una corrida del workflow. No hay forma de que un push accidental llegue al cliente. | Más fricción para hotfixes (hay que crear tag). |
| Compose reusa `compose.staging.yaml` | Ya tenía la red `dokploy-network` declarada y las dependencias correctas. Crear un archivo separado duplicaba código sin valor. | El nombre del archivo es engañoso ("staging") pero sirve para ambos. |
| Reverb sobre puerto 8080 con `publishMode: host` | Traefik no enruta WebSockets a puertos no-HTTP de forma directa. Mientras no hay dominio + TLS, exponer 8080 con bind directo es lo más simple. | Cuando llegue el dominio hay que cambiar a `wss://` por 443 vía Traefik (ver runbook). |
| Backups: spatie/laravel-backup → MinIO local + Cloudflare R2 | Doble destino: rápido restore mientras vive el VPS + supervivencia si el VPS muere. R2 free tier alcanza con la política de retención + cap de 8 GB. | Sin un test de restore real periódico, no hay garantía. **Pendiente**. |
| Variables `VITE_*` como build args (no env del contenedor) | Vite las inlinea en el bundle al hacer `npm run build:ssr`. Cambiarlas requiere re-build completo, no sólo restart. | Cada vez que cambia una `VITE_*` hay que disparar `application.deploy` (full build), no `application.redeploy`. |
| Secretos en `secrets/sgte-prod.env` (gitignored) | Permite re-aplicar la config con `envsubst` sin meter secretos al repo. | Si se pierde el laptop sin que esté en password manager, hay que regenerar todo. |

---

## Inventario de recursos

### VPS (Contabo)

- **IP:** `167.86.74.55`
- **SSH:** el usuario tiene acceso (no se documenta el usuario aquí; preguntar)
- **UFW recomendado** (el usuario indicó que lo aplicaría): permitir `22, 80, 8080, 9000, 3000`; bloquear el resto. Los puertos de infra (`5432, 6379, 8108, 8025`) se publican al host pero quedan filtrados por UFW.

### Dokploy del cliente

- **Panel:** `http://167.86.74.55:3000` — HTTP plano, sin TLS
- **Organización:** "Linestur" (`0Kf_ZmfFzU34JlnGKTJRb`)
- **Proyecto:** "Linestur SGTE" (`pNF9HDNPn_aFmMHy1DFcb`)
- **Environment:** "production" (`fKlGo7gXfcomXifLUpCod`)
- **API:** `http://167.86.74.55:3000/api/*` — autenticación con header `x-api-key: <token>` (NO Bearer; el staging viejo sí usa Bearer, este no)

Servicios dentro del proyecto:

| Servicio | Tipo | ID | Source |
|---|---|---|---|
| `sgte-app` | Application | `4XoeFL7NbK2M7TVDzRAIX` | GitHub `cristian-home/sgte-app`, branch `main`, Dockerfile `docker/production/Dockerfile` |
| `sgte-services` | Compose | `f35acrb1i4DNrsr2J3qlz` | GitHub `cristian-home/sgte-app`, branch `main`, path `./compose.staging.yaml` |

Container name (generado por Dokploy): `linestur-sgte-app-n4plse`.

### GitHub

- **Repo:** `cristian-home/sgte-app`
- **GitHub App instalado en Dokploy:** `dokploy-linestur` (instalado durante esta sesión)
- **Branches relevantes:** `develop` (integración), `main` (releases, deploy a prod)
- **Tag actual:** `v1.0.0`
- **Secrets de GitHub Actions necesarios** (no creados todavía — ver pendientes):
  - `DOKPLOY_PROD_URL` — `http://167.86.74.55:3000`
  - `DOKPLOY_PROD_TOKEN` — el `x-api-key` del Dokploy del cliente
  - `DOKPLOY_PROD_APP_ID` — `4XoeFL7NbK2M7TVDzRAIX`

> Los secrets se llaman con prefijo `_PROD_` para no chocar con `DOKPLOY_URL`/`DOKPLOY_TOKEN`/`DOKPLOY_APP_ID` que ya usa el workflow `deploy-staging.yml`.

### Cloudflare R2

- **Bucket de backups producción** (privado, no listable). Credenciales (`BACKUP_S3_*`) en `secrets/sgte-prod.env`.
- **Política de retención:** definida por `DefaultStrategy` de spatie/laravel-backup + cap de 8 GB para no salirse del free tier.

### MinIO en el VPS

- **Bucket `sgte`** — `mc anonymous set download` aplicado. Sirve los attachments (laravel-medialibrary) por HTTP.
- **Bucket `sgte-backups`** — privado. Destino local de los backups encriptados.
- **Credenciales:** las del root de MinIO (`MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD`) viven en el env del compose en Dokploy.

### Email (SMTP saliente)

- **Mailer:** Gmail SMTP (`smtp.gmail.com:587`, STARTTLS)
- **Cuenta:** `linestur.conta@gmail.com` (usa App Password, no la contraseña real)
- **From + Notification mail:** ambos = `linestur.conta@gmail.com`

---

## Configuración en Dokploy

### Application `sgte-app`

| Campo | Valor |
|---|---|
| Build type | `dockerfile` |
| Dockerfile | `docker/production/Dockerfile` |
| Docker context | `.` |
| Docker build stage | (vacío — usa la última stage `production`) |
| Branch | `main` |
| Auto deploy | OFF |
| Status | `done` |

**Ports** (verificado vía `application.one`):

| publishedPort | targetPort | publishMode | Para qué |
|---|---|---|---|
| 80 | 8000 | `ingress` | Traefik enruta tráfico HTTP del host al Octane interno (8000). **No borrar este mapping** — eliminarlo droppea la regla de Traefik y la app deja de responder. |
| 8080 | 8080 | `host` | Reverb WebSocket. `host` mode hace bind directo al puerto del host porque Traefik (en su config por defecto) no enruta WS a puertos no-HTTP. |

**Build args** (verificado — 8 keys; se inyectan como `docker build --build-arg`):

```
VITE_APP_DEBUG
VITE_APP_NAME
VITE_GOOGLE_MAPS_BROWSER_KEY
VITE_GOOGLE_MAPS_MAP_ID
VITE_REVERB_APP_KEY
VITE_REVERB_HOST
VITE_REVERB_PORT
VITE_REVERB_SCHEME
```

Estos están duplicados en el `env` también (necesarios en runtime para que el bundle pueda leerlos en SSR / Echo). Cuando cambia cualquiera de estos, hay que actualizar **los dos campos** y disparar `application.deploy` (full rebuild) — no basta con `redeploy`.

**Env** (verificado — 86 keys totales). Las claves sensibles viven aquí y se gestionan localmente desde `secrets/sgte-prod.env`. Lista de claves (sin valores):

```
APP_DEBUG, APP_ENV, APP_FAKER_LOCALE, APP_FALLBACK_LOCALE, APP_KEY,
APP_LOCALE, APP_NAME, APP_TAGLINE, APP_URL,
AWS_ACCESS_KEY_ID, AWS_BUCKET, AWS_DEFAULT_REGION, AWS_ENDPOINT,
AWS_SECRET_ACCESS_KEY, AWS_URL, AWS_USE_PATH_STYLE_ENDPOINT,
BACKUP_ARCHIVE_PASSWORD, BACKUP_LOCAL_BUCKET, BACKUP_NOTIFICATION_MAIL,
BACKUP_S3_ACCESS_KEY_ID, BACKUP_S3_BUCKET, BACKUP_S3_ENDPOINT,
BACKUP_S3_REGION, BACKUP_S3_SECRET_ACCESS_KEY,
BCRYPT_ROUNDS, BROADCAST_CONNECTION, CACHE_STORE,
DB_CONNECTION, DB_DATABASE, DB_HOST, DB_PASSWORD, DB_PORT, DB_USERNAME,
FILESYSTEM_DISK,
GOOGLE_MAPS_BROWSER_KEY, GOOGLE_MAPS_MAP_ID, GOOGLE_MAPS_SERVER_KEY,
LOG_CHANNEL, LOG_DEPRECATIONS_CHANNEL, LOG_LEVEL, LOG_STACK,
MAIL_ENCRYPTION, MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_HOST,
MAIL_MAILER, MAIL_PASSWORD, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME,
MEDIA_DISK, OPERATION_TZ, QUEUE_CONNECTION,
REDIS_CLIENT, REDIS_HOST, REDIS_PASSWORD, REDIS_PORT,
REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, REVERB_HOST,
REVERB_PORT, REVERB_SCHEME, REVERB_SERVER_HOST, REVERB_SERVER_PORT,
SCOUT_DRIVER, SCOUT_QUEUE,
SESSION_DRIVER, SESSION_LIFETIME, SESSION_SECURE_COOKIE,
SGTE_FUEC_ENABLED, SGTE_GPS_ENABLED,
SUPER_ADMIN_PASSWORD, SUPER_ADMIN_USER,
TYPESENSE_API_KEY, TYPESENSE_HOST, TYPESENSE_PORT, TYPESENSE_PROTOCOL,
VITE_APP_DEBUG, VITE_APP_NAME, VITE_GOOGLE_MAPS_BROWSER_KEY,
VITE_GOOGLE_MAPS_MAP_ID, VITE_REVERB_APP_KEY, VITE_REVERB_HOST,
VITE_REVERB_PORT, VITE_REVERB_SCHEME
```

Valores actuales sin TLS (resumen — para los valores exactos consultar `secrets/sgte-prod.env`):

- `APP_URL=http://167.86.74.55`
- `APP_ENV=staging` (sí, ver caveat arriba)
- `APP_DEBUG=false`
- `SESSION_SECURE_COOKIE=false`
- `DB_HOST=linestur-sgte-services-pnst0e-pgsql-1` (hostname del contenedor en `dokploy-network`)
- `REDIS_HOST=linestur-sgte-services-pnst0e-redis-1`
- `TYPESENSE_HOST=linestur-sgte-services-pnst0e-typesense-1`
- `AWS_ENDPOINT=http://linestur-sgte-services-pnst0e-minio-1:9000` (interno)
- `AWS_URL=http://167.86.74.55:9000/sgte` (externo, lo que ven los browsers)
- `REVERB_HOST=167.86.74.55`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`
- `OPERATION_TZ=America/Bogota`

### Compose `sgte-services`

| Campo | Valor |
|---|---|
| Source type | `github` |
| Branch | `main` |
| Compose path | `./compose.staging.yaml` |
| Auto deploy | OFF |

El archivo `compose.staging.yaml` define los servicios `pgsql`, `redis`, `typesense`, `minio`, `mailpit`. El servicio `app` está bajo `profiles: [local]` y por defecto **no** se levanta — sólo aparece cuando uno hace `--profile local` en local; Dokploy levanta sólo los servicios base. La red `dokploy-network` está declarada como `external: true` y conecta todos los contenedores con la red del proxy de Dokploy.

> En este Dokploy del cliente NO usamos Mailpit en runtime (el SMTP saliente es Gmail real), pero el contenedor se levanta igual porque es parte del compose. No tiene efecto.

---

## CI/CD: workflow de deploy

Archivo: `.github/workflows/deploy-production.yml`

```yaml
on:
  push:
    tags: ['v*']
  workflow_dispatch:
    inputs:
      reason:
        description: 'Motivo del deploy (ej. re-deploy tras cambio de env, hotfix manual)'
        required: false
        default: 'manual'
```

**Lo que hace:** una sola llamada `curl` al endpoint `application.redeploy` del Dokploy del cliente. **NO ejecuta tests** (los tests viven en `tests.yml` y corren por separado en cada push). El job tarda ~5 segundos; el build real en Dokploy tarda ~10 min.

**Cuándo dispara:**

1. **Push de tag `v*`** → cada release nuevo (ej. `v1.0.1`, `v1.1.0`) deploya automáticamente.
2. **Manual** (`workflow_dispatch`) → para re-deploy tras cambiar un env var en Dokploy, sin necesidad de tag nuevo.

**redeploy vs deploy:**

- `application.redeploy` → reinicia el contenedor reusando la imagen actual. Toma ~20 segundos. Sirve para cambios de env vars (que sólo entran en runtime).
- `application.deploy` → build completo desde el código. Toma ~10 min. Necesario cuando cambia el código, el Dockerfile o cualquier `buildArg` (incluyendo todos los `VITE_*`).

El workflow usa `redeploy` por defecto. Para forzar un build completo desde GitHub no hay botón — hay que pulsar "Deploy" en el panel de Dokploy o llamar la API directamente. **Esto es intencional**: un tag nuevo siempre cambia el código, así que el redeploy posterior al merge en `main` no es suficiente; en el flujo de release hay que **pulsar "Deploy" en Dokploy una vez** después del tag (ver runbook).

> Alternativa: cambiar el workflow para llamar `application.deploy` en lugar de `application.redeploy`. Hoy no se hizo porque el primer release se cocinó a mano y el endpoint manual resolvió el caso.

---

## Secretos y archivo `secrets/sgte-prod.env`

`secrets/` está en `.gitignore` (entrada `/secrets`). El archivo `secrets/sgte-prod.env` vive **sólo en el laptop del usuario** y es la fuente desde donde se reaplica la configuración de Dokploy cuando algo cambia.

**Estructura del flujo:**

1. Editar `secrets/sgte-prod.env`.
2. `source secrets/sgte-prod.env && envsubst < secrets/sgte-prod.env > /tmp/sgte-prod-expanded.env`
   - Por qué `envsubst`: Dokploy **no** sustituye referencias `${VAR}` dentro del blob de env, las pasa literales al contenedor. phpdotenv (en runtime, dentro del contenedor) sí sustituye pero Octane las cachea con `config:cache`, así que para `VITE_*` ya es tarde — Vite las leyó durante el build con el valor literal `"${REVERB_APP_KEY}"`. Hay que pre-expandir.
3. Construir el payload JSON con `jq -n --arg env "$(cat /tmp/sgte-prod-expanded.env)" --arg id "$DOKPLOY_APP_ID" '{applicationId: $id, env: $env}'`.
4. POST a `application.update`.
5. POST a `application.deploy` o `application.redeploy` según corresponda.

**Secretos que también deben vivir off-VPS (password manager):**

- `APP_KEY` — sin este los datos encriptados en el DB son ilegibles.
- `BACKUP_ARCHIVE_PASSWORD` — sin este los backups encriptados en R2 son inútiles tras una catástrofe del VPS.

**Estado actual:** no confirmado que estén en el password manager. **Pendiente.**

---

## Bugs del primer deploy (resueltos)

Documentado en detalle en la memoria `project_dokploy_deploy_gotchas`. Resumen para retomar:

| # | Bug | Fix permanente |
|---|---|---|
| 1 | Dokploy no sustituye `${VAR}` en el blob de env | Pre-expandir con `envsubst` antes de POST a `application.update` |
| 2 | Variables `VITE_*` quedaban vacías en el bundle JS porque el Dockerfile no las declaraba como `ARG` antes del `npm run build:ssr` | Commit `30ec273` en `main`: agregó `ARG`+`ENV` para las 8 `VITE_*`. **También** hay que poblar el campo `buildArgs` en Dokploy (separado del `env`). |
| 3 | `publishMode: ingress` no enruta WebSockets a puertos no-80/443 | `port.update` a `publishMode: host` para el puerto 8080 de Reverb |
| 4 | Buckets de MinIO no se crean solos | `docker run --rm -e MC_HOST_x=... minio/mc mb x/sgte x/sgte-backups`, luego `mc anonymous set download x/sgte` |

Plus dos errores propios que vale la pena recordar:

- **No borrar el port mapping `80:8000 ingress`** pensando que es redundante. Esa entrada es la que dispara la regla de Traefik. Si se borra, la app queda 404 hasta recrearla.
- **Validar nombres reales de env vars** antes de copiarlos a `secrets/sgte-prod.env`. El primer borrador metió `ASSET_URL`, `APP_TIMEZONE` y `SGTE_OPERATION_TZ` que no existen en `config/*` ni en `.env.example`. Los correctos son `APP_URL` (Laravel deriva asset URLs), no hay `APP_TIMEZONE`, y la variable es `OPERATION_TZ`.

---

## Procedimiento de deploy de un cambio

### Caso 1: cambio de código (sin nuevo release)

> **Política actual:** `autoDeploy: false`. Mientras esté así, los cambios en `main` **no salen** a producción hasta que alguien dispare un deploy.

1. Trabajar en `develop` con Git Flow normal.
2. Cuando esté listo:
   - **Opción A (deploy directo sin tag):** `git checkout main && git merge --no-ff develop && git push` → ir a Dokploy panel y pulsar "Deploy" en `sgte-app` (build completo).
   - **Opción B (release con tag, recomendado):** ver runbook "cortar `v1.0.1`" abajo.

### Caso 2: cambio sólo de env vars

1. Editar `secrets/sgte-prod.env`.
2. Re-aplicar:
   ```bash
   source .env  # carga DOKPLOY_URL, DOKPLOY_TOKEN, DOKPLOY_APP_ID
   source secrets/sgte-prod.env
   envsubst < secrets/sgte-prod.env > /tmp/sgte-prod-expanded.env
   curl -sSf -X POST "$DOKPLOY_URL/api/application.update" \
     -H "x-api-key: $DOKPLOY_TOKEN" -H "Content-Type: application/json" \
     -d "$(jq -n --arg id "$DOKPLOY_APP_ID" --arg env "$(cat /tmp/sgte-prod-expanded.env)" \
       '{applicationId: $id, env: $env}')"
   ```
3. Trigger deploy:
   - Si **ningún** `VITE_*` cambió → `application.redeploy` (rápido, ~20s).
   - Si cualquier `VITE_*` cambió → **también** actualizar `buildArgs` (mismo subset) y `application.deploy` (full build, ~10 min).

### Caso 3: cambio del Dockerfile o de `compose.staging.yaml`

- Dockerfile → cambio de código, va por el caso 1.
- `compose.staging.yaml` → es código de la compose. Después del merge a `main`, en el panel de Dokploy ir al servicio `sgte-services` y pulsar "Deploy".

---

## Runbooks

### Runbook: validar backups end-to-end

**Estado:** no validado todavía. Es el primer pendiente crítico.

```bash
# 1) SSH al VPS
ssh <user>@167.86.74.55

# 2) Encontrar el contenedor de la app
docker ps --format '{{.Names}}' | grep linestur-sgte-app

# 3) Disparar un backup manual (saltea los gates de APP_ENV)
docker exec -it linestur-sgte-app-n4plse php artisan backup:run --only-db

# 4) Verificar destino local (MinIO)
docker run --rm \
  -e MC_HOST_x="http://<MINIO_USER>:<MINIO_PASS>@167.86.74.55:9000" \
  minio/mc ls x/sgte-backups

# 5) Verificar destino off-site (R2)
docker run --rm \
  -e MC_HOST_r2="https://<R2_KEY>:<R2_SECRET>@<R2_ACCOUNT>.r2.cloudflarestorage.com" \
  minio/mc ls r2/<bucket>

# 6) Descargar un archivo, extraerlo localmente y validar el dump
docker run --rm -v /tmp:/tmp -e MC_HOST_r2="..." minio/mc cp r2/<bucket>/<archive>.zip /tmp/
7z x -p"$BACKUP_ARCHIVE_PASSWORD" /tmp/<archive>.zip -o/tmp/restore
file /tmp/restore/db-dumps/*.backup   # debe decir "PostgreSQL custom database dump"
```

Si todo lo anterior pasa, el flujo está confirmado y el scheduler (que ya corre dentro del contenedor a las 01:30 UTC) seguirá produciendo backups todos los días.

### Runbook: conectar dominio `sgte.linestur.com.co`

Cuando el cliente provea el DNS (estimado ~1 semana desde 2026-05-25):

1. **Crear registros DNS** apuntando al VPS:
   - `sgte.linestur.com.co  A  167.86.74.55`
   - `ws.sgte.linestur.com.co  A  167.86.74.55`
2. **En Dokploy panel → `sgte-app` → Domains:**
   - Agregar `sgte.linestur.com.co` → container port `8000`, TLS Let's Encrypt ON.
   - Agregar `ws.sgte.linestur.com.co` → container port `8080`, TLS Let's Encrypt ON.
3. **Cambiar el port mapping del WebSocket** de `host` a `ingress` (ahora sí Traefik puede manejarlo bajo TLS):
   ```bash
   # Listar puertos, encontrar el id del 8080
   curl -sSf -H "x-api-key: $DOKPLOY_TOKEN" \
     "$DOKPLOY_URL/api/application.one?applicationId=$DOKPLOY_APP_ID" \
     | jq '.ports[]'
   # port.update con publishMode: ingress
   ```
4. **Actualizar 7 variables en `secrets/sgte-prod.env` y re-aplicar (Caso 2 + buildArgs + full deploy):**
   ```
   APP_URL=https://sgte.linestur.com.co
   AWS_URL=https://sgte.linestur.com.co/storage   # o un subdominio dedicado para MinIO
   REVERB_HOST=ws.sgte.linestur.com.co
   REVERB_PORT=443
   REVERB_SCHEME=https
   SESSION_SECURE_COOKIE=true
   VITE_REVERB_HOST=ws.sgte.linestur.com.co
   VITE_REVERB_PORT=443
   VITE_REVERB_SCHEME=https
   ```
   - Nota: como tres son `VITE_*`, **es full build** (`application.deploy`, ~10 min).
5. **Verificar:**
   - `curl -sI https://sgte.linestur.com.co/up` → 200 + cert válido
   - Login en `https://sgte.linestur.com.co/login` con admin@sgte.app + check de cookie `Secure`
   - Abrir Horizon/Mapas/cualquier vista que use WS y confirmar `wss://` conectado (DevTools → Network → WS)

### Runbook: cortar `v1.0.1`

Razón (actualizada 2026-05-28): `v1.0.0` apunta a `c6881f0`, pero `main` ya está **144 commits adelante** tras la promoción del backlog de `develop` (commit `a0c7437`). Eso incluye 3 migraciones, la conversión de `billing_groups` a catálogo, refactors masivos del Gantt y muchos features de facturación. El tag dejó de ser representativo y hay que cortar uno nuevo (probablemente `v1.1.0`, no `v1.0.1`, dado el scope). Para que el tag refleje lo realmente desplegado:

```bash
git checkout main
git pull
git tag -a v1.1.0 -m "release: promote develop backlog (Gantt overhaul, billing groups catalog, invoices revamp)"
git push origin v1.1.0
```

El push del tag dispara el workflow `deploy-production.yml` → `application.redeploy` (cuando los secrets `DOKPLOY_PROD_*` estén configurados; ver pendiente #1). **Si querés que el contenido del tag rebuildee** (no sólo restart), hay que pulsar manualmente "Deploy" en Dokploy después, o ajustar el workflow para llamar `application.deploy`. Para el caso de `v1.1.0` el restart basta — el binario que corre ya contiene ese commit.

> Antes de cortar el tag, configurar primero los secrets de GitHub (runbook siguiente), si no el workflow falla con 401.

### Runbook: configurar secrets de GitHub

```
Repo: cristian-home/sgte-app → Settings → Secrets and variables → Actions → New repository secret

DOKPLOY_PROD_URL    = http://167.86.74.55:3000
DOKPLOY_PROD_TOKEN  = <x-api-key del Dokploy del cliente, copiar de .env local>
DOKPLOY_PROD_APP_ID = 4XoeFL7NbK2M7TVDzRAIX
```

Verificación: ir a Actions → `deploy-production` → "Run workflow" → con motivo "test". Tiene que salir verde y aparecer una entrada nueva en `application.one → deployments[]` con el título "Rebuild deployment".

### Runbook: rollback / hotfix

**Rollback dentro de Dokploy:**

1. Panel → `sgte-app` → tab "Deployments"
2. Encontrar la deploy anterior con status `done`
3. Pulsar el botón de rollback (re-arranca el contenedor con esa imagen)

**Hotfix con código nuevo:**

1. Branch `hotfix/<descripcion>` saliendo de `main` (NO de develop — Git Flow estricto para hotfixes).
2. Fix + test + merge `--no-ff` a `main` Y a `develop`.
3. Cortar tag `v1.0.X+1` y push. El workflow dispara `redeploy` automático, luego pulsar "Deploy" en Dokploy para que rebuildee.

> Si la urgencia no permite tag, alternativa rápida: merge a main → en Dokploy pulsar "Deploy". Pero queda fuera del audit trail de tags y hay que cortar tag retro después.

---

## Tareas pendientes (priorizadas)

| # | Tarea | Por qué urge | Esfuerzo |
|---|---|---|---|
| 1 | Configurar los 3 secrets de GitHub (runbook arriba) | Sin esto el workflow falla y el flujo de deploy automático no existe | 5 min |
| 2 | Cortar `v1.1.0` para alinear tag con lo desplegado | Tras el deploy del 2026-05-28 el tag `v1.0.0` quedó 144 commits atrás. El binario en prod ya no corresponde a ningún tag | 10 min |
| 3 | Validar backups end-to-end (runbook arriba) | Backup no validado = no hay backup | 30 min |
| 4 | Guardar `APP_KEY` y `BACKUP_ARCHIVE_PASSWORD` en password manager off-VPS | Si se pierde el VPS sin estos secretos, los backups encriptados no se pueden restaurar | 10 min |
| 5 | Rotar GitHub App `dokploy-linestur` (private key + webhook secret) | Quedaron expuestos en plaintext vía `gitProvider.getAll` durante esta sesión | 20 min |
| 6 | Cuando llegue DNS: ejecutar runbook "conectar dominio" | Hoy todo es HTTP y cookies `Secure` están off → no es seguro para producción real con usuarios externos | 1 h (incluye full deploy) |
| 7 | TLS al panel Dokploy (`:3000`) | Hoy las credenciales del panel viajan en HTTP plano | depende de cómo se monte (Caddy / Traefik del propio Dokploy) |
| 8 | Restore test periódico (cada trimestre) en DB scratch | Un backup que nunca se restaura no es un backup | 1 h por test |

---

## Seguridad: deuda y riesgos conocidos

| Riesgo | Estado | Mitigación |
|---|---|---|
| Panel Dokploy en HTTP plano | Pendiente | Poner TLS (tarea #7) |
| App en HTTP, cookies sin `Secure` | Pendiente (espera DNS) | Tarea #6 cuando llegue el dominio |
| `gitProvider.getAll` devuelve secrets de GitHub App en plaintext | Conocido | Rotar tras cualquier sesión que la haya consultado (tarea #5) |
| `application.one` y `compose.one` devuelven env (con secrets) en plaintext | Conocido | Tratar el `DOKPLOY_TOKEN` como acceso root al servidor. No compartir, rotar si se sospecha. |
| `APP_KEY` y `BACKUP_ARCHIVE_PASSWORD` sólo en VPS + laptop del usuario | Pendiente | Tarea #4 |
| MinIO bucket `sgte` con `anonymous download` | Aceptado | Por diseño — sirve attachments de la app. Sólo objetos que ya tengan URL conocida (no listable). Si se considera muy expuesto, mover detrás de un signed-URL flow vía Laravel. |
| No hay alerting fuera del `backup:monitor` por email | Conocido | A futuro: monitor externo (UptimeRobot / similar) para `/up` y para la latencia de R2 |
| `APP_ENV=staging` en producción real | Aceptado | Necesario para que los gates de `routes/console.php` disparen los backups. Renombrar requeriría tocar también esos gates. |

---

## Referencia rápida: API de Dokploy

Autenticación: `x-api-key: $DOKPLOY_TOKEN` (NO Bearer).

```bash
source /Users/cristian/Projects/sgte-app/.env   # carga DOKPLOY_URL, DOKPLOY_TOKEN, DOKPLOY_APP_ID, DOKPLOY_PROJECT_ID

# Estado del proyecto completo
curl -sSf -H "x-api-key: $DOKPLOY_TOKEN" \
  "$DOKPLOY_URL/api/project.one?projectId=$DOKPLOY_PROJECT_ID" | jq

# Estado de la app
curl -sSf -H "x-api-key: $DOKPLOY_TOKEN" \
  "$DOKPLOY_URL/api/application.one?applicationId=$DOKPLOY_APP_ID" | jq

# Disparar redeploy (restart, sin rebuild)
curl -sSf -X POST -H "x-api-key: $DOKPLOY_TOKEN" -H "Content-Type: application/json" \
  -d "{\"applicationId\":\"$DOKPLOY_APP_ID\"}" \
  "$DOKPLOY_URL/api/application.redeploy"

# Disparar full deploy (rebuild)
curl -sSf -X POST -H "x-api-key: $DOKPLOY_TOKEN" -H "Content-Type: application/json" \
  -d "{\"applicationId\":\"$DOKPLOY_APP_ID\"}" \
  "$DOKPLOY_URL/api/application.deploy"
```

Endpoints detallados (cuerpo de payloads, polling de build, advertencias de seguridad): ver `/Users/cristian/.claude/projects/-Users-cristian-Projects-sgte-app/memory/project_dokploy_api.md`.

---

## Apéndice: comandos `gh` útiles

```bash
# Switch a la cuenta correcta (la que tiene scope para crear releases)
gh auth switch -u cristian-home

# Ver últimas corridas del workflow de deploy
gh run list --workflow=deploy-production.yml --limit 10

# Ver detalle de la última corrida
gh run view --log

# Crear release manual (alternativa a git push origin <tag>)
gh release create v1.0.1 --generate-notes
```

---

## Historial de cambios de este documento

- **2026-05-25** — Versión inicial, verificada contra el Dokploy del cliente (`http://167.86.74.55:3000`). Estado: `v1.0.0` desplegada, sin dominio, pendientes 8 tareas.
- **2026-05-28** — Deploy manual del backlog acumulado (`develop` → `main`, 136 commits, commit `a0c7437`). Build de ~9 min, status `done`, sin errores. Disparado vía `application.deploy` con `DOKPLOY_TOKEN` local (no por workflow — los GitHub secrets `DOKPLOY_PROD_*` siguen pendientes). Aplicó 3 migraciones incluida la conversión `billing_groups` enum → catálogo. Tag `v1.0.0` quedó 144 commits atrás de prod; el siguiente release debería ser `v1.1.0` por el scope.
