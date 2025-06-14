# Webhook-Less Bridge Configuration

## 🔄 **Perfect for Systems Not Reachable from Internet**

This configuration optimizes the Generic Calendar Bridge for environments that cannot receive webhooks (internal networks, firewalls, NAT, etc.).

### **✅ What Works Without Webhooks:**

1. **✅ Bidirectional Sync**: Full event synchronization in both directions
2. **✅ Cancellation Detection**: Your inactive event → Outlook deletion use case
3. **✅ Real-time Polling**: Frequent polling provides near-real-time sync
4. **✅ Health Monitoring**: Complete system monitoring and logging
5. **✅ Error Recovery**: Robust error handling and retry mechanisms

### **🔧 Optimized Cron Configuration:**

For webhook-less environments, update your cron jobs for more frequent polling:

#### **High-Frequency Polling** (Near Real-time - 2-5 minutes)
```bash
# Ultra-responsive sync - every 2 minutes
*/2 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" \
  -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+3 days\" +%Y-%m-%d)"}'

*/3 * * * * curl -X POST http://localhost:8080/bridges/sync/outlook/booking_system \
  -H "Content-Type: application/json" \
  -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+3 days\" +%Y-%m-%d)"}'

# Cancellation detection - every 2 minutes
*/2 * * * * curl -X POST http://localhost:8080/cancel/detect

# Deletion sync - every 5 minutes
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions
```

#### **Balanced Polling** (Good Performance - 5-10 minutes) - **CURRENT DEFAULT**
```bash
# Current optimized schedule (already in docker-entrypoint.sh)
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook
*/10 * * * * curl -X POST http://localhost:8080/bridges/sync/outlook/booking_system
*/5 * * * * curl -X POST http://localhost:8080/cancel/detect
```

#### **Conservative Polling** (Resource-friendly - 15-30 minutes)
```bash
# Lower frequency for resource-constrained environments
*/15 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook
*/20 * * * * curl -X POST http://localhost:8080/bridges/sync/outlook/booking_system
*/10 * * * * curl -X POST http://localhost:8080/cancel/detect
```

### **⚙️ Smart Polling Strategy (Option 3):**

**Smart Polling** optimizes performance by focusing on recent changes rather than polling all data. This provides the best balance of efficiency and responsiveness.

#### **Smart Polling Configuration:**
```bash
# Smart Polling - Optimized date ranges and batch processing
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" \
  -d '{
    "start_date":"$(date -d \"-3 days\" +%Y-%m-%d)",
    "end_date":"$(date -d \"+7 days\" +%Y-%m-%d)",
    "limit": 50,
    "changed_since": "$(date -d \"-1 day\" --iso-8601=seconds)"
  }'

*/7 * * * * curl -X POST http://localhost:8080/bridges/sync/outlook/booking_system \
  -H "Content-Type: application/json" \
  -d '{
    "start_date":"$(date -d \"-2 days\" +%Y-%m-%d)",
    "end_date":"$(date -d \"+14 days\" +%Y-%m-%d)",
    "limit": 50,
    "changed_since": "$(date -d \"-2 hours\" --iso-8601=seconds)"
  }'

# Cancellation detection with recent focus
*/3 * * * * curl -X POST http://localhost:8080/cancel/detect

# Quick deletion sync for immediate responsiveness
*/10 * * * * curl -X POST http://localhost:8080/bridges/sync-deletions
```

#### **Benefits of Smart Polling:**
- **🚀 Faster Performance**: Smaller datasets = faster sync operations
- **💾 Lower Resource Usage**: Reduces memory and CPU consumption
- **🎯 Focus on Changes**: Prioritizes recent modifications
- **⚡ Better Response Time**: Shorter sync windows mean quicker detection
- **📊 Efficient Processing**: Batched operations with limits

#### **Smart Polling Parameters:**

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `start_date` | Begin sync window | `$(date -d "-3 days" +%Y-%m-%d)` |
| `end_date` | End sync window | `$(date -d "+7 days" +%Y-%m-%d)` |
| `limit` | Max events per request | `50` |
| `changed_since` | Only sync recent changes | `$(date -d "-1 day" --iso-8601=seconds)` |

#### **Customizing Smart Polling for Your Needs:**

