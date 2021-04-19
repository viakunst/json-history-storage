<?php

use Steampixel\Route;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

require dirname(__DIR__).'/vendor/autoload.php';
$config = require_once dirname(__DIR__).'/config.php';

// verify database connection
$db = null;
try {
    $host = $config['db_host'];
    $name = $config['db_name'];
    $db = new PDO("mysql:host=$host;dbname=$name", $config['db_user'], $config['db_pass']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500); // Server error
    echo $e->getMessage();
    die;
}

// verify access token in Authorization header
$matches = [];
$bearer_regex = '/^Bearer ([A-Za-z0-9-_\.\~\+\/]+=*)$/';
if (!isset($_SERVER['HTTP_AUTHORIZATION']) || !preg_match($bearer_regex, $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
    http_response_code(401); // Unauthorized
    header('WWW-Authenticate: Bearer realm="Access to the profile data history storage"');
    echo "Please provide a valid access token in the Authorization header.";
    die;
}

// test whether provided access token is valid 
$oidc = new OpenIDConnectClient($config['oidc_provider'], $config['oidc_id'] ?? null, $config['oidc_secret'] ?? null, $config['oidc_issuer'] ?? null);
$oidc->setAccessToken($matches[1]);
$user = null;
try {
    $user = $oidc->requestUserInfo();
} catch (OpenIDConnectClientException $e) {
    http_response_code(500);
    echo "Problem when connecting to the OpenID Connect server:";
    echo $e->getMessage();
    die;
}

// install the utility
Route::add('/install', function() use ($db) {
    try {
        if ($db->query("SHOW TABLES LIKE 'profile_history'")->num_rows > 0) {
            http_response_code(409); // Conflict
            echo "The profile_history table already exists, installation was performed earlier";
            die;
        }

        $sql = "CREATE TABLE `profile_history` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `author` VARCHAR(255) NOT NULL,
            `profile` VARCHAR(255) NOT NULL,
            `profile_version` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `profile_content` TEXT NOT NULL
        )";

        $db->exec($sql);
    } catch(PDOException $e) {
        http_response_code(500);
        echo $e->getMessage();
        die;
    }

    echo 'Success!';
});

// list all available profiles
Route::add('/profile', function() use ($config, $db, $user) {
    api_headers($config);
    try {
        if (verify_admin($user)) {
            $stmt = $db->prepare("SELECT DISTINCT(`profile`) FROM profile_history");
        } else {
            $stmt = $db->prepare("SELECT DISTINCT(`profile`) FROM profile_history WHERE `profile` LIKE ?");
            $stmt->bindParam(1, $user->sub);
        }
        $stmt->execute();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        die;
    }
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}, ['get', 'options']);

// get profile history
Route::add('/profile/([A-Za-z0-9-_\.\~]{1,255})', function(string $profile) use ($config, $db, $user) {
    api_headers($config);
    if (!verify_admin($user) && $profile !== $user->sub) {
        http_response_code(403); // Forbidden
        die;
    }

    try {
        $stmt = $db->prepare("SELECT `profile_version`, `author` FROM `profile_history` WHERE `profile` LIKE ?");
        $stmt->bindParam(1, $profile);
        $stmt->execute();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        die;
    }
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}, ['get', 'options']);

// add a new profile version
Route::add('/profile/([A-Za-z0-9-_\.\~]{1,255})/add', function(string $profile) use ($config, $db, $user) {
    api_headers($config);
    if (!verify_admin($user) && $profile !== $user->sub) {
        http_response_code(403); // Forbidden
        die;
    }

    $decoded = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() != JSON_ERROR_NONE) {
        http_response_code(415); // Unsupported media type
        die;
    }

    try {
        $stmt = $db->prepare("INSERT INTO `profile_history` (`author`, `profile`, `profile_content`) VALUES (?, ?, ?)");
        $stmt->bindParam(1, $user->sub);
        $stmt->bindParam(2, $profile);
        $stmt->bindParam(3, json_encode($decoded));
        $stmt->execute();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        die;
    }
}, ['post', 'options']);

// delete a profile (all versions)
Route::add('/profile/([A-Za-z0-9-_\.\~]{1,255})/delete', function(string $profile) use ($config, $db, $user) {
    api_headers($config);
    if (!verify_admin($user) && $profile !== $user->sub) {
        http_response_code(403); // Forbidden
        die;
    }

    try {
        $stmt = $db->prepare("DELETE FROM `profile_history` WHERE `profile` LIKE ?");
        $stmt->bindParam(1, $profile);
        $stmt->execute();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        die;
    }
}, ['post', 'options']);

// get the latest version of the profile
Route::add('/profile/([A-Za-z0-9-_\.\~]{1,255})/latest', function(string $profile) use ($config, $db, $user) {
    api_headers($config);
    if (!verify_admin($user) && $profile !== $user->sub) {
        http_response_code(403); // Forbidden
        die;
    }

    try {
        $stmt = $db->prepare("SELECT `author`, `profile_version`,` profile_content` FROM `profile_history` WHERE `profile` LIKE ? ORDER BY `profile_version` DESC LIMIT 1");
        $stmt->bindParam(1, $profile);
        $stmt->execute();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        die;
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        http_response_code(404); // Not found
        die;
    }

    echo json_encode([
        'author' => $result->author,
        'profile_version' => $result->profile_version,
        'profile_content' => json_decode($result->profile_content),
    ]);
}, ['get', 'options']);

// get a specific version of the profile
Route::add('/profile/([A-Za-z0-9-_\.\~]{1,255})/([A-Za-z0-9-_\.\~]{1,255})', function(string $profile, string $version) use ($config, $db, $user) {
    api_headers($config);
    if (!verify_admin($user) && $profile !== $user->sub) {
        http_response_code(403); // Forbidden
        die;
    }

    try {
        $stmt = $db->prepare("SELECT `author`, `profile_content` FROM `profile_history` WHERE `profile` LIKE ? AND `profile_version` LIKE ?");
        $stmt->bindParam(1, $profile);
        $stmt->bindParam(1, $version);
        $stmt->execute();
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        die;
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        http_response_code(404); // Not found
        die;
    }

    echo json_encode([
        'author' => $result->author,
        'profile_version' => $result->profile_version,
        'profile_content' => json_decode($result->profile_content),
    ]);
}, ['get', 'options']);

Route::run('/api/v1');

function api_headers($config, $allowed_methods = ['GET']) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    $allowed_origin = $config['cors_allowed_origins']; 
    $allowed_header = ['Authorization', 'Content-Type'];

    // General headers
    $response_headers = [
        'Content-Type' => 'application/json',
        'Vary' => [...$allowed_header, 'Origin'],
        'Cache-Control' => 'no-store',
    ];

    // Handle CORS allowed origin
    if (in_array($origin, $allowed_origin)) {
        $response_headers['Access-Control-Allow-Origin'] = $origin;
        $response_headers["Access-Control-Allow-Credentials"] = "true";

        // CORS headers, specific for preflight
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
            $response_headers["Access-Control-Allow-Methods"] = $allowed_methods;
        }
        
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            $request_headers = explode(", ", $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
            $cors_safe_headers = ['Accept', 'Accept-Language', 'Content-Language', 'Content-Type'];
            $response_headers["Access-Control-Allow-Headers"] = array_intersect($request_headers, array_diff($allowed_header, $cors_safe_headers));
        }
    }

    // Set headers
    foreach ($response_headers as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        header("$key: $value");
    }

    // Stop at an OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }
}