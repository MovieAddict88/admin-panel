<?php
// Set header to return JSON
header('Content-Type: application/json');

// Include database connection
require_once '../db.php';

// Check if entry_id is provided
if (isset($_GET['id'])) {
    $entry_id = $_GET['id'];

    // Prepare and execute the statement for the entry
    $stmt = $conn->prepare("SELECT * FROM entries WHERE id = ?");
    $stmt->bind_param("i", $entry_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $entry = $result->fetch_assoc();

        // Fetch servers for the entry
        $stmt_servers = $conn->prepare("SELECT * FROM servers WHERE entry_id = ?");
        $stmt_servers->bind_param("i", $entry_id);
        $stmt_servers->execute();
        $result_servers = $stmt_servers->get_result();
        $servers = array();
        while($server = $result_servers->fetch_assoc()) {
            $servers[] = $server;
        }
        $entry['servers'] = $servers;
        $stmt_servers->close();

        // Fetch seasons for the entry
        $stmt_seasons = $conn->prepare("SELECT * FROM seasons WHERE entry_id = ? ORDER BY season_number");
        $stmt_seasons->bind_param("i", $entry_id);
        $stmt_seasons->execute();
        $result_seasons = $stmt_seasons->get_result();
        $seasons = array();
        while($season = $result_seasons->fetch_assoc()) {
            // Fetch episodes for the season
            $stmt_episodes = $conn->prepare("SELECT * FROM episodes WHERE season_id = ? ORDER BY episode_number");
            $stmt_episodes->bind_param("i", $season['id']);
            $stmt_episodes->execute();
            $result_episodes = $stmt_episodes->get_result();
            $episodes = array();
            while($episode = $result_episodes->fetch_assoc()) {
                // Fetch servers for the episode
                $stmt_episode_servers = $conn->prepare("SELECT * FROM servers WHERE episode_id = ?");
                $stmt_episode_servers->bind_param("i", $episode['id']);
                $stmt_episode_servers->execute();
                $result_episode_servers = $stmt_episode_servers->get_result();
                $episode_servers = array();
                while($server = $result_episode_servers->fetch_assoc()) {
                    $episode_servers[] = $server;
                }
                $episode['servers'] = $episode_servers;
                $stmt_episode_servers->close();
                $episodes[] = $episode;
            }
            $season['episodes'] = $episodes;
            $stmt_episodes->close();
            $seasons[] = $season;
        }
        $entry['seasons'] = $seasons;
        $stmt_seasons->close();

        $response = $entry;

    } else {
        $response = array('error' => 'Entry not found.');
    }
    $stmt->close();
} else {
    // If no id is provided, return an error message
    $response = array('error' => 'No id provided.');
}

// Close the connection
$conn->close();

// Echo the JSON response
echo json_encode($response);
?>
