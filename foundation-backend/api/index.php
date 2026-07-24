<?php
// Enable PHP Error Reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Prevent caching of API responses
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Parse request URI
$basePath = '/foundation-backend/api';
$requestUri = $_SERVER['REQUEST_URI'];
// Remove query string
if (($pos = strpos($requestUri, '?')) !== false) {
    $requestUri = substr($requestUri, 0, $pos);
}

// Extract route
if (strpos($requestUri, $basePath) === 0) {
    $route = substr($requestUri, strlen($basePath));
} else {
    $route = $requestUri;
}
$route = trim($route, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Env loader
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $value = trim($value, '"\'');
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

// Load env variables
loadEnv(__DIR__ . '/../.env');

// Database credentials
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_DATABASE') ?: 'itlc_foundation');
define('DB_USER', getenv('DB_USERNAME') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');

// Connect to Database
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

// JWT Helper
class JWT {
    private static function getSecret() {
        return getenv('JWT_SECRET') ?: 'supersecretkeyforitlcadmin';
    }

    public static function sign($payload) {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function verify($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        list($header, $payload, $signature) = $parts;
        $validSignature = hash_hmac('sha256', $header . "." . $payload, self::getSecret(), true);
        if (self::base64UrlEncode($validSignature) !== $signature) return null;
        $decodedPayload = json_decode(self::base64UrlDecode($payload), true);
        return $decodedPayload;
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}

// Auth Middleware
function getAuthenticatedAdmin() {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
    
    if (!$authHeader) {
        http_response_code(401);
        echo json_encode(["error" => "No authorization header provided"]);
        exit;
    }
    
    $parts = explode(' ', $authHeader);
    $token = isset($parts[1]) ? $parts[1] : null;
    if (!$token) {
        http_response_code(401);
        echo json_encode(["error" => "Token missing"]);
        exit;
    }
    
    $decoded = JWT::verify($token);
    if (!$decoded) {
        http_response_code(403);
        echo json_encode(["error" => "Invalid or expired token"]);
        exit;
    }
    
    return $decoded;
}

// Request Payload Helper
$GLOBALS['raw_request_body'] = null;
function getRequestBody() {
    if ($GLOBALS['raw_request_body'] === null) {
        $GLOBALS['raw_request_body'] = file_get_contents('php://input');
    }
    return json_decode($GLOBALS['raw_request_body'], true) ?: [];
}
function getRawRequestBody() {
    if ($GLOBALS['raw_request_body'] === null) {
        $GLOBALS['raw_request_body'] = file_get_contents('php://input');
    }
    return $GLOBALS['raw_request_body'];
}

// Minimal PDF String Generator
class ReceiptPDF {
    public static function generate($donation) {
        $receiptNo = "ITLC-80G-" . $donation['id'] . "-" . date('Y', strtotime($donation['created_at']));
        $donationDate = date('d F Y', strtotime($donation['created_at']));
        
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources 4 0 R /MediaBox [0 0 595 842] /Contents 5 0 R >>\nendobj\n";
        $pdf .= "4 0 obj\n<< /Font << /F1 6 0 R /F2 7 0 R >> >>\nendobj\n";
        $pdf .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $pdf .= "7 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";
        
        $text = "BT\n";
        $text .= "/F2 20 Tf\n200 750 Td\n(ITLC FOUNDATION) Tj\n";
        $text .= "/F1 10 Tf\n-80 -20 Td\n(Serving Humanity. Protecting Nature. Saving Lives.) Tj\n";
        $text .= "0 -15 Td\n(G1/0049, Olive Wood Villa, Golf City, Lucknow, Uttar Pradesh - 226030) Tj\n";
        $text .= "0 -15 Td\n(Email: info@itlcfoundation.org | Website: www.itlcfoundation.org) Tj\n";
        
        $text .= "0 -40 Td\n/F2 14 Tf\n(DONATION RECEIPT UNDER SECTION 80G) Tj\n";
        
        $text .= "0 -40 Td\n/F1 11 Tf\n(Receipt No: " . $receiptNo . ") Tj\n";
        $text .= "200 0 Td\n(Date: " . $donationDate . ") Tj\n";
        $text .= "-200 -30 Td\n(Received with thanks from: " . $donation['donor_name'] . ") Tj\n";
        $text .= "0 -20 Td\n(Email Address: " . $donation['donor_email'] . ") Tj\n";
        $text .= "0 -20 Td\n(Donation Type: " . ($donation['type'] === 'monthly' ? 'Monthly Support' : 'One-time Donation') . ") Tj\n";
        $text .= "0 -20 Td\n(Amount Received: INR " . number_format($donation['amount'], 2) . ") Tj\n";
        
        $text .= "0 -50 Td\n(PAN: AABTI8329D | Registration No: LKO/80G/2023-24/1109A) Tj\n";
        $text .= "0 -15 Td\n(Donations are exempt from income tax under Section 80G of the Income Tax Act, 1961.) Tj\n";
        
        $text .= "150 -60 Td\n/F2 11 Tf\n(For ITLC FOUNDATION) Tj\n";
        $text .= "0 -40 Td\n(Authorized Signatory) Tj\n";
        $text .= "ET";
        
        $pdf .= "5 0 obj\n<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream\nendobj\n";
        $pdf .= "xref\n0 8\n0000000000 65535 f\n";
        $pdf .= "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n250\n%%EOF";
        
        return $pdf;
    }
}

// Receipt Email sender
function sendReceiptEmail($donation, $pdfContent) {
    $receiptNo = "ITLC-80G-" . $donation['id'] . "-" . date('Y', strtotime($donation['created_at']));
    $amountFormatted = number_format($donation['amount'], 2);
    $typeLabel = $donation['type'] === 'monthly' ? 'Monthly Subscription' : 'One-time Donation';
    $to = $donation['donor_email'];
    $subject = "Thank you for your donation to ITLC Foundation - 80G Tax Receipt Included";
    
    $smtpUser = getenv('SMTP_USER') ?: '';
    
    $htmlContent = "
        <div style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;\">
          <h2 style=\"color: #1B5E20; text-align: center; border-bottom: 2px solid #1B5E20; padding-bottom: 12px; margin-top: 0;\">ITLC FOUNDATION</h2>
          <p>Dear <strong>" . htmlspecialchars($donation['donor_name']) . "</strong>,</p>
          <p>Thank you so much for your generous support. We have successfully received your donation of <strong>INR " . $amountFormatted . "</strong>.</p>
          <p>Your donation will be utilized to support our key welfare, animal rescue, and tree plantation drives in Lucknow and across Uttar Pradesh.</p>
          
          <div style=\"background-color: #f7fafc; border-left: 4px solid #1B5E20; padding: 16px; margin: 20px 0; border-radius: 4px;\">
            <p style=\"margin: 0; font-size: 14px;\"><strong>Donation Details:</strong></p>
            <p style=\"margin: 4px 0 0 0; font-size: 14px;\">Amount: INR " . $amountFormatted . "</p>
            <p style=\"margin: 4px 0 0 0; font-size: 14px;\">Type: " . $typeLabel . "</p>
            <p style=\"margin: 4px 0 0 0; font-size: 14px;\">Receipt Number: " . $receiptNo . "</p>
          </div>
          <p>Your official 80G Tax Exemption Receipt is attached to this email.</p>
          <p>If you have any questions, feel free to write to us at info@itlcfoundation.org.</p>
          <br/>
          <p style=\"margin-bottom: 0;\">Warm regards,</p>
          <p style=\"margin-top: 4px; font-weight: bold; color: #1B5E20;\">ITLC Foundation Team</p>
        </div>
    ";
    
    if ($smtpUser && $smtpUser !== 'your_email@gmail.com') {
        $filename = "ITLC_Donation_Receipt_" . $donation['id'] . ".pdf";
        $attachment = chunk_split(base64_encode($pdfContent));
        $uid = md5(uniqid(time()));
        
        $header = "From: ITLC Foundation <info@itlcfoundation.org>\r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-Type: multipart/mixed; boundary=\"" . $uid . "\"\r\n\r\n";
        
        $message = "--" . $uid . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $htmlContent . "\r\n\r\n";
        $message .= "--" . $uid . "\r\n";
        $message .= "Content-Type: application/pdf; name=\"" . $filename . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . $filename . "\"\r\n\r\n";
        $message .= $attachment . "\r\n\r\n";
        $message .= "--" . $uid . "--";
        
        mail($to, $subject, $message, $header);
    } else {
        $logMsg = date('[Y-m-d H:i:s]') . " [SMTP MOCK] Email to " . $to . " | Amount: INR " . $amountFormatted . " | Receipt: " . $receiptNo . "\n";
        file_put_contents(__DIR__ . '/../mail_mock.log', $logMsg, FILE_APPEND);
    }
}

// Twilio SMS Dispatcher
function sendSMS($to, $message) {
    $sid = getenv('TWILIO_SID') ?: '';
    $token = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $from = getenv('TWILIO_FROM') ?: '';
    
    if (!$sid || !$token || !$from || strpos($sid, 'your_twilio') !== false) {
        $logMsg = date('[Y-m-d H:i:s]') . " [SMS MOCK] SMS to " . $to . " | Message: " . $message . "\n";
        file_put_contents(__DIR__ . '/../sms_mock.log', $logMsg, FILE_APPEND);
        return true;
    }
    
    $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'To' => $to,
        'From' => $from,
        'Body' => $message
    ]));
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    $response = curl_exec($ch);
    curl_close($ch);
    return true;
}

// Transaction lock and post-payment processes handler
function completePaymentSuccess($pdo, $donationId, $paymentId, $signatureVal) {
    $stmt = $pdo->prepare("SELECT id, status, donor_name, donor_email, donor_phone, amount, type, razorpay_subscription_id, created_at FROM donations WHERE id = ?");
    $stmt->execute([$donationId]);
    $donation = $stmt->fetch();
    
    if (!$donation || $donation['status'] === 'success') {
        return; // Already processed
    }
    
    // Update status to success
    $stmt = $pdo->prepare("UPDATE donations SET status = ?, razorpay_payment_id = ?, razorpay_signature = ? WHERE id = ?");
    $stmt->execute(['success', $paymentId, $signatureVal, $donationId]);
    
    $donation['status'] = 'success';
    $donation['razorpay_payment_id'] = $paymentId;
    $donation['razorpay_signature'] = $signatureVal;

    $logMsg = date('[Y-m-d H:i:s]') . " Payment Success Processed | Donation ID: " . $donation['id'] . " | Donor: " . $donation['donor_name'] . " | Amount: INR " . $donation['amount'] . "\n";
    file_put_contents(__DIR__ . '/../webhook_debug.log', $logMsg, FILE_APPEND);
    
    // Process subscription active status if monthly
    if ($donation['type'] === 'monthly' && $donation['razorpay_subscription_id']) {
        $stmt = $pdo->prepare("INSERT INTO subscriptions (donor_name, donor_email, donor_phone, amount, status, razorpay_subscription_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = 'active'");
        $stmt->execute([
            $donation['donor_name'],
            $donation['donor_email'],
            $donation['donor_phone'],
            $donation['amount'],
            'active',
            $donation['razorpay_subscription_id']
        ]);
    }
    
    // Send SMS
    $smsMessage = "Dear " . $donation['donor_name'] . ", thank you for your generous donation of INR " . number_format($donation['amount'], 2) . " to ITLC Foundation. Your support empowers our key social welfare, environment conservation, and stray animal care projects in Lucknow and Uttar Pradesh. Warm regards, ITLC Foundation Team.";
    sendSMS($donation['donor_phone'], $smsMessage);
    
    // Generate receipt and send email
    $pdfContent = ReceiptPDF::generate($donation);
    sendReceiptEmail($donation, $pdfContent);
}

// ----------------------------------------------------
// ROUTING TABLE
// ----------------------------------------------------

// 1. ADMIN AUTHENTICATION
if ($route === 'auth/login' && $method === 'POST') {
    $body = getRequestBody();
    $username = $body['username'] ?? '';
    $password = $body['password'] ?? '';
    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(["error" => "Username and password are required"]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(["error" => "Invalid username or password"]);
        exit;
    }
    
    $token = JWT::sign([
        "id" => $admin['id'],
        "username" => $admin['username'],
        "is_subadmin" => (int)$admin['is_subadmin']
    ]);
    
    echo json_encode([
        "message" => "Login successful",
        "token" => $token,
        "admin" => [
            "id" => (int)$admin['id'],
            "username" => $admin['username'],
            "email" => $admin['email'],
            "is_subadmin" => (int)$admin['is_subadmin']
        ]
    ]);
    exit;
}

if ($route === 'auth/create-subadmin' && $method === 'POST') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $username = $body['username'] ?? '';
    $password = $body['password'] ?? '';
    $email = $body['email'] ?? '';
    
    if (!$username || !$password || !$email) {
        http_response_code(400);
        echo json_encode(["error" => "Username, password, and email are required"]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(["error" => "Username is already taken"]);
        exit;
    }
    
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, email, is_subadmin) VALUES (?, ?, ?, 1)");
    $stmt->execute([$username, $hash, $email]);
    
    http_response_code(201);
    echo json_encode(["message" => "Sub-admin account created successfully"]);
    exit;
}

