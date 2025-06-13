<?php

namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Service for detecting cancelled reservations in the booking system
 */
class CancellationDetectionService
{
    private PDO $db;
    private LoggerInterface $logger;
    private CancellationService $cancellationService;

    public function __construct(PDO $db, LoggerInterface $logger, CancellationService $cancellationService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->cancellationService = $cancellationService;
    }

    /**
     * Detect and process newly cancelled reservations
     * This should be called periodically (e.g., via cron job)
     * 
     * @return array Results of cancellation detection and processing
     */
    public function detectAndProcessCancellations()
    {
        $results = [
            'detected' => 0,
            'processed' => 0,
            'errors' => [],
            'cancelled_events' => [],
            'cancelled_bookings' => [],
            'cancelled_allocations' => []
        ];

        try {
            // Detect cancelled events
            $cancelledEvents = $this->detectCancelledEvents();
            $results['cancelled_events'] = $cancelledEvents;
            $results['detected'] += count($cancelledEvents);

            // Detect cancelled bookings
            $cancelledBookings = $this->detectCancelledBookings();
            $results['cancelled_bookings'] = $cancelledBookings;
            $results['detected'] += count($cancelledBookings);

            // Detect cancelled allocations
            $cancelledAllocations = $this->detectCancelledAllocations();
            $results['cancelled_allocations'] = $cancelledAllocations;
            $results['detected'] += count($cancelledAllocations);

            // Process each detected cancellation
            foreach ($cancelledEvents as $event) {
                $this->processCancellation('event', $event, $results);
            }

            foreach ($cancelledBookings as $booking) {
                $this->processCancellation('booking', $booking, $results);
            }

            foreach ($cancelledAllocations as $allocation) {
                $this->processCancellation('allocation', $allocation, $results);
            }

            $this->logger->info('Completed cancellation detection', $results);

        } catch (\Exception $e) {
            $results['errors'][] = 'Detection failed: ' . $e->getMessage();
            $this->logger->error('Error in cancellation detection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Detect cancelled events (active != 1) that still have active sync mappings
     * 
     * @return array
     */
    private function detectCancelledEvents()
    {
        $sql = "
            SELECT 
                e.id,
                e.name,
                e.active,
                m.resource_id,
                m.id as mapping_id,
                m.outlook_event_id,
                m.sync_status
            FROM bb_event e
            INNER JOIN outlook_calendar_mapping m ON (
                m.reservation_type = 'event' 
                AND m.reservation_id = e.id
                AND m.sync_status NOT IN ('cancelled', 'error')
            )
            WHERE e.active != 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Detect cancelled bookings (active != 1) that still have active sync mappings
     * 
     * @return array
     */
    private function detectCancelledBookings()
    {
        $sql = "
            SELECT 
                b.id,
                b.active,
                m.resource_id,
                m.id as mapping_id,
                m.outlook_event_id,
                m.sync_status
            FROM bb_booking b
            INNER JOIN outlook_calendar_mapping m ON (
                m.reservation_type = 'booking' 
                AND m.reservation_id = b.id
                AND m.sync_status NOT IN ('cancelled', 'error')
            )
            WHERE b.active != 1
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning('bb_booking table may not exist', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Detect cancelled allocations (active != 1) that still have active sync mappings
     * 
     * @return array
     */
    private function detectCancelledAllocations()
    {
        $sql = "
            SELECT 
                a.id,
                a.active,
                m.resource_id,
                m.id as mapping_id,
                m.outlook_event_id,
                m.sync_status
            FROM bb_allocation a
            INNER JOIN outlook_calendar_mapping m ON (
                m.reservation_type = 'allocation' 
                AND m.reservation_id = a.id
                AND m.sync_status NOT IN ('cancelled', 'error')
            )
            WHERE a.active != 1
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning('bb_allocation table may not exist', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Process a detected cancellation
     * 
     * @param string $type
     * @param array $reservation
     * @param array &$results
     */
    private function processCancellation($type, $reservation, &$results)
    {
        try {
            $this->logger->info('Processing detected cancellation', [
                'type' => $type,
                'reservation_id' => $reservation['id'],
                'resource_id' => $reservation['resource_id'],
                'outlook_event_id' => $reservation['outlook_event_id'] ?? null
            ]);

            // Use the CancellationService to handle the cancellation
            $cancellationResult = $this->cancellationService->handleBookingSystemCancellation(
                $type,
                $reservation['id'],
                $reservation['resource_id']
            );

            if ($cancellationResult['success']) {
                $results['processed']++;
                $this->logger->info('Successfully processed cancellation', [
                    'type' => $type,
                    'reservation_id' => $reservation['id'],
                    'outlook_deleted' => $cancellationResult['outlook_deleted']
                ]);
            } else {
                $results['errors'][] = [
                    'type' => $type,
                    'reservation_id' => $reservation['id'],
                    'errors' => $cancellationResult['errors']
                ];
            }

        } catch (\Exception $e) {
            $results['errors'][] = [
                'type' => $type,
                'reservation_id' => $reservation['id'],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check for specific reservation cancellation
     * 
     * @param string $reservationType
     * @param int $reservationId
     * @return array|null
     */
    public function checkReservationCancellation($reservationType, $reservationId)
    {
        try {
            $tableName = $this->getTableNameForType($reservationType);
            
            $sql = "
                SELECT 
                    r.id,
                    r.active,
                    m.resource_id,
                    m.outlook_event_id,
                    m.sync_status
                FROM {$tableName} r
                LEFT JOIN outlook_calendar_mapping m ON (
                    m.reservation_type = :reservation_type 
                    AND m.reservation_id = r.id
                )
                WHERE r.id = :reservation_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'reservation_type' => $reservationType,
                'reservation_id' => $reservationId
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return null;
            }

            return [
                'id' => $result['id'],
                'active' => $result['active'],
                'is_cancelled' => $result['active'] != 1,
                'has_mapping' => !empty($result['resource_id']),
                'has_outlook_event' => !empty($result['outlook_event_id']),
                'sync_status' => $result['sync_status']
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error checking reservation cancellation', [
                'reservation_type' => $reservationType,
                'reservation_id' => $reservationId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get table name for reservation type
     * 
     * @param string $reservationType
     * @return string
     */
    private function getTableNameForType($reservationType)
    {
        switch ($reservationType) {
            case 'event':
                return 'bb_event';
            case 'booking':
                return 'bb_booking';
            case 'allocation':
                return 'bb_allocation';
            default:
                throw new \InvalidArgumentException("Unknown reservation type: $reservationType");
        }
    }

    /**
     * Get statistics about cancellation detection
     * 
     * @return array
     */
    public function getDetectionStats()
    {
        $stats = [
            'potential_cancellations' => [
                'events' => 0,
                'bookings' => 0,
                'allocations' => 0
            ],
            'sync_status_breakdown' => []
        ];

        try {
            // Count cancelled events with active mappings
            $sql = "
                SELECT COUNT(*) as count
                FROM bb_event e
                INNER JOIN outlook_calendar_mapping m ON (
                    m.reservation_type = 'event' 
                    AND m.reservation_id = e.id
                    AND m.sync_status NOT IN ('cancelled', 'error')
                )
                WHERE e.active != 1
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['potential_cancellations']['events'] = (int)$result['count'];

            // Get sync status breakdown
            $sql = "
                SELECT sync_status, COUNT(*) as count
                FROM outlook_calendar_mapping
                GROUP BY sync_status
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $row) {
                $stats['sync_status_breakdown'][$row['sync_status']] = (int)$row['count'];
            }

        } catch (\Exception $e) {
            $this->logger->error('Error getting detection stats', [
                'error' => $e->getMessage()
            ]);
        }

        return $stats;
    }

    /**
     * Mark reservations as cancelled in mapping table without processing
     * This is useful for bulk operations or when you don't want to delete Outlook events
     * 
     * @param array $reservations
     * @return array
     */
    public function markAsCancelled($reservations)
    {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'errors' => []
        ];

        foreach ($reservations as $reservation) {
            try {
                $sql = "
                    UPDATE outlook_calendar_mapping 
                    SET 
                        sync_status = 'cancelled',
                        updated_at = NOW()
                    WHERE reservation_type = :reservation_type 
                        AND reservation_id = :reservation_id 
                        AND resource_id = :resource_id
                ";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'reservation_type' => $reservation['type'],
                    'reservation_id' => $reservation['id'],
                    'resource_id' => $reservation['resource_id']
                ]);

                $results['processed']++;
                $results['successful']++;

            } catch (\Exception $e) {
                $results['processed']++;
                $results['errors'][] = [
                    'reservation' => $reservation,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
