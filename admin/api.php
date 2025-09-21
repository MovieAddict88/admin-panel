<?php
// api.php
header('Content-Type: application/json');
require_once 'auth_check.php';
require_once '../db.php';
require_once '../config.php';

// Function to fetch from TMDB with API key rotation
function fetchFromTmdb($endpoint) {
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
        $url = $base_url . $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . "api_key=" . $api_key;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CineMax-PHP-API');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            return json_decode($response, true);
        } else {
            $_SESSION['tmdb_api_key_index'] = ($key_index + 1) % count(TMDB_API_KEYS);
            if ($http_code != 401 && $http_code != 429) {
                return null;
            }
        }
    }
    return null; // All keys failed
}

// Function to generate a movie and save to database
function generateMovieFromTmdb($tmdb_id, $conn) {
    $item_details = fetchFromTmdb("movie/{$tmdb_id}?append_to_response=videos,credits,release_dates");
    if (!$item_details) {
        return ['status' => 'error', 'message' => 'Could not fetch details for movie.'];
    }

    $item_year = substr($item_details['release_date'] ?? '', 0, 4);
    $item_title = $item_details['title'];

    // Check for duplicates
    $stmt_check = $conn->prepare("SELECT id FROM entries WHERE title = ? AND year = ?");
    $stmt_check->bind_param("ss", $item_title, $item_year);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        return ['status' => 'warning', 'message' => "SKIPPED: '{$item_title}' ({$item_year}) already exists."];
    }
    $stmt_check->close();

    $conn->begin_transaction();
    try {
        $description = $item_details['overview'];
        $poster = $item_details['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $item_details['poster_path'] : '';
        $thumbnail = $item_details['backdrop_path'] ? 'https://image.tmdb.org/t/p/w500' . $item_details['backdrop_path'] : $poster;
        $country = !empty($item_details['production_countries']) ? $item_details['production_countries'][0]['name'] : '';
        $rating = $item_details['vote_average'];

        $category_name = 'Movies';
        $cat_res = $conn->query("SELECT id FROM categories WHERE name = '" . $conn->real_escape_string($category_name) . "'");
        $category_id = $cat_res->fetch_assoc()['id'];

        $subcategory_id = null;
        if (!empty($item_details['genres'])) {
             $genre_name = $item_details['genres'][0]['name'];
             $stmt_subcategory = $conn->prepare("INSERT INTO subcategories (name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
             $stmt_subcategory->bind_param("s", $genre_name);
             $stmt_subcategory->execute();
             $subcat_res = $conn->query("SELECT id FROM subcategories WHERE name = '" . $conn->real_escape_string($genre_name) . "'");
             $subcategory_id = $subcat_res->fetch_assoc()['id'];
             $stmt_subcategory->close();
        }

        $duration = $item_details['runtime'] ? floor($item_details['runtime'] / 60) . 'h ' . ($item_details['runtime'] % 60) . 'm' : '';
        $parental_rating = '';
        if (!empty($item_details['release_dates']['results'])) {
             $us_release = current(array_filter($item_details['release_dates']['results'], fn($r) => $r['iso_3166_1'] == 'US'));
             if ($us_release && !empty($us_release['release_dates'][0]['certification'])) {
                 $parental_rating = $us_release['release_dates'][0]['certification'];
             }
        }

        $stmt_entry = $conn->prepare("INSERT INTO entries (title, description, poster, thumbnail, category_id, subcategory_id, country, rating, duration, year, parental_rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_entry->bind_param("ssssiisdsis", $item_title, $description, $poster, $thumbnail, $category_id, $subcategory_id, $country, $rating, $duration, $item_year, $parental_rating);
        $stmt_entry->execute();
        $entry_id = $conn->insert_id;
        $stmt_entry->close();

        // Add default servers
        $servers = [['VidSrc', "https://vidsrc.net/embed/movie/{$item_details['id']}"], ['VidJoy', "https://vidjoy.pro/embed/movie/{$item_details['id']}"]];
        $stmt_server = $conn->prepare("INSERT INTO servers (entry_id, name, url) VALUES (?, ?, ?)");
        foreach ($servers as $server) {
            $stmt_server->bind_param("iss", $entry_id, $server[0], $server[1]);
            $stmt_server->execute();
        }
        $stmt_server->close();

        $conn->commit();
        return ['status' => 'success', 'message' => "IMPORTED: '{$item_title}'."];
    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => "Failed to import '{$item_title}'. Reason: {$e->getMessage()}"];
    }
}

// Function to generate a TV series and save to database
function generateSeriesFromTmdb($tmdb_id, $conn) {
    $item_details = fetchFromTmdb("tv/{$tmdb_id}?append_to_response=videos,credits,content_ratings");
    if (!$item_details) {
        return ['status' => 'error', 'message' => 'Could not fetch details for TV series.'];
    }

    $item_year = substr($item_details['first_air_date'] ?? '', 0, 4);
    $item_title = $item_details['name'];

    // Check for duplicates
    $stmt_check = $conn->prepare("SELECT id FROM entries WHERE title = ? AND year = ?");
    $stmt_check->bind_param("ss", $item_title, $item_year);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        return ['status' => 'warning', 'message' => "SKIPPED: '{$item_title}' ({$item_year}) already exists."];
    }
    $stmt_check->close();

    $conn->begin_transaction();
    try {
        $description = $item_details['overview'];
        $poster = $item_details['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $item_details['poster_path'] : '';
        $thumbnail = $item_details['backdrop_path'] ? 'https://image.tmdb.org/t/p/w500' . $item_details['backdrop_path'] : $poster;
        $country = !empty($item_details['production_countries']) ? $item_details['production_countries'][0]['name'] : '';
        $rating = $item_details['vote_average'];

        $category_name = 'TV Series';
        $cat_res = $conn->query("SELECT id FROM categories WHERE name = '" . $conn->real_escape_string($category_name) . "'");
        $category_id = $cat_res->fetch_assoc()['id'];

        $subcategory_id = null;
        if (!empty($item_details['genres'])) {
             $genre_name = $item_details['genres'][0]['name'];
             $stmt_subcategory = $conn->prepare("INSERT INTO subcategories (name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
             $stmt_subcategory->bind_param("s", $genre_name);
             $stmt_subcategory->execute();
             $subcat_res = $conn->query("SELECT id FROM subcategories WHERE name = '" . $conn->real_escape_string($genre_name) . "'");
             $subcategory_id = $subcat_res->fetch_assoc()['id'];
             $stmt_subcategory->close();
        }

        $duration = !empty($item_details['episode_run_time']) ? $item_details['episode_run_time'][0] . 'm' : '';
        $parental_rating = '';
        if (!empty($item_details['content_ratings']['results'])) {
             $us_rating = current(array_filter($item_details['content_ratings']['results'], fn($r) => $r['iso_3166_1'] == 'US'));
             if($us_rating) $parental_rating = $us_rating['rating'];
        }

        $stmt_entry = $conn->prepare("INSERT INTO entries (title, description, poster, thumbnail, category_id, subcategory_id, country, rating, duration, year, parental_rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_entry->bind_param("ssssiisdsis", $item_title, $description, $poster, $thumbnail, $category_id, $subcategory_id, $country, $rating, $duration, $item_year, $parental_rating);
        $stmt_entry->execute();
        $entry_id = $conn->insert_id;
        $stmt_entry->close();

        // Add seasons and episodes
        $stmt_season = $conn->prepare("INSERT INTO seasons (entry_id, season_number, poster) VALUES (?, ?, ?)");
        $stmt_episode = $conn->prepare("INSERT INTO episodes (season_id, episode_number, title, duration, description, thumbnail) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_server = $conn->prepare("INSERT INTO servers (episode_id, name, url) VALUES (?, ?, ?)");

        foreach ($item_details['seasons'] as $season_data) {
            if ($season_data['season_number'] == 0) continue; // Skip specials
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

                // Add default servers for each episode
                $servers = [['VidSrc', "https://vidsrc.net/embed/tv/{$item_details['id']}/{$season_data['season_number']}/{$episode_data['episode_number']}"], ['VidJoy', "https://vidjoy.pro/embed/tv/{$item_details['id']}/{$season_data['season_number']}/{$episode_data['episode_number']}"]];
                foreach ($servers as $server) {
                    $stmt_server->bind_param("iss", $episode_id, $server[0], $server[1]);
                    $stmt_server->execute();
                }
            }
            usleep(250000); // To avoid hitting TMDB rate limits
        }
        $stmt_season->close();
        $stmt_episode->close();
        $stmt_server->close();

        $conn->commit();
        return ['status' => 'success', 'message' => "IMPORTED: '{$item_title}'."];
    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => "Failed to import '{$item_title}'. Reason: {$e->getMessage()}"];
    }
}


$response = ['status' => 'error', 'message' => 'Invalid request'];

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    switch ($action) {
        case 'get_all_data':
            try {
                $data = [
                    'Categories' => []
                ];
                $categories_sql = "SELECT * FROM categories";
                $categories_result = $conn->query($categories_sql);
                while($category = $categories_result->fetch_assoc()) {
                    $mainCategory = [
                        "MainCategory" => $category['name'],
                        "SubCategories" => [],
                        "Entries" => []
                    ];

                    $entries_sql = "SELECT * FROM entries WHERE category_id = " . $category['id'];
                    $entries_result = $conn->query($entries_sql);
                    while($entry = $entries_result->fetch_assoc()) {
                        $entry_data = [
                            "Title" => $entry['title'],
                            "SubCategory" => "", // We can get this from subcategories table later
                            "Country" => $entry['country'],
                            "Description" => $entry['description'],
                            "Poster" => $entry['poster'],
                            "Thumbnail" => $entry['thumbnail'],
                            "Rating" => (float)$entry['rating'],
                            "Duration" => $entry['duration'],
                            "Year" => (int)$entry['year'],
                            "parentalRating" => $entry['parental_rating'],
                            "Servers" => []
                        ];

                        if ($category['name'] == 'Movies') {
                            $servers_sql = "SELECT * FROM servers WHERE entry_id = " . $entry['id'];
                            $servers_result = $conn->query($servers_sql);
                            while($server = $servers_result->fetch_assoc()) {
                                $entry_data['Servers'][] = [
                                    "name" => $server['name'],
                                    "url" => $server['url']
                                ];
                            }
                        } else if ($category['name'] == 'TV Series') {
                            $entry_data['Seasons'] = [];
                            $seasons_sql = "SELECT * FROM seasons WHERE entry_id = " . $entry['id'];
                            $seasons_result = $conn->query($seasons_sql);
                            while($season = $seasons_result->fetch_assoc()) {
                                $season_data = [
                                    "Season" => (int)$season['season_number'],
                                    "SeasonPoster" => $season['poster'],
                                    "Episodes" => []
                                ];
                                $episodes_sql = "SELECT * FROM episodes WHERE season_id = " . $season['id'];
                                $episodes_result = $conn->query($episodes_sql);
                                while($episode = $episodes_result->fetch_assoc()) {
                                    $episode_data = [
                                        "Episode" => (int)$episode['episode_number'],
                                        "Title" => $episode['title'],
                                        "Duration" => $episode['duration'],
                                        "Description" => $episode['description'],
                                        "Thumbnail" => $episode['thumbnail'],
                                        "Servers" => []
                                    ];
                                    $servers_sql = "SELECT * FROM servers WHERE episode_id = " . $episode['id'];
                                    $servers_result_ep = $conn->query($servers_sql);
                                    while($server = $servers_result_ep->fetch_assoc()) {
                                        $episode_data['Servers'][] = [
                                            "name" => $server['name'],
                                            "url" => $server['url']
                                        ];
                                    }
                                    $season_data['Episodes'][] = $episode_data;
                                }
                                $entry_data['Seasons'][] = $season_data;
                            }
                        }
                        $mainCategory['Entries'][] = $entry_data;
                    }
                    $data['Categories'][] = $mainCategory;
                }
                $response = ['status' => 'success', 'data' => $data];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'add_manual':
            $post_data = json_decode(file_get_contents('php://input'), true);
            if ($post_data) {
                if (empty($post_data['title']) || empty($post_data['type']) || empty($post_data['source_url'])) {
                    $response = ['status' => 'error', 'message' => 'Title, type, and source URL are required.'];
                } else {
                    $conn->begin_transaction();
                    try {
                        $title = $post_data['title'];
                        $description = $post_data['description'] ?? '';
                        $poster = $post_data['image'] ?? '';
                        $thumbnail = $poster;
                        $country = ''; // Not in form
                        $rating = $post_data['rating'] ?? 0;
                        $duration = $post_data['duration'] ?? '';
                        $year = $post_data['year'] ?? date('Y');
                        $parental_rating = ''; // Not in form

                        $category_name = '';
                        if ($post_data['type'] == 'movie') $category_name = 'Movies';
                        elseif ($post_data['type'] == 'series') $category_name = 'TV Series';
                        else $category_name = 'Live TV';

                        $cat_res = $conn->query("SELECT id FROM categories WHERE name = '" . $conn->real_escape_string($category_name) . "'");
                        $category_id = $cat_res->fetch_assoc()['id'];

                        $subcategory_name = $post_data['category'] ?? 'General';
                        $subcat_res = $conn->query("SELECT id FROM subcategories WHERE name = '" . $conn->real_escape_string($subcategory_name) . "'");
                        if ($subcat_res->num_rows > 0) {
                            $subcategory_id = $subcat_res->fetch_assoc()['id'];
                        } else {
                            $stmt_sub = $conn->prepare("INSERT INTO subcategories (name) VALUES (?)");
                            $stmt_sub->bind_param("s", $subcategory_name);
                            $stmt_sub->execute();
                            $subcategory_id = $conn->insert_id;
                            $stmt_sub->close();
                        }

                        $stmt_entry = $conn->prepare("INSERT INTO entries (title, description, poster, thumbnail, category_id, subcategory_id, country, rating, duration, year, parental_rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_entry->bind_param("ssssiisdsis", $title, $description, $poster, $thumbnail, $category_id, $subcategory_id, $country, $rating, $duration, $year, $parental_rating);
                        $stmt_entry->execute();
                        $entry_id = $conn->insert_id;
                        $stmt_entry->close();

                        if ($post_data['type'] == 'series' && !empty($post_data['seasons'])) {
                            $stmt_season = $conn->prepare("INSERT INTO seasons (entry_id, season_number) VALUES (?, ?)");
                            $stmt_episode = $conn->prepare("INSERT INTO episodes (season_id, episode_number, title) VALUES (?, ?, ?)");
                            $stmt_server = $conn->prepare("INSERT INTO servers (episode_id, name, url) VALUES (?, ?, ?)");

                            foreach ($post_data['seasons'] as $season_data) {
                                $stmt_season->bind_param("ii", $entry_id, $season_data['season_number']);
                                $stmt_season->execute();
                                $season_id = $conn->insert_id;

                                foreach ($season_data['episodes'] as $episode_data) {
                                    $stmt_episode->bind_param("iis", $season_id, $episode_data['episode_number'], $episode_data['title']);
                                    $stmt_episode->execute();
                                    $episode_id = $conn->insert_id;

                                    // Assuming one server per episode for now
                                    $server_name = 'Default Server';
                                    $server_url = $episode_data['url'];
                                    if (!empty($server_url)) {
                                        $stmt_server->bind_param("iss", $episode_id, $server_name, $server_url);
                                        $stmt_server->execute();
                                    }
                                }
                            }
                            $stmt_season->close();
                            $stmt_episode->close();
                            $stmt_server->close();
                        } else {
                            // For manual add, we assume one server source for movies/live tv
                            $server_name = $post_data['type'] . ' Source';
                            $server_url = $post_data['source_url'];
                            $is_drm = $post_data['is_drm'] ? 1 : 0;
                            $license_url = $post_data['license_url'] ?? null;

                            $stmt_server = $conn->prepare("INSERT INTO servers (entry_id, name, url, is_drm, license_url) VALUES (?, ?, ?, ?, ?)");
                            $stmt_server->bind_param("issis", $entry_id, $server_name, $server_url, $is_drm, $license_url);
                            $stmt_server->execute();
                            $stmt_server->close();
                        }

                        $conn->commit();
                        $response = ['success' => true, 'message' => "Successfully added '{$title}'."];
                    } catch (Exception $e) {
                        $conn->rollback();
                        $response = ['success' => false, 'message' => "Database error: {$e->getMessage()}"];
                    }
                }
            } else {
                $response = ['success' => false, 'message' => 'Invalid data received.'];
            }
            break;

        case 'generate_movie':
            $tmdb_id = $_GET['tmdb_id'] ?? null;
            if ($tmdb_id) {
                $response = generateMovieFromTmdb($tmdb_id, $conn);
            } else {
                $response['message'] = 'TMDB ID is required.';
            }
            break;

        case 'generate_series':
            $tmdb_id = $_GET['tmdb_id'] ?? null;
            if ($tmdb_id) {
                $response = generateSeriesFromTmdb($tmdb_id, $conn);
            } else {
                $response['message'] = 'TMDB ID is required.';
            }
            break;

        case 'search_tmdb':
            $query = $_GET['query'] ?? '';
            $type = $_GET['type'] ?? 'multi';
            if ($query) {
                $endpoint = "search/{$type}?query=" . urlencode($query);
                $data = fetchFromTmdb($endpoint);
                if ($data && isset($data['results'])) {
                    $response = ['status' => 'success', 'data' => $data['results']];
                } else {
                    $response = ['status' => 'error', 'message' => 'Failed to fetch search results from TMDB.'];
                }
            } else {
                $response['message'] = 'Search query is required.';
            }
            break;

        case 'delete_item':
            $post_data = json_decode(file_get_contents('php://input'), true);
            if (isset($post_data['title']) && isset($post_data['category'])) {
                try {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("DELETE e FROM entries e JOIN categories c ON e.category_id = c.id WHERE e.title = ? AND c.name = ?");
                    $stmt->bind_param("ss", $post_data['title'], $post_data['category']);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    $response = ['status' => 'success'];
                } catch (Exception $e) {
                    $conn->rollback();
                    $response = ['status' => 'error', 'message' => $e->getMessage()];
                }
            } else {
                $response['message'] = 'Title and category are required for deletion.';
            }
            break;

        case 'clear_all_data':
            try {
                $conn->begin_transaction();
                $conn->query("DELETE FROM servers");
                $conn->query("DELETE FROM episodes");
                $conn->query("DELETE FROM seasons");
                $conn->query("DELETE FROM entries");
                // Optionally reset categories and subcategories if needed
                // $conn->query("DELETE FROM subcategories");
                // $conn->query("DELETE FROM categories");
                $conn->commit();
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $conn->rollback();
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'bulk_generate_year':
            $post_data = json_decode(file_get_contents('php://input'), true);
            if ($post_data) {
                $content_type = $post_data['type'] ?? 'movie';
                $year = $post_data['year'] ?? date('Y');
                $page = $post_data['page'] ?? 1;
                $skip_duplicates = $post_data['skip_duplicates'] ?? true;

                $generated_count = 0;
                $skipped_count = 0;

                $endpoint = "discover/{$content_type}?sort_by=popularity.desc&page={$page}";
                if ($content_type == 'movie') {
                    $endpoint .= "&primary_release_year={$year}";
                } else {
                    $endpoint .= "&first_air_date_year={$year}";
                }

                $data = fetchFromTmdb($endpoint);

                if ($data && isset($data['results'])) {
                    foreach ($data['results'] as $item_summary) {
                        $tmdb_id = $item_summary['id'];

                        if ($skip_duplicates) {
                            $item_title = $item_summary['title'] ?? $item_summary['name'];
                            $item_year_val = substr($item_summary['release_date'] ?? $item_summary['first_air_date'] ?? '', 0, 4);
                            $stmt_check = $conn->prepare("SELECT id FROM entries WHERE title = ? AND year = ?");
                            $stmt_check->bind_param("ss", $item_title, $item_year_val);
                            $stmt_check->execute();
                            if ($stmt_check->get_result()->num_rows > 0) {
                                $skipped_count++;
                                continue;
                            }
                            $stmt_check->close();
                        }

                        if ($content_type == 'movie') {
                            generateMovieFromTmdb($tmdb_id, $conn);
                        } else {
                            generateSeriesFromTmdb($tmdb_id, $conn);
                        }
                        $generated_count++;
                    }
                    $response = ['success' => true, 'generated' => $generated_count, 'skipped' => $skipped_count];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to fetch data from TMDB.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Invalid data for bulk import.'];
            }
            break;

        default:
            $response['message'] = 'Unknown action.';
            break;
    }
}

echo json_encode($response);
$conn->close();
?>