if ($route === 'auth/change-password' && $method === 'POST') {
    $admin = getAuthenticatedAdmin();
    $body = getRequestBody();
    $currentPassword = $body['currentPassword'] ?? '';
    $newPassword = $body['newPassword'] ?? '';
    
    if (!$currentPassword || !$newPassword) {
        http_response_code(400);
        echo json_encode(["error" => "Current password and new password are required"]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
    $stmt->execute([$admin['id']]);
    $res = $stmt->fetch();
    if (!$res || !password_verify($currentPassword, $res['password_hash'])) {
        http_response_code(400);
        echo json_encode(["error" => "Incorrect current password"]);
        exit;
    }
    
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
    $stmt->execute([$newHash, $admin['id']]);
    
    echo json_encode(["message" => "Password changed successfully"]);
    exit;
}

// 2. NAVBAR LINKS
if ($route === 'content/navbar' && $method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM navbar_links ORDER BY sort_order ASC');
    $rows = $stmt->fetchAll();
    
    $parents = array_filter($rows, function($item) {
        return $item['parent_id'] === null || $item['parent_id'] === 0 || $item['parent_id'] === '0';
    });
    
    $result = [];
    foreach ($parents as $parent) {
        $children = array_filter($rows, function($item) use ($parent) {
            return (int)$item['parent_id'] === (int)$parent['id'];
        });
        
        $childrenFormatted = [];
        foreach ($children as $c) {
            $childrenFormatted[] = [
                "id" => (int)$c['id'],
                "label" => $c['label'],
                "href" => $c['href'],
                "sort_order" => (int)$c['sort_order'],
                "page_title" => $c['page_title'],
                "page_content" => $c['page_content'],
                "page_image_url" => $c['page_image_url'],
                "page_image_hint" => $c['page_image_hint']
            ];
        }
        
        $item = [
            "id" => (int)$parent['id'],
            "label" => $parent['label'],
            "href" => $parent['href'],
            "sort_order" => (int)$parent['sort_order'],
            "page_title" => $parent['page_title'],
            "page_content" => $parent['page_content'],
            "page_image_url" => $parent['page_image_url'],
            "page_image_hint" => $parent['page_image_hint']
        ];
        if (count($childrenFormatted) > 0) {
            $item["children"] = $childrenFormatted;
        }
        $result[] = $item;
    }
    echo json_encode($result);
    exit;
}

if ($route === 'content/navbar' && $method === 'POST') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $label = $body['label'] ?? '';
    $href = $body['href'] ?? '';
    $parent_id = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;
    $sort_order = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    $page_title = $body['page_title'] ?? '';
    $page_content = $body['page_content'] ?? '';
    $page_image_url = $body['page_image_url'] ?? '';
    $page_image_hint = $body['page_image_hint'] ?? '';
    
    if (!$label || !$href) {
        http_response_code(400);
        echo json_encode(["error" => "Label and href are required"]);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO navbar_links (label, href, parent_id, sort_order, page_title, page_content, page_image_url, page_image_hint) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$label, $href, $parent_id, $sort_order, $page_title, $page_content, $page_image_url, $page_image_hint]);
    
    http_response_code(201);
    echo json_encode(["message" => "Navbar link created", "id" => (int)$pdo->lastInsertId()]);
    exit;
}

