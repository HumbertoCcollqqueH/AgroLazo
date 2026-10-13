# AgroConecta — roadmap de las fases 1 a 20

Plan creado en Fase 0, 8 de septiembre de 2026. **Actualización del 19 de septiembre: fases 1–6 y 9–19 implementadas y comprobadas localmente; 17 fases de desarrollo.** Las fases 7–8 tienen adaptadores y pruebas de contrato, con validación real pendiente. La fase 20 tiene Docker de producción ensayado localmente; quedan servidor y operación pública. WhatsApp utiliza Meta oficial a través de Evolution; SMS utiliza Twilio para pruebas. La conexión SMS bidireccional en Perú requiere validación independiente. Fase 0 es el análisis previo y no se cuenta dentro de las 20.

## Método de trabajo y puertas de salida

Antes de cada fase se explicará qué construiremos, por qué, cómo funciona, qué archivos se modificarán, qué comandos se ejecutarán y cómo comprobar el resultado. Después se implementarán los archivos reales, se ejecutarán las comprobaciones pertinentes y se actualizará README con evidencia. No se marcará una fase completada por tener pantallas sin reglas de negocio.

Al finalizar cada fase se informará: **FASE COMPLETADA**, qué funciona, qué se probó, qué falta y cuál sigue. Si una integración externa queda bloqueada, se indicará qué funciona localmente y qué falta demostrar; no se dará por terminada la integración real. La comprobación técnica permite avanzar a la siguiente fase. La confirmación solicitada al entregar Fase 0 ya fue recibida; el usuario autorizó continuar.

Seguridad, autorización, auditoría y pruebas acompañan la implementación de cada módulo. Fases 18 y 19 son endurecimiento y validación conjunta. Los eventos de trazabilidad se registran desde las primeras operaciones, aunque su consulta pública/QR se construya en 16.

La tabla conserva las áreas del plan original. Las implementaciones concretas hasta la fase 19 están en application; README y las guías describen sus nombres y límites. Los módulos 17–19 incorporan reportes privados, seguridad, recorrido E2E y restauración local. La fase 20 incorpora imágenes/Compose de producción, ensayo HTTPS local, arranque del primer administrador y workflow de CI preparado. El despliegue cloud, la ejecución remota de CI/CD y las validaciones externas permanecen pendientes; no se activaron comercio ni canales reales.

## Entregables y comprobaciones

