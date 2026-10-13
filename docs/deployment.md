# AgroConecta — diseño de entorno y despliegue

Estado al **19 de septiembre de 2026: Docker de producción preparado y ensayado localmente; fase 20 parcial**. Todavía no hay servidor ni dominio. El procedimiento actual está en [Docker de producción](#docker-de-produccion-preparado-fase-20); las primeras secciones conservan el diseño histórico.

## Estado de implementación local

Se han creado `docker-compose.yml`, `docker/php/Dockerfile` y `docker/nginx/default.conf`. El archivo Compose pasa `docker compose config --quiet`, incluidos los perfiles `workers` y `mail`. PHP 8.4, Composer 2, PostgreSQL 18, Redis 8, Nginx y Mailpit tienen imágenes fijadas por digests consultados directamente en sus registros el 8 de septiembre de 2026. PhpRedis está fijado a 6.3.0, publicado como estable en [PECL](https://pecl.php.net/package/redis).

La configuración es de desarrollo: código montado desde `application`, PHP como `www-data`, red de datos interna y PostgreSQL/Redis sin puertos publicados. El directorio de construcción Docker se limita a `docker/php`; no envía `.env` ni el código de la aplicación al build. Nginx publica solo `127.0.0.1:8080` por defecto. Mailpit usa el perfil opcional `mail` y publica solo su interfaz en `127.0.0.1:8025`; SMTP permanece interno.

La raíz `.env` suministra las variables de Compose. `application/.env` será la configuración de Laravel y usará los hosts `postgres` y `redis`. Redis requiere una contraseña aleatoria con letras, dígitos, guiones o guiones bajos; el generador local utiliza 64 caracteres hexadecimales. No se incluyen valores reales en este documento.

PostgreSQL comprueba una consulta autenticada y Redis un `PING` autenticado; ambos evitan imprimir credenciales en sus comprobaciones. PHP-FPM comprueba `/ping` mediante FastCGI; Nginx comprueba la ruta Laravel `/up`. El ping FPM no demuestra que el esquema esté preparado: las migraciones siguen siendo un paso explícito. Los workers y el scheduler requieren activar el perfil `workers` tras completar el bootstrap. La configuración inicial de cola usa timeout de 60 segundos, por lo que Laravel debe mantener `retry_after` por encima de ese valor; se propone 90 segundos.

Secuencia de operación local desde `E:\VSCODE\Agroconecta` en PowerShell, una vez que Laravel, las variables y la clave local estén preparados:

1. `docker compose config --quiet`: validar sin mostrar secretos resueltos.
2. `docker compose build app`: construir la imagen PHP compartida.
3. `docker compose run --rm --no-deps app composer install`: instalar las dependencias bloqueadas.
4. `docker compose up -d postgres redis app nginx`: iniciar persistencia y HTTP.
5. `docker compose exec app php artisan migrate`: aplicar migraciones de manera controlada.
6. `docker compose --profile workers up -d queue scheduler`: activar procesos asíncronos.
7. `docker compose --profile mail up -d mailpit`: activar el buzón local si se necesita.

La validación sintáctica no demuestra funcionamiento real. La base local ya se verificó y Evolution se incorpora con `docker-compose.evolution.yml`, sin puertos públicos. La configuración administrativa admite Meta oficial o QR/Baileys según la elección explícita del administrador; véase [Evolution](evolution.md). El arranque actual instala PHP y assets en volúmenes Linux y separa las cachés HTTP de la configuración de pruebas y restauración.

Antes de producción se sustituirá el montaje de código por imágenes de release, se separarán credenciales de aplicación/migración/administración, se inyectarán secretos mediante el mecanismo del destino y se ejecutará la prueba de recuperación. El usuario PostgreSQL creado inicialmente por la imagen es una credencial de bootstrap local; no debe convertirse en la credencial de aplicación productiva.

## Referencia de diseño aprobada en Fase 0

Fecha de análisis y consulta de las fuentes de diseño: **8 de septiembre de 2026**. Las secciones siguientes describen el plan original; los elementos marcados como futuros se implementarán y validarán en su fase correspondiente. La sección de estado anterior prevalece respecto de los archivos Docker ya creados.

## 1. Base tecnológica propuesta

El entorno ejecutará un monolito modular Laravel con procesos separados para HTTP, colas y tareas programadas. Compartirán código y versión; no serán microservicios.

| Componente | Decisión de diseño | Verificación y condición para implementarlo |
| --- | --- | --- |
| Laravel | **13.x**, fijado mediante `composer.lock` al implementar | La versión mayor estable actual se publicó el 17 de marzo de 2026; admite PHP 8.3–8.5 y recibe correcciones de seguridad hasta el 17 de marzo de 2028. [Política oficial](https://laravel.com/framework/docs/releases). |
| PHP | **8.4**, variante FPM para HTTP y CLI para procesos de consola de la misma imagen de aplicación | Compatible con Laravel 13; soporte de seguridad de PHP hasta el 31 de diciembre de 2028. Antes de construir se resolverá un parche soportado de la [imagen oficial PHP](https://hub.docker.com/_/php). [Ciclo de soporte PHP](https://www.php.net/supported-versions.php). |
| PostgreSQL | **18**, sin extensiones geográficas inicialmente | La rama estable 18 tiene soporte hasta el 14 de noviembre de 2030. El catálogo oficial consultado lista la imagen `postgres:18-bookworm`; se usará solo como referencia de familia y se fijarán parche y digest en Fase 1. [Soporte](https://www.postgresql.org/support/versioning/), [imagen oficial](https://hub.docker.com/_/postgres). |
| Redis | **8**, para colas, caché, sesiones y coordinación | El repositorio oficial publica `redis:8`. Se resolverán variante, parche y digest al implementar y se registrará la licencia aplicable. La etiqueta mayor es móvil y no es una referencia de producción reproducible. [Imagen y licencias oficiales](https://hub.docker.com/_/redis). |
| Nginx | Imagen oficial estable, parche y digest por resolver | Servirá exclusivamente el directorio `public` de Laravel y enviará PHP a FPM por red privada. |
| Composer / Node | Composer 2 y Node LTS compatible con el conjunto elegido de Vite, Livewire y Tailwind | Son herramientas de construcción; no servicios públicos. Fase 1 comprobará compatibilidad y generará los archivos de bloqueo sin versiones inventadas. |
| Docker | Docker Desktop, contenedores Linux, backend WSL 2, Compose V2 | El proyecto y la base de datos se denominarán `agroconecta`. |

Las versiones menores, paquetes de interfaz, tags completos y digests aún no están fijados ni instalados. Se comprobarán nuevamente en Fase 1; una actualización mayor posterior será una decisión revisada. La presencia de capacidades de IA en Laravel 13 no implica incorporarlas al MVP.

## 2. Requisitos de desarrollo en Windows 11

Se utilizará Docker Desktop con virtualización habilitada y WSL 2. Docker documenta WSL **2.1.5 como mínimo**, recomendando una versión actual. Es necesario comprobar los requisitos de la edición y compilación de Windows instalada; no se han auditado en Fase 0. [Docker Desktop con WSL 2](https://docs.docker.com/desktop/features/wsl/).

El código seguirá inicialmente en `E:\VSCODE\Agroconecta`. Si los montajes desde NTFS afectan a Composer o la recarga de assets, se medirá una copia de trabajo en el sistema de archivos Linux de WSL y se documentará la ruta elegida; este análisis no mueve archivos. VS Code podrá acceder al entorno mediante su integración WSL. La base de datos y Redis usarán volúmenes administrados por Docker, no directorios de datos internos editados desde Windows.

Como presupuesto inicial de laboratorio, se propone disponer de 16 GB de RAM en el equipo y al menos 20 GB libres para imágenes, dependencias y volúmenes. Son estimaciones de trabajo, no mínimos oficiales ni resultados de medición. Se ajustarán al equipo durante la Fase 1.

## 3. Arquitectura Docker

```text
Navegador en Windows
    │ http://localhost:8080 (propuesto; reservado a loopback)
    ▼
nginx ── FastCGI privado ──► app (Laravel / PHP-FPM)
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
          postgres          redis       archivos privados
              ▲               ▲               ▲
              └───────┬───────┴───────────────┘
                      │
                 queue / scheduler
                  misma imagen app

Construcción: Composer + Node → vendor + assets → imagen app / nginx
Desarrollo opcional: mailpit (buzón local, sin envío real)
Producción futura: HTTPS → nginx; copia de seguridad fuera del servidor
```

| Servicio previsto | Responsabilidad | Persistencia / conectividad |
| --- | --- | --- |
| `app` | Atender Laravel mediante PHP-FPM | Red de aplicación y de datos; FPM no publica puerto al host. Código de desarrollo montado; código de producción incorporado en imagen. |
| `nginx` | Entrada HTTP, assets, límite de tamaño de petición | Solo accede a la red de aplicación. Puerto local 8080 enlazado a `127.0.0.1`; en producción 80/443 según terminación TLS. |
| `postgres` | Fuente de verdad comercial y transaccional | Red de datos, volumen propio, sin puerto publicado. |
| `redis` | Colas, caché, sesiones y bloqueos auxiliares | Red de datos, volumen propio, sin puerto publicado. Prefijos y conexiones separados por uso. |
| `queue` | Procesar mensajería, notificaciones, QR y reportes | Misma imagen y release que `app`, un proceso worker en primer plano por contenedor; acceso a PostgreSQL, Redis y archivos según necesidad. |
| `scheduler` | Despachar vencimientos, reconciliaciones y tareas periódicas | Una instancia activa inicialmente. Desarrollo con `schedule:work`; producción con ejecución de `schedule:run` cada minuto mediante un planificador supervisado. |
| `mailpit` | Capturar correo de desarrollo | Perfil opcional local; interfaz solo en loopback. No estará en producción. |
| Construcción Composer / Node | Instalar dependencias bloqueadas y compilar assets | Etapas de construcción o servicios efímeros; sin exposición de red entrante. |

En producción, la red de datos será privada. El acceso saliente necesario para WhatsApp, correo o almacenamiento se concederá a los procesos correspondientes; aislar la base de datos no debe impedir esas llamadas. No se montará el socket Docker en los contenedores de aplicación.

## 4. Persistencia, archivos y permisos

PostgreSQL 18 requiere prestar atención al cambio de la imagen oficial: el volumen se monta en **`/var/lib/postgresql`** y el `PGDATA` predeterminado es **`/var/lib/postgresql/18/docker`**. Copiar una configuración antigua con el montaje en `/var/lib/postgresql/data` puede dejar los datos reales fuera del volumen esperado. Este punto tendrá una comprobación explícita de persistencia en Fase 1. [Documentación de la imagen](https://hub.docker.com/_/postgres).

Se separarán datos PostgreSQL, persistencia Redis y archivos de negocio. Fotografías originales, comprobantes y documentos personales serán privados, con descargas autorizadas y límites de tipo/tamaño. Solo derivados aprobados para publicación podrán servirse públicamente. Los QR no incorporarán archivos privados ni datos personales en su contenido.

La imagen de producción será inmutable y ejecutará PHP con usuario sin privilegios; escritura limitada a almacenamiento, temporales y cachés necesarios. El proceso debe poder escribir en `storage` y `bootstrap/cache`; no se resolverán permisos con acceso global de escritura. Nginx no expondrá el directorio raíz del repositorio ni `.env`. [Despliegue de Laravel](https://laravel.com/framework/docs/13.x/deployment).

Redis tendrá límites de memoria y, al compartir una instancia con colas durante el MVP, política de no expulsión de claves para evitar eliminar trabajos silenciosamente. La caché tendrá límites de tamaño y expiración. Si el consumo crece, separar una segunda instancia Redis para caché es una evolución posible; usar bases lógicas distintas por sí solo no separa memoria ni políticas de expulsión.

Se propone AOF con sincronización periódica y snapshots complementarios. AOF no ofrece pérdida cero: Redis documenta una posible pérdida reciente con sincronización cada segundo. La aplicación persistirá entradas y solicitudes de salida en PostgreSQL para poder reconciliar trabajos tras una caída. Redis nunca decidirá por sí solo disponibilidad agrícola, asignaciones o pagos. [Persistencia Redis](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/).

## 5. Arranque, salud y procesos

El arranque previsto será: validar configuración, iniciar PostgreSQL y Redis, esperar sus comprobaciones de salud, ejecutar preparación de esquema mediante un paso controlado y después habilitar `app`, `queue`, `scheduler` y la entrada HTTP. Las migraciones no se ejecutarán automáticamente en el arranque de cada worker o réplica. Un operador o una única ejecución del pipeline será responsable del cambio de esquema.

Compose distingue servicio iniciado de servicio preparado: las dependencias requerirán salud satisfactoria; un paso efímero puede requerir finalización exitosa. Ese orden no resuelve fallos posteriores, para los cuales habrá reintentos acotados, reconexión y alertas. [Orden de arranque de Compose](https://docs.docker.com/compose/how-tos/startup-order/).

| Comprobación | Qué debe demostrar | Limitación que se atenderá |
| --- | --- | --- |
| PostgreSQL | `pg_isready` y conexión real con el usuario de aplicación | El servidor disponible no demuestra que las migraciones estén completas. |
| Redis | Respuesta autenticada y capacidad de uso por la aplicación | Un `PING` no demuestra que el worker esté consumiendo. |
| Laravel | `/up` devuelve éxito de arranque | Por defecto no prueba las dependencias; habrá una comprobación interna adicional de DB, Redis y versión de esquema. |
| Nginx | Petición HTTP llega a Laravel y assets correctos | Un proceso Nginx vivo puede ocultar errores FastCGI. |
| Worker | Heartbeat reciente y consumo de un trabajo inocuo de prueba | No basta encontrar un proceso PHP. |
| Scheduler | Marca persistida de última ejecución, con alerta por retraso | La ausencia de errores no demuestra que esté programando. |

La ruta pública de salud no mostrará secretos ni detalles de conexión. La comprobación interna de preparación se reservará al operador o al monitor. La ruta estándar `/up` de Laravel comprueba el arranque y permite ampliar diagnóstico. [Salud en Laravel](https://laravel.com/framework/docs/13.x/deployment#the-health-route).

Los trabajos tendrán timeout, intentos limitados, espera progresiva, identificadores idempotentes y registro de fallo definitivo. El timeout del worker deberá ser menor que `retry_after`; el periodo de terminación de Docker permitirá finalizar trabajo en curso. Las colas asumirán que un trabajo puede ejecutarse más de una vez. Cambiar la imagen requiere renovar workers, no solamente PHP-FPM. [Colas Laravel](https://laravel.com/framework/docs/13.x/queues).

El scheduler será una sola instancia, con tareas idempotentes y protección contra solapamiento. La expiración de una reserva será efectiva también al consultar o confirmar bajo transacción; no dependerá exclusivamente de que haya corrido el scheduler. [Planificador Laravel](https://laravel.com/framework/docs/13.x/scheduling).

## 6. Configuración y secretos

| Grupo | Variables conceptuales que se documentarán en Fase 1 | Tratamiento |
| --- | --- | --- |
| Aplicación | `APP_ENV`, `APP_URL`, `APP_DEBUG`, `APP_KEY` | Clave distinta por entorno; debug desactivado en producción. |
| Base de datos | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Usuario de aplicación sin privilegios de administración; credencial de migraciones separable. |
| Redis y procesos | `REDIS_HOST`, `REDIS_PASSWORD`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | Credenciales y prefijos por entorno; nunca compartir Redis de desarrollo con producción. |
| Canales | Selector de proveedor, identificadores y secretos WhatsApp/SMS | Primero proveedor falso; secretos reales solo durante su integración autorizada. |
| Archivos / correo | Disco privado, credenciales de almacenamiento y correo | Privilegios mínimos y entornos separados. |
| Negocio | `AUTO_VERIFICATION_ENABLED=false` | La automatización permanece desactivada al iniciar el MVP. |

Desarrollo usará `.env` fuera de Git y un `.env.example` sin secretos. Producción recibirá secretos mediante archivos montados o un gestor de secretos, con acceso limitado por servicio. Compose puede montar secretos en `/run/secrets/`; esto no reemplaza el cifrado y los permisos del host. La lectura de archivos de secreto por Laravel se resolverá explícitamente en configuración; no se supondrá soporte automático de una variable `_FILE`. [Secretos en Compose](https://docs.docker.com/compose/how-tos/use-secrets/).

No se incorporarán credenciales a capas de imagen, argumentos de construcción, logs o archivos de caché publicados. `APP_KEY` y las claves específicas de cifrado/búsqueda de datos personales tendrán custodia, copia recuperable y procedimiento de rotación; perderlas puede inutilizar datos aunque PostgreSQL se restaure correctamente. Nunca se regenerarán como parte de cada despliegue.

En producción: HTTPS, cookies seguras, autenticación y autorización por rol/política, protección de webhooks, limitación de peticiones, cargas privadas y auditoría desde sus fases correspondientes. La Fase 18 profundiza las defensas; no pospone las protecciones básicas. El simulador de mensajes y herramientas de depuración quedarán inaccesibles en producción.

## 7. Backups y recuperación

Un volumen Docker persistente no es un backup. La propuesta de lanzamiento debe preservar PostgreSQL, archivos privados, configuración necesaria y acceso recuperable a claves. Habrá copias **fuera del servidor de aplicación**, cifradas y con credenciales distintas; el proceso de aplicación no podrá eliminar esas copias. Se evaluará versionado o inmutabilidad en el almacenamiento elegido.

| Objetivo propuesto | Significado | Mecanismo y prueba necesarios |
| --- | --- | --- |
| RPO PostgreSQL de 15 minutos en piloto productivo | Pérdida máxima objetivo de datos confirmados ante un desastre | Backup base más archivo continuo de WAL o servicio equivalente, con alerta de retraso de copia externo. Un dump nocturno no cumple este objetivo. |
| RTO de 4 horas en piloto | Tiempo objetivo para volver a operar desde una pérdida del servidor | Procedimiento de aprovisionamiento, restauración y comprobación ensayado en un entorno aislado. |
| Archivos críticos con RPO de 15 minutos | Preservar comprobantes y evidencias con referencias coherentes | Almacenamiento externo duradero/versionado o copias incrementales verificadas dentro de ese intervalo. Reconciliar archivos y registros tras restaurar. |
| Entorno de desarrollo con RPO de 24 horas | Conservar datos de prueba útiles | Exportación lógica local y una segunda ubicación de copia; sin datos personales reales. |

Son objetivos de diseño sujetos a aceptación operativa y a presupuesto antes del piloto, no un SLA ni una capacidad ya demostrada. Si no se puede financiar o administrar la recuperación de 15 minutos, se deberá acordar explícitamente una tolerancia mayor antes de aceptar operaciones reales; no se presentará un backup diario como equivalente.

Se propone retención inicial de copias diarias durante 30 días y copias mensuales durante 12 meses, revisada según minimización y obligaciones del negocio antes de producción. Se registrarán hora, tamaño, checksum, resultado, destino y último punto recuperable. Los respaldos de datos personales tendrán acceso auditado y un procedimiento de caducidad.

La recuperación a un punto en el tiempo de PostgreSQL necesita backups base y una secuencia utilizable de WAL. Los dumps lógicos facilitan exportaciones y restauraciones selectivas, pero no sustituyen esa cadena. [Backup y restauración PostgreSQL](https://www.postgresql.org/docs/18/backup.html), [archivo continuo y PITR](https://www.postgresql.org/docs/18/continuous-archiving.html).

Antes del primer piloto y luego mensualmente se restaurará una copia en un entorno aislado. La prueba verificará claves de descifrado, integridad referencial, cantidades no negativas, órdenes/asignaciones, pagos/liquidaciones, archivos y acceso autorizado. Se medirá RPO/RTO real y se conservará el acta de resultado. Un código de salida exitoso al copiar no reemplaza esta prueba.

Al recuperar se mantendrán workers y canales salientes detenidos hasta reconciliar mensajes y operaciones. Se reconstruirán trabajos pendientes desde registros durables; no se reenviarán automáticamente pagos o avisos cuyo resultado sea incierto. La política idempotente debe resistir datos restaurados y reintentos del proveedor.

## 8. Logs, métricas y respuesta a fallos

La aplicación emitirá logs estructurados hacia stdout/stderr con correlación de solicitud, mensaje, trabajo y entidad comercial. Se registrarán severidad, categoría, duración y resultado. DNI, tokens, texto sensible de mensajes y comprobantes no se incluirán en logs. La auditoría de negocio se almacenará de forma controlada, diferenciada del log técnico.

Los contenedores tendrán rotación y límite de logs. Se propone retención técnica de 30 días, revisable, con copia central externa para incidentes importantes. La política de auditoría comercial se definirá de acuerdo con sus fines de trazabilidad, distinta de la de depuración.

Alertas mínimas para el piloto:

- Aplicación no disponible, tasa de errores sostenida y vencimiento próximo de certificado.
- Espacio libre bajo, memoria y reinicios anormales.
- Trabajo más antiguo demasiado retrasado, cola fallida y heartbeat ausente del worker.
- Scheduler atrasado y conciliaciones pendientes.
- Webhooks rechazados, mensajes sin procesar o respuesta de proveedor incierta.
- Copia de seguridad fallida, WAL atrasado o prueba de restauración vencida.

Cada alerta tendrá responsable y una acción concreta. Se propone medir inicialmente consumo de colas y disponibilidad cada minuto, y salud de backups en cada ejecución. Los umbrales de latencia, carga y volumen se establecerán con pruebas; no hay capacidad productiva medida en Fase 0.

## 9. Cloud: comparación pendiente antes de elegir proveedor

La Fase 20 comparará VPS, DigitalOcean, Hetzner, AWS, Azure, Google Cloud y Railway/Render cuando la aplicación local esté estable. Esta lista recoge candidatos del prompt; no constituye una recomendación ni presupone prestaciones de un plan concreto.

| Criterio | Evidencia que se obtendrá |
| --- | --- |
| Costo total | Cotización vigente de cómputo, disco, PostgreSQL, Redis, transferencia, copias externas, monitorización, impuestos y administración humana. |
| Simplicidad | Pasos reales para desplegar Laravel, ejecutar workers permanentes y un scheduler único. |
| Backups | Retención, PITR, ubicación externa, exportación y prueba de restauración disponibles en el plan. |
| Volumen | Productores, compradores, mensajes/día, picos concurrentes, tamaño de archivos y crecimiento esperado. |
| Operación | Responsable de parches, recuperación, certificados, incidentes y tiempo disponible. |
| Red y datos | Latencia medida desde Perú, ubicación de datos, redes privadas y transferencias. |
| Portabilidad | Exportación PostgreSQL, recuperación de archivos, imágenes estándar y costos de salida. |

Como hipótesis sencilla se evaluará un servidor Linux con Docker Compose y almacenamiento externo de copias. Un único servidor es un punto de fallo y necesita recuperación practicable. También se comparará separar PostgreSQL en un servicio administrado. No se decidirá infraestructura ni se publicarán precios sin esa comparación. Kubernetes y microservicios quedan fuera del MVP.

## 10. CI/CD, despliegue y rollback

GitHub Actions se incorporará después de contar con código y pruebas. El pipeline previsto será:

1. Revisar el cambio y ejecutar formato, análisis, auditoría de dependencias y pruebas pertinentes.
2. Ejecutar pruebas críticas sobre PostgreSQL y Redis reales de prueba, incluidas concurrencia e idempotencia. Ningún fallo crítico permitirá desplegar.
3. Construir una sola vez desde los archivos de bloqueo; producir imágenes etiquetadas con el commit y conservar su digest, metadatos y resultado del escaneo.
4. Validar la misma imagen en staging con datos sintéticos, configuración equivalente y sin credenciales de producción.
5. Serializar despliegues a producción; comprobar backup recuperable, compatibilidad de esquema y release anterior.
6. Ejecutar las migraciones compatibles mediante una única tarea controlada. Detener el despliegue si falla.
7. Activar la imagen probada para HTTP, workers y scheduler; renovar procesos de larga duración con terminación ordenada.
8. Comprobar HTTP, assets, conexión, consumo de una cola inocua y heartbeat del scheduler; observar errores y conservar un registro de release.

La caché de configuración se construirá con los secretos del entorno de destino después de inyectarlos, sin publicarla en el registro de imágenes. La imagen contendrá únicamente dependencias de producción y assets compilados. Las credenciales de CI tendrán privilegios mínimos; un cambio procedente de un origen no confiable no recibirá secretos de despliegue.

Las migraciones seguirán **expansión y contracción**: primero añadir estructura compatible, después desplegar código que la utilice, completar/revisar transformaciones de datos y retirar estructura antigua en otro release cuando ya no exista código que la necesite. Los jobs ya encolados deberán ser compatibles con el nuevo consumidor; se evitarán payloads que requieran clases o campos eliminados.

El rollback habitual reactivará el digest anterior **solo si es compatible con el esquema actual**. No se ejecutará automáticamente `migrate:rollback` ante cualquier fallo. Restaurar un backup puede perder transacciones posteriores y exige reconciliación; es una operación de recuperación, no una reversión rutinaria. Ante un cambio incompatible se usará una corrección hacia delante o una ventana de mantenimiento planificada con procedimiento ensayado.

Compose en un único servidor no garantiza despliegue sin interrupción. Para el MVP se aceptará una ventana breve y anunciada si las pruebas muestran que el cambio la requiere. Los webhooks deberán permitir reintentos sin duplicar operaciones.

## 11. Contrato de la futura Fase 1

**Qué se construirá:** esqueleto Laravel, imagen PHP, Compose, Nginx, PostgreSQL, Redis, worker, scheduler y configuración local. **Por qué:** disponer de una base repetible que permita probar cada módulo sin instalar PHP o PostgreSQL directamente en Windows. **Cómo funcionará:** Compose conectará procesos aislados mediante redes privadas y volúmenes persistentes.

Archivos previstos, todavía no creados por este diseño:

- `E:\VSCODE\Agroconecta\docker-compose.yml`.
- `E:\VSCODE\Agroconecta\docker\php\Dockerfile`.
- `E:\VSCODE\Agroconecta\docker\nginx\default.conf`.
- `E:\VSCODE\Agroconecta\.env.example` y exclusiones de Git/Docker.
- `E:\VSCODE\Agroconecta\application\` con Laravel y archivos de bloqueo.
- Actualizaciones de `README.md` y de este documento con resultados medidos.

Los siguientes son **comandos previstos para Fase 1, no instrucciones ejecutables ahora**: falta crear y verificar sus archivos y servicios. No se han ejecutado en Fase 0. Su orden definitivo se explicará al implementar. Todos los comandos Docker se ejecutarán desde `E:\VSCODE\Agroconecta` en PowerShell; dentro del contenedor el directorio de trabajo Laravel quedará configurado en su imagen.

| Comando futuro | Finalidad y condición |
| --- | --- |
| `wsl --version` | Comprobar versión del entorno antes de instalar el proyecto. |
| `docker version` y `docker compose version` | Comprobar Docker activo y Compose disponible. |
| `docker compose config --quiet` | Validar sintaxis sin imprimir la configuración resuelta con secretos. |
| `docker compose build` | Construir cuando existan Dockerfile y archivos de bloqueo. |
| `docker compose up -d postgres redis` | Iniciar persistencia y esperar sus comprobaciones de salud. |
| `docker compose run --rm app php artisan migrate` | Preparación controlada del esquema local una vez que exista configuración y `APP_KEY`; no es un paso automático de cada contenedor. |
| `docker compose up -d app nginx queue scheduler` | Iniciar los procesos con el esquema preparado. |
| `docker compose ps` | Revisar estado y puertos. |
| `docker compose exec app php artisan migrate:status` | Comprobar esquema aplicado. |
| `docker compose exec app php artisan test` | Ejecutar pruebas sobre una base de pruebas aislada cuando la suite esté creada. |

La Fase 1 detallará por separado creación inicial, instalación de dependencias, generación única de clave local y construcción de assets; esta tabla no es un instalador completo. No existen todavía usuarios demo ni credenciales funcionales.

Criterios de aceptación de Fase 1:

- Laravel responde en localhost y entrega assets; se registran versiones efectivas.
- Solo la entrada web y las herramientas locales autorizadas publican puertos en loopback; PostgreSQL, Redis y FPM permanecen privados.
- Aplicación conecta con PostgreSQL y Redis mediante nombres de servicio, sin recurrir a `localhost` dentro de contenedores.
- Un dato de prueba permanece después de recrear el contenedor PostgreSQL conservando el volumen; se verifica la ruta de datos de PostgreSQL 18.
- Un trabajo inocuo pasa realmente por Redis y el worker; reiniciar el worker no pierde de forma silenciosa un trabajo pendiente.
- Scheduler registra actividad, no existe una segunda instancia inadvertida y se comprueba su recuperación tras reinicio.
- Fallos de dependencia se reflejan en salud/logs sin exponer credenciales; `.env` no es accesible desde HTTP ni está en Git.
- Se puede detener y volver a iniciar el entorno sin borrar datos. Cualquier eliminación de volúmenes quedará fuera del procedimiento normal.
- Se documentan comandos, límites del equipo, pruebas realizadas y siguiente fase. La autenticación y el negocio no se declaran implementados por completar el entorno.

**Situación de Fase 0:** arquitectura operativa y criterios definidos; entorno, credenciales, backups, CI/CD y nube aún no implementados ni probados. La siguiente fase será Entorno, tras la confirmación explícita solicitada por el usuario.

## Controles y restauración local al cierre de fase 19

El entorno actual incluye el override de Evolution oficial y los perfiles de README; las primeras secciones conservan decisiones iniciales. La cola mantiene retry_after=120; exportaciones tienen timeout de 90 segundos y otros jobs conservan 60. El respaldo concede hasta 100 segundos para detener procesos.

Antes de producción debe pasar agroconecta:security-check --production: APP_ENV=production, debug apagado, APP_URL HTTPS, cookies Secure/HttpOnly/SameSite=Lax cifradas, claves originales, simulador apagado y archivos privados sin publicación automática. La terminación TLS y una lista explícita de proxies confiables deben configurarse según el host. X-Forwarded-Proto arbitrario no acredita HTTPS.

scripts/backup-local.ps1 fue verificado en Windows PowerShell y Docker. Guarda PostgreSQL custom, TAR privado y manifiestos; restaura en una base temporal para comprobar descifrado y evidencias. Las claves se guardan por separado. No incluye persistencia Redis/Evolution ni secretos; no constituye un procedimiento productivo con retención o RPO/RTO.

**Fase 20 parcial:** imágenes/Compose productivos y ensayo HTTPS local preparados. Faltan servidor, dominio/HTTPS público, CI/CD remoto, correo, backup externo cifrado, monitoreo, recuperación del canal y reversión. No se crearon recursos cloud ni se habilitaron mensajes o pagos reales.

## Docker de produccion preparado (fase 20)

El ensayo del 19/09/2026 aprobó HTTPS, cookies Secure/HttpOnly/SameSite=Lax, rutas de desarrollo cerradas, migraciones, siete restricciones de privilegios PostgreSQL y consumo real de cola/scheduler. Resultado: `.local/production-rehearsal/result.json`; log: `.local/phase-20-rehearsal.log`. La demo de 8080 conserva sus datos.

### Ejecutar el ensayo en Windows

Desde `E:\VSCODE\Agroconecta`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\production-rehearsal.ps1
```

Genera secretos una sola vez, construye imágenes, prepara una base aislada, aplica migraciones/catálogos e inicia seis servicios. Si existe volumen de base y falta una clave, exige recuperar la original. No crea usuarios demo ni envía mensajes/correos. El certificado local dura siete días y se valida mediante `curl --cacert`, sin instalar confianza en Windows. Para explorar el negocio utiliza la demo de http://localhost:8080.

Ya preparado, reinicia con una sola línea:

```powershell
docker compose --env-file .local/production-rehearsal/compose.env -p agroconecta-production-rehearsal -f docker-compose.production.yml up -d
```

Para detenerlo conservando datos, sustituye `up -d` por `stop`. Para repetir las comprobaciones sin construir, ejecuta el script con `-SkipBuild`. Cuando venza el certificado, renueva únicamente `tls/server.crt` y `tls/server.key` y recrea Nginx. Conserva APP_KEY, PRODUCER_LOOKUP_KEY y contraseñas de persistencia.

### Configuración incorporada

- `docker-compose.production.yml` es independiente del entorno de desarrollo. Especifica siempre `--env-file` y `-p` para evitar heredar el nombre de la demo.
- `docker/production/Dockerfile` copia código seleccionado, instala PHP sin dependencias de desarrollo y compila assets. Las imágenes verificadas no contienen `.env`, datos privados, tests, PHPUnit ni node_modules.
- App, cola y scheduler usan UID 33; Nginx, UID 101; Redis, su usuario. Se limitan capacidades y escrituras, con tmpfs para temporales. PostgreSQL conserva su inicialización oficial.
- Los roles PostgreSQL separan administrador de infraestructura, migrador y aplicación. La aplicación opera datos, pero no crea tablas, roles o bases ni tiene privilegios de superusuario. Los privilegios sobre nuevos objetos los concede el migrador.
- Los secretos se montan desde archivos y se leen en el entrypoint. No se construye caché de configuración con sus valores. Para Artisan mediante `exec`, utiliza el wrapper mostrado abajo.
- Nginx termina TLS directamente y transmite HTTPS por FastCGI. PostgreSQL y Redis no publican puertos. Mantenimiento compartido en Redis, un scheduler y logs con rotación.
- `MAIL_MAILER=array` descarta correos: configura y prueba SMTP antes de admitir usuarios reales. Canales, simulador y comercio real permanecen apagados.

### Procedimiento del futuro servidor Linux

Este procedimiento está preparado, pero no se ejecutó en un servidor. Necesita host, DNS, certificado público con renovación y copias externas. No reutilices los secretos del ensayo.

1. Copia `docker/production/production.env.example` fuera del repositorio, por ejemplo a `/srv/agroconecta/production.env`. Configura un tag de release común para app/web, URL HTTPS, hostname, dirección de enlace y puertos 80/443. El ejemplo está limitado a loopback. Usa rutas absolutas privadas para secretos y TLS.
2. Solo en una base NUEVA, crea seis archivos: `app_key` (prefijo base64: seguido de 32 bytes aleatorios en base64), `producer_lookup_key`, `db_admin_password`, `db_app_password`, `db_migration_password` y `redis_password`. Para los últimos cinco, usa 32 bytes aleatorios en hexadecimal. Al restaurar datos, recupera las claves originales.
3. Guarda la cadena completa de certificado en `tls/server.crt` y su clave en `tls/server.key`. En Linux: directorios secrets/tls propiedad root con modo 0700; archivos secretos y certificado 0444; clave TLS propiedad 101:101 con modo 0400. Los directorios privados impiden el acceso de otros usuarios del host, y los mounts explícitos permiten la lectura a los procesos autorizados. Verifica permisos efectivos: Compose no remapea uid/gid/mode para secretos basados en bind mounts. [Documentación de secretos](https://docs.docker.com/compose/how-tos/use-secrets/), [servicios Compose](https://docs.docker.com/reference/compose-file/services/).
4. Desde el checkout revisado, en Bash:

```bash
docker build -t agroconecta-app:local docker/php
dc() { docker compose --env-file /srv/agroconecta/production.env -p agroconecta-production -f docker-compose.production.yml "$@"; }
dc config --quiet
dc build app nginx
dc up -d --wait postgres redis
dc run --rm migrate
dc run --rm migrate php artisan db:seed --force --no-interaction
dc up -d --wait app queue scheduler nginx
dc exec -T app /usr/local/bin/agroconecta-entrypoint php artisan agroconecta:security-check --production
dc exec -T app /usr/local/bin/agroconecta-entrypoint php artisan agroconecta:ready
dc exec -T app /usr/local/bin/agroconecta-entrypoint php artisan agroconecta:health --wait=60
```

Conserva el release anterior. Antes de una actualización revisa respaldo y compatibilidad de esquema; no ejecutes migraciones automáticamente en cada réplica ni rollback destructivo de la base.

### Primer administrador

Los seeders de producción crean roles/catálogos/métodos, sin cuentas demo. Guarda una contraseña nueva en un archivo privado fuera del repositorio, legible por UID 33 al montarlo: mínimo 16 caracteres, mayúsculas, minúsculas, números y símbolos. Usando la función dc anterior, reemplaza el correo y nombre por los del responsable real:

```bash
dc run --rm --no-deps -v /srv/agroconecta/secrets/bootstrap_admin_password:/run/secrets/bootstrap_admin_password:ro app php artisan agroconecta:bootstrap-admin --email=administracion@example.com --name="Administración AgroConecta" --password-file=/run/secrets/bootstrap_admin_password --no-interaction
```

El comando crea una cuenta activa/verificada con rol administrador y auditoría. Rechaza una segunda creación si existe administrador; no promueve compradores ni restablece contraseñas existentes. No imprime la contraseña. Retira el archivo temporal tras conservar la credencial en un gestor seguro.

BootstrapAdministratorTest y DeploymentHealthTest aprobaron **6 casos y 22 aserciones**. `agroconecta:ready` comprueba esquema y salud; `agroconecta:health` además espera el procesamiento de un trabajo inocuo real.

### CI preparado y pendientes

`.github/workflows/validate.yml` permite push, pull request y ejecución manual, con checkout fijado a commit y permisos de lectura. Ejecuta `scripts/ci.sh` sobre un checkout nuevo: claves efímeras, PostgreSQL/Redis de prueba, dependencias bloqueadas, formato, suite, auditorías y construcción de app/web. No publica imágenes ni despliega.

La sintaxis del script se comprobó localmente y el build productivo/ensayo sí se ejecutaron. El workflow remoto no se ejecutó: todavía no existe remote Git. Las 347 pruebas de fase 19 y las seis nuevas están aprobadas en sus ejecuciones documentadas; no se atribuye ese resultado a GitHub.

Para cerrar fase 20 faltan servidor, DNS/HTTPS público y renovación, SMTP, releases y CI/CD remotos, respaldos cifrados fuera del host con restauración/RPO/RTO medidos, monitoreo/alertas y ensayo de reversión. `backup-local.ps1` está probado para la demo; no lo utilices como respaldo de este Compose productivo. Evolution/Meta y Twilio requieren cuentas y pruebas reales; el override local de Evolution no se incorpora automáticamente a este Compose.

Referencias: [despliegue Laravel](https://laravel.com/framework/docs/deployment), [privilegios PostgreSQL](https://www.postgresql.org/docs/18/sql-grant.html), [privilegios futuros](https://www.postgresql.org/docs/18/sql-alterdefaultprivileges.html) y [TLS en Nginx](https://nginx.org/en/docs/http/ngx_http_ssl_module.html).
