<?php
require_once '../config.php';

// Start session to keep track of the current API key index
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Initialize the key index if it's not set
if (!isset($_SESSION['tmdb_api_key_index'])) {
    $_SESSION['tmdb_api_key_index'] = 0;
}

// Basic validation
if (!isset($_GET['id']) || !isset($_GET['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id or type parameter']);
    exit;
}

$id = $_GET['id'];
$type = $_GET['type']; // 'movie' or 'tv'

if ($type !== 'movie' && $type !== 'tv') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type parameter. Must be "movie" or "tv".']);
    exit;
}

$base_url = "https://api.themoviedb.org/3/";
$endpoint = "{$type}/{$id}?append_to_response=videos,credits,release_dates,content_ratings";

$max_retries = count(TMDB_API_KEYS);
$success = false;
$result = null;

for ($i = 0; $i < $max_retries; $i++) {
    $key_index = $_SESSION['tmdb_api_key_index'];
    $api_key = TMDB_API_KEYS[$key_index];
    $url = $base_url . $endpoint . "&api_key=" . $api_key;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CineMax-PHP'); // TMDB requires a user agent
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $success = true;
        $result = $response;
        break;
    } else {
        // Rotate to the next key
        $_SESSION['tmdb_api_key_index'] = ($key_index + 1) % count(TMDB_API_KEYS);
        // If the error is not a rate limit or auth issue, don't retry
        if ($http_code != 401 && $http_code != 429) {
            http_response_code($http_code);
            echo $response; // Pass the error from TMDB through
            exit;
        }
    }
}

if ($success) {
    header('Content-Type: application/json');
    echo $result;
} else {
    // If all keys failed
    http_response_code(503); // Service Unavailable
    echo json_encode(['error' => 'All TMDB API keys failed. Please check your keys and their usage limits.']);
}
?>