if (preg_match('/^content\/navbar\/([0-9]+)$/', $route, $matches) && $method === 'PUT') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $body = getRequestBody();
    $label = $body['label'] ?? '';
    $href = $body['href'] ?? '';
    $parent_id = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;
    $sort_order = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    $page_title = $body['page_title'] ?? '';
    $page_content = $body['page_content'] ?? '';
    $page_image_url = $body['page_image_url'] ?? '';
    $page_image_hint = $body['page_image_hint'] ?? '';
    
    if (!$label || !$href) {
        http_response_code(400);
        echo json_encode(["error" => "Label and href are required"]);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE navbar_links SET label = ?, href = ?, parent_id = ?, sort_order = ?, page_title = ?, page_content = ?, page_image_url = ?, page_image_hint = ? WHERE id = ?");
    $stmt->execute([$label, $href, $parent_id, $sort_order, $page_title, $page_content, $page_image_url, $page_image_hint, $id]);
    echo json_encode(["message" => "Navbar link updated"]);
    exit;
}

if (preg_match('/^content\/navbar\/([0-9]+)$/', $route, $matches) && $method === 'DELETE') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $stmt = $pdo->prepare("DELETE FROM navbar_links WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["message" => "Navbar link deleted"]);
    exit;
}

// 3. HERO CONTENT
if ($route === 'content/hero' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM hero_content ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    echo json_encode($row ?: ["heading" => "", "paragraph" => "", "image_url" => ""]);
    exit;
}

