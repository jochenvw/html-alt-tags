# Security Architecture — Authentication & Authorisation

> **Audience:** Developers who are new to Azure or have limited experience with Azure identity and access management.
> This document explains how every component in the Alt-Text Pipeline authenticates, what permissions it has, and why each approach was chosen.

---

## The Core Principle: No Passwords Anywhere

This system follows a **zero-credential** approach. There are no API keys, passwords, or connection strings hardcoded in the application source code. Every service-to-service connection uses **Azure Managed Identity** — an identity that Azure itself issues and manages. This eliminates the most common security risk in cloud applications: leaked or stale credentials.

The only exception is the **external source system** that uploads images into Azure. Because it runs outside of Azure, it cannot use Managed Identity and must authenticate using a **Service Principal** (explained below).

---

## Key Concepts (Quick Glossary)

Before diving into the architecture, here are four Azure concepts that appear throughout this document.

### Managed Identity

A Managed Identity is like an employee badge that Azure issues to your application automatically. Your code never sees or handles the actual credential — Azure manages the entire lifecycle (creation, rotation, expiry) behind the scenes. When your application needs to talk to another Azure service (e.g., storage), it asks Azure for a short-lived token, and Azure verifies the identity on the back end. There are two types: **system-assigned** (tied to a single resource, deleted when the resource is deleted) and **user-assigned** (created independently, can be shared across resources). This system uses a **user-assigned** identity so the same identity can be reused by the Container App, Container Registry, and future services.

### RBAC (Role-Based Access Control)

RBAC is how Azure decides what a given identity is allowed to do. Instead of giving an application full admin access, you assign it a specific **role** with only the permissions it needs — for example, "can read and write blobs" but "cannot delete storage accounts". This is called the **principle of least privilege**. Roles are always scoped to a specific resource (e.g., one storage account), so the identity cannot access anything outside that scope.

### Service Principal

A Service Principal is an identity for applications or automation tools that run **outside** of Azure — for example, your on-premise content management system (CMS) or a CI/CD pipeline running on GitHub. Unlike Managed Identity, a Service Principal requires you to create and manage credentials (a client secret or certificate) and configure your external application to use them. Think of it as a visitor badge with an expiry date: it works, but you are responsible for issuing and renewing it.

### Bearer Token

A short-lived access token (typically valid for about 1 hour) that proves an identity's right to access a resource. The application obtains a token from Azure Active Directory, then includes it in the `Authorization: Bearer <token>` HTTP header on every API call. If the token expires, the application must request a new one. In this system, tokens are cached for approximately 50 minutes and refreshed automatically.

---

## Authentication Map

This table shows every connection in the system, how it authenticates, and why that method was chosen.

| From | To | Auth Method | Role / Permission | Why This Method |
|---|---|---|---|---|
| Container App | Blob Storage | User-Assigned Managed Identity | Storage Blob Data Contributor | App runs inside Azure; MI eliminates credential management |
| Container App | AI Foundry (Phi-4 model) | User-Assigned Managed Identity | Cognitive Services User | Same identity, different token scope; no API key needed |
| Container App | Computer Vision | User-Assigned Managed Identity | Cognitive Services User | Consistent pattern; one identity for all AI services |
| Container App | Translator | User-Assigned Managed Identity | Cognitive Services User | Same as above |
| Container App | Container Registry (image pull) | User-Assigned Managed Identity | ACR Pull | Container Apps pulls the app image on startup; MI avoids storing registry passwords |
| Container App | Storage Queue (dead-letter) | User-Assigned Managed Identity | Storage Queue Data Contributor | Access to the dead-letter queue for failed events |
| Event Grid | Container App | HTTPS webhook + validation handshake | None (public endpoint) | Event Grid verifies it owns the endpoint via a challenge-response handshake |
| **External system** | **Blob Storage** | **Service Principal (client credentials)** | **Storage Blob Data Contributor (scoped to `ingest` container)** | **Runs outside Azure; Managed Identity is not available** |

---

## How Managed Identity Works in This System

The application is written in PHP and uses a custom class (`ManagedIdentityCredential`) to acquire tokens. Here is what happens step by step:

1. **Container Apps injects environment variables** when the app starts:
   - `IDENTITY_ENDPOINT` — a local URL where the app can request tokens
   - `IDENTITY_HEADER` — a secret header that proves the request comes from inside the container
   - `AZURE_CLIENT_ID` — the ID of the user-assigned identity to use

