/**
 * Calendar Bridge Service - Dashboard JavaScript
 * Real-time monitoring dashboard functionality
 */

let refreshInterval;

// Simple API key management for the dashboard
const API_KEY_STORAGE_KEY = 'dashboard_api_key';
const TENANT_STORAGE_KEY = 'dashboard_tenant_id';

function getApiKey() {
    try {
        return localStorage.getItem(API_KEY_STORAGE_KEY) || '';
    } catch (_) {
        return '';
    }
}

function setApiKey(key) {
    try {
        if (key) {
            localStorage.setItem(API_KEY_STORAGE_KEY, key);
        }
    } catch (_) {
        // ignore
    }
}

function clearApiKey() {
    try { localStorage.removeItem(API_KEY_STORAGE_KEY); } catch (_) {}
}

function promptForApiKey(message = 'Enter API key for the API (header: api_key):') {
    const key = window.prompt(message, '');
    if (key && key.trim()) {
        setApiKey(key.trim());
        setActionStatus('API key saved for this browser (localStorage).', 'success');
        return key.trim();
    }
    setActionStatus('API key not set. Some actions may fail with 401 Unauthorized.', 'warning');
    return '';
}

function authHeaders() {
    const key = getApiKey();
    const hdrs = key ? { 'api_key': key } : {};
    try {
        const tenant = localStorage.getItem(TENANT_STORAGE_KEY);
        if (tenant && tenant.trim()) {
            hdrs['X-Tenant-Id'] = tenant.trim();
        }
    } catch (_) { /* ignore */ }
    return hdrs;
}

/**
 * Fetch data from API endpoint with proper error handling
 * @param {string} endpoint - API endpoint to fetch from
 * @returns {Promise<Object>} - Response data
 */