if ($route === 'content/hero' && $method === 'PUT') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $heading = $body['heading'] ?? '';
    $paragraph = $body['paragraph'] ?? '';
    $image_url = $body['image_url'] ?? '';
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM hero_content");
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $stmt = $pdo->prepare("UPDATE hero_content SET heading = ?, paragraph = ?, image_url = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$heading, $paragraph, $image_url]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO hero_content (heading, paragraph, image_url) VALUES (?, ?, ?)");
        $stmt->execute([$heading, $paragraph, $image_url]);
    }
    echo json_encode(["message" => "Hero content updated"]);
    exit;
}

// 4. KEY PROJECTS
if ($route === 'content/projects' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM key_projects ORDER BY id DESC");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($route === 'content/projects' && $method === 'POST') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $title = $body['title'] ?? '';
    $category = $body['category'] ?? '';
    $status = $body['status'] ?? '';
    $description = $body['description'] ?? '';
    $image_url = $body['image_url'] ?? '';
    
    $stmt = $pdo->prepare("INSERT INTO key_projects (title, category, status, description, image_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$title, $category, $status, $description, $image_url]);
    
    http_response_code(201);
    echo json_encode(["message" => "Project created", "id" => (int)$pdo->lastInsertId()]);
    exit;
}

if (preg_match('/^content\/projects\/([0-9]+)$/', $route, $matches) && $method === 'PUT') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $body = getRequestBody();
    $title = $body['title'] ?? '';
    $category = $body['category'] ?? '';
    $status = $body['status'] ?? '';
    $description = $body['description'] ?? '';
    $image_url = $body['image_url'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE key_projects SET title = ?, category = ?, status = ?, description = ?, image_url = ? WHERE id = ?");
    $stmt->execute([$title, $category, $status, $description, $image_url, $id]);
    echo json_encode(["message" => "Project updated"]);
    exit;
}

if (preg_match('/^content\/projects\/([0-9]+)$/', $route, $matches) && $method === 'DELETE') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $stmt = $pdo->prepare("DELETE FROM key_projects WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["message" => "Project deleted"]);
    exit;
}

// 5. FAQS
if ($route === 'content/faqs' && $method === 'GET') {
    $page = $_GET['page'] ?? '';
    if ($page) {
        $stmt = $pdo->prepare("SELECT * FROM faq_items WHERE page = ? ORDER BY id ASC");
        $stmt->execute([$page]);
    } else {
        $stmt = $pdo->query("SELECT * FROM faq_items ORDER BY id DESC");
    }
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($route === 'content/faqs' && $method === 'POST') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $question = $body['question'] ?? '';
    $answer = $body['answer'] ?? '';
    $page = $body['page'] ?? 'general';
    
    $stmt = $pdo->prepare("INSERT INTO faq_items (question, answer, page) VALUES (?, ?, ?)");
    $stmt->execute([$question, $answer, $page]);
    
    http_response_code(201);
    echo json_encode(["message" => "FAQ created", "id" => (int)$pdo->lastInsertId()]);
    exit;
}

if (preg_match('/^content\/faqs\/([0-9]+)$/', $route, $matches) && $method === 'PUT') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $body = getRequestBody();
    $question = $body['question'] ?? '';
    $answer = $body['answer'] ?? '';
    $page = $body['page'] ?? 'general';
    
    $stmt = $pdo->prepare("UPDATE faq_items SET question = ?, answer = ?, page = ? WHERE id = ?");
    $stmt->execute([$question, $answer, $page, $id]);
    echo json_encode(["message" => "FAQ updated"]);
    exit;
}