**For High-Activity Systems:**
```bash
# More frequent checks with smaller windows
*/2 * * * * curl -X POST http://localhost:8080/cancel/detect
# Sync only last 24 hours + next 3 days
-d '{"start_date":"$(date -d \"-1 day\" +%Y-%m-%d)","end_date":"$(date -d \"+3 days\" +%Y-%m-%d)","limit": 25}'
```

**For Low-Activity Systems:**
```bash
# Less frequent checks with larger windows  
*/10 * * * * curl -X POST http://localhost:8080/cancel/detect
# Sync last 7 days + next 30 days
-d '{"start_date":"$(date -d \"-7 days\" +%Y-%m-%d)","end_date":"$(date -d \"+30 days\" +%Y-%m-%d)","limit": 100}'
```

**For Your Inactive Event Use Case:**
```bash
# Optimized for cancellation detection
*/3 * * * * curl -X POST http://localhost:8080/cancel/detect
*/5 * * * * curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" \
  -d '{"start_date":"$(date +%Y-%m-%d)","end_date":"$(date -d \"+7 days\" +%Y-%m-%d)","limit": 30}'
```

### **🎯 Your Specific Use Case (Inactive Events → Outlook Deletion):**

**This works perfectly without webhooks!**

```bash
# Set event to inactive in booking system database
UPDATE bb_event SET active = 0 WHERE id = 12345;

# Within 5 minutes (or 2 minutes with high-frequency polling):
# 1. Cron job triggers: curl -X POST /cancel/detect
# 2. System detects active = 0 
# 3. Outlook event automatically deleted
# 4. Mapping updated to 'cancelled'
```

### **⚡ Performance Optimizations for Polling:**

#### **1. Optimized Sync Windows**
Instead of syncing large date ranges, focus on recent changes:

```bash
# Sync only recent data (last 3 days + next 7 days)
curl -X POST http://localhost:8080/bridges/sync/booking_system/outlook \
  -H "Content-Type: application/json" \
  -d '{
    "start_date":"$(date -d \"-3 days\" +%Y-%m-%d)",
    "end_date":"$(date -d \"+7 days\" +%Y-%m-%d)",
    "limit": 100
  }'
```

#### **2. Change Detection Optimization**
Configure the bridges to use efficient change detection:

```bash
# Enable change tracking in booking system bridge
curl -X POST http://localhost:8080/bridges/booking_system/config \
  -H "Content-Type: application/json" \
  -d '{
    "change_detection": "timestamp",
    "poll_interval": 300,
    "batch_size": 50
  }'
```

### **🔍 Monitoring Webhook-Less Operation:**

#### **Verify Polling is Working:**
```bash
# Check sync activity
curl http://localhost:8080/bridges/health | jq '.logs[] | select(.operation == "sync")'

# Check cancellation detection
curl http://localhost:8080/cancel/stats

# Monitor polling frequency
curl http://localhost:8080/bridges/health | jq '.last_sync_times'
```

#### **Performance Metrics:**
```bash
# Get sync performance stats
curl http://localhost:8080/bridges/health | jq '{
  outlook_last_sync: .bridges.outlook.last_sync,
  booking_last_sync: .bridges.booking_system.last_sync,
  total_events_synced: .total_events_synced,
  avg_response_time: .avg_response_time_ms
}'
```

### **💡 Why This Works Well:**

1. **No External Dependencies**: No need for public IP, domain, or SSL certificates
2. **Reliable**: Polling is more predictable than webhook delivery
3. **Configurable**: Adjust frequency based on your responsiveness needs
4. **Resilient**: Automatic retry and error recovery
5. **Comprehensive**: Covers all use cases including your inactive event scenario

### **🎯 Recommended Approach:**

For your environment, I recommend:

1. **Use Current Default**: The 5-10 minute polling (already configured) works great
2. **Monitor Performance**: Check if timing meets your needs
3. **Adjust if Needed**: Increase frequency to 2-3 minutes if you need faster response
4. **Focus on Cancellation**: Your inactive event use case works perfectly with polling

### **⚠️ What You're NOT Missing:**

**Webhooks provide instant notifications, but:**
- ✅ **5-minute polling** gives you near-real-time performance  
- ✅ **More reliable** than webhook delivery failures
- ✅ **Easier to troubleshoot** than webhook issues
- ✅ **No network complexity** or firewall configuration needed

The bridge system is **designed for polling-first operation** with webhooks as an optional enhancement. You're getting the full functionality without any compromises!
