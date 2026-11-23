# Arquitectura

## Visión general

```
Navegador ──▶ HTML estático + assets/app.js, ui.js
                    │
                    │  fetch('api.php?action=…')  (JSON + token CSRF)
                    ▼
               api.php  ──▶ MariaDB (PDO)
                    │
                    ├──▶ Stripe (Checkout, Billing Portal)
                    ◀──  Stripe (webhooks firmados)
                    └──▶ SMTP (correos transaccionales)
```

No hay framework ni paso de compilación: se sube la carpeta `public/` y funciona.

## La API (`api.php`)

Un único punto de entrada. Cada petición lleva `?action=<nombre>` y el archivo despacha a la rama correspondiente. Responde siempre JSON.

| Área | Acciones principales |
|---|---|
| Cuenta | `register`, `login`, `logout`, `me`, `profile`, `forgot_password`, `reset_password`, `logo_upload` |
| Directorio | `companies`, `company` |
| Oportunidades | `opportunities`, `opportunity`, `create_opportunity`, `update_opportunity`, `close_opportunity`, `delete_opportunity`, `my_opportunities` |
| Contacto | `contact` |
| Facturación | `create_checkout`, `billing_portal`, `billing_status`, `stripe_webhook` |
| Fundadoras e invitaciones | `founder_status`, `invitation_get`, `invitation_activate` |
| Intermediación | `mediation_create`, `mediation_mine`, `mediation_document_upload`, `mediation_documents_list` |
| Administración | `admin_login`, `admin_companies`, `admin_stats`, `admin_opportunities`, `admin_create_invitation`, `admin_mediation_list`… |

Funciones transversales dentro del mismo archivo: conexión PDO perezosa, cliente SMTP, CSRF, *rate limiting* y verificación de firma de Stripe.

## Planes y suscripciones

`userPlan()` decide el plan efectivo de una empresa en este orden:

1. Suscripción de Stripe activa o en periodo de prueba.
2. Empresa Fundadora vigente (Profesional gratis durante 12 meses).
3. Plan estratégico por invitación.
4. Plan gratuito.

Cada plan define límites (oportunidades activas, contactos). El estado de la suscripción se mantiene sincronizado con los eventos del webhook de Stripe (`checkout.session.completed`, `customer.subscription.*`, `invoice.paid`, `invoice.payment_failed`).

## Base de datos

Tablas principales: `users`, `opportunities`, `subscriptions`, `founder_slots`, `company_invitations`, `invited_companies`, `mediation_requests`, `mediation_documents`, `contact_events`, `password_resets`, `rate_limits`. El esquema completo está en `public/schema.sql`; además `api.php` crea las tablas que falten al arrancar.

## Módulos (`mega-bloque/`)

Código más reciente y más ordenado, escrito con clases y servicios:

- **alertas/** – reserva transaccional de avisos con tope mensual, cancelaciones y ganchos de eventos. Requiere InnoDB.
- **conexiones/** – preferencias de cada empresa (qué ofrece, qué busca, provincias, palabras clave), emparejamiento con oportunidades nuevas y envío de avisos con baja en un clic.
- **comerciales/** – agentes comerciales, servicio de administración con transacciones y registro de auditoría.

## Comisiones comerciales

`sales-stripe-commissions.php` calcula un 30 % por cada factura de suscripción pagada. Antes de calcular, exige que los importes de la factura cuadren (pagado, total y pendiente) y rechaza facturas con notas de crédito o saldo sin conciliar. Solo calcula: no hace transferencias.

## ConectaJob

Bolsa de empleo en `conectajob/`. Tiene su propio registro y sesión de candidatos y de empresas, y comparte la base de datos con ConectaObra a través de `conexion.php`.

## Frontend

- `assets/ui.css` define un sistema de diseño *mobile-first* con variables CSS (colores, radios, tipografía fluida con `clamp()`, márgenes de seguridad para móviles).
- `assets/ui.js` aporta el menú lateral, los filtros en hoja inferior, los formularios por pasos y los avisos.
- `assets/app.js` encapsula las llamadas a la API (con tiempo máximo de espera, mensajes de error claros y envío automático del token CSRF).