if (preg_match('/^content\/faqs\/([0-9]+)$/', $route, $matches) && $method === 'DELETE') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $stmt = $pdo->prepare("DELETE FROM faq_items WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["message" => "FAQ deleted"]);
    exit;
}

// 6. GALLERY (MOMENTS OF IMPACT)
if ($route === 'content/gallery' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM moments_of_impact ORDER BY sort_order ASC, id DESC");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($route === 'content/gallery' && $method === 'POST') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $image_url = $body['image_url'] ?? '';
    $description = $body['description'] ?? '';
    $category = $body['category'] ?? 'Events';
    $image_hint = $body['image_hint'] ?? '';
    $sort_order = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    
    $stmt = $pdo->prepare("INSERT INTO moments_of_impact (image_url, description, category, image_hint, sort_order) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$image_url, $description, $category, $image_hint, $sort_order]);
    
    http_response_code(201);
    echo json_encode(["message" => "Gallery item created", "id" => (int)$pdo->lastInsertId()]);
    exit;
}

if (preg_match('/^content\/gallery\/([0-9]+)$/', $route, $matches) && $method === 'PUT') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $body = getRequestBody();
    $image_url = $body['image_url'] ?? '';
    $description = $body['description'] ?? '';
    $category = $body['category'] ?? 'Events';
    $image_hint = $body['image_hint'] ?? '';
    $sort_order = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    
    $stmt = $pdo->prepare("UPDATE moments_of_impact SET image_url = ?, description = ?, category = ?, image_hint = ?, sort_order = ? WHERE id = ?");
    $stmt->execute([$image_url, $description, $category, $image_hint, $sort_order, $id]);
    echo json_encode(["message" => "Gallery item updated"]);
    exit;
}

if (preg_match('/^content\/gallery\/([0-9]+)$/', $route, $matches) && $method === 'DELETE') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $stmt = $pdo->prepare("DELETE FROM moments_of_impact WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["message" => "Gallery item deleted"]);
    exit;
}