async function fetchData(endpoint) {
    try {
        const headers = authHeaders();
        const response = await fetch(endpoint, { headers });
        if (!response.ok) {
            if (response.status === 401) {
                // Offer to set the API key when unauthorized
                promptForApiKey('Unauthorized (401). Enter a valid API key:');
            }
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return await response.json();
    } catch (error) {
        console.error(`Error fetching ${endpoint}:`, error);
        return { success: false, error: error.message };
    }
}

/**
 * Get status badge HTML
 * @param {string} status - Status string
 * @returns {string} - HTML for status badge
 */
function getStatusBadge(status) {
    return `<span class="status-badge status-${status}">${status}</span>`;
}

/**
 * Format timestamp for display
 * @param {string|Date} timestamp - Timestamp to format
 * @returns {string} - Formatted timestamp
 */
function formatTimestamp(timestamp) {
    return new Date(timestamp).toLocaleString();
}

/**
 * Render system overview statistics
 * @param {Object} data - Dashboard data
 * @param {Object} syncStatusData - Sync status data
 * @returns {string} - HTML for system overview
 */
function renderSystemOverview(data, syncStatusData) {
    if (!data.success || !data.dashboard) {
        return '<div class="stat-box"><div class="stat-number">ERROR</div><div class="stat-label">System Data</div></div>';
    }

    const overview = data.dashboard.system_overview;
    let html = `
        <div class="stat-box">
            <div class="stat-number">${overview.total_mappings || 0}</div>
            <div class="stat-label">Total Mappings</div>
        </div>
    `;

    // Use sync status data if available, otherwise fall back to dashboard data
    if (syncStatusData && syncStatusData.success && syncStatusData.sync_status) {
        const breakdown = syncStatusData.sync_status.overall_sync_health.breakdown;
        html += `
            <div class="stat-box">
                <div class="stat-number">${breakdown.synced || 0}</div>
                <div class="stat-label">Synced</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">${breakdown.pending || 0}</div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">${breakdown.error || 0}</div>
                <div class="stat-label">Errors</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">${breakdown.cancelled || 0}</div>
                <div class="stat-label">Cancelled</div>
            </div>
        `;
    } else {
        // Fallback to dashboard data
        html += `
            <div class="stat-box">
                <div class="stat-number">${overview.synced_count || 0}</div>
                <div class="stat-label">Synced</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">${overview.pending_count || 0}</div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">${overview.error_count || 0}</div>
                <div class="stat-label">Errors</div>
            </div>
        `;
    }

    return html;
}

/**
 * Render health checks section
 * @param {Object} healthData - Health data from API
 * @returns {string} - HTML for health checks
 */
function renderHealthChecks(healthData) {
    if (!healthData.success || !healthData.health) {
        return '<div class="card"><h3>❌ Health Check Failed</h3><p>Unable to retrieve health data</p></div>';
    }

    const health = healthData.health;
    const checks = health.checks;

    let html = `
        <div class="card">
            <h3>🏥 System Health ${getStatusBadge(health.status)}</h3>
            <div class="metric">
                <span class="metric-label">Last Check</span>
                <span class="metric-value">${formatTimestamp(health.timestamp)}</span>
            </div>
            <div class="metric">
                <span class="metric-label">System Uptime</span>
                <span class="metric-value">${health.uptime}</span>
            </div>
        </div>
    `;

    // Database Health
    if (checks.database) {
        html += `
            <div class="card">
                <h3>🗄️ Database ${getStatusBadge(checks.database.status)}</h3>
                <div class="metric">
                    <span class="metric-label">Response Time</span>
                    <span class="metric-value">${checks.database.response_time_ms || 'N/A'}ms</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Total Mappings</span>
                    <span class="metric-value">${checks.database.total_mappings || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Active Queries</span>
                    <span class="metric-value">${checks.database.active_queries || 0}</span>
                </div>
            </div>
        `;
    }

    // Cron Jobs Health
    if (checks.cron_jobs) {
        html += `
            <div class="card">
                <h3>⏰ Cron Jobs ${getStatusBadge(checks.cron_jobs.status)}</h3>
                <div class="metric">
                    <span class="metric-label">Daemon Running</span>
                    <span class="metric-value">${checks.cron_jobs.cron_daemon_running ? '✅ Yes' : '❌ No'}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Recent Automated Syncs</span>
                    <span class="metric-value">${checks.cron_jobs.recent_automated_syncs || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Last Automated Sync</span>
                    <span class="metric-value">${checks.cron_jobs.last_automated_sync ? formatTimestamp(checks.cron_jobs.last_automated_sync) : 'None'}</span>
                </div>
            </div>
        `;
    }

    // Bridge Health
    if (checks.bridges) {
        html += `
            <div class="card">
                <h3>🌉 Bridge Status ${getStatusBadge(checks.bridges.status)}</h3>
                <div class="metric">
                    <span class="metric-label">Active Bridges</span>
                    <span class="metric-value">${checks.bridges.active_count || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Outlook Connected</span>
                    <span class="metric-value">${checks.bridges.outlook_connected ? '✅ Yes' : '❌ No'}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Booking System Connected</span>
                    <span class="metric-value">${checks.bridges.booking_system_connected ? '✅ Yes' : '❌ No'}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Resource Mappings</span>
                    <span class="metric-value">${checks.bridges.resource_mappings || 0}</span>
                </div>
            </div>
        `;
    }

    // System Resources
    if (checks.memory_usage && checks.disk_space) {
        html += `
            <div class="card">
                <h3>💾 System Resources</h3>
                <div class="metric">
                    <span class="metric-label">Memory Usage</span>
                    <span class="metric-value">${checks.memory_usage.usage_percent || 0}% (${checks.memory_usage.current_usage_mb || 0}MB)</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Disk Usage</span>
                    <span class="metric-value">${checks.disk_space.usage_percent || 0}% (${checks.disk_space.free_space_gb || 0}GB free)</span>
                </div>
            </div>
        `;
    }

    return html;
}

/**
 * Render bridge health section
 * @param {Object} bridgeHealthData - Bridge health data from API
 * @returns {string} - HTML for bridge health
 */
function renderBridgeHealth(bridgeHealthData) {
    if (!bridgeHealthData.success || !bridgeHealthData.bridges) {
        return '<div class="card"><h3>🌉 Bridge Health</h3><p>Unable to retrieve bridge health data</p></div>';
    }

    const { overall_health, bridges, summary } = bridgeHealthData;
    
    let html = `
        <div class="card">
            <h3>🌉 Bridge Health ${getStatusBadge(overall_health)}</h3>
            <div class="metric">
                <span class="metric-label">Total Bridges</span>
                <span class="metric-value">${summary.total_bridges}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Healthy Bridges</span>
                <span class="metric-value">${summary.healthy_bridges}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Unhealthy Bridges</span>
                <span class="metric-value">${summary.unhealthy_bridges}</span>
            </div>
            <div style="margin-top: 15px;">
                <h4>Bridge Details:</h4>
    `;

    Object.values(bridges).forEach(bridge => {
        const status = bridge.health?.status || 'unknown';
        const lastCheck = bridge.health?.timestamp || 'Never';
        html += `
            <div style="margin: 8px 0; padding: 8px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid ${status === 'healthy' ? '#28a745' : '#dc3545'};">
                <strong>${bridge.name}</strong> ${getStatusBadge(status)}
                <div style="font-size: 0.85rem; color: #666; margin-top: 4px;">
                    Type: ${bridge.type} | Last Check: ${lastCheck === 'Never' ? 'Never' : formatTimestamp(lastCheck)}
                </div>
                ${bridge.health?.error ? `<div style="color: #dc3545; font-size: 0.85rem; margin-top: 2px;">Error: ${bridge.health.error}</div>` : ''}
            </div>
        `;
    });

    html += `
            </div>
        </div>
    `;

    return html;
}

/**
 * Render sync status section
 * @param {Object} dashboardData - Dashboard data from API
 * @returns {string} - HTML for sync status
 */
function renderSyncStatus(dashboardData) {
    if (!dashboardData.success || !dashboardData.dashboard) {
        return '<div class="card"><h3>📊 Sync Status</h3><p>Unable to retrieve sync data</p></div>';
    }

    const dashboard = dashboardData.dashboard;
    let html = '';

    // Recent Activity
    if (dashboard.recent_activity && dashboard.recent_activity.length > 0) {
        html += `
            <div class="card">
                <h3>📈 Recent Activity</h3>
                <div class="activity-list">
        `;
        
        dashboard.recent_activity.slice(0, 10).forEach(activity => {
            const statusColor = activity.sync_status === 'synced' ? '#28a745' : 
                               activity.sync_status === 'error' ? '#dc3545' : '#ffc107';
            html += `
                <div class="activity-item" style="border-left-color: ${statusColor}">
                    <div class="activity-header">
                        ${activity.reservation_type} - ${activity.sync_direction} - ${activity.sync_status}
                    </div>
                    <div class="activity-details">
                        ${formatTimestamp(activity.updated_at)}
                        ${activity.error_message ? `<br>Error: ${activity.error_message}` : ''}
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    }

    // Error Summary
    if (dashboard.error_summary && dashboard.error_summary.length > 0) {
        html += `
            <div class="card">
                <h3>🚨 Recent Errors (24h)</h3>
                <div class="error-list">
        `;
        
        dashboard.error_summary.forEach(error => {
            html += `
                <div class="error-item">
                    <div class="error-message">${error.error_message}</div>
                    <div class="error-details">
                        Count: ${error.error_count} | Last: ${formatTimestamp(error.last_occurrence)}
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    }

    // Performance Metrics
    if (dashboard.performance_metrics) {
        const perf = dashboard.performance_metrics;
        html += `
            <div class="card">
                <h3>⚡ Performance Metrics</h3>
                <div class="metric">
                    <span class="metric-label">Current Memory</span>
                    <span class="metric-value">${perf.memory_usage?.current_mb || 0}MB</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Peak Memory</span>
                    <span class="metric-value">${perf.memory_usage?.peak_mb || 0}MB</span>
                </div>
                <div class="metric">
                    <span class="metric-label">DB Connections</span>
                    <span class="metric-value">${perf.database_connections || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Syncs/Hour</span>
                    <span class="metric-value">${perf.sync_throughput?.syncs_last_hour || 0}</span>
                </div>
            </div>
        `;
    }

    // Deletion/Cancellation Sync Status
    if (dashboard.deletion_sync_stats) {
        const stats = dashboard.deletion_sync_stats;
        html += `
            <div class="card">
                <h3>🗑️ Deletion & Cancellation Sync</h3>
                <div class="metric">
                    <span class="metric-label">Queue Size</span>
                    <span class="metric-value">${stats.queue_size || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Processed Today</span>
                    <span class="metric-value">${stats.processed_today || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Cancellations Detected</span>
                    <span class="metric-value">${stats.cancellations_detected || 0}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Last Deletion Sync</span>
                    <span class="metric-value">${stats.last_deletion_sync ? formatTimestamp(stats.last_deletion_sync) : 'Never'}</span>
                </div>
            </div>
        `;
    }

    return html;
}

/**
 * Render resource management section
 * @returns {string} - HTML for resource management
 */
function renderResourceManagement() {
    return `
        <div class="card">
            <h3>🔗 Resource Management</h3>
            <div style="margin-bottom: 15px;">
                <button class="action-button" onclick="viewResourceMappings()">📋 View Resource Mappings</button>
                <button class="action-button" onclick="viewAvailableResources()">🏢 View Available Resources</button>
                <button class="action-button" onclick="viewAvailableGroups()">👥 View Available Groups</button>
            </div>
            <div id="resourceContent" style="max-height: 300px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px; background: #f8f9fa;">
                <p style="text-align: center; color: #666; margin: 20px 0;">Select an option above to view resource information</p>
            </div>
        </div>
    `;
}

// Resource management functions
async function viewResourceMappings() {
    setResourceContent('Loading resource mappings...', 'info');
    try {
        const data = await fetchData('/mappings/resources');
        if (data.success && data.mappings) {
            let html = '<h4>Current Resource Mappings:</h4>';
            if (data.mappings.length === 0) {
                html += '<p>No resource mappings found.</p>';
            } else {
                data.mappings.forEach(mapping => {
                    html += `
                        <div style="margin: 8px 0; padding: 8px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>ID:</strong> ${mapping.id}<br>
                            <strong>From:</strong> ${mapping.bridge_from} (${mapping.source_calendar_id || mapping.source_calendar_name || 'N/A'})<br>
                            <strong>To:</strong> ${mapping.bridge_to} (${mapping.target_calendar_id || mapping.target_calendar_name || 'N/A'})<br>
                            <strong>Status:</strong> <span class="status-badge status-${mapping.sync_status}">${mapping.sync_status}</span><br>
                            <strong>Last Sync:</strong> ${mapping.last_sync_time ? formatTimestamp(mapping.last_sync_time) : 'Never'}
                        </div>
                    `;
                });
            }
            setResourceContent(html, 'success');
        } else {
            setResourceContent('Failed to load resource mappings: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setResourceContent('Error loading resource mappings: ' + error.message, 'error');
    }
}

async function viewAvailableResources() {
    setResourceContent('Loading available resources...', 'info');
    try {
        const data = await fetchData('/bridges/outlook/available-resources?limit=20');
        if (data.success && data.resources) {
            let html = '<h4>Available Outlook Resources:</h4>';
            if (data.resources.length === 0) {
                html += '<p>No resources found.</p>';
            } else {
                data.resources.forEach(resource => {
                    html += `
                        <div style="margin: 8px 0; padding: 8px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>${resource.displayName || resource.name}</strong><br>
                            <small>ID: ${resource.id}</small><br>
                            ${resource.emailAddress ? `<small>Email: ${resource.emailAddress}</small><br>` : ''}
                            ${resource.capacity ? `<small>Capacity: ${resource.capacity}</small>` : ''}
                        </div>
                    `;
                });
                if (data.metadata && data.metadata.total_records) {
                    html += `<p><small>Showing ${data.resources.length} of ${data.metadata.total_records} total resources</small></p>`;
                }
            }
            setResourceContent(html, 'success');
        } else {
            setResourceContent('Failed to load resources: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setResourceContent('Error loading resources: ' + error.message, 'error');
    }
}

async function viewAvailableGroups() {
    setResourceContent('Loading available groups...', 'info');
    try {
        const data = await fetchData('/bridges/outlook/available-groups?limit=20');
        if (data.success && data.groups) {
            let html = '<h4>Available Outlook Groups:</h4>';
            if (data.groups.length === 0) {
                html += '<p>No groups found.</p>';
            } else {
                data.groups.forEach(group => {
                    html += `
                        <div style="margin: 8px 0; padding: 8px; background: white; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>${group.displayName || group.name}</strong><br>
                            <small>ID: ${group.id}</small><br>
                            ${group.description ? `<small>Description: ${group.description}</small><br>` : ''}
                            ${group.memberCount ? `<small>Members: ${group.memberCount}</small>` : ''}
                        </div>
                    `;
                });
                if (data.metadata && data.metadata.total_records) {
                    html += `<p><small>Showing ${data.groups.length} of ${data.metadata.total_records} total groups</small></p>`;
                }
            }
            setResourceContent(html, 'success');
        } else {
            setResourceContent('Failed to load groups: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setResourceContent('Error loading groups: ' + error.message, 'error');
    }
}

function setResourceContent(content, type) {
    const element = document.getElementById('resourceContent');
    if (element) {
        const colors = {
            'info': '#0066cc',
            'success': '#28a745',
            'error': '#dc3545'
        };
        element.style.color = colors[type] || '#333';
        element.innerHTML = content;
    }
}

/**
 * Load and render dashboard data
 */
async function loadDashboard() {
    try {
        document.getElementById('lastUpdate').textContent = 'Loading...';
        
        // Fetch health, dashboard, bridge, and sync status data in parallel
        const [healthData, dashboardData, bridgeHealthData, syncStatusData] = await Promise.all([
            fetchData('/health/system'),
            fetchData('/health/dashboard'),
            fetchData('/bridges/health').catch(error => {
                console.warn('Bridge health endpoint not available:', error);
                return { success: false, error: 'Bridge health endpoint not available' };
            }),
            fetchData('/health/sync-status')
        ]);

        // Update system overview with sync status data
        document.getElementById('systemOverview').innerHTML = renderSystemOverview(dashboardData, syncStatusData);

        // Update dashboard content
        let dashboardHTML = renderHealthChecks(healthData);
        dashboardHTML += renderBridgeHealth(bridgeHealthData);
        dashboardHTML += renderSyncStatusOverview(syncStatusData);
        dashboardHTML += renderSyncStatus(dashboardData);
        dashboardHTML += renderSyncActions();
        
        // Add resource management section
        dashboardHTML += renderResourceManagement();
        
        document.getElementById('dashboardContent').innerHTML = dashboardHTML;
        
        document.getElementById('lastUpdate').textContent = `Last updated: ${formatTimestamp(new Date())}`;
        
    } catch (error) {
        console.error('Error loading dashboard:', error);
        document.getElementById('dashboardContent').innerHTML = `
            <div class="card">
                <h3>❌ Error Loading Dashboard</h3>
                <p>Unable to load dashboard data: ${error.message}</p>
                <p>Please check the API endpoints and try again.</p>
            </div>
        `;
        document.getElementById('lastUpdate').textContent = `Error: ${error.message}`;
    }
}

/**
 * Render sync status overview section
 * @param {Object} syncStatusData - Sync status data from API
 * @returns {string} - HTML for sync status overview
 */
function renderSyncStatusOverview(syncStatusData) {
    if (!syncStatusData || !syncStatusData.success || !syncStatusData.sync_status) {
        return '<div class="card"><h3>📊 Sync Status Overview</h3><p>Unable to retrieve sync status data</p></div>';
    }

    const syncStatus = syncStatusData.sync_status;
    const overallHealth = syncStatus.overall_sync_health;
    
    let html = `
        <div class="card">
            <h3>📊 Sync Status Overview ${getStatusBadge(overallHealth.status)}</h3>
            <div class="metric">
                <span class="metric-label">Overall Health</span>
                <span class="metric-value">${overallHealth.status.toUpperCase()}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Total Items</span>
                <span class="metric-value">${overallHealth.total_items}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Error Rate</span>
                <span class="metric-value">${overallHealth.error_rate_percent}%</span>
            </div>
            <div class="metric">
                <span class="metric-label">Pending Rate</span>
                <span class="metric-value">${overallHealth.pending_rate_percent}%</span>
            </div>
            <div class="metric">
                <span class="metric-label">Stuck Syncs</span>
                <span class="metric-value">${overallHealth.stuck_syncs}</span>
            </div>
            <div class="metric">
                <span class="metric-label">Last Activity</span>
                <span class="metric-value">${overallHealth.last_activity ? formatTimestamp(overallHealth.last_activity) : 'None'}</span>
            </div>
    `;

    // Issues
    if (overallHealth.issues && overallHealth.issues.length > 0) {
        html += `
            <div style="margin-top: 15px;">
                <h4 style="color: #e53e3e; margin-bottom: 10px;">⚠️ Issues:</h4>
                <ul style="margin: 0; padding-left: 20px;">
        `;
        overallHealth.issues.forEach(issue => {
            html += `<li style="color: #e53e3e; margin-bottom: 5px;">${issue}</li>`;
        });
        html += `</ul></div>`;
    }

    html += `</div>`;

    // Bridge-specific sync stats
    if (syncStatus.bridge_sync_stats && Object.keys(syncStatus.bridge_sync_stats).length > 0) {
        html += `
            <div class="card">
                <h3>🌉 Bridge Sync Statistics</h3>
        `;
        
        Object.entries(syncStatus.bridge_sync_stats).forEach(([bridgePair, stats]) => {
            html += `
                <div style="margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                    <h4 style="margin: 0 0 8px 0;">${bridgePair}</h4>
            `;
            
            Object.entries(stats).forEach(([status, data]) => {
                html += `
                    <div style="margin: 5px 0; display: flex; justify-content: space-between;">
                        <span><strong>${status}:</strong> ${data.count} items</span>
                        <span style="font-size: 0.85rem; color: #666;">
                            ${data.avg_retries ? `Avg retries: ${data.avg_retries}` : ''}
                            ${data.last_activity ? `Last: ${formatTimestamp(data.last_activity)}` : ''}
                        </span>
                    </div>
                `;
            });
            
            html += `</div>`;
        });
        
        html += `</div>`;
    }

    // Retry Analysis
    if (syncStatus.retry_analysis && syncStatus.retry_analysis.total_with_retries > 0) {
        html += `
            <div class="card">
                <h3>🔄 Retry Analysis</h3>
                <div class="metric">
                    <span class="metric-label">Items with Retries</span>
                    <span class="metric-value">${syncStatus.retry_analysis.total_with_retries}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Max Retry Count</span>
                    <span class="metric-value">${syncStatus.retry_analysis.max_retry_count}</span>
                </div>
        `;
        
        if (syncStatus.retry_analysis.retry_breakdown && syncStatus.retry_analysis.retry_breakdown.length > 0) {
            html += `<div style="margin-top: 10px;"><h4>Retry Breakdown:</h4>`;
            syncStatus.retry_analysis.retry_breakdown.forEach(item => {
                html += `
                    <div style="margin: 5px 0; padding: 5px; background: #f8f9fa; border-radius: 3px;">
                        <strong>${item.retry_count} retries:</strong> ${item.count} items
                    </div>
                `;
            });
            html += `</div>`;
        }
        
        html += `</div>`;
    }

    // Cancellation Stats
    if (syncStatus.cancellation_stats && syncStatus.cancellation_stats.total_cancelled > 0) {
        html += `
            <div class="card">
                <h3>❌ Cancellation Statistics</h3>
                <div class="metric">
                    <span class="metric-label">Total Cancelled</span>
                    <span class="metric-value">${syncStatus.cancellation_stats.total_cancelled}</span>
                </div>
        `;
        
        if (syncStatus.cancellation_stats.by_bridge_pair && syncStatus.cancellation_stats.by_bridge_pair.length > 0) {
            html += `<div style="margin-top: 10px;"><h4>By Bridge Pair:</h4>`;
            syncStatus.cancellation_stats.by_bridge_pair.forEach(item => {
                html += `
                    <div style="margin: 5px 0; padding: 5px; background: #f8f9fa; border-radius: 3px;">
                        <strong>${item.bridge_pair}:</strong> ${item.count} cancelled
                    </div>
                `;
            });
            html += `</div>`;
        }
        
        html += `</div>`;
    }

    return html;
}

// Sync action functions
async function triggerSync(sourceBridge, targetBridge) {
    setActionStatus(`Triggering sync: ${sourceBridge} → ${targetBridge}...`, 'info');
    try {
        const response = await fetch(`/bridges/sync/${sourceBridge}/${targetBridge}`, { 
            method: 'POST',
            headers: { ...authHeaders() }
        });
        const result = await response.json();
        if (result.success) {
            const summary = `✅ Sync completed: ${result.mappings_processed || 0} mappings processed, ${result.total_synced || 0} events synced`;
            if (result.total_errors > 0) {
                setActionStatus(summary + `, ${result.total_errors} errors`, 'warning');
            } else {
                setActionStatus(summary, 'success');
            }
        } else {
            setActionStatus('❌ Sync failed: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('❌ Sync failed: ' + error.message, 'error');
    }
}

async function triggerDeletionSync() {
    setActionStatus('Processing deletions...', 'info');
    try {
        const response = await fetch('/bridges/sync-deletions', { 
            method: 'POST',
            headers: { ...authHeaders() }
        });
        const result = await response.json();
        if (result.success) {
            setActionStatus('✅ Deletion sync completed', 'success');
        } else {
            setActionStatus('❌ Deletion sync failed: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('❌ Deletion sync failed: ' + error.message, 'error');
    }
}

async function detectCancellations() {
    setActionStatus('Detecting cancellations...', 'info');
    try {
        const response = await fetch('/bridges/sync-deletions', { 
            method: 'POST',
            headers: { ...authHeaders() }
        });
        const result = await response.json();
        if (result.success) {
            setActionStatus(`✅ Found ${result.results.deleted || 0} cancellations`, 'success');
        } else {
            setActionStatus('❌ Cancellation detection failed: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('❌ Cancellation detection failed: ' + error.message, 'error');
    }
}

function refreshDashboard() {
    setActionStatus('Refreshing dashboard...', 'info');
    loadDashboard();
}

function setActionStatus(message, type) {
    const statusDiv = document.getElementById('actionStatus');
    if (statusDiv) {
        statusDiv.textContent = message;
        statusDiv.style.color = type === 'success' ? '#28a745' : 
                               type === 'error' ? '#dc3545' : '#666';
        
        if (type !== 'info') {
            setTimeout(() => {
                statusDiv.textContent = '';
            }, 5000);
        }
    }
}

async function viewBridgesList() {
    setActionStatus('Loading bridges...', 'info');
    try {
        const data = await fetchData('/bridges');
        if (data.success && data.bridges) {
            let message = `Found ${data.count} bridges:\n`;
            Object.entries(data.bridges).forEach(([key, bridge]) => {
                message += `\n• ${bridge.name} (${bridge.type}) - ${bridge.health?.status || 'unknown'}`;
            });
            setActionStatus(message, 'success');
        } else {
            setActionStatus('Failed to load bridges: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('Error loading bridges: ' + error.message, 'error');
    }
}

// New sync status action functions
async function processPendingSyncs() {
    setActionStatus('Processing pending syncs...', 'info');
    try {
        const response = await fetch('/bridges/process-pending-syncs', { 
            method: 'POST',
            headers: { ...authHeaders() }
        });
        const result = await response.json();
        if (result.success) {
            let totalProcessed = 0;
            let totalErrors = 0;
            
            if (result.results) {
                Object.values(result.results).forEach(bridgeResult => {
                    totalProcessed += bridgeResult.processed || 0;
                    totalErrors += bridgeResult.errors || 0;
                });
            }
            
            const processed = result.processed || totalProcessed;
            const errors = result.errors || totalErrors;
            
            let message = `✅ Processed ${processed} pending syncs`;
            if (errors > 0) {
                message += ` with ${errors} errors`;
                setActionStatus(message, 'warning');
            } else {
                setActionStatus(message, 'success');
            }
        } else {
            setActionStatus('❌ Failed to process pending syncs: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('❌ Error processing pending syncs: ' + error.message, 'error');
    }
}

async function reEnableFailedEvents() {
    setActionStatus('Re-enabling failed events...', 'info');
    try {
        const response = await fetch('/bridges/re-enable-failed', { 
            method: 'POST',
            headers: { ...authHeaders() }
        });
        const result = await response.json();
        if (result.success) {
            let totalReEnabled = 0;
            let totalErrors = 0;
            
            if (result.results) {
                Object.values(result.results).forEach(bridgeResult => {
                    totalReEnabled += bridgeResult.re_enabled_count || bridgeResult.re_enabled || 0;
                    totalErrors += bridgeResult.errors || 0;
                });
            }
            
            const reEnabled = result.re_enabled || totalReEnabled;
            const errors = result.errors || totalErrors;
            
            let message = `✅ Re-enabled ${reEnabled} failed events`;
            if (errors > 0) {
                message += ` with ${errors} errors`;
                setActionStatus(message, 'warning');
            } else {
                setActionStatus(message, 'success');
            }
        } else {
            setActionStatus('❌ Failed to re-enable events: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('❌ Error re-enabling failed events: ' + error.message, 'error');
    }
}

async function viewCancelledEvents() {
    setActionStatus('Loading cancelled events...', 'info');
    try {
        const data = await fetchData('/bridges/cancelled-events');
        if (data.success) {
            // Handle both all_cancelled_events and cancelled_events response formats
            const events = data.all_cancelled_events || data.cancelled_events || [];
            
            if (events.length === 0) {
                setActionStatus('✅ No cancelled events found', 'success');
                return;
            }
            
            let message = `Found ${events.length} cancelled events:\n`;
            events.slice(0, 5).forEach(event => {
                message += `\n• ${event.event_title || event.subject || 'No title'} (${event.bridge_from} → ${event.bridge_to})`;
                if (event.cancelled_at) {
                    message += ` - Cancelled: ${formatTimestamp(event.cancelled_at)}`;
                }
            });
            if (events.length > 5) {
                message += `\n... and ${events.length - 5} more`;
            }
            setActionStatus(message, 'success');
        } else {
            setActionStatus('Failed to load cancelled events: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('Error loading cancelled events: ' + error.message, 'error');
    }
}

async function viewSyncStats() {
    setActionStatus('Loading sync statistics...', 'info');
    try {
        const data = await fetchData('/bridges/sync-stats');
        if (data.success) {
            // Handle both all_bridge_stats and stats response formats
            const stats = data.all_bridge_stats || data.stats || {};
            
            if (Object.keys(stats).length === 0) {
                setActionStatus('✅ No sync statistics available', 'success');
                return;
            }
            
            let message = 'Sync Statistics:\n';
            Object.entries(stats).forEach(([bridge, bridgeStats]) => {
                message += `\n${bridge}:`;
                if (Array.isArray(bridgeStats)) {
                    // Array format from database
                    bridgeStats.forEach(stat => {
                        message += ` ${stat.sync_status}=${stat.count}`;
                    });
                } else {
                    // Object format
                    Object.entries(bridgeStats).forEach(([status, count]) => {
                        message += ` ${status}=${count}`;
                    });
                }
            });
            setActionStatus(message, 'success');
        } else {
            setActionStatus('Failed to load sync stats: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('Error loading sync stats: ' + error.message, 'error');
    }
}

function renderSyncActions() {
    return `
        <div class="card">
            <h3>🎛️ Sync Management Actions</h3>
            <div style="margin-bottom: 15px;">
                <h4>Sync Operations:</h4>
                <button class="action-button" onclick="triggerSync('outlook', 'booking_system')">🔄 Sync: Outlook → Booking</button>
                <button class="action-button" onclick="triggerSync('booking_system', 'outlook')">🔄 Sync: Booking → Outlook</button>
            </div>
            <div style="margin-bottom: 15px;">
                <h4>Sync Status Management:</h4>
                <button class="action-button" onclick="processPendingSyncs()">⏳ Process Pending Syncs</button>
                <button class="action-button" onclick="reEnableFailedEvents()">🔄 Re-enable Failed Events</button>
            </div>
            <div style="margin-bottom: 15px;">
                <h4>Deletion & Cancellation:</h4>
                <button class="action-button" onclick="triggerDeletionSync()">🗑️ Process Deletions</button>
                <button class="action-button" onclick="detectCancellations()">🔍 Detect Cancellations</button>
                <button class="action-button" onclick="viewCancelledEvents()">📋 View Cancelled Events</button>
            </div>
            <div style="margin-bottom: 15px;">
                <h4>Maintenance:</h4>
                <button class="action-button" onclick="cleanupLogs()">🧹 Cleanup Sync Logs</button>
            </div>
            <div style="margin-bottom: 15px;">
                <h4>Monitoring & Statistics:</h4>
                <button class="action-button" onclick="viewSyncStats()">📊 View Sync Statistics</button>
                <button class="action-button" onclick="viewBridgesList()">🌉 View Bridges</button>
                <button class="action-button" onclick="refreshDashboard()">🔄 Refresh Dashboard</button>
            </div>
            <div id="actionStatus" style="margin-top: 15px; padding: 10px; border-radius: 4px; font-size: 0.9rem; min-height: 20px;"></div>
        </div>
    `;
}

// Maintenance action: cleanup old sync logs
async function cleanupLogs() {
    let days = window.prompt('Cleanup sync logs older than N days (default 30):', '30');
    if (days === null) {
        setActionStatus('Cleanup cancelled.', 'info');
        return;
    }
    days = parseInt(days, 10);
    if (!Number.isFinite(days) || days < 1) {
        setActionStatus('Please enter a valid number of days (>= 1).', 'error');
        return;
    }
    setActionStatus(`Cleaning up sync logs older than ${days} days...`, 'info');
    try {
        const response = await fetch(`/maintenance/cleanup-logs?days=${encodeURIComponent(days)}`, {
            method: 'POST',
            headers: { ...authHeaders() }
        });
        if (!response.ok) {
            if (response.status === 401) {
                promptForApiKey('Unauthorized (401). Enter a valid API key:');
            }
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        const result = await response.json();
        if (result && result.success) {
            const kept = typeof result.days_kept !== 'undefined' ? result.days_kept : days;
            const deleted = result.deleted ?? 0;
            setActionStatus(`✅ Cleanup complete: deleted ${deleted} rows (kept ${kept} days).`, 'success');
        } else {
            setActionStatus('❌ Cleanup failed: ' + (result && result.error ? result.error : 'Unknown error'), 'error');
        }
    } catch (error) {
        setActionStatus('❌ Cleanup failed: ' + error.message, 'error');
    }
}

// Initialize dashboard
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tenant selector (dropdown)
    (async () => {
        const sel = document.getElementById('tenantIdSelect');
        const clr = document.getElementById('clearTenantBtn');
        if (!sel) return;
        const stored = localStorage.getItem(TENANT_STORAGE_KEY) || '';
        try {
            // Load tenants via admin endpoint (requires global admin key in localStorage.dashboard_api_key)
            const adminKey = localStorage.getItem('dashboard_api_key') || '';
            const res = await fetch('/admin/tenants', { headers: adminKey ? { 'api_key': adminKey, 'X-API-Key': adminKey } : {} });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            const tenants = (data && data.tenants) || [];
            if (tenants.length === 0) {
                sel.innerHTML = `<option value="">No tenants</option>`;
            } else {
                sel.innerHTML = `<option value="">(none)</option>` + tenants.map(t => `<option value="${t.id}">${t.id} — ${t.name}</option>`).join('');
                if (stored) sel.value = stored;
            }
        } catch (e) {
            sel.innerHTML = `<option value="">(enter manually via Ctrl+T)</option>`;
            document.getElementById('tenantHint')?.appendChild(document.createTextNode(' · Unable to load tenants (set admin key or use Ctrl+T).'));
        }
        sel.addEventListener('change', () => {
            const tid = sel.value || '';
            if (tid) {
                localStorage.setItem(TENANT_STORAGE_KEY, tid);
                setActionStatus(`Tenant set to: ${tid}`, 'success');
            } else {
                localStorage.removeItem(TENANT_STORAGE_KEY);
                setActionStatus('Tenant cleared. Using DEFAULT_TENANT_ID if configured.', 'info');
            }
            loadDashboard();
        });
        if (clr) {
            clr.addEventListener('click', () => {
                sel.value = '';
                localStorage.removeItem(TENANT_STORAGE_KEY);
                setActionStatus('Tenant cleared. Using DEFAULT_TENANT_ID if configured.', 'info');
                loadDashboard();
            });
        }
    })();
    // Ensure we have an API key saved; prompt on first load
    if (!getApiKey()) {
        promptForApiKey();
    }

    loadDashboard();

    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(loadDashboard, 30000);

    // Shortcut to reset API key: Ctrl+K
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            clearApiKey();
            promptForApiKey('Update API key:');
        }
    });
    // Shortcut to set tenant manually: Ctrl+T
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && (e.key === 't' || e.key === 'T')) {
            e.preventDefault();
            const current = (localStorage.getItem(TENANT_STORAGE_KEY) || '');
            const tid = window.prompt('Set X-Tenant-Id (leave empty to clear):', current) || '';
            if (tid.trim()) {
                localStorage.setItem(TENANT_STORAGE_KEY, tid.trim());
                setActionStatus(`Tenant set to: ${tid.trim()}`, 'success');
                const sel = document.getElementById('tenantIdSelect'); if (sel) sel.value = tid.trim();
                loadDashboard();
            } else {
                localStorage.removeItem(TENANT_STORAGE_KEY);
                setActionStatus('Tenant cleared. Using DEFAULT_TENANT_ID if configured.', 'info');
                const sel = document.getElementById('tenantIdSelect'); if (sel) sel.value = '';
                loadDashboard();
            }
        }
    });
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});
