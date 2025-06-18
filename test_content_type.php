<?php
/**
 * Demonstration of Content-Type differences
 * 
 * This script shows the difference between:
 * 1. application/x-www-form-urlencoded (populates $_GET/$_POST)
 * 2. application/json (requires php://input)
 */

echo "🧪 Content-Type Demonstration\n";
echo "============================\n\n";

echo "📋 When you send data with different Content-Type headers:\n\n";

echo "1️⃣  Content-Type: application/x-www-form-urlencoded\n";
echo "   ✅ $_GET populated from URL parameters\n";
echo "   ✅ $_POST populated from request body\n";
echo "   ✅ Traditional PHP applications expect this\n";
echo "   📝 Data format: key1=value1&key2=value2\n\n";

echo "2️⃣  Content-Type: application/json\n";
echo "   ✅ $_GET populated from URL parameters\n";
echo "   ❌ $_POST is EMPTY (not populated)\n";
echo "   📝 Data must be read from php://input\n";
echo "   📝 Data format: {\"key1\":\"value1\",\"key2\":\"value2\"}\n\n";

echo "🔧 Your BookingSystemBridge now uses:\n";
echo "   Content-Type: application/x-www-form-urlencoded\n";
echo "   This ensures $_GET and $_POST are properly populated!\n\n";

// Simulate what happens on the receiving end
echo "📨 Example of what your booking system receives:\n\n";

echo "GET /api/resources?session_id=abc123&domain=example.com\n";
echo "Content-Type: application/x-www-form-urlencoded\n";
echo "Body: resource_id=123&action=list\n\n";

echo "📥 PHP receives:\n";
echo "   \$_GET['session_id'] = 'abc123'\n";
echo "   \$_GET['domain'] = 'example.com'\n";
echo "   \$_POST['resource_id'] = '123'\n";
echo "   \$_POST['action'] = 'list'\n\n";

echo "vs. if we used application/json:\n";
echo "   \$_GET['session_id'] = 'abc123'  ✅\n";
echo "   \$_GET['domain'] = 'example.com'  ✅\n";
echo "   \$_POST = []  ❌ EMPTY!\n";
echo "   php://input = '{\"resource_id\":\"123\",\"action\":\"list\"}'\n\n";

echo "💡 Key Insight:\n";
echo "   Most traditional PHP applications expect form-encoded data\n";
echo "   because they use \$_GET and \$_POST arrays directly.\n";
echo "   Modern REST APIs often use JSON, but require special handling.\n\n";

echo "🎯 Your booking system (based on ApiClient.php) uses:\n";
echo "   - Traditional PHP session management\n";
echo "   - URL parameters for session info\n";
echo "   - Form data for POST requests\n";
echo "   - This is why we changed from JSON to form-encoded!\n\n";

echo "✨ Migration Complete! ✨\n";
echo "Now your booking system should properly receive data in \$_GET and \$_POST\n";
