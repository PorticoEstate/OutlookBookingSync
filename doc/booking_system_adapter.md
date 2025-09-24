# Booking System Adapter Integration Guide

This guide is for teams adapting an existing (or new) booking / reservation system to work with the Generic Calendar Bridge. It consolidates the booking-system–specific parts that were formerly embedded in `README_BRIDGE.md`.

Contents

- Purpose & Data Flow
- Required REST API Endpoints
- Event & Resource Data Model Expectations
- Optional Webhook Contract
- Ownership & sync_direction (Quick Reference)
- Resource Mapping Usage
- Deletions, Cancellations & Re‑Enabling
- Operating Without Webhooks (Polling Mode)
- Testing Checklist
- Security & Hardening Tips

---
## Purpose & Data Flow

High level:

1. Bridge discovers or you supply booking resources (rooms, equipment, etc.).
2. You create resource mappings specifying `bridge_from`, `bridge_to`, IDs, and `sync_direction` ownership.
3. Scheduled syncs (or webhook events) pull changes from owner side, transform, and push to the other side.
4. Deletions / cancellations and inactive events are reconciled to keep parity.

Your booking system acts as one bridge endpoint (logical name `booking_system`). The bridge synchronizes events between it and a target calendar system (e.g. Outlook). Resource (calendar) mappings define which booking resources map to which target calendars and what the ownership model is.

See `architecture.md` for core concepts and `usage.md` for general flows.

---
## Required REST API Endpoints

If you implement REST (recommended), provide these endpoints. Names are suggestions; adapt as needed but keep semantics.

1. List Resources

```http
GET /api/resources
```

Response (example fields):

```json
{
  "success": true,
  "resources": [
    { "id": "123", "name": "Conference Room 1", "type": "room", "capacity": 12, "active": true }
  ],
  "count": 1
}
```

1. List Events for Resource

```http
GET /api/resources/{resourceId}/events?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
```

Response (trimmed):

```json
{
  "success": true,
  "resource_id": "123",
  "events": [
    {
      "id": "456",
      "title": "Team Meeting",
      "start_time": "2025-06-14T10:00:00Z",
      "end_time": "2025-06-14T11:00:00Z",
      "status": "confirmed",
      "created_at": "2025-06-13T09:00:00Z",
      "updated_at": "2025-06-13T09:00:00Z"
    }
  ]
}
```

1. Create Event

```http
POST /api/resources/{resourceId}/events
```

Request (common fields):

```json
{
  "title": "New Meeting",
  "start_time": "2025-06-15T14:00:00Z",
  "end_time": "2025-06-15T15:00:00Z",
  "description": "Client meeting",
  "contact_name": "Jane Smith",
  "contact_email": "jane@company.com",
  "attendees": ["jane@company.com", "client@external.com"],
  "source": "calendar_bridge",
  "bridge_import": true
}
```

1. Update Event

```http
PUT /api/resources/{resourceId}/events/{eventId}
```

1. Delete Event (soft delete acceptable)

```http
DELETE /api/resources/{resourceId}/events/{eventId}
```

1. (Optional) Webhook Subscription Management

```http
POST /api/webhooks/subscribe
DELETE /api/webhooks/{subscriptionId}
```

### Minimal Event Fields

| Field | Purpose |
|-------|---------|
| id | Unique event identifier |
| title / name | Human readable summary |
| start_time / end_time | ISO8601 timestamps (UTC preferred) |
| description | Optional notes |
| contact_name / contact_email | Organizer/contact info |
| status | e.g. confirmed / tentative / cancelled |
| created_at / updated_at | For incremental change detection |
| bridge_import (bool) | Flag events created by the bridge |

### Optional Enhancements

- Attendees list
- Recurrence pattern (future support)
- Custom metadata fields (namespaced)

---
## Webhook Contract (Optional)


If you implement webhooks, POST notifications to:

```http
POST {BRIDGE_BASE_URL}/bridges/webhook/booking_system
Content-Type: application/json
```
Payload example:

```json
{
  "action": "updated",  "resource_id": "123",  "event_id": "456",
  "event": { "id": "456", "title": "New Title", "start_time": "2025-06-15T10:00:00Z", "end_time": "2025-06-15T11:00:00Z" },
  "timestamp": "2025-06-14T10:00:00Z",
  "source": "booking_system"
}
```
Actions: `created`, `updated`, `deleted`.


If you do not supply webhooks, the bridge polling + cron model (see `operations.md`) provides near real‑time sync.

---
 
## Ownership & sync_direction (Quick Reference)