// 7. BLOG POSTS
if ($route === 'content/blogs' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM blogs ORDER BY id DESC");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($route === 'content/blogs' && $method === 'POST') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $title = $body['title'] ?? '';
    $excerpt = $body['excerpt'] ?? '';
    $category = $body['category'] ?? 'General';
    $image_url = $body['image_url'] ?? '';
    $image_hint = $body['image_hint'] ?? '';
    
    $stmt = $pdo->prepare("INSERT INTO blogs (title, excerpt, category, image_url, image_hint) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$title, $excerpt, $category, $image_url, $image_hint]);
    
    http_response_code(201);
    echo json_encode(["message" => "Blog post created", "id" => (int)$pdo->lastInsertId()]);
    exit;
}

if (preg_match('/^content\/blogs\/([0-9]+)$/', $route, $matches) && $method === 'PUT') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $body = getRequestBody();
    $title = $body['title'] ?? '';
    $excerpt = $body['excerpt'] ?? '';
    $category = $body['category'] ?? 'General';
    $image_url = $body['image_url'] ?? '';
    $image_hint = $body['image_hint'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE blogs SET title = ?, excerpt = ?, category = ?, image_url = ?, image_hint = ? WHERE id = ?");
    $stmt->execute([$title, $excerpt, $category, $image_url, $image_hint, $id]);
    echo json_encode(["message" => "Blog post updated"]);
    exit;
}

if (preg_match('/^content\/blogs\/([0-9]+)$/', $route, $matches) && $method === 'DELETE') {
    getAuthenticatedAdmin();
    $id = $matches[1];
    $stmt = $pdo->prepare("DELETE FROM blogs WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(["message" => "Blog post deleted"]);
    exit;
}

// 8. FOOTER CONTENT
if ($route === 'content/footer' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM footer_content ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    echo json_encode($row ?: [
        "cta_heading" => "",
        "cta_subheading" => "",
        "about_text" => "",
        "facebook_url" => "#",
        "twitter_url" => "#",
        "instagram_url" => "#",
        "linkedin_url" => "#",
        "contact_email" => "",
        "contact_address" => ""
    ]);
    exit;
}

if ($route === 'content/footer' && $method === 'PUT') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $cta_heading = $body['cta_heading'] ?? '';
    $cta_subheading = $body['cta_subheading'] ?? '';
    $about_text = $body['about_text'] ?? '';
    $facebook_url = $body['facebook_url'] ?? '#';
    $twitter_url = $body['twitter_url'] ?? '#';
    $instagram_url = $body['instagram_url'] ?? '#';
    $linkedin_url = $body['linkedin_url'] ?? '#';
    $contact_email = $body['contact_email'] ?? '';
    $contact_address = $body['contact_address'] ?? '';
    
    if (!$cta_heading || !$cta_subheading || !$about_text || !$contact_email || !$contact_address) {
        http_response_code(400);
        echo json_encode(["error" => "Required fields: cta_heading, cta_subheading, about_text, contact_email, contact_address"]);
        exit;
    }
    
    $stmt = $pdo->query("SELECT id FROM footer_content LIMIT 1");
    $row = $stmt->fetch();
    
    if ($row) {
        $stmt = $pdo->prepare("UPDATE footer_content SET cta_heading = ?, cta_subheading = ?, about_text = ?, facebook_url = ?, twitter_url = ?, instagram_url = ?, linkedin_url = ?, contact_email = ?, contact_address = ? WHERE id = ?");
        $stmt->execute([$cta_heading, $cta_subheading, $about_text, $facebook_url, $twitter_url, $instagram_url, $linkedin_url, $contact_email, $contact_address, $row['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO footer_content (cta_heading, cta_subheading, about_text, facebook_url, twitter_url, instagram_url, linkedin_url, contact_email, contact_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cta_heading, $cta_subheading, $about_text, $facebook_url, $twitter_url, $instagram_url, $linkedin_url, $contact_email, $contact_address]);
    }
    echo json_encode(["message" => "Footer content updated"]);
    exit;
}

// 9. DONATE PAGE GENERAL CONTENT
if ($route === 'content/donate-content' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM donate_page_content WHERE id = 1");
    $row = $stmt->fetch();
    
    $upiId = getenv('UPI_ID') ?: '8090311359@ybl';
    $cleanQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode("upi://pay?pa=" . $upiId . "&pn=ITLC Foundation&cu=INR");
    
    if ($row) {
        if (empty($row['qr_image_url']) || strpos($row['qr_image_url'], 'qr.png') !== false) {
            $row['qr_image_url'] = $cleanQrUrl;
        }
        echo json_encode($row);
    } else {
        echo json_encode([
            "main_heading" => "Make a Difference in Lucknow Today",
            "main_subheading" => "Your support is crucial for our social welfare mission in Uttar Pradesh.",
            "side_image_url" => "/pro/c.png",
            "qr_title" => "Scan to Support Our NGO",
            "qr_description" => "Quickly donate to our Lucknow projects via any UPI App",
            "qr_image_url" => $cleanQrUrl,
            "transparency_text" => "Hum har donation ka proper utilization record maintain karte hain aur donors ko updates provide karte hain. Your trust is our biggest asset. As a top NGO in Lucknow, transparency is our priority."
        ]);
    }
    exit;
}

if ($route === 'content/donate-content' && $method === 'PUT') {
    getAuthenticatedAdmin();
    $body = getRequestBody();
    $main_heading = $body['main_heading'] ?? '';
    $main_subheading = $body['main_subheading'] ?? '';
    $side_image_url = $body['side_image_url'] ?? '';
    $qr_title = $body['qr_title'] ?? '';
    $qr_description = $body['qr_description'] ?? '';
    $qr_image_url = $body['qr_image_url'] ?? '';
    $transparency_text = $body['transparency_text'] ?? '';
    
    $stmt = $pdo->query("SELECT id FROM donate_page_content WHERE id = 1");
    $row = $stmt->fetch();
    
    if ($row) {
        $stmt = $pdo->prepare("UPDATE donate_page_content SET main_heading = ?, main_subheading = ?, side_image_url = ?, qr_title = ?, qr_description = ?, qr_image_url = ?, transparency_text = ? WHERE id = 1");
        $stmt->execute([$main_heading, $main_subheading, $side_image_url, $qr_title, $qr_description, $qr_image_url, $transparency_text]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO donate_page_content (id, main_heading, main_subheading, side_image_url, qr_title, qr_description, qr_image_url, transparency_text) VALUES (1, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$main_heading, $main_subheading, $side_image_url, $qr_title, $qr_description, $qr_image_url, $transparency_text]);
    }
    echo json_encode(["message" => "Donate content updated"]);
    exit;
}

// 10. FILE UPLOADS
if ($route === 'content/upload' && $method === 'POST') {
    getAuthenticatedAdmin();
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["error" => "No file uploaded or upload error"]);
        exit;
    }
    
    $file = $_FILES['image'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(["error" => "Only images (jpg, png, webp, gif, svg) are allowed!"]);
        exit;
    }
    
    $uploadDir = __DIR__ . '/../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $filename = uniqid() . '-' . mt_rand(100000, 999999) . '.' . $ext;
    $destPath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https" : "http";
        $fileUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/foundation-backend/uploads/" . $filename;
        echo json_encode(["url" => $fileUrl]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to save uploaded file"]);
    }
    exit;
}

// 11. DONATION SYSTEM TRANSACTIONS
if ($route === 'donations/create-order' && $method === 'POST') {
    $body = getRequestBody();
    $name = $body['name'] ?? '';
    $email = $body['email'] ?? '';
    $phone = $body['phone'] ?? '';
    $amount = $body['amount'] ?? '';
    
    if (!$name || !$email || !$phone || !$amount) {
        http_response_code(400);
        echo json_encode(["error" => "Name, email, phone, and amount are required"]);
        exit;
    }
    
    $rzpKeyId = getenv('RAZORPAY_KEY_ID') ?: '';
    $rzpKeySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';
    
    $orderId = '';
    $isMock = true;
    
    if ($rzpKeyId && $rzpKeySecret) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'amount' => (int)($amount * 100), // in paise
            'currency' => 'INR',
            'receipt' => 'rcpt_ot_' . time() . mt_rand(10, 99)
        ]));
        curl_setopt($ch, CURLOPT_USERPWD, $rzpKeyId . ':' . $rzpKeySecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $orderData = json_decode($response, true);
        if ($httpCode === 200 && isset($orderData['id'])) {
            $orderId = $orderData['id'];
            $isMock = false;
        } else {
            error_log("Razorpay Order Creation Failed: " . $response);
        }
    }
    
    if ($isMock) {
        $orderId = 'mock_order_' . time() . mt_rand(100, 999);
    }
    
    $stmt = $pdo->prepare("INSERT INTO donations (donor_name, donor_email, donor_phone, amount, type, status, razorpay_order_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $amount, 'one-time', 'pending', $orderId]);
    $donationId = $pdo->lastInsertId();
    
    echo json_encode([
        "orderId" => $orderId,
        "amount" => $amount,
        "isMock" => $isMock,
        "donationId" => (int)$donationId,
        "keyId" => $rzpKeyId ?: "rzp_test_your_razorpay_key_id"
    ]);
    exit;
}

