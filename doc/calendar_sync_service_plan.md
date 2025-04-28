# Calendar Synchronization Service Planning Document

## 1. **Objective**
Synchronize room bookings between the internal booking system and Outlook (Microsoft 365) room calendars, ensuring both systems reflect the current state of all room reservations.

---

## 2. **Scope**
- Calendars involved: Outlook, booking system
- The types of events: create, update, delete, recurring events, all-day events, cancellations.
- The types of resources: rooms and equipment.
- The types of users: internal for Outlook, external and internal for the booking system.
- The types of events: meetings, appointments, etc.

---

## 3. **Direction of Synchronization**
- [X] Outlook → Booking System
- [X] Booking System → Outlook
- [X] Both directions (bi-directional)

---

## 4. **Triggers**
- The booking system should detect changes in Outlook by subscribing to Microsoft Graph webhooks.
- How changes should be detected in the booking system: Modify the booking system to emit events (e.g., via a message queue like RabbitMQ, Kafka, or even Redis) whenever a booking is created, updated, or deleted.
- The sync-service subscribes to these events and processes them in real time.
- Fallback reconciliation should run as a cron job every X minutes

---

## 5. **Data Mapping**
- A table to list rooms/resources and their corresponding identifiers in both systems.
- There are three levels of events in the booking system:
  - **Allocation**: A recurring timeslot is allocated to an organisation.
  - **Booking**: The organisation refines the allocation to sub groups within the limitation of the allocation.
  - **Event**: This is a specific instance of a booking that occurs in the room.
  - All three levels are represented in the booking system, but only the event is represented in Outlook.
  - All three levels can occur at the same time, but they are prioritized as follows.
	- Event
	- Booking
	- Allocation
- One way to list all levels is to establish a database view as a union of the three levels.
- I might be able to map the three levels by type and id to the same event in Outlook, but I need to check if this is possible.
- I will also need to consider how to handle updates to recurring events in both systems.
- Timezone differences and how they are represented in both systems.
- Fields to be synchronized: title, time, organizer

---

## 6. **Conflict Resolution**
- What happens if the same room is booked in both systems at the same time?
- Which system is authoritative, or how are conflicts resolved?

---

## 7. **Loop Prevention**
- How do we prevent infinite sync loops when an event is updated by the sync itself?

---

## 8. **Error Handling & Logging**
- How are errors handled and retried?
- Where are sync actions and errors logged?

---

## 9. **Security & Permissions**
- What permissions are required for Microsoft Graph?
- How is authentication handled for both systems?

---

## 10. **Architecture Overview**
- This will be a standalone service, that also can be triggered by a cron job.
- It will be a microservice that can be deployed independently.
- It will be a RESTful service that can be called by the booking system and Outlook.
- I want to use PHP and Microsoft Graph SDK for the service.
- I want to use php SLIM framework for the service.
- I will use a database to store the state of the sync and any errors that occur.
- I will use a message queue to handle the sync events and ensure they are processed in order.
- I will use a logging library to log the sync events and errors.
- I will use a monitoring tool to monitor the service and alert me if there are any issues.
- I will use a CI/CD pipeline to deploy the service and run tests.
- I will use a containerization tool to package the service and its dependencies.
- How will the service be deployed and monitored?

---

## 11. **Edge Cases & Special Scenarios**
- Recurring events
- All-day events
- Cancellations
- Time zone differences
- Room unavailability

---

## 12. **Next Steps**
- [ ] Fill in each section with current understanding
- [ ] Review and refine with stakeholders
- [ ] Identify unknowns and research as needed

---

*Use this file as a living document. Update and refine as you clarify requirements and design decisions. When ready, submit the updated file for further planning or code generation.*