| sync_direction | Owner | Non-Owner Behavior | Deletion Recreation |
|----------------|-------|--------------------|---------------------|
| source_to_target | Source (booking system) | Target updates skipped | Yes (target recreated) |
| target_to_source | Target (Outlook) | Source updates skipped | Yes (source recreated) |
| bidirectional | Shared | Both modify | Policy-based |

Select per mapping. See `architecture.md` for deeper discussion.

---
 
## Resource Mapping Usage


Create a mapping:

```http
POST /mappings/resources
```
Body example:

```json
{
  "bridge_from": "booking_system",
  "bridge_to": "outlook",
  "source_calendar_id": "room_123",
  "target_calendar_id": "conference-room-a@company.com",
  "sync_direction": "source_to_target"
}
```

> **Note**: When mapping to Outlook bridge, the `target_calendar_id` must be in email format (e.g., `room@company.com`). Booking system `source_calendar_id` must use integer format (e.g., `123`).

List mappings for a resource:

```http
GET /mappings/resources/by-resource/{resourceId}?bridge_from=booking_system
```
Trigger sync for a mapping:

```http
POST /mappings/resources/{id}/sync
```
Full endpoint details: `api_endpoints.md`.

---
 
## Deletions, Cancellations & Re‑Enabling



Scenarios handled automatically:

| Scenario | Detection Mechanism | Result |
|----------|---------------------|--------|
| Outlook event deleted | Webhook or periodic deletion sweep | Booking event soft-deleted / inactive |
| Booking event set inactive (`active=0`) | Cancellation detection in deletion sweep | Outlook event deleted |
| Booking event reactivated (`active=1`) | Reactivation detection | Outlook event recreated |

Primary endpoints / jobs:

| Purpose | Endpoint |
|---------|----------|
| Deletion + cancellation sweep | `POST /bridges/sync-deletions` |
| Process webhook deletion queue | `POST /bridges/process-deletion-queue` |
| Cancelled events listing | `GET /bridges/cancelled-events` |
| Sync statistics | `GET /bridges/sync-stats` |

Implementation tips:

- Use a soft delete (`active` flag) in booking DB; bridge interprets inactive as cancellation.
- Ensure events created by the bridge set a marker (e.g. `bridge_import`) to aid troubleshooting.
- Keep `updated_at` accurate; bridge uses it to decide if changes need propagation.

---
 
## Operating Without Webhooks (Polling Mode)


Webhook-free mode is fully supported and often simpler for internal systems. Recommended cron examples (adjust tenant & key):

```bash
*/5 * * * * curl -s -X POST -H "X-API-Key: <key>" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync/booking_system/outlook
*/10 * * * * curl -s -X POST -H "X-API-Key: <key>" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync/outlook/booking_system
*/5 * * * * curl -s -X POST -H "X-API-Key: <key>" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/sync-deletions
*/5 * * * * curl -s -X POST -H "X-API-Key: <key>" -H "X-Tenant-Id: tenantA" http://localhost:8082/bridges/process-deletion-queue
```
See `operations.md` for complete operational guidance.

---
 
## Testing Checklist


| Test | Expected Outcome |
|------|------------------|
| List resources | Returns active resources with IDs & names |
| List events window | Correct events inside [start,end] only |
| Create event (owner side) | Event appears on target system |
| Update event title | Title propagates respecting ownership |
| Delete event (owner) | Event removed from opposite side |
| Set booking event inactive | Outlook event deleted & mapping updated |
| Reactivate booking event | Outlook event recreated |
| Ownership violation attempt | Logged skip, no change |
| Deletion sweep run | Queue processed, stats updated |

---
 
## Security & Hardening Tips


| Area | Recommendation |
|------|---------------|
| Authentication | Protect booking API with its own auth (token / mTLS) besides bridge X-API-Key |
| Webhooks | Sign payloads or IP restrict (if implemented) |
| PII | Avoid unnecessary personal data in event payloads |
| Rate Limiting | Implement server-side throttling for create/update bursts |
| Logging | Exclude secrets and attendee emails where not needed |

See `security_hardening.md` for broader bridge security layers.

---
 
## Reference Links

- Architecture overview: `architecture.md`
- Usage flows: `usage.md`
- Operations & cron: `operations.md`
- API endpoints matrix: `api_endpoints.md`
- Multi-tenancy: `multi_tenancy.md`
- Security: `security_hardening.md`

---
Maintained as the authoritative specification for integrating a booking system with the Generic Calendar Bridge.