| Fase | Qué se construye y por qué | Archivos/áreas previstos | Comprobación necesaria para cerrar |
|---|---|---|---|
| **1. Entorno** | Laravel/PHP, Nginx, PostgreSQL, Redis, queue y scheduler en Docker; base reproducible local | `docker-compose.yml`, `docker/php/`, `docker/nginx/`, `application/`, archivos de bloqueo, `.env.example`, README | `localhost:8080` propuesto responde a Laravel; conexión DB y Redis; job inocuo consumido; scheduler observado; reiniciar servicios conserva datos; secretos fuera de Git |
| **2. Autenticación y roles** | Sesión, registro comprador, recuperación, admin/coordinador/comprador y políticas por recurso | `application/app/Models/`, `Policies/`, `Http/`, vistas de acceso, migraciones, seeders de roles, pruebas Feature | Comprador no accede a administración ni a datos ajenos; coordinador no confirma pagos; recuperación por correo de prueba; altas no permiten escalar rol |
| **3. Catálogos** | Categorías, productos, variedades, unidades, conversiones, calidad y localidades | Modelos, migraciones, acciones de catálogo, Livewire/vistas, seeders | Registrar producto adicional a quinua/papa; variedad pertenece al producto; saco sin factor no se convierte; desactivar no destruye referencias |
| **4. Productores** | Registro asistido con cuatro datos obligatorios, opcionales, verificación e historial | Productores, verificaciones, ubicaciones/consentimientos, validación y panel; `docs/database.md` | DNI/teléfono duplicados rechazados; productor pendiente no publica; verificación deja responsable; información privada fuera de consultas públicas; cambio de teléfono controlado |
| **5. Ofertas** | Registro, solicitud de información, verificación, condiciones aceptadas y publicación | Offers, acuerdos, historial/fotos, acciones y Policies; eventos de trazabilidad | Mensaje/registro no publica automáticamente; solo productor verificado y acuerdo aceptado habilitan oferta; edición autorizada auditada; unidades y disponibilidad completas |
| **6. Simulador** | `/dev/message-simulator`, máquina conversacional y parser determinista; probar sin depender de credenciales | `Services/Messaging/`, contratos, proveedor falso, conversaciones/messages/outbox, jobs, ruta y vista local | `HOLA`, registro asistido, `VENDER`, `QUINUA 80KG CABANA`, corrección/cancelación; mensajes repetidos no duplican oferta; mismo flujo de aplicación para canales posteriores |
| **7. WhatsApp real** | Adaptador oficial, firmas/challenge, webhooks, worker, plantillas y recibos | `Integrations/Messaging/`, controladores webhook, configuración, intentos de envío; `docs/messaging.md` | Prueba entrante/saliente con cuenta real habilitada y destinatario de prueba; firma inválida rechazada; duplicados/reintentos fuera de orden controlados; no pedir DNI por chat; entrega distinguida de aceptación de API |
| **8. SMS** | Adaptador con envío y recepción, compartiendo servicios de dominio | Proveedor SMS, validación webhook, configuración y pruebas de contrato | Cobertura y bidireccionalidad comprobadas con proveedor elegido; segmentación, recibos y comandos probados; no confundir adaptador falso con SMS real |
| **9. Lotes** | Sugerencia por compatibilidad, confirmación manual y porciones segregadas | Lots, lot_offers, `AggregationService`, historial y panel | Suma de porciones no supera oferta; calidad/variedad/fechas incompatibles excluidas; mismo kilo no aparece en dos lotes; despublicar no borra compromisos |
| **10. Marketplace** | Listado, filtros, detalle, cantidad y estimación claramente identificada | Blade/Livewire/Tailwind, API Resources de productos/lotes | Filtros del prompt, paginación, móvil/teclado, disponibilidad consistente; no exponer identidad privada; hasta fase 11 no ofrecer cotización operativa ni checkout habilitado |
| **11. Pricing** | Referencias documentadas, precio ponderado, costos, descuentos y quotes | `PricingService`, quotes/items, proveedores de precios y endpoints; pruebas Unit/Feature | Recalcular desde asignación candidata real; total concilia al céntimo; quote guarda snapshot/vencimiento; referencia sin fuente no se presenta oficial; quote no retiene stock |
| **12. Compras** | Reserva temporal, compra directa, conversión, FIFO e idempotencia | `AllocationService`, reservas/items, orders/items/allocations, ajustes, `CheckoutService` | Compra parcial/completa; dos procesos sobre PostgreSQL no sobrevendan; confirmar/vencer compiten correctamente; repetir petición crea una sola orden; precio o stock cambiado requiere aceptación |
| **13. Acopio** | Puntos, capacidad/horarios, sugerencia aproximada y planificación con notificaciones | Collection points/events, planificador, vistas y notificaciones | Coordenadas opcionales; lugares ambiguos se aclaran; solapamientos/capacidad revisados; coordinador puede cambiar sugerencia; aviso a cada productor con su cantidad |
| **14. Logística** | Pesaje/calidad, incidencias, lotes físicos, despacho y entrega | Receipts, physical_lots/items, shipments/items/events, acciones/vistas | Recibido = aceptado + rechazado tras control; múltiples recepciones sin duplicar; faltantes/exceso requieren resolución; no despachar más de aceptado; cadena física conservada |
| **15. Pagos y liquidaciones** | Métodos manuales, evidencia, revisión, reembolsos, obligaciones y desembolsos | Payments/proofs/refunds, settlements/items/payouts, acciones/policies y almacenamiento privado | Subir comprobante no confirma ingreso; solo revisor autorizado; cobro no acredita desembolso; parciales/refunds concilian; liquidación usa acuerdo congelado y peso aceptado |
| **16. Trazabilidad y QR** | Recorrido privado, publicación pública limitada, código y QR | TraceabilityService, trace_publications, controladores/resources, generador QR y vistas | Compra hasta recepción/productor trazable para autorizados; QR público sin DNI/teléfono/comprador; token revocable; origen de despacho corresponde al físico |
| **17. Dashboards y reportes** | Admin/comprador, métricas de negocio, gráficos, mapa y exportaciones | Consultas/reportes, jobs de exportación, Livewire/vistas y permisos de descarga | Totales coinciden con operaciones; GMV separado de ingreso propio; neto pagado vs liquidado; comparación documentada; Excel/CSV/PDF válidos y privados cuando corresponde |
| **18. Seguridad integral** | Revisar despliegue y superficies completas, límites, permisos, auditoría y privacidad | Configuración, middleware, Policies, validación, secretos, dependencias y guías operativas | Acceso horizontal/vertical, CSRF/XSS, uploads, firmas, límites, debug y simulador cerrados en producción; CSV sin fórmulas inyectadas; logs sin datos sensibles |
| **19. Testing integral** | Verificar recorrido completo del prompt y recuperación ante fallos | Tests Unit/Feature/Integration, escenario E2E, datos sintéticos y restauración local | Productor → oferta → lote → compra 220 kg → acopio → pago/liquidaciones → entrega → QR/dashboard; fallos de proveedor, concurrencia y restauración no crean duplicados |
| **20. Cloud** | Comparar costo/operación, dominio/HTTPS, CI/CD, workers, backups externos y alertas | Compose producción, imagen release, `.github/workflows/`, runbooks y `docs/deployment.md` | Despliegue probado, tests críticos obligatorios, HTTPS y DNS, backup restaurado con RPO/RTO medidos, workers/scheduler observables, rollback compatible y smoke test |