if ($route === 'donations/create-subscription' && $method === 'POST') {
    $body = getRequestBody();
    $name = $body['name'] ?? '';
    $email = $body['email'] ?? '';
    $phone = $body['phone'] ?? '';
    $amount = $body['amount'] ?? '';
    
    if (!$name || !$email || !$phone || !$amount) {
        http_response_code(400);
        echo json_encode(["error" => "Name, email, phone, and amount are required"]);
        exit;
    }
    
    $rzpKeyId = getenv('RAZORPAY_KEY_ID') ?: '';
    $rzpKeySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';
    
    $subscriptionId = '';
    $isMock = true;
    
    if ($rzpKeyId && $rzpKeySecret) {
        $planId = getenv('RAZORPAY_PLAN_ID_' . $amount) ?: getenv('RAZORPAY_PLAN_ID');
        if ($planId) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/subscriptions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'plan_id' => $planId,
                'total_count' => 12,
                'quantity' => 1,
                'customer_notify' => 1
            ]));
            curl_setopt($ch, CURLOPT_USERPWD, $rzpKeyId . ':' . $rzpKeySecret);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $subData = json_decode($response, true);
            if ($httpCode === 200 && isset($subData['id'])) {
                $subscriptionId = $subData['id'];
                $isMock = false;
            } else {
                error_log("Razorpay Subscription Creation Failed: " . $response);
            }
        }
    }
    
    if ($isMock) {
        $subscriptionId = 'mock_sub_' . time() . mt_rand(100, 999);
    }
    
    $stmt = $pdo->prepare("INSERT INTO donations (donor_name, donor_email, donor_phone, amount, type, status, razorpay_subscription_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $amount, 'monthly', 'pending', $subscriptionId]);
    $donationId = $pdo->lastInsertId();
    
    echo json_encode([
        "subscriptionId" => $subscriptionId,
        "amount" => $amount,
        "isMock" => $isMock,
        "donationId" => (int)$donationId,
        "keyId" => $rzpKeyId ?: "rzp_test_your_razorpay_key_id"
    ]);
    exit;
}

if ($route === 'donations/verify' && $method === 'POST') {
    $body = getRequestBody();
    $razorpay_order_id = $body['razorpay_order_id'] ?? null;
    $razorpay_subscription_id = $body['razorpay_subscription_id'] ?? null;
    $razorpay_payment_id = $body['razorpay_payment_id'] ?? null;
    $razorpay_signature = $body['razorpay_signature'] ?? null;
    $donationId = $body['donationId'] ?? null;
    $isMock = $body['isMock'] ?? false;
    
    if (!$donationId) {
        http_response_code(400);
        echo json_encode(["error" => "donationId is required"]);
        exit;
    }
    
    $paymentId = $razorpay_payment_id;
    $signatureVal = $razorpay_signature;
    
    $rzpKeySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';
    $rzpKeyId = getenv('RAZORPAY_KEY_ID') ?: '';
    
    // Only allow simulation if keys are set to placeholder values, to keep endpoints secure
    $isLocalTesting = ($rzpKeyId === 'rzp_test_your_razorpay_key_id' || empty($rzpKeySecret));
    
    if ($isLocalTesting) {
        if (empty($paymentId)) $paymentId = 'mock_pay_' . time() . mt_rand(100, 999);
        if (empty($signatureVal)) $signatureVal = 'mock_sig_' . time() . mt_rand(100, 999);
    } else {
        if (!$paymentId || !$signatureVal) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Payment ID and signature are required"]);
            exit;
        }
        
        $expectedSignature = '';
        if ($razorpay_order_id) {
            $expectedSignature = hash_hmac('sha256', $razorpay_order_id . '|' . $paymentId, $rzpKeySecret);
        } else if ($razorpay_subscription_id) {
            $expectedSignature = hash_hmac('sha256', $paymentId . '|' . $razorpay_subscription_id, $rzpKeySecret);
        }
        
        if ($expectedSignature !== $signatureVal) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Signature verification failed"]);
            exit;
        }
    }
    
    completePaymentSuccess($pdo, $donationId, $paymentId, $signatureVal);
    
    echo json_encode(["status" => "success", "message" => "Payment verified and logged."]);
    exit;
}

