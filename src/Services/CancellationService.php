<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Service for handling cancellations and deletions in bidirectional sync
 */
class CancellationService
{
    private PDO $db;
    private LoggerInterface $logger;
    private CalendarMappingService $mappingService;

    public function __construct(PDO $db, LoggerInterface $logger, CalendarMappingService $mappingService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->mappingService = $mappingService;
    }

    /**
     * Handle cancellation of a booking system reservation
     * This will delete the corresponding Outlook event and update mapping status
     * 
     * @param string $reservationType
     * @param int $reservationId
     * @param int $resourceId
     * @return array Results of the cancellation
     */
    public function handleBookingSystemCancellation($reservationType, $reservationId, $resourceId)
    {
        $results = [
            'success' => false,
            'outlook_deleted' => false,
            'mapping_updated' => false,
            'errors' => []
        ];

        try {
            $this->logger->info('Handling booking system cancellation', [
                'reservation_type' => $reservationType,
                'reservation_id' => $reservationId,
                'resource_id' => $resourceId
            ]);

            // Find the mapping entry
            $mapping = $this->findMappingForReservation($reservationType, $reservationId, $resourceId);
            
            if (!$mapping) {
                $results['errors'][] = 'No mapping found for this reservation';
                return $results;
            }

            // Check if there's a corresponding Outlook event to delete
            if ($mapping['outlook_event_id']) {
                $outlookDeleteResult = $this->deleteOutlookEvent($mapping['outlook_event_id'], $mapping['outlook_item_id']);
                
                if ($outlookDeleteResult['success']) {
                    $results['outlook_deleted'] = true;
                    $this->logger->info('Successfully deleted Outlook event', [
                        'outlook_event_id' => $mapping['outlook_event_id']
                    ]);
                } else {
                    $results['errors'][] = 'Failed to delete Outlook event: ' . $outlookDeleteResult['error'];
                    // Continue anyway to update mapping status
                }
            }

            // Update mapping status to cancelled
            $mappingUpdateResult = $this->updateMappingToCancelled($mapping['id']);
            
            if ($mappingUpdateResult) {
                $results['mapping_updated'] = true;
            } else {
                $results['errors'][] = 'Failed to update mapping status';
            }

            $results['success'] = empty($results['errors']) || $results['mapping_updated'];
            
            $this->logger->info('Completed cancellation handling', $results);
            
            return $results;

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            
            $this->logger->error('Error handling cancellation', [
                'reservation_type' => $reservationType,
                'reservation_id' => $reservationId,
                'error' => $e->getMessage()
            ]);
            
            return $results;
        }
    }

    /**
     * Handle cancellation of an Outlook-originated event
     * This updates the booking system and mapping status
     * 
     * @param string $outlookEventId
     * @return array Results of the cancellation
     */
    public function handleOutlookCancellation($outlookEventId)
    {
        $results = [
            'success' => false,
            'booking_cancelled' => false,
            'mapping_updated' => false,
            'errors' => []
        ];

        try {
            $this->logger->info('Handling Outlook cancellation', [
                'outlook_event_id' => $outlookEventId
            ]);

            // Find the mapping entry
            $mapping = $this->findMappingForOutlookEvent($outlookEventId);
            
            if (!$mapping) {
                $results['errors'][] = 'No mapping found for this Outlook event';
                return $results;
            }

            // If this was an imported event with a booking system entry, cancel it
            if ($mapping['reservation_id'] && $mapping['sync_direction'] === 'outlook_to_booking') {
                $bookingCancelResult = $this->cancelBookingSystemEntry(
                    $mapping['reservation_type'], 
                    $mapping['reservation_id']
                );
                
                if ($bookingCancelResult['success']) {
                    $results['booking_cancelled'] = true;
                } else {
                    $results['errors'][] = 'Failed to cancel booking system entry: ' . $bookingCancelResult['error'];
                }
            }

            // Update mapping status to cancelled
            $mappingUpdateResult = $this->updateMappingToCancelled($mapping['id']);
            
            if ($mappingUpdateResult) {
                $results['mapping_updated'] = true;
            } else {
                $results['errors'][] = 'Failed to update mapping status';
            }

            $results['success'] = empty($results['errors']) || $results['mapping_updated'];
            
            $this->logger->info('Completed Outlook cancellation handling', $results);
            
            return $results;

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            
            $this->logger->error('Error handling Outlook cancellation', [
                'outlook_event_id' => $outlookEventId,
                'error' => $e->getMessage()
            ]);
            
            return $results;
        }
    }

