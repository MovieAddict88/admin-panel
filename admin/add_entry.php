<?php
require_once 'auth_check.php';
require_once '../db.php';

// Function to fetch from TMDB (can be moved to a helper file later)
function fetchFromTmdb($endpoint) {
    // This function will handle fetching from TMDB and key rotation
    if (session_status() == PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['tmdb_api_key_index'])) $_SESSION['tmdb_api_key_index'] = 0;
    $base_url = "https://api.themoviedb.org/3/";
    $max_retries = count(TMDB_API_KEYS);
    for ($i = 0; $i < $max_retries; $i++) {
        $key_index = $_SESSION['tmdb_api_key_index'];
        $api_key = TMDB_API_KEYS[$key_index];
        $url = $base_url . $endpoint . (strpos($endpoint, '?') ? '&' : '?') . "api_key=" . $api_key;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CineMax-PHP');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code == 200) return json_decode($response, true);
        else {
            $_SESSION['tmdb_api_key_index'] = ($key_index + 1) % count(TMDB_API_KEYS);
            if ($http_code != 401 && $http_code != 429) return null;
        }
    }
    return null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $success_message = '';

    // Check if we are adding from TMDB data
    if (!empty($_POST['tmdb_data'])) {
        $tmdb_data = json_decode($_POST['tmdb_data'], true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $is_tv = isset($tmdb_data['seasons']);
            $year = substr($tmdb_data['release_date'] ?? $tmdb_data['first_air_date'] ?? '', 0, 4);
            $title = $tmdb_data['title'] ?? $tmdb_data['name'];

            // Check for duplicates
            $stmt_check = $conn->prepare("SELECT id FROM entries WHERE title = ? AND year = ?");
            $stmt_check->bind_param("ss", $title, $year);
            $stmt_check->execute();
            $result = $stmt_check->get_result();

            if ($result->num_rows > 0) {
                $success_message = "<div class='alert alert-warning'>Entry for '{$title}' ({$year}) already exists.</div>";
            } else {
                $conn->begin_transaction();
                try {
                    $description = $tmdb_data['overview'];
                    $poster = $tmdb_data['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $tmdb_data['poster_path'] : '';
                    $thumbnail = $tmdb_data['backdrop_path'] ? 'https://image.tmdb.org/t/p/w500' . $tmdb_data['backdrop_path'] : $poster;
                    $country = !empty($tmdb_data['production_countries']) ? $tmdb_data['production_countries'][0]['name'] : '';
                    $rating = $tmdb_data['vote_average'];
                    $category_name = $is_tv ? 'TV Series' : 'Movies';
                    $cat_res = $conn->query("SELECT id FROM categories WHERE name = '" . $conn->real_escape_string($category_name) . "'");
                    $category_id = $cat_res->fetch_assoc()['id'];
                    $subcategory_id = null;
                    if (!empty($tmdb_data['genres'])) {
                         $stmt_subcategory = $conn->prepare("INSERT INTO subcategories (name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                         $genre_name = $tmdb_data['genres'][0]['name'];
                         $stmt_subcategory->bind_param("s", $genre_name);
                         $stmt_subcategory->execute();
                         $subcat_res = $conn->query("SELECT id FROM subcategories WHERE name = '" . $conn->real_escape_string($genre_name) . "'");
                         $subcategory_id = $subcat_res->fetch_assoc()['id'];
                         $stmt_subcategory->close();
                    }
                    $duration = '';
                    $parental_rating = '';
                    if (!$is_tv) {
                        $duration = $tmdb_data['runtime'] ? floor($tmdb_data['runtime'] / 60) . 'h ' . ($tmdb_data['runtime'] % 60) . 'm' : '';
                        if (!empty($tmdb_data['release_dates']['results'])) {
                             $us_release = current(array_filter($tmdb_data['release_dates']['results'], fn($r) => $r['iso_3166_1'] == 'US'));
                             if ($us_release && !empty($us_release['release_dates'][0]['certification'])) $parental_rating = $us_release['release_dates'][0]['certification'];
                        }
                    } else {
                        $duration = !empty($tmdb_data['episode_run_time']) ? $tmdb_data['episode_run_time'][0] . 'm' : '';
                        if (!empty($tmdb_data['content_ratings']['results'])) {
                             $us_rating = current(array_filter($tmdb_data['content_ratings']['results'], fn($r) => $r['iso_3166_1'] == 'US'));
                             if($us_rating) $parental_rating = $us_rating['rating'];
                        }
                    }

                    $stmt_entry = $conn->prepare("INSERT INTO entries (title, description, poster, thumbnail, category_id, subcategory_id, country, rating, duration, year, parental_rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_entry->bind_param("ssssiisdsis", $title, $description, $poster, $thumbnail, $category_id, $subcategory_id, $country, $rating, $duration, $year, $parental_rating);
                    $stmt_entry->execute();
                    $entry_id = $conn->insert_id;

                    if (!$is_tv) { // It's a movie
                        $stmt_server = $conn->prepare("INSERT INTO servers (entry_id, name, url) VALUES (?, ?, ?)");
                        $servers = [['VidSrc', "https://vidsrc.net/embed/movie/{$tmdb_data['id']}"], ['VidJoy', "https://vidjoy.pro/embed/movie/{$tmdb_data['id']}"]];
                        foreach ($servers as $server) {
                            $stmt_server->bind_param("iss", $entry_id, $server[0], $server[1]);
                            $stmt_server->execute();
                        }
                        $stmt_server->close();
                    } else { // It's a TV show
                        $stmt_season = $conn->prepare("INSERT INTO seasons (entry_id, season_number, poster) VALUES (?, ?, ?)");
                        $stmt_episode = $conn->prepare("INSERT INTO episodes (season_id, episode_number, title, duration, description, thumbnail) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt_server = $conn->prepare("INSERT INTO servers (episode_id, name, url) VALUES (?, ?, ?)");

                        foreach ($tmdb_data['seasons'] as $season_data) {
                            if ($season_data['season_number'] == 0) continue; // Skip "Specials" for now
                            $season_details = fetchFromTmdb("tv/{$tmdb_data['id']}/season/{$season_data['season_number']}");
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

                                $servers = [['VidSrc', "https://vidsrc.net/embed/tv/{$tmdb_data['id']}/{$season_data['season_number']}/{$episode_data['episode_number']}"], ['VidJoy', "https://vidjoy.pro/embed/tv/{$tmdb_data['id']}/{$season_data['season_number']}/{$episode_data['episode_number']}"]];
                                foreach ($servers as $server) {
                                    $stmt_server->bind_param("iss", $episode_id, $server[0], $server[1]);
                                    $stmt_server->execute();
                                }
                            }
                             usleep(250000); // 250ms delay between seasons
                        }
                        $stmt_season->close();
                        $stmt_episode->close();
                        $stmt_server->close();
                    }
                    $conn->commit();
                    $success_message = "<div class='alert alert-success'>Successfully added '{$title}' and all associated data from TMDB. <a href='edit_entry.php?id=$entry_id'>Click here to edit it.</a></div>";

                } catch (Exception $e) {
                    $conn->rollback();
                    $success_message = "<div class='alert alert-danger'>An error occurred during insertion: " . $e->getMessage() . "</div>";
                }
            }
            $stmt_check->close();
        } else {
            $success_message = "<div class='alert alert-danger'>Invalid TMDB data provided.</div>";
        }
    } else if (!empty($_POST['title'])) {
        // Fallback for manual form submission if no tmdb_data is present
        $stmt = $conn->prepare("INSERT INTO entries (title, description, poster, thumbnail, category_id, subcategory_id, country, rating, duration, year, parental_rating) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $thumbnail = $_POST['poster'];
        $subcategory_id = null;
        $stmt->bind_param("ssssiisdsis", $_POST['title'], $_POST['description'], $_POST['poster'], $thumbnail, $_POST['category_id'], $subcategory_id, $_POST['country'], $_POST['rating'], $_POST['duration'], $_POST['year'], $_POST['parental_rating']);
        $stmt->execute();
        $new_entry_id = $conn->insert_id;
        $stmt->close();
        $success_message = "Entry created successfully! <a href='edit_entry.php?id=$new_entry_id'>Click here to edit it.</a>";
    }
}


include '../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Add New Entry</h2>
    <a href="manage_entries.php" class="btn btn-secondary">Back to Entries</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Fetch from TMDB</h5>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="tmdb_id">TMDB ID</label>
                <input type="text" id="tmdb_id" class="form-control" placeholder="e.g., 550">
            </div>
            <div class="form-group col-md-3">
                <label for="tmdb_type">Content Type</label>
                <select id="tmdb_type" class="form-control">
                    <option value="movie">Movie</option>
                    <option value="tv">TV Series</option>
                </select>
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
                <button type="button" id="fetch_tmdb" class="btn btn-info">Fetch Data</button>
            </div>
        </div>
        <div id="tmdb-status" class="mt-2"></div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form action="add_entry.php" method="post">
            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" name="title" id="title" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea name="description" id="description" class="form-control" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="poster">Poster URL</label>
                <input type="url" name="poster" id="poster" class="form-control">
            </div>
            <div class="form-group">
                <label for="category_id">Category</label>
                <select name="category_id" id="category_id" class="form-control" required>
                    <option value="">-- Select a Category --</option>
                    <?php
                    $sql = "SELECT id, name FROM categories ORDER BY name";
                    $result = $conn->query($sql);
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['name']) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" name="country" id="country" class="form-control">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="year">Year</label>
                        <input type="number" name="year" id="year" class="form-control">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                     <div class="form-group">
                        <label for="rating">Rating (e.g., 8.5)</label>
                        <input type="text" name="rating" id="rating" class="form-control">
                    </div>
                </div>
                <div class="col-md-4">
                     <div class="form-group">
                        <label for="duration">Duration (e.g., 1h 30m)</label>
                        <input type="text" name="duration" id="duration" class="form-control">
                    </div>
                </div>
                <div class="col-md-4">
                     <div class="form-group">
                        <label for="parental_rating">Parental Rating (e.g., PG-13)</label>
                        <input type="text" name="parental_rating" id="parental_rating" class="form-control">
                    </div>
                </div>
            </div>
            <textarea name="tmdb_data" id="tmdb_data" style="display:none;"></textarea>
            <button type="submit" class="btn btn-primary">Create Entry and Continue to Edit</button>
        </form>
    </div>
</div>

<?php
$conn->close();
?>

<script>
document.getElementById('fetch_tmdb').addEventListener('click', function() {
    const tmdbId = document.getElementById('tmdb_id').value;
    const tmdbType = document.getElementById('tmdb_type').value;
    const statusDiv = document.getElementById('tmdb-status');

    if (!tmdbId) {
        statusDiv.innerHTML = '<div class="alert alert-warning">Please enter a TMDB ID.</div>';
        return;
    }

    statusDiv.innerHTML = '<div class="alert alert-info">Fetching data from TMDB...</div>';

    fetch(`../api/tmdb.php?id=${tmdbId}&type=${tmdbType}`)
        .then(response => {
            if (!response.ok) {
                return response.json().then(errorBody => {
                    throw new Error(errorBody.status_message || `Request failed with status ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success === false) {
                throw new Error(data.status_message || 'TMDB API returned an error.');
            }

            // Store the full data object in the hidden textarea
            document.getElementById('tmdb_data').value = JSON.stringify(data);

            // Create a more descriptive success message
            let successMessage = `Fetched '${data.title || data.name}'.`;
            if (data.seasons) {
                successMessage += ` It has ${data.seasons.length} seasons.`;
            }
            successMessage += ' Form populated. Review and click "Create Entry" to save.';
            statusDiv.innerHTML = `<div class="alert alert-success">${successMessage}</div>`;

            // Populate form fields
            document.getElementById('title').value = data.title || data.name || '';
            document.getElementById('description').value = data.overview || '';
            if (data.poster_path) {
                document.getElementById('poster').value = 'https://image.tmdb.org/t/p/w500' + data.poster_path;
            }

            const releaseDate = data.release_date || data.first_air_date || '';
            if (releaseDate) {
                document.getElementById('year').value = releaseDate.substring(0, 4);
            }

            if (data.production_countries && data.production_countries.length > 0) {
                document.getElementById('country').value = data.production_countries.map(c => c.name).join(', ');
            }

            document.getElementById('rating').value = data.vote_average ? data.vote_average.toFixed(1) : '';

            if (tmdbType === 'movie') {
                if (data.runtime) {
                    const hours = Math.floor(data.runtime / 60);
                    const minutes = data.runtime % 60;
                    document.getElementById('duration').value = `${hours}h ${minutes}m`;
                }
                setCategory('Movies');
                if (data.release_dates && data.release_dates.results) {
                    const usRelease = data.release_dates.results.find(r => r.iso_3166_1 === 'US');
                    if (usRelease && usRelease.release_dates[0] && usRelease.release_dates[0].certification) {
                        document.getElementById('parental_rating').value = usRelease.release_dates[0].certification;
                    }
                }
            } else if (tmdbType === 'tv') {
                if (data.episode_run_time && data.episode_run_time.length > 0) {
                    document.getElementById('duration').value = `${data.episode_run_time[0]}m`;
                }
                setCategory('TV Series');
                if (data.content_ratings && data.content_ratings.results) {
                    const usRating = data.content_ratings.results.find(r => r.iso_3166_1 === 'US');
                    if (usRating && usRating.rating) {
                        document.getElementById('parental_rating').value = usRating.rating;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error fetching from TMDB:', error);
            statusDiv.innerHTML = `<div class="alert alert-danger">Error fetching data: ${error.message}</div>`;
        });
});

function setCategory(categoryName) {
    const categorySelect = document.getElementById('category_id');
    for (let i = 0; i < categorySelect.options.length; i++) {
        if (categorySelect.options[i].text.trim().toLowerCase() === categoryName.trim().toLowerCase()) {
            categorySelect.selectedIndex = i;
            break;
        }
    }
}
</script>

<?php
include '../footer.php';
?>