Para conservar la secuencia, fase 11 incluirá la selección candidata necesaria para pricing; fase 12 añadirá el compromiso transaccional definitivo. En fase 10 el precio podrá ser una referencia/estimación documentada; nunca se presentará como un motor operativo todavía no implementado.

La fase 15 implementa registro y conciliación manual de cobros y desembolsos; no ejecuta transferencias. Antes de esa fase, pruebas de logística usarán condiciones de pago del escenario sintético y no habilitarán operaciones comerciales reales sin los controles financieros. La captura de registros de trazabilidad se incorporará con sus módulos; fase 16 expone consultas y QR.

## Comandos futuros y carpeta de ejecución

Esta sección conserva el contrato de operación inicial. El entorno, Docker y Artisan ya existen. Utiliza los comandos actualizados de README: el script prepara instalaciones/actualizaciones y Docker Compose levanta los servicios ya preparados.

Carpeta de trabajo en PowerShell:

```powershell
Set-Location 'E:\VSCODE\Agroconecta'
```

En Fase 1, después de crear y revisar el entorno:

```powershell
docker version
docker compose version
docker compose config --quiet
docker compose build
docker compose up -d
docker compose ps
docker compose exec app php artisan about
docker compose exec app php artisan migrate:status
docker compose logs --tail=100 app queue scheduler nginx
```

La instalación de Laravel, generación de `APP_KEY`, dependencias y primera migración tendrán una secuencia específica al construir el entorno; estos comandos de comprobación no sustituyen ese procedimiento. No se imprimirá la clave ni se regenerará después de guardar datos cifrados.

En fases con migraciones, una vez revisadas:

```powershell
docker compose exec app php artisan migrate
docker compose exec app php artisan test
```

El comando de pruebas se acotará al módulo cuando corresponda, con nombres de tests que existan en ese momento; en 19 se ejecutará la suite completa y el ensayo concurrente. No se usará `migrate:fresh` sobre datos que deban conservarse. Seeders demo se ejecutarán expresamente solo en local/testing y su comando se documentará al implementarlos.

Para fases de mensajería se comprobarán errores e intentos desde el panel y el worker. Reintentar un job se hará después de evaluar su idempotencia y resultado externo, no como respuesta automática a cualquier timeout. La exposición HTTPS temporal para un webhook se definirá en fase 7, sin publicar las rutas administrativas de desarrollo indiscriminadamente.

En 20, CI ejecutará tests, build y controles antes de desplegar; las migraciones de producción se ejecutarán una vez con `--force` dentro de un paso controlado. La especificación de Compose producción y comandos de rollback se concretarán tras elegir host. La migración o restauración no se probará directamente sobre datos productivos sin un procedimiento de recuperación.

## Criterio final de MVP

Un operador debe poder reproducir los 26 pasos del apartado 69 del prompt con evidencia persistida, y un comprador debe completar una compra parcial cuyas cantidades, cobros, liquidaciones y entrega concilien. El marketplace no debe depender de datos demo para funcionar. El simulador será una herramienta de pruebas, mientras la integración real tendrá evidencia independiente.

No se considerará finalizado por contar usuarios, mostrar gráficos o generar un QR sin cadena de datos. El sistema debe responder cuánto aportó cada productor, cuánto se aceptó, cuánto le corresponde, cuánto recibió y cuándo; y si el comprador realizó una compra posterior.

**Estado al 19 de septiembre de 2026:** Fase 0 documentada; **fases 1–6 y 9–19 implementadas: 17 fases de desarrollo validadas localmente**. Las fases **7–8 tienen adaptadores Evolution/Meta oficial y Twilio probados localmente**, con conexión real pendiente. La **fase 20 tiene Docker de producción preparado y ensayado localmente con HTTPS**; falta desplegarlo en un servidor y completar su operación externa. Los mensajes, pagos y mercancías de prueba son sintéticos; las integraciones reales siguen desactivadas.