    /**
     * Process bulk cancellations from booking system
     * This is useful for handling multiple cancellations at once
     * 
     * @param array $reservations Array of [type, id, resource_id] arrays
     * @return array Bulk processing results
     */
    public function processBulkCancellations($reservations)
    {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'errors' => []
        ];

        foreach ($reservations as $reservation) {
            if (!isset($reservation['type'], $reservation['id'], $reservation['resource_id'])) {
                $results['errors'][] = [
                    'reservation' => $reservation,
                    'error' => 'Invalid reservation format'
                ];
                continue;
            }

            $result = $this->handleBookingSystemCancellation(
                $reservation['type'],
                $reservation['id'],
                $reservation['resource_id']
            );

            $results['processed']++;
            
            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['errors'][] = [
                    'reservation' => $reservation,
                    'errors' => $result['errors']
                ];
            }
        }

        return $results;
    }

    /**
     * Find mapping entry for a booking system reservation
     * 
     * @param string $reservationType
     * @param int $reservationId
     * @param int $resourceId
     * @return array|null
     */
    private function findMappingForReservation($reservationType, $reservationId, $resourceId)
    {
        $sql = "
            SELECT id, outlook_event_id, outlook_item_id, sync_status, sync_direction
            FROM outlook_calendar_mapping 
            WHERE reservation_type = :reservation_type 
                AND reservation_id = :reservation_id 
                AND resource_id = :resource_id
            LIMIT 1
        ";

        $params = [
            'reservation_type' => $reservationType,
            'reservation_id' => $reservationId,
            'resource_id' => $resourceId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find mapping entry for an Outlook event
     * 
     * @param string $outlookEventId
     * @return array|null
     */
    private function findMappingForOutlookEvent($outlookEventId)
    {
        $sql = "
            SELECT id, reservation_type, reservation_id, resource_id, sync_status, sync_direction
            FROM outlook_calendar_mapping 
            WHERE outlook_event_id = :outlook_event_id
            LIMIT 1
        ";

        $params = ['outlook_event_id' => $outlookEventId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Delete an event from Outlook calendar
     * 
     * @param string $eventId
     * @param string $calendarId
     * @return array
     */
    private function deleteOutlookEvent($eventId, $calendarId)
    {
        try {
            // Get GraphServiceClient from OutlookController
            $outlookController = new \App\Controller\OutlookController();
            $reflection = new \ReflectionClass($outlookController);
            $property = $reflection->getProperty('graphServiceClient');
            $property->setAccessible(true);
            $graphServiceClient = $property->getValue($outlookController);
            
            if (!$graphServiceClient) {
                throw new \Exception('GraphServiceClient not available');
            }

            // Delete the event from Microsoft Graph
            $graphServiceClient->users()->byUserId($calendarId)->events()->byEventId($eventId)->delete()->wait();
            
            return ['success' => true];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel a booking system entry (soft delete)
     * 
     * @param string $reservationType
     * @param int $reservationId
     * @return array
     */
    private function cancelBookingSystemEntry($reservationType, $reservationId)
    {
        try {
            switch ($reservationType) {
                case 'event':
                    return $this->cancelEvent($reservationId);
                case 'booking':
                    return $this->cancelBooking($reservationId);
                case 'allocation':
                    return $this->cancelAllocation($reservationId);
                default:
                    throw new \InvalidArgumentException("Unknown reservation type: $reservationType");
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel an event (set active = 0 and update description)
     * 
     * @param int $eventId
     * @return array
     */
    private function cancelEvent($eventId)
    {
        try {
            // First get the current description to preserve it
            $selectSql = "SELECT description FROM bb_event WHERE id = :event_id";
            $selectStmt = $this->db->prepare($selectSql);
            $selectStmt->execute(['event_id' => $eventId]);
            $result = $selectStmt->fetch(PDO::FETCH_ASSOC);
            
            $currentDescription = $result['description'] ?? '';
            $cancellationNote = "\n\n--- Cancelled from Outlook ---";
            
            // Only add the cancellation note if it's not already there
            if (!str_contains($currentDescription, 'Cancelled from Outlook')) {
                $newDescription = $currentDescription . $cancellationNote;
            } else {
                $newDescription = $currentDescription;
            }
            
            $sql = "UPDATE bb_event SET active = 0, description = :description WHERE id = :event_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'event_id' => $eventId,
                'description' => $newDescription
            ]);
            
            $this->logger->info('Successfully cancelled event from Outlook', [
                'event_id' => $eventId,
                'description_updated' => !str_contains($currentDescription, 'Cancelled from Outlook')
            ]);
            
            return ['success' => true];
        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel event', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel a booking (set active = 0 and update description)
     * 
     * @param int $bookingId
     * @return array
     */
    private function cancelBooking($bookingId)
    {
        try {
            // Check if bb_booking table has a description field
            $checkColumnSql = "
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'bb_booking' AND column_name = 'description'
            ";
            $checkStmt = $this->db->prepare($checkColumnSql);
            $checkStmt->execute();
            $hasDescription = $checkStmt->fetch() !== false;
            
            if ($hasDescription) {
                // Get current description and update it
                $selectSql = "SELECT description FROM bb_booking WHERE id = :booking_id";
                $selectStmt = $this->db->prepare($selectSql);
                $selectStmt->execute(['booking_id' => $bookingId]);
                $result = $selectStmt->fetch(PDO::FETCH_ASSOC);
                
                $currentDescription = $result['description'] ?? '';
                $cancellationNote = "\n\n--- Cancelled from Outlook ---";
                
                if (!str_contains($currentDescription, 'Cancelled from Outlook')) {
                    $newDescription = $currentDescription . $cancellationNote;
                } else {
                    $newDescription = $currentDescription;
                }
                
                $sql = "UPDATE bb_booking SET active = 0, description = :description WHERE id = :booking_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'booking_id' => $bookingId,
                    'description' => $newDescription
                ]);
            } else {
                // No description field, just set active = 0
                $sql = "UPDATE bb_booking SET active = 0 WHERE id = :booking_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['booking_id' => $bookingId]);
            }
            
            $this->logger->info('Successfully cancelled booking from Outlook', [
                'booking_id' => $bookingId,
                'description_updated' => $hasDescription
            ]);
            
            return ['success' => true];
        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel booking', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel an allocation (set active = 0 and update description)
     * 
     * @param int $allocationId
     * @return array
     */
    private function cancelAllocation($allocationId)
    {
        try {
            // Check if bb_allocation table has a description field
            $checkColumnSql = "
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'bb_allocation' AND column_name = 'description'
            ";
            $checkStmt = $this->db->prepare($checkColumnSql);
            $checkStmt->execute();
            $hasDescription = $checkStmt->fetch() !== false;
            
            if ($hasDescription) {
                // Get current description and update it
                $selectSql = "SELECT description FROM bb_allocation WHERE id = :allocation_id";
                $selectStmt = $this->db->prepare($selectSql);
                $selectStmt->execute(['allocation_id' => $allocationId]);
                $result = $selectStmt->fetch(PDO::FETCH_ASSOC);
                
                $currentDescription = $result['description'] ?? '';
                $cancellationNote = "\n\n--- Cancelled from Outlook ---";
                
                if (!str_contains($currentDescription, 'Cancelled from Outlook')) {
                    $newDescription = $currentDescription . $cancellationNote;
                } else {
                    $newDescription = $currentDescription;
                }
                
                $sql = "UPDATE bb_allocation SET active = 0, description = :description WHERE id = :allocation_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'allocation_id' => $allocationId,
                    'description' => $newDescription
                ]);
            } else {
                // No description field, just set active = 0
                $sql = "UPDATE bb_allocation SET active = 0 WHERE id = :allocation_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['allocation_id' => $allocationId]);
            }
            
            $this->logger->info('Successfully cancelled allocation from Outlook', [
                'allocation_id' => $allocationId,
                'description_updated' => $hasDescription
            ]);
            
            return ['success' => true];
        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel allocation', [
                'allocation_id' => $allocationId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update mapping status to cancelled
     * 
     * @param int $mappingId
     * @return bool
     */
    private function updateMappingToCancelled($mappingId)
    {
        try {
            $sql = "
                UPDATE outlook_calendar_mapping 
                SET 
                    sync_status = 'cancelled',
                    updated_at = NOW()
                WHERE id = :mapping_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['mapping_id' => $mappingId]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update mapping to cancelled', [
                'mapping_id' => $mappingId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get statistics about cancellations
     * 
     * @return array
     */
    public function getCancellationStats()
    {
        $sql = "
            SELECT 
                sync_direction,
                COUNT(*) as cancelled_count
            FROM outlook_calendar_mapping 
            WHERE sync_status = 'cancelled'
            GROUP BY sync_direction
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'total_cancelled' => 0,
            'booking_to_outlook' => 0,
            'outlook_to_booking' => 0
        ];

        foreach ($results as $row) {
            $stats['total_cancelled'] += $row['cancelled_count'];
            $stats[$row['sync_direction']] = (int)$row['cancelled_count'];
        }

        return $stats;
    }
}
