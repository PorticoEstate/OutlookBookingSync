# OutlookBookingSync

A **production-ready bidirectional calendar synchronization system** that synchronizes room bookings between internal booking systems and Outlook (Microsoft 365) room calendars, ensuring both systems reflect the current state of all room reservations.

## 🚀 Key Features

- ✅ **Complete Bidirectional Sync** - Events flow seamlessly between booking system and Outlook
- ✅ **Automatic Cancellation Handling** - Detects and processes cancellations from both systems
- ✅ **Dual-Mode Operation** - Supports both webhook and polling-based change detection
- ✅ **Docker Containerized** - Easy deployment with cron support
- ✅ **RESTful API** - 25+ endpoints for comprehensive management
- ✅ **Real-time Monitoring** - Statistics and health monitoring
- ✅ **Production Tested** - Zero error rate in sync operations

## 📋 System Requirements

- Docker & Docker Compose
- PostgreSQL Database
- Microsoft Graph API Credentials
- Network access to Microsoft 365

## 🏗️ Quick Start

### 1. Clone and Setup

```bash
git clone <repository-url>
cd OutlookBookingSync
```

### 2. Configure Environment

```bash
cp .env.example .env
# Edit .env with your database and Microsoft Graph credentials
```

### 3. Build and Run

```bash
# Build container with cron support
docker compose build --no-cache

# Start the service
docker compose up -d
```

### 4. Verify Installation

```bash
# Check container status
docker ps -f name=portico_outlook

# Test API endpoint
curl http://localhost:8082/sync/pending-items
```

## 🔧 Configuration

### Environment Variables

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` - Database configuration
- `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, `GRAPH_TENANT_ID` - Microsoft Graph API
- `API_KEY` - Optional API key for endpoint security

### Database Setup

```bash
# Run database schema creation
psql -h localhost -U your_user -d your_db -f database/create_tables.sql
```

## ⚙️ Automated Scheduling

The system includes automated cron jobs that run inside the container:

- **Every 15 minutes**: Poll Outlook for changes
- **Every hour**: Detect missing/deleted events
- **Every 10 minutes**: Process cancellation detection
- **Daily at 8 AM**: Generate statistics logs

## 📊 API Endpoints

### Sync Operations

- `GET /sync/pending-items` - View items awaiting sync
- `POST /sync/to-outlook` - Sync booking system → Outlook
- `POST /sync/from-outlook` - Import Outlook → booking system

### Cancellation Management

- `POST /cancel/detect-and-process` - Automatic cancellation detection
- `GET /cancel/stats` - View cancellation statistics

### Monitoring & Stats

- `GET /sync/stats` - Comprehensive sync statistics
- `GET /polling/stats` - Polling system health

### Webhook Support

- `POST /webhook/outlook-notifications` - Handle Microsoft Graph webhooks
- `POST /webhook/create-subscriptions` - Set up webhook subscriptions

**📋 Webhook Setup Required:** For real-time sync, webhooks require SSL certificates and public domain access. See [Webhook Setup Guide](doc/sync_usage_guide.md#webhook-setup-real-time-sync) for complete configuration instructions.

## 📖 Documentation

- **[Complete Usage Guide](doc/sync_usage_guide.md)** - Comprehensive API documentation **+ Webhook Setup**
- **[Service Planning](doc/calendar_sync_service_plan.md)** - Architecture and design
- **[Cancellation Detection](doc/outlook_cancellation_detection.md)** - Cancellation handling
- **[Database Schema](database/create_tables.sql)** - Required database tables

## 🌐 Service Access

The service runs on port **8082** and provides:

- RESTful API endpoints
- Real-time webhook handling
- Automated background processing
- Comprehensive logging and monitoring

## 🔍 Troubleshooting

### Container Logs

```bash
# View application logs
docker logs portico_outlook

# Check cron job status
docker exec portico_outlook crontab -u www-data -l
```

### Common Issues

- Ensure `.env` file is properly configured
- Verify Microsoft Graph API permissions
- Check database connectivity
- Confirm network access to Microsoft 365

## 📝 Production Readiness

✅ **Verified Production Features:**

- Transaction safety with rollback support
- Zero error rate in sync operations
- Loop prevention mechanisms
- Comprehensive audit logging
- Real-time statistics and monitoring
- Graceful error handling and recovery

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## 📄 License

See [LICENSE](LICENSE) file for details.