2. **The app requests a token** for a specific service. For example, to access storage, it requests a token for `https://storage.azure.com`. To access AI Foundry, it requests a token for `https://cognitiveservices.azure.com`.

3. **Azure returns a signed JWT token** valid for approximately 1 hour. The app caches this token for 50 minutes and automatically requests a new one before it expires.

4. **The app includes the token** in every outgoing HTTP request as `Authorization: Bearer <token>`. The target service (storage, AI model, etc.) validates the token with Azure Active Directory.

5. **If the Container Apps environment is not available** (e.g., during local development), the code falls back to the Azure Instance Metadata Service (IMDS) at `169.254.169.254`, which provides the same token acquisition mechanism on Azure VMs and other compute services.

### What developers need to know

- You **never** handle credentials directly — the Managed Identity class does it for you.
- Tokens are scoped: a token for storage **cannot** be used for AI services, and vice versa.
- If you see `401 Unauthorized` errors, the most likely cause is a missing RBAC role assignment, not a wrong password.

---

## RBAC Roles Assigned

The following roles are assigned to the pipeline's Managed Identity in the Bicep infrastructure template. Each follows the principle of least privilege — only the minimum permissions required.

| Role Name | Role ID | Scope | What It Allows |
|---|---|---|---|
| Storage Blob Data Contributor | `ba92f5b4-2d11-453d-a403-e96b0029c9fe` | Storage account | Read, write, and delete blobs in all containers |
| Storage Queue Data Contributor | `974c5e8b-45b9-4653-ba55-5f855dd0fb88` | Storage account | Read and write messages in the dead-letter queue |
| ACR Pull | `7f951dda-4ed3-4680-a7ca-43fe172d538d` | Container Registry | Pull (download) container images only — cannot push or delete |
| Cognitive Services User | *(assigned via script)* | Computer Vision + Translator | Call AI service APIs — cannot manage or delete the services |

**Note:** The Managed Identity does **not** have `Owner`, `Contributor`, or any management-plane role. It can only interact with data (blobs, queues, AI APIs) — it cannot create, modify, or delete Azure resources.

---

## External Upload Authentication (Service Principal)

### The Problem

The source system that uploads product images — typically a content management system (CMS), digital asset manager (DAM), or CI/CD pipeline — most likely runs **outside Azure**. It could be on-premise, in another cloud provider, or a SaaS platform. Because it is not running on Azure infrastructure, it cannot use Managed Identity (which requires the Azure fabric to issue tokens). Azure Arc could bridge this gap, but most organisations do not have Arc deployed on their content management infrastructure.

### The Solution: Service Principal with Client Credentials

Create an **Azure AD App Registration** (which generates a Service Principal) and give it narrowly scoped access to upload files to the `ingest` container only.

**Step-by-step:**

1. **Register an application** in Azure Active Directory (Azure Portal → App registrations → New registration).
2. **Create a client secret** (or upload a certificate for production environments). Note the expiry date — secrets must be rotated before they expire.
3. **Assign the RBAC role** `Storage Blob Data Contributor` scoped to the **ingest container only** (not the entire storage account). This ensures the external system can upload images but cannot read approved results from the `public` container or tamper with dead-letter data.
4. **Configure the external system** with three values:
   - `AZURE_TENANT_ID` — your Azure AD tenant
   - `AZURE_CLIENT_ID` — the app registration's Application (client) ID
   - `AZURE_CLIENT_SECRET` — the generated secret (or certificate thumbprint)
5. The external system uses the **OAuth2 client credentials flow** to obtain a Bearer token, then calls the Azure Storage REST API (or uses an Azure SDK) to upload image files and YAML metadata.

### Security Recommendations for the Service Principal

| Recommendation | Why |
|---|---|
| **Use certificates instead of secrets in production** | Certificates are harder to leak than text-based secrets and can be stored in hardware security modules (HSMs) |
| **Scope the role to the `ingest` container only** | Limit blast radius — the external system should not access `public` or `deadletter` containers |
| **Set short secret expiry (90–180 days)** | Forces regular rotation; prevents long-lived credentials from becoming stale attack vectors |
| **Store credentials in a vault** | The external system should retrieve the secret from its own secret manager (e.g., HashiCorp Vault, AWS Secrets Manager) rather than hardcoding it |
| **Monitor sign-in logs** | Azure AD logs every Service Principal authentication; set up alerts for unusual patterns (e.g., login from unexpected IP ranges) |
| **Consider Conditional Access policies** | Restrict the Service Principal to specific IP ranges or networks if possible |