if ($route === 'donations/razorpay-webhook' && $method === 'POST') {
    $body = getRequestBody();
    $event = $body['event'] ?? '';
    $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
    
    $logMsg = date('[Y-m-d H:i:s]') . " Webhook Received | Event: " . $event . " | Signature present: " . ($signature ? 'Yes' : 'No') . "\n";
    file_put_contents(__DIR__ . '/../webhook_debug.log', $logMsg, FILE_APPEND);
    
    $webhookSecret = getenv('RAZORPAY_WEBHOOK_SECRET') ?: '';
    if ($webhookSecret && $signature) {
        $payload = getRawRequestBody();
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        if ($expectedSignature !== $signature) {
            $errLog = date('[Y-m-d H:i:s]') . " Webhook Signature Check Failed! Expected: $expectedSignature | Got: $signature\n";
            file_put_contents(__DIR__ . '/../webhook_debug.log', $errLog, FILE_APPEND);
            http_response_code(400);
            echo json_encode(["error" => "Invalid signature"]);
            exit;
        }
    }
    
    if ($event === 'order.paid' || $event === 'payment.captured') {
        $payload = $body['payload'] ?? [];
        $paymentEntity = $payload['payment']['entity'] ?? [];
        $orderId = $paymentEntity['order_id'] ?? '';
        $paymentId = $paymentEntity['id'] ?? '';
        
        if ($orderId) {
            $stmt = $pdo->prepare("SELECT id FROM donations WHERE razorpay_order_id = ?");
            $stmt->execute([$orderId]);
            $donationId = $stmt->fetchColumn();
            if ($donationId) {
                completePaymentSuccess($pdo, $donationId, $paymentId, $signature ?: 'webhook_verified');
            }
        }
    } else if ($event === 'subscription.charged') {
        $payload = $body['payload'] ?? [];
        $subscriptionEntity = $payload['subscription']['entity'] ?? [];
        $paymentEntity = $payload['payment']['entity'] ?? [];
        
        $subscriptionId = $subscriptionEntity['id'] ?? '';
        $paymentId = $paymentEntity['id'] ?? '';
        $amount = ($paymentEntity['amount'] ?? 0) / 100;
        
        $stmt = $pdo->prepare("SELECT donor_name, donor_email, donor_phone FROM subscriptions WHERE razorpay_subscription_id = ?");
        $stmt->execute([$subscriptionId]);
        $subs = $stmt->fetch();
        
        if ($subs) {
            $stmt = $pdo->prepare("SELECT id FROM donations WHERE razorpay_payment_id = ?");
            $stmt->execute([$paymentId]);
            $exists = $stmt->fetchColumn();
            
            if (!$exists) {
                $stmt = $pdo->prepare("INSERT INTO donations (donor_name, donor_email, donor_phone, amount, type, status, razorpay_subscription_id, razorpay_payment_id, razorpay_signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $subs['donor_name'],
                    $subs['donor_email'],
                    $subs['donor_phone'],
                    $amount,
                    'monthly',
                    'success',
                    $subscriptionId,
                    $paymentId,
                    'webhook_verified'
                ]);
                $newDonationId = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("SELECT * FROM donations WHERE id = ?");
                $stmt->execute([$newDonationId]);
                $donation = $stmt->fetch();
                
                if ($donation) {
                    $smsMessage = "Dear " . $donation['donor_name'] . ", thank you for your monthly subscription payment of INR " . number_format($donation['amount'], 2) . " to ITLC Foundation. Your support empowers our key social welfare, environment conservation, and stray animal care projects in Lucknow and Uttar Pradesh. Warm regards, ITLC Foundation Team.";
                    sendSMS($donation['donor_phone'], $smsMessage);
                    
                    $pdfContent = ReceiptPDF::generate($donation);
                    sendReceiptEmail($donation, $pdfContent);
                }
            }
        }
    }
    echo json_encode(["status" => "ok"]);
    exit;
}

if ($route === 'donations' && $method === 'GET') {
    getAuthenticatedAdmin();
    $stmt = $pdo->query("SELECT * FROM donations ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll());
    exit;
}

// Route not found fallback
http_response_code(404);
echo json_encode(["error" => "Route not found (" . htmlspecialchars($route) . ")"]);
exit;
