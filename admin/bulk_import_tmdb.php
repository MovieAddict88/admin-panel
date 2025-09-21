<?php
require_once 'auth_check.php';
require_once '../db.php'; // for $conn
require_once '../config.php'; // for TMDB_API_KEYS

include '../header.php';

function fetchFromTmdb($endpoint) {
    // This function will handle fetching from TMDB and key rotation
    // It's similar to the logic in api/tmdb.php but for server-side use.
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['tmdb_api_key_index'])) {
        $_SESSION['tmdb_api_key_index'] = 0;
    }

    $base_url = "https://api.themoviedb.org/3/";
    $max_retries = count(TMDB_API_KEYS);

    for ($i = 0; $i < $max_retries; $i++) {
        $key_index = $_SESSION['tmdb_api_key_index'];
        $api_key = TMDB_API_KEYS[$key_index];
        $url = $base_url . $endpoint . "&api_key=" . $api_key;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CineMax-PHP-Bulk-Import');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            return json_decode($response, true);
        } else {
            $_SESSION['tmdb_api_key_index'] = ($key_index + 1) % count(TMDB_API_KEYS);
            if ($http_code != 401 && $http_code != 429) {
                // For other errors, just return null
                return null;
            }
        }
    }
    return null; // All keys failed
}

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Bulk Import from TMDB</h2>
    <a href="index.php" class="btn btn-secondary">Back to Admin Panel</a>
</div>

<?php
// Data for UI
$genres = [
    "Action" => 28, "Adventure" => 12, "Animation" => 16, "Comedy" => 35, "Crime" => 80,
    "Documentary" => 99, "Drama" => 18, "Family" => 10751, "Fantasy" => 14, "History" => 36,
    "Horror" => 27, "Music" => 10402, "Mystery" => 9648, "Romance" => 10749,
    "Science Fiction" => 878, "TV Movie" => 10770, "Thriller" => 53, "War" => 10752, "Western" => 37
];