### Alternative: SAS Tokens

For simpler integrations, you can generate a **Shared Access Signature (SAS) token** — a time-limited URL that grants write access to the ingest container without needing a Service Principal. SAS tokens are easier to set up but harder to audit and cannot be revoked once issued (they expire naturally). Use SAS tokens for quick prototyping; use Service Principals for production.

---

## Event Grid Webhook Security

When Event Grid delivers a blob-created notification to the Container App, the communication is secured in two ways:

1. **Endpoint validation handshake:** When the Event Grid subscription is first created, Event Grid sends a validation event containing a unique code. The application must echo this code back in the response to prove it owns the endpoint. This prevents Event Grid from sending events to URLs that haven't opted in. The handler in this system responds to `Microsoft.EventGrid.SubscriptionValidationEvent` automatically.

2. **HTTPS-only delivery:** Event Grid only sends events over HTTPS. The Container App is configured with `allowInsecure: false`, meaning all traffic must use TLS. Combined with Azure-managed TLS certificates on Container Apps, this ensures events cannot be intercepted in transit.

3. **Retry and dead-letter:** If the application is temporarily unavailable, Event Grid retries delivery up to 5 times over 60 minutes. After all retries are exhausted, the event is sent to the `deadletter` blob container for manual investigation. This ensures no events are silently dropped.

---

## Multi-Tenant Session Tokens

The application includes a `/login` endpoint that can issue session tokens scoped to a tenant ID. This is designed for future scenarios where multiple organisations share the same pipeline instance. Each session token encodes the tenant ID, user ID, and a 1-hour expiry. Multi-tenant mode is disabled by default (controlled by the `MULTI_TENANT_ENABLED` environment variable) and is not required for single-tenant deployments.

---

## What Is Not Implemented Yet

The Bicep infrastructure template includes commented-out sections for additional security measures that can be enabled as the system matures:

| Feature | Purpose | When to Enable |
|---|---|---|
| **Azure Key Vault** | Centralised secret management for connection strings and AI endpoint keys | When you want to eliminate the storage connection string from Container Apps environment variables |
| **Private Endpoints** | Restrict storage and AI service access to a private virtual network (VNET) | When the pipeline processes sensitive or regulated data that must not traverse the public internet |
| **Azure Front Door / WAF** | Web Application Firewall and DDoS protection for the Container App's public endpoint | When the `/describe` endpoint is exposed to untrusted callers beyond Event Grid |

---

## Summary Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                     OUTSIDE AZURE                                   │
│                                                                     │
│  ┌──────────────────────┐                                          │
│  │  CMS / DAM / CI/CD   │                                          │
│  │  (Source System)      │                                          │
│  └──────────┬───────────┘                                          │
│             │  Service Principal                                    │
│             │  (client ID + secret or cert)                         │
│             │  OAuth2 client credentials → Bearer token             │
└─────────────┼───────────────────────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       INSIDE AZURE                                  │
│                                                                     │
│  ┌──────────────────┐    Managed Identity    ┌──────────────────┐  │
│  │  Blob Storage     │◄─────────────────────▶│  Container App   │  │
│  │  (ingest/public)  │   (Bearer token for   │  (PHP pipeline)  │  │
│  └──────────────────┘    storage.azure.com)  └───────┬──────────┘  │
│                                                       │            │
│           Managed Identity (Bearer token for          │            │
│           cognitiveservices.azure.com)                 │            │
│                    ┌──────────────────────────────────┘            │
│                    │                                                │
│           ┌────────┼────────┬─────────────────┐                    │
│           ▼        ▼        ▼                 ▼                    │
│  ┌─────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐              │
│  │ AI      │ │ Computer │ │ Trans-   │ │ Container│              │
│  │ Foundry │ │ Vision   │ │ lator    │ │ Registry │              │
│  │ (Phi-4) │ │ (backup) │ │          │ │ (ACR)    │              │
│  └─────────┘ └──────────┘ └──────────┘ └──────────┘              │
│                                                                     │
│  All internal connections: Managed Identity + RBAC                 │
│  No API keys, no passwords, no connection strings in code          │
└─────────────────────────────────────────────────────────────────────┘
```
