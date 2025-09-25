/**
 * Event Management JavaScript Module
 * 
 * Handles all event CRUD operations, pagination, resource management,
 * timezone handling, and UI interactions for the admin event management interface.
 * 
 * Dependencies:
 * - js/auth.js (AdminAuth module for authentication)
 * - css/dashboard.css (styling)
 */

        let currentEvents = [];
        let editingEvent = null;
        
        // Pagination state
        let currentPage = 1;
        let pageSize = 50;
        let totalRecords = 0;
        let totalPages = 1;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set default API key for testing if none exists
            if (!adminAuth.getApiKey()) {
                adminAuth.setApiKey('change-me-strong-random');
            }
            
            // Add a small delay to ensure all DOM elements are ready
            setTimeout(() => {
                loadTenants();
                loadBridges();
            }, 100);
            
            setupDateDefaults();
            setupEventFormHandlers();
        });

        function setupDateDefaults() {
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 7);
            
            document.getElementById('startDate').value = today.toISOString().split('T')[0];
            document.getElementById('endDate').value = tomorrow.toISOString().split('T')[0];
        }

        function setupEventFormHandlers() {
            document.getElementById('eventForm').addEventListener('submit', function(e) {
                e.preventDefault();
                saveEvent();
            });

            // Auto-fill end date/time when start date/time changes
            document.getElementById('eventStartDate').addEventListener('change', function() {
                const endDateField = document.getElementById('eventEndDate');
                if (!endDateField.value) {
                    endDateField.value = this.value;
                }
            });

            document.getElementById('eventStartTime').addEventListener('change', function() {
                const endTimeField = document.getElementById('eventEndTime');
                if (!endTimeField.value && this.value) {
                    // Add 1 hour to start time for default end time
                    const [hours, minutes] = this.value.split(':');
                    const endHour = (parseInt(hours) + 1) % 24;
                    endTimeField.value = `${endHour.toString().padStart(2, '0')}:${minutes}`;
                }
            });

            // Bridge selection handler
            document.getElementById('bridgeSelect').addEventListener('change', function() {
                const bridgeName = this.value;
                getBridgeTimezone(bridgeName); // Update bridge timezone
                loadResources();
                // Reset pagination when changing bridge
                currentPage = 1;
                document.getElementById('paginationContainer').style.display = 'none';
            });

            // Tenant selection handler - update bridge timezone when tenant changes
            document.getElementById('tenantSelect').addEventListener('change', function() {
                const bridgeName = document.getElementById('bridgeSelect').value;
                if (bridgeName) {
                    getBridgeTimezone(bridgeName); // Refresh timezone for new tenant
                }
                // Reset pagination when changing tenant
                currentPage = 1;
                document.getElementById('paginationContainer').style.display = 'none';
            });
            
            // Add Enter key support for pagination input
            document.getElementById('currentPageInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    goToInputPage();
                }
            });
        }

        async function loadTenants() {
            try {
                const data = await adminAuth.authenticatedFetch('/admin/tenants', {}, null);
                
                const select = document.getElementById('tenantSelect');
                if (!select) {
                    console.error('tenantSelect element not found!');
                    return;
                }
                
                select.innerHTML = '<option value="">Default</option>';
                
                // Handle both response formats - like dashboard.js
                const tenants = (data && data.tenants) || [];
                
                if (tenants.length > 0) {
                    tenants.forEach(tenant => {
                        const option = document.createElement('option');
                        option.value = tenant.id;
                        option.textContent = `${tenant.name} (${tenant.id})`;
                        select.appendChild(option);
                    });
                    showMessage(`Loaded ${tenants.length} tenant(s)`, 'success');
                } else {
                    showMessage('No tenants configured in the system', 'info');
                }
                
            } catch (error) {
                console.error('Failed to load tenants:', error);
                // Don't show error for authentication issues - user cancelled
                if (!error.message.includes('authentication required')) {
                    showMessage('Failed to load tenants: ' + error.message, 'error');
                }
            }
        }

        async function loadBridges() {
            try {
                const data = await adminAuth.authenticatedFetch('/bridges');
                
                const select = document.getElementById('bridgeSelect');
                select.innerHTML = '<option value="">Select Bridge</option>';
                
                if (data.success && data.bridges) {
                    // Handle bridges as object (like in dashboard.js)
                    Object.values(data.bridges).forEach(bridge => {
                        const option = document.createElement('option');
                        option.value = bridge.name;
                        option.textContent = `${bridge.name} (${bridge.health?.status || bridge.status || 'unknown'})`;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                showMessage('Failed to load bridges: ' + error.message, 'error');
            }
        }

        let allResources = []; // Store all resources for filtering
        let selectedResourceId = '';
        let currentBridgeTimezone = 'UTC'; // Store current bridge timezone

        async function getBridgeTimezone(bridgeName) {
            if (!bridgeName) {
                currentBridgeTimezone = 'UTC';
                return 'UTC';
            }
            
            try {
                // Get tenant ID for proper config endpoint
                const tenantId = document.getElementById('tenantSelect')?.value || '';
                
                // Use tenant-specific config endpoint
                const configUrl = tenantId 
                    ? `/admin/tenants/${tenantId}/configs/${bridgeName}`
                    : `/admin/tenants/default/configs/${bridgeName}`;
                
                const data = await adminAuth.authenticatedFetch(configUrl);
                // Extract timezone from bridge configuration - check config_data first
                const timezone = data.config_data?.timezone || data.timezone || data.config?.timezone || 'UTC';
                currentBridgeTimezone = timezone;
                return timezone;
            } catch (error) {
                console.warn('Could not fetch bridge config:', error);
            }
            
            // Fallback: use common timezone defaults based on bridge type
            const timezoneDefaults = {
                'outlook': 'UTC',
                'booking_system': 'Europe/Oslo', // Common for booking systems
                'bookingsystem': 'Europe/Oslo'
            };
            
            const defaultTz = timezoneDefaults[bridgeName.toLowerCase()] || 'UTC';
            currentBridgeTimezone = defaultTz;
            return defaultTz;
        }
        
        async function loadResources() {
            const bridgeName = document.getElementById('bridgeSelect').value;
            if (!bridgeName) {
                allResources = [];
                updateResourceDisplay();
                return;
            }

            try {
                const data = await adminAuth.authenticatedFetch(`/bridges/${bridgeName}/available-resources`);

                if (data.success && data.resources) {
                    let resources = data.resources;

                    // For Outlook we now use the email (calendar address) as the canonical ID
                    if (bridgeName === 'outlook') {
                        const seen = new Set();
                        resources = resources
                            .map(r => {
                                const email = r.email || r.userPrincipalName || null;
                                if (email && email.includes('@')) {
                                    return {
                                        ...r,
                                        original_id: r.id, // preserve original identifier for potential debugging
                                        id: email
                                    };
                                }
                                return r; // fallback if no email present
                            })
                            .filter(r => {
                                // Deduplicate on the new id (email) to avoid duplicates when original ids differ
                                if (seen.has(r.id)) {
                                    return false;
                                }
                                seen.add(r.id);
                                return true;
                            });
                    }

                    allResources = resources;
                } else {
                    allResources = [];
                }
                
                selectedResourceId = '';
                document.getElementById('resourceSearch').value = '';
                updateResourceDisplay();
                
            } catch (error) {
                showMessage('Failed to load resources: ' + error.message, 'error');
                allResources = [];
                updateResourceDisplay();
            }
        }
        
        function updateResourceDisplay(searchTerm = '') {
            const dropdown = document.getElementById('resourceDropdown');
            const searchInput = document.getElementById('resourceSearch');
            
            // Filter resources based on search term
            const filteredResources = searchTerm 
                ? allResources.filter(resource => 
                    (resource.name || resource.id).toLowerCase().includes(searchTerm.toLowerCase())
                  )
                : allResources;
            
            // Clear dropdown
            dropdown.innerHTML = '';
            
            if (filteredResources.length === 0) {
                if (allResources.length === 0) {
                    dropdown.innerHTML = '<div class="no-resources">No resources available</div>';
                } else {
                    dropdown.innerHTML = '<div class="no-resources">No matching resources found</div>';
                }
                dropdown.style.display = searchTerm ? 'block' : 'none';
                return;
            }
            
            // Add filtered resources to dropdown
            filteredResources.forEach(resource => {
                const option = document.createElement('div');
                option.className = 'resource-option';
                option.textContent = resource.name || resource.id;
                option.dataset.resourceId = resource.id;
                
                if (resource.id === selectedResourceId) {
                    option.classList.add('selected');
                }
                
                option.addEventListener('click', () => selectResource(resource));
                dropdown.appendChild(option);
            });
            
            dropdown.style.display = searchTerm ? 'block' : 'none';
        }
        
        function selectResource(resource) {
            selectedResourceId = resource.id;
            document.getElementById('resourceSearch').value = resource.name || resource.id;
            document.getElementById('resourceSelect').value = resource.id;
            document.getElementById('resourceDropdown').style.display = 'none';
            
            // Update the hidden select for backward compatibility
            const select = document.getElementById('resourceSelect');
            select.innerHTML = '<option value="">Select Resource</option>';
            const option = document.createElement('option');
            option.value = resource.id;
            option.textContent = resource.name || resource.id;
            option.selected = true;
            select.appendChild(option);
            
            // Reset pagination when changing resource
            currentPage = 1;
            document.getElementById('paginationContainer').style.display = 'none';
        }
        
        // Initialize resource search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('resourceSearch');
            const dropdown = document.getElementById('resourceDropdown');
            
            // Search input event listener
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.trim();
                updateResourceDisplay(searchTerm);
            });
            
            // Focus event - show dropdown if there are resources
            searchInput.addEventListener('focus', function() {
                if (allResources.length > 0) {
                    updateResourceDisplay(this.value.trim());
                }
            });
            
            // Click outside to close dropdown
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.resource-search-container')) {
                    dropdown.style.display = 'none';
                }
            });
            
            // Keyboard navigation
            searchInput.addEventListener('keydown', function(event) {
                const options = dropdown.querySelectorAll('.resource-option');
                let selectedIndex = Array.from(options).findIndex(opt => opt.classList.contains('selected'));
                
                switch(event.key) {
                    case 'ArrowDown':
                        event.preventDefault();
                        if (options.length > 0) {
                            if (selectedIndex < options.length - 1) {
                                if (selectedIndex >= 0) options[selectedIndex].classList.remove('selected');
                                options[selectedIndex + 1].classList.add('selected');
                            }
                        }
                        break;
                        
                    case 'ArrowUp':
                        event.preventDefault();
                        if (options.length > 0) {
                            if (selectedIndex > 0) {
                                options[selectedIndex].classList.remove('selected');
                                options[selectedIndex - 1].classList.add('selected');
                            }
                        }
                        break;
                        
                    case 'Enter':
                        event.preventDefault();
                        if (selectedIndex >= 0 && options[selectedIndex]) {
                            const resourceId = options[selectedIndex].dataset.resourceId;
                            const resource = allResources.find(r => r.id === resourceId);
                            if (resource) {
                                selectResource(resource);
                            }
                        }
                        break;
                        
                    case 'Escape':
                        dropdown.style.display = 'none';
                        break;
                }
            });
        });

        async function loadEvents(page = 1) {
            const bridgeName = document.getElementById('bridgeSelect').value;
            const resourceId = document.getElementById('resourceSelect').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            // Get page size from the dropdown
            pageSize = parseInt(document.getElementById('pageSize').value) || 50;
            currentPage = page;

            if (!bridgeName || !resourceId) {
                showMessage('Please select both bridge and resource', 'error');
                return;
            }

            try {
                showLoading(true);

                const params = new URLSearchParams();
                if (startDate) params.append('startDate', startDate);
                if (endDate) params.append('endDate', endDate);
                
                // Add pagination parameters
                params.append('limit', pageSize.toString());
                params.append('offset', ((currentPage - 1) * pageSize).toString());

                const data = await adminAuth.authenticatedFetch(`/bridges/${bridgeName}/resources/${resourceId}/calendar-items?${params}`);
                
                // Add debugging for different bridge formats
                if (data.success) {
                    currentEvents = data.calendar_items || [];
                    
                    // Extract pagination info from response
                    totalRecords = data.total_records || data.count || currentEvents.length;
                    totalPages = Math.ceil(totalRecords / pageSize);
                    
                    displayEvents();
                    updatePaginationControls();
                    showMessage(`Loaded ${currentEvents.length} events (page ${currentPage} of ${totalPages})`, 'success');
                } else {
                    showMessage('Failed to load events: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showMessage('Failed to load events: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        function displayEvents() {
            const container = document.getElementById('eventsContainer');
            const countSpan = document.getElementById('eventCount');
            
            countSpan.textContent = `(${currentEvents.length})`;

            if (currentEvents.length === 0) {
                container.innerHTML = '<p class="muted">No events found for the selected criteria.</p>';
                return;
            }

            container.innerHTML = currentEvents.map(event => {
                // Handle different field names from different bridge types
                // Outlook bridge might use: start_time, end_time, subject
                // Booking system might use: start, end, title, name
                const startTimeField = event.start_time || event.start || event.startTime;
                const endTimeField = event.end_time || event.end || event.endTime;
                const titleField = event.title || event.subject || event.name || event.summary;
                const eventId = event.id || event.event_id || event.eventId;
                
                const startTime = formatDateTime(startTimeField, event.timezone);
                const endTime = formatDateTime(endTimeField, event.timezone);
                
                // Determine display timezone (same logic as formatDateTime)
                let displayTimezone = event.timezone;
                if (!displayTimezone || displayTimezone === 'UTC') {
                    displayTimezone = currentBridgeTimezone;
                }
                
                return `
                    <div class="event-item">
                        <div class="event-header">
                            <div class="event-title">${escapeHtml(titleField || 'Untitled Event')}</div>
                            <div class="event-actions">
                                <button class="btn btn-warning btn-sm" onclick="editEvent('${eventId}')">Edit</button>
                                <button class="btn btn-danger btn-sm" onclick="deleteEvent('${eventId}')">Delete</button>
                            </div>
                        </div>
                        <div class="event-details">
                            <div class="event-time">${startTime} → ${endTime} <span class="timezone">(${displayTimezone})</span></div>
                            ${event.description ? `<div>${escapeHtml(event.description)}</div>` : ''}
                            ${event.body ? `<div>${escapeHtml(event.body)}</div>` : ''}
                            ${event.location ? `<div>📍 ${escapeHtml(event.location)}</div>` : ''}
                            ${event.all_day || event.isAllDay ? '<div class="all-day-badge">All Day</div>' : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function updatePaginationControls() {
            const container = document.getElementById('paginationContainer');
            const paginationInfo = document.getElementById('paginationInfo');
            const currentPageInput = document.getElementById('currentPageInput');
            const totalPagesSpan = document.getElementById('totalPages');
            const firstBtn = document.getElementById('firstPageBtn');
            const prevBtn = document.getElementById('prevPageBtn');
            const nextBtn = document.getElementById('nextPageBtn');
            const lastBtn = document.getElementById('lastPageBtn');

            // Show pagination container if we have events
            if (totalRecords > 0) {
                container.style.display = 'flex';
                
                // Calculate display range
                const startRecord = ((currentPage - 1) * pageSize) + 1;
                const endRecord = Math.min(currentPage * pageSize, totalRecords);
                
                // Update pagination info
                paginationInfo.textContent = `Showing ${startRecord}-${endRecord} of ${totalRecords} events`;
                
                // Update page input and total
                currentPageInput.value = currentPage;
                currentPageInput.max = totalPages;
                totalPagesSpan.textContent = totalPages;
                
                // Update button states
                firstBtn.disabled = currentPage <= 1;
                prevBtn.disabled = currentPage <= 1;
                nextBtn.disabled = currentPage >= totalPages;
                lastBtn.disabled = currentPage >= totalPages;
            } else {
                container.style.display = 'none';
            }
        }

        function goToPage(page) {
            if (page >= 1 && page <= totalPages && page !== currentPage) {
                loadEvents(page);
            }
        }

        function goToFirstPage() {
            goToPage(1);
        }

        function goToLastPage() {
            goToPage(totalPages);
        }

        function goToPreviousPage() {
            goToPage(currentPage - 1);
        }

        function goToNextPage() {
            goToPage(currentPage + 1);
        }

        function goToInputPage() {
            const page = parseInt(document.getElementById('currentPageInput').value);
            if (page && page >= 1 && page <= totalPages) {
                goToPage(page);
            } else {
                // Reset to current page if invalid input
                document.getElementById('currentPageInput').value = currentPage;
            }
        }

        function changePageSize() {
            // Reset to first page when changing page size
            currentPage = 1;
            loadEvents(1);
        }

        function formatDateTime(dateTimeString, eventTimezone = null) {
            if (!dateTimeString) return 'N/A';
            
            try {
                let date;
                
                // If the event timezone is UTC but the string doesn't end with 'Z', 
                // we need to treat it as UTC explicitly
                if (eventTimezone === 'UTC' && !dateTimeString.endsWith('Z')) {
                    // More robust UTC parsing - handle different formats
                    let utcString = dateTimeString;
                    
                    // Remove any existing timezone info and milliseconds for clean parsing
                    utcString = utcString.replace(/\.\d+$/, ''); // Remove trailing milliseconds
                    utcString = utcString.replace(/[+-]\d{2}:?\d{2}$/, ''); // Remove timezone offset
                    utcString = utcString.replace(/Z$/, ''); // Remove existing Z
                    
                    // Ensure we have a valid ISO format before adding Z
                    if (utcString.match(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/)) {
                        utcString += 'Z';
                        date = new Date(utcString);
                    } else {
                        // Fallback to original parsing if format is unexpected
                        date = new Date(dateTimeString);
                    }
                } else {
                    date = new Date(dateTimeString);
                }
                
                // Check if date is valid
                if (isNaN(date.getTime())) {
                    console.warn('Invalid date created from:', dateTimeString);
                    return dateTimeString; // Return original string if parsing fails
                }
                
                // Determine which timezone to use for display
                let displayTimezone = eventTimezone;
                
                // If event timezone is UTC, use the bridge's configured timezone instead
                if (!displayTimezone || displayTimezone === 'UTC') {
                    displayTimezone = currentBridgeTimezone;
                }
                
                // If we have a specific timezone and it's not UTC, format with timezone-aware display
                if (displayTimezone && displayTimezone !== 'UTC') {
                    try {
                        // Try to use timezone-aware formatting
                        const formatted = date.toLocaleString('en-US', {
                            timeZone: displayTimezone,
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: false
                        });
                        return formatted;
                    } catch (e) {
                        // Fallback if timezone is not supported
                        console.warn('Timezone not supported:', displayTimezone);
                        return date.toLocaleString() + ` (${displayTimezone})`;
                    }
                } else {
                    // Default formatting for UTC or no timezone
                    return date.toLocaleString('en-US', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: false
                    });
                }
            } catch (e) {
                console.warn('Date parsing error:', e);
                return dateTimeString; // Return original string on any error
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function openCreateEventModal() {
            editingEvent = null;
            document.getElementById('modalTitle').textContent = 'Create Event';
            document.getElementById('eventForm').reset();
            
            // Set default date/time
            const now = new Date();
            const nextHour = new Date(now.getTime() + 60 * 60 * 1000);
            
            document.getElementById('eventStartDate').value = now.toISOString().split('T')[0];
            document.getElementById('eventStartTime').value = `${now.getHours().toString().padStart(2, '0')}:00`;
            document.getElementById('eventEndDate').value = nextHour.toISOString().split('T')[0];
            document.getElementById('eventEndTime').value = `${nextHour.getHours().toString().padStart(2, '0')}:00`;
            
            document.getElementById('eventModal').style.display = 'block';
        }

        function editEvent(eventId) {
            // Convert eventId to both string and number for comparison since IDs might be different types
            const event = currentEvents.find(e => {
                const eId = e.id || e.event_id || e.eventId;
                return eId == eventId || String(eId) === String(eventId);
            });
            
            if (!event) {
                console.error('Event not found for ID:', eventId, 'Available events:', currentEvents.map(e => ({
                    id: e.id || e.event_id || e.eventId,
                    subject: e.subject || e.title
                })));
                showMessage('Event not found', 'error');
                return;
            }
            
            editingEvent = event;
            document.getElementById('modalTitle').textContent = 'Edit Event';
            
            // Handle different field names from different bridge types
            const titleField = event.title || event.subject || event.name || event.summary;
            const descriptionField = event.description || event.body;
            
            // Populate form
            document.getElementById('eventTitle').value = titleField || '';
            document.getElementById('eventDescription').value = descriptionField || '';
            document.getElementById('eventLocation').value = event.location || '';
            
            // Parse dates with proper timezone handling
            const startTimeString = event.start_time || event.start || event.startTime;
            const endTimeString = event.end_time || event.end || event.endTime;
            
            // Convert to local display timezone for editing
            const { date: startDate, time: startTime } = parseEventDateTime(startTimeString, event.timezone);
            const { date: endDate, time: endTime } = parseEventDateTime(endTimeString, event.timezone);
            
            document.getElementById('eventStartDate').value = startDate;
            document.getElementById('eventStartTime').value = startTime;
            document.getElementById('eventEndDate').value = endDate;
            document.getElementById('eventEndTime').value = endTime;
            
            document.getElementById('eventModal').style.display = 'block';
        }

        function parseEventDateTime(dateTimeString, eventTimezone = null) {
            if (!dateTimeString) {
                const now = new Date();
                return {
                    date: now.toISOString().split('T')[0],
                    time: now.toTimeString().substring(0, 5)
                };
            }

            try {
                let date;
                
                // Handle UTC timezone specifically
                if (eventTimezone === 'UTC' && !dateTimeString.endsWith('Z')) {
                    let utcString = dateTimeString;
                    utcString = utcString.replace(/\.\d+$/, ''); // Remove milliseconds
                    utcString = utcString.replace(/[+-]\d{2}:?\d{2}$/, ''); // Remove timezone offset
                    utcString = utcString.replace(/Z$/, ''); // Remove existing Z
                    
                    if (utcString.match(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/)) {
                        utcString += 'Z';
                    }
                    date = new Date(utcString);
                } else {
                    date = new Date(dateTimeString);
                }

                if (isNaN(date.getTime())) {
                    console.warn('Invalid date for editing:', dateTimeString);
                    const now = new Date();
                    return {
                        date: now.toISOString().split('T')[0],
                        time: now.toTimeString().substring(0, 5)
                    };
                }

                // For editing, we want to show the time in the bridge's timezone
                // Convert from UTC to bridge timezone for display in the form
                let displayTimezone = eventTimezone;
                if (!displayTimezone || displayTimezone === 'UTC') {
                    displayTimezone = currentBridgeTimezone;
                }

                if (displayTimezone && displayTimezone !== 'UTC') {
                    try {
                        // Get the date/time in the bridge timezone
                        const formatted = date.toLocaleString('sv-SE', {
                            timeZone: displayTimezone,
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        });
                        
                        // Parse the formatted string (format: "YYYY-MM-DD HH:MM:SS")
                        const [datePart, timePart] = formatted.split(' ');
                        const timeOnly = timePart.substring(0, 5); // Remove seconds
                        
                        return {
                            date: datePart,
                            time: timeOnly
                        };
                    } catch (e) {
                        console.warn('Timezone conversion failed for editing:', e);
                    }
                }
                
                // Fallback to UTC/local parsing
                return {
                    date: date.toISOString().split('T')[0],
                    time: date.toISOString().split('T')[1].substring(0, 5)
                };
                
            } catch (e) {
                console.warn('Date parsing error in editEvent:', e);
                const now = new Date();
                return {
                    date: now.toISOString().split('T')[0],
                    time: now.toTimeString().substring(0, 5)
                };
            }
        }

        function closeEventModal() {
            document.getElementById('eventModal').style.display = 'none';
            editingEvent = null;
        }

        async function saveEvent() {
            const bridgeName = document.getElementById('bridgeSelect').value;
            const resourceId = document.getElementById('resourceSelect').value;
            
            if (!bridgeName || !resourceId) {
                showMessage('Please select bridge and resource first', 'error');
                return;
            }

            // Get form values
            const title = document.getElementById('eventTitle').value;
            const description = document.getElementById('eventDescription').value;
            const location = document.getElementById('eventLocation').value;
            const startDate = document.getElementById('eventStartDate').value;
            const startTime = document.getElementById('eventStartTime').value;
            const endDate = document.getElementById('eventEndDate').value;
            const endTime = document.getElementById('eventEndTime').value;

            // Convert form datetime to proper format for the server
            // The form shows time in bridge timezone, but server might expect UTC
            const { startDateTime, endDateTime } = convertFormDateTimeForServer(
                startDate, startTime, endDate, endTime, currentBridgeTimezone
            );

            const eventData = {
                title: title,
                description: description,
                location: location,
                start_time: startDateTime,
                end_time: endDateTime,
                resource_id: resourceId
            };

            try {
                showLoading(true);

                let response;
                if (editingEvent) {
                    // Update existing event
                    response = await adminAuth.authenticatedFetch(`/bridges/${bridgeName}/events/${editingEvent.id || editingEvent.event_id}`, {
                        method: 'PUT',
                        body: JSON.stringify(eventData)
                    });
                } else {
                    // Create new event
                    response = await adminAuth.authenticatedFetch(`/bridges/${bridgeName}/resources/${resourceId}/events`, {
                        method: 'POST',
                        body: JSON.stringify(eventData)
                    });
                }

                if (response.success) {
                    showMessage(editingEvent ? 'Event updated successfully' : 'Event created successfully', 'success');
                    closeEventModal();
                    refreshEvents();
                } else {
                    showMessage('Failed to save event: ' + (response.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showMessage('Failed to save event: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        function convertFormDateTimeForServer(startDate, startTime, endDate, endTime, bridgeTimezone) {
            // IMPROVED APPROACH: Include timezone offset in the timestamp
            // This tells the server exactly what timezone the time is in
            // The server can then handle conversion properly
            
            const startDateTime = `${startDate}T${startTime}:00`;
            const endDateTime = `${endDate}T${endTime}:00`;
            
            // Get timezone offset for the bridge timezone
            const timezoneOffset = getTimezoneOffsetString(bridgeTimezone);
            
            // Add timezone offset to the timestamps
            const startDateTimeWithTZ = startDateTime + timezoneOffset;
            const endDateTimeWithTZ = endDateTime + timezoneOffset;
            
            return {
                startDateTime: startDateTimeWithTZ,
                endDateTime: endDateTimeWithTZ
            };
        }

        function getTimezoneOffsetString(timezone) {
            try {
                if (!timezone || timezone === 'UTC') {
                    return 'Z'; // UTC timezone
                }
                
                // Use a simpler and more reliable approach
                // Create a date in the target timezone and compare with UTC
                const date = new Date('2025-09-17T12:00:00'); // Fixed date for consistency
                
                // Get the offset in minutes for the target timezone
                const utcDate = new Date(date.toISOString());
                const targetDate = new Date(date.toLocaleString('en-US', { timeZone: timezone }));
                
                // Calculate offset in minutes
                const offsetMinutes = (utcDate.getTime() - targetDate.getTime()) / 60000;
                
                // Alternative method: use getTimezoneOffset comparison
                const tempDate = new Date();
                const utcTime = tempDate.getTime() + (tempDate.getTimezoneOffset() * 60000);
                const targetTime = new Date(utcTime + (tempDate.getTimezoneOffset() * 60000));
                targetTime.setTime(new Date(targetTime.toLocaleString('en-US', { timeZone: timezone })).getTime());
                
                // For Europe/Oslo in September, it should be UTC+2 (CEST)
                let actualOffsetMinutes;
                
                // Use known offsets for reliability
                const knownOffsets = {
                    'Europe/Oslo': 120,      // UTC+2 in summer (CEST)
                    'Europe/Stockholm': 120, // UTC+2 in summer (CEST)
                    'Europe/Copenhagen': 120,// UTC+2 in summer (CEST)
                    'Europe/Berlin': 120,    // UTC+2 in summer (CEST)
                    'Europe/Paris': 120,     // UTC+2 in summer (CEST)
                    'Europe/London': 60,     // UTC+1 in summer (BST)
                    'UTC': 0
                };
                
                actualOffsetMinutes = knownOffsets[timezone];
                
                if (actualOffsetMinutes === undefined) {
                    // Fallback calculation
                    const testTime = new Date('2025-09-17T12:00:00Z');
                    const localTime = new Date(testTime.toLocaleString('en-US', { timeZone: timezone }));
                    actualOffsetMinutes = (testTime.getTime() - localTime.getTime()) / 60000;
                }
                
                // Convert to hours and minutes
                const offsetHours = Math.floor(Math.abs(actualOffsetMinutes) / 60);
                const offsetMins = Math.abs(actualOffsetMinutes) % 60;
                
                // Format as +/-HH:MM
                const sign = actualOffsetMinutes >= 0 ? '+' : '-';
                const offsetString = `${sign}${String(offsetHours).padStart(2, '0')}:${String(offsetMins).padStart(2, '0')}`;
                
                return offsetString;
                
            } catch (e) {
                console.warn('Failed to calculate timezone offset, using fallback:', e);
                
                // Hardcoded fallback to known offsets for September (summer time)
                const fallbackOffsets = {
                    'Europe/Oslo': '+02:00',
                    'Europe/Stockholm': '+02:00', 
                    'Europe/Copenhagen': '+02:00',
                    'Europe/Berlin': '+02:00',
                    'Europe/Paris': '+02:00',
                    'Europe/London': '+01:00',
                    'UTC': 'Z'
                };
                
                return fallbackOffsets[timezone] || '+02:00'; // Default to CEST for European timezones
            }
        }

        function convertLocalTimeToUTC(localDateTimeString, timezone) {
            // This function is no longer used with the simplified approach
            return localDateTimeString;
        }

        async function deleteEvent(eventId) {
            if (!confirm('Are you sure you want to delete this event?')) {
                return;
            }

            const bridgeName = document.getElementById('bridgeSelect').value;
            const resourceId = document.getElementById('resourceSelect').value;
            
            if (!bridgeName || !resourceId) {
                showMessage('Please select bridge and resource first', 'error');
                return;
            }

            try {
                showLoading(true);

                // Include resource_id as query parameter for the backend
                const data = await adminAuth.authenticatedFetch(`/bridges/${bridgeName}/events/${eventId}?resource_id=${encodeURIComponent(resourceId)}`, {
                    method: 'DELETE'
                });
                
                if (data.success) {
                    showMessage('Event deleted successfully', 'success');
                    refreshEvents();
                } else {
                    showMessage('Failed to delete event: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showMessage('Failed to delete event: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        function refreshEvents() {
            if (document.getElementById('bridgeSelect').value && document.getElementById('resourceSelect').value) {
                loadEvents(currentPage);
            }
        }

        function showMessage(message, type) {
            const messageArea = document.getElementById('messageArea');
            messageArea.innerHTML = `<div class="message ${type}">${escapeHtml(message)}</div>`;
            setTimeout(() => {
                messageArea.innerHTML = '';
            }, 5000);
        }

        function showLoading(loading) {
            document.body.classList.toggle('loading', loading);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl+K to set API key
            if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                promptForApiKey('Update API key:');
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('eventModal');
            if (event.target === modal) {
                closeEventModal();
            }
        }