$regions = [
    'hollywood' => ['name' => 'Hollywood', 'params' => ['with_origin_country' => 'US']],
    'anime' => ['name' => 'Anime', 'params' => ['with_origin_country' => 'JP', 'with_genres' => '16']],
    'kdrama' => ['name' => 'K-Drama (Korean)', 'params' => ['with_origin_country' => 'KR', 'with_genres' => '18']],
    'cdrama' => ['name' => 'C-Drama (Chinese)', 'params' => ['with_origin_country' => 'CN', 'with_genres' => '18']],
    'jdrama' => ['name' => 'J-Drama (Japanese)', 'params' => ['with_origin_country' => 'JP', 'with_genres' => '18']],
    'pinoy' => ['name' => 'Pinoy Series (Filipino)', 'params' => ['with_origin_country' => 'PH']],
    'thai' => ['name' => 'Thai Drama', 'params' => ['with_origin_country' => 'TH']],
    'indian' => ['name' => 'Indian Series', 'params' => ['with_origin_country' => 'IN']],
    'turkish' => ['name' => 'Turkish Drama', 'params' => ['with_origin_country' => 'TR']],
];
?>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">Advanced Bulk Import</h5>
        <p>Import content from TMDB using advanced filters. This can take some time to process.</p>
        <form method="post">
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label for="content_type">Content Type</label>
                    <select name="content_type" id="content_type" class="form-control">
                        <option value="movie">Movies</option>
                        <option value="tv">TV Shows</option>
                    </select>
                </div>
                 <div class="form-group col-md-3">
                    <label for="genre">Genre</label>
                    <select name="genre" id="genre" class="form-control">
                        <option value="">Any Genre</option>
                        <?php foreach ($genres as $name => $id): ?>
                            <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="region">Region</label>
                    <select name="region" id="region" class="form-control">
                        <option value="">Any Region</option>
                        <?php foreach ($regions as $key => $region): ?>
                            <option value="<?php echo $key; ?>"><?php echo $region['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label for="year">Year</label>
                    <input type="number" name="year" id="year" class="form-control" placeholder="e.g., <?php echo date('Y'); ?>">
                </div>
                <div class="form-group col-md-3">
                    <label for="pages">Number of Pages (20 items per page)</label>
                    <input type="number" name="pages" id="pages" class="form-control" value="1" min="1" max="10">
                </div>
            </div>
            <button type="submit" name="advanced_import" class="btn btn-primary">Start Advanced Import</button>
        </form>
    </div>
</div>

<?php
if (isset($_POST['advanced_import'])) {
    echo '<div class="card mt-4"><div class="card-body"><h5>Import Log</h5>';

    // Sanitize and get inputs
    $content_type = $_POST['content_type'] ?? 'movie';
    $genre = $_POST['genre'] ?? '';
    $region_key = $_POST['region'] ?? '';
    $year = $_POST['year'] ?? '';
    $pages = min((int)($_POST['pages'] ?? 1), 10); // Limit to 10 pages max for performance

    $imported_count = 0;
    $skipped_count = 0;
    $failed_count = 0;

    // Build the dynamic API query
    $api_params = ['sort_by' => 'popularity.desc'];
    if ($genre) $api_params['with_genres'] = $genre;
    if ($year) {
        if ($content_type == 'movie') $api_params['primary_release_year'] = $year;
        else $api_params['first_air_date_year'] = $year;
    }
    if ($region_key && isset($regions[$region_key])) {
        $api_params = array_merge($api_params, $regions[$region_key]['params']);
    }

    // Prepare all statements once
    $stmt_check = $conn->prepare("SELECT id FROM entries WHERE title = ? AND year = ?");
    $stmt_entry = $conn->prepare("INSERT INTO entries (title, description, poster, thumbnail, category_id, subcategory_id, country, rating, duration, year, parental_rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_subcategory = $conn->prepare("INSERT INTO subcategories (name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt_season = $conn->prepare("INSERT INTO seasons (entry_id, season_number, poster) VALUES (?, ?, ?)");
    $stmt_episode = $conn->prepare("INSERT INTO episodes (season_id, episode_number, title, duration, description, thumbnail) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_server = $conn->prepare("INSERT INTO servers (entry_id, episode_id, name, url) VALUES (?, ?, ?, ?)");

    for ($page = 1; $page <= $pages; $page++) {
        $api_params['page'] = $page;
        $query_string = http_build_query($api_params);
        $endpoint = "discover/{$content_type}?{$query_string}";

        $data = fetchFromTmdb($endpoint);

        if (!$data || !isset($data['results'])) {
            echo "<p class='text-danger'>Failed to fetch data for page {$page}. Stopping.</p>";
            break;
        }

        echo "<p><strong>Processing Page {$page}/{$pages}... found " . count($data['results']) . " items.</strong></p>";

        foreach ($data['results'] as $item_summary) {
            $item_year = substr($item_summary['release_date'] ?? $item_summary['first_air_date'] ?? '', 0, 4);
            $item_title = $item_summary['title'] ?? $item_summary['name'];

            $stmt_check->bind_param("ss", $item_title, $item_year);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                echo "<p><span class='text-warning'>SKIPPED:</span> '{$item_title}' ({$item_year}) already exists.</p>";
                $skipped_count++;
                continue;
            }

            $item_details = fetchFromTmdb("{$content_type}/{$item_summary['id']}?append_to_response=videos,credits,release_dates,content_ratings");
            if (!$item_details) {
                echo "<p><span class='text-danger'>ERROR:</span> Could not fetch details for '{$item_title}'.</p>";
                $failed_count++;
                continue;
            }

            $conn->begin_transaction();
            try {
                $is_tv = $content_type == 'tv';
                $description = $item_details['overview'];
                $poster = $item_details['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $item_details['poster_path'] : '';
                $thumbnail = $item_details['backdrop_path'] ? 'https://image.tmdb.org/t/p/w500' . $item_details['backdrop_path'] : $poster;
                $country = !empty($item_details['production_countries']) ? $item_details['production_countries'][0]['name'] : '';
                $rating = $item_details['vote_average'];
                $category_name = $is_tv ? 'TV Series' : 'Movies';
                $cat_res = $conn->query("SELECT id FROM categories WHERE name = '" . $conn->real_escape_string($category_name) . "'");
                $category_id = $cat_res->fetch_assoc()['id'];
                $subcategory_id = null;
                if (!empty($item_details['genres'])) {
                     $genre_name = $item_details['genres'][0]['name'];
                     $stmt_subcategory->bind_param("s", $genre_name);
                     $stmt_subcategory->execute();
                     $subcat_res = $conn->query("SELECT id FROM subcategories WHERE name = '" . $conn->real_escape_string($genre_name) . "'");
                     $subcategory_id = $subcat_res->fetch_assoc()['id'];
                }
                $duration = '';
                $parental_rating = '';
                if (!$is_tv) {
                    $duration = $item_details['runtime'] ? floor($item_details['runtime'] / 60) . 'h ' . ($item_details['runtime'] % 60) . 'm' : '';
                    if (!empty($item_details['release_dates']['results'])) {
                         $us_release = current(array_filter($item_details['release_dates']['results'], fn($r) => $r['iso_3166_1'] == 'US'));
                         if ($us_release && !empty($us_release['release_dates'][0]['certification'])) $parental_rating = $us_release['release_dates'][0]['certification'];
                    }
                } else {
                    $duration = !empty($item_details['episode_run_time']) ? $item_details['episode_run_time'][0] . 'm' : '';
                    if (!empty($item_details['content_ratings']['results'])) {
                         $us_rating = current(array_filter($item_details['content_ratings']['results'], fn($r) => $r['iso_3166_1'] == 'US'));
                         if($us_rating) $parental_rating = $us_rating['rating'];
                    }
                }

                $stmt_entry->bind_param("ssssiisdsis", $item_title, $description, $poster, $thumbnail, $category_id, $subcategory_id, $country, $rating, $duration, $item_year, $parental_rating);
                $stmt_entry->execute();
                $entry_id = $conn->insert_id;

                if (!$is_tv) {
                    $servers = [['VidSrc', "https://vidsrc.net/embed/movie/{$item_details['id']}"], ['VidJoy', "https://vidjoy.pro/embed/movie/{$item_details['id']}"]];
                    foreach ($servers as $server) {
                        $episode_id_null = null;
                        $stmt_server->bind_param("iiss", $entry_id, $episode_id_null, $server[0], $server[1]);
                        $stmt_server->execute();
                    }
                } else {
                    foreach ($item_details['seasons'] as $season_data) {
                        if ($season_data['season_number'] == 0) continue;
                        $season_details = fetchFromTmdb("tv/{$item_details['id']}/season/{$season_data['season_number']}");
                        if (!$season_details) continue;
                        $season_poster = $season_details['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $season_details['poster_path'] : $poster;
                        $stmt_season->bind_param("iis", $entry_id, $season_details['season_number'], $season_poster);
                        $stmt_season->execute();
                        $season_id = $conn->insert_id;
                        foreach ($season_details['episodes'] as $episode_data) {
                            $ep_desc = $episode_data['overview'];
                            $ep_thumb = $episode_data['still_path'] ? 'https://image.tmdb.org/t/p/w500' . $episode_data['still_path'] : $thumbnail;
                            $ep_duration = $episode_data['runtime'] ? $episode_data['runtime'] . 'm' : '';
                            $stmt_episode->bind_param("isssss", $season_id, $episode_data['episode_number'], $episode_data['name'], $ep_duration, $ep_desc, $ep_thumb);
                            $stmt_episode->execute();
                            $episode_id = $conn->insert_id;
                            $servers = [['VidSrc', "https://vidsrc.net/embed/tv/{$item_details['id']}/{$season_data['season_number']}/{$episode_data['episode_number']}"], ['VidJoy', "https://vidjoy.pro/embed/tv/{$item_details['id']}/{$season_data['season_number']}/{$episode_data['episode_number']}"]];
                            foreach ($servers as $server) {
                                $entry_id_null = null;
                                $stmt_server->bind_param("iiss", $entry_id_null, $episode_id, $server[0], $server[1]);
                                $stmt_server->execute();
                            }
                        }
                         usleep(250000);
                    }
                }
                $conn->commit();
                echo "<p><span class='text-success'>IMPORTED:</span> '{$item_title}'.</p>";
                $imported_count++;
            } catch (Exception $e) {
                $conn->rollback();
                echo "<p><span class='text-danger'>ERROR:</span> Failed to import '{$item_title}'. Reason: {$e->getMessage()}</p>";
                $failed_count++;
            }
            usleep(250000);
        }
    }
    $stmt_check->close();
    $stmt_entry->close();
    $stmt_subcategory->close();
    $stmt_season->close();
    $stmt_episode->close();
    $stmt_server->close();
    echo "<hr><p><strong>Import complete.</strong> Imported: {$imported_count}, Skipped: {$skipped_count}, Failed: {$failed_count}.</p>";
    echo '</div></div>';
}

include '../footer.php';
?>
