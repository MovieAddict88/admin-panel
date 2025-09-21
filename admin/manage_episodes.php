<?php
require_once 'auth_check.php';
require_once '../db.php';

$season_id = $_GET['season_id'];

// Fetch season and entry details for context
$stmt = $conn->prepare("SELECT s.season_number, e.title as entry_title, e.id as entry_id FROM seasons s JOIN entries e ON s.entry_id = e.id WHERE s.id = ?");
$stmt->bind_param("i", $season_id);
$stmt->execute();
$result = $stmt->get_result();
$season_info = $result->fetch_assoc();
$stmt->close();

if (!$season_info) {
    header("Location: manage_entries.php");
    exit();
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    // Add a new episode
    if ($action == 'add_episode') {
        $stmt = $conn->prepare("INSERT INTO episodes (season_id, episode_number, title, duration, description, thumbnail) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $season_id, $_POST['episode_number'], $_POST['title'], $_POST['duration'], $_POST['description'], $_POST['thumbnail']);
        $stmt->execute();
        $stmt->close();
    }

    // Delete an episode
    if ($action == 'delete_episode') {
        $episode_id = $_POST['episode_id'];
        // Also delete associated servers
        $stmt = $conn->prepare("DELETE FROM servers WHERE episode_id = ?");
        $stmt->bind_param("i", $episode_id);
        $stmt->execute();
        $stmt->close();
        // Now delete the episode
        $stmt = $conn->prepare("DELETE FROM episodes WHERE id = ?");
        $stmt->bind_param("i", $episode_id);
        $stmt->execute();
        $stmt->close();
    }

    // Add a server to an episode
    if ($action == 'add_episode_server') {
        $episode_id = $_POST['episode_id'];
        $stmt = $conn->prepare("INSERT INTO servers (episode_id, name, url, license, drm) VALUES (?, ?, ?, ?, ?)");
        $drm = isset($_POST['drm']) ? 1 : 0;
        $stmt->bind_param("isssi", $episode_id, $_POST['name'], $_POST['url'], $_POST['license'], $drm);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: manage_episodes.php?season_id=" . $season_id);
    exit();
}


include '../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Manage Episodes for: <?php echo htmlspecialchars($season_info['entry_title']); ?> - Season <?php echo htmlspecialchars($season_info['season_number']); ?></h2>
    <a href="edit_entry.php?id=<?php echo $season_info['entry_id']; ?>" class="btn btn-secondary">Back to Entry</a>
</div>

<!-- Add new episode form -->
<div class="card mb-4">
    <div class="card-header"><h4>Add New Episode</h4></div>
    <div class="card-body">
        <form action="manage_episodes.php?season_id=<?php echo $season_id; ?>" method="post">
            <input type="hidden" name="action" value="add_episode">
            <!-- Form fields for a new episode -->
            <div class="form-group">
                <input type="number" name="episode_number" class="form-control" placeholder="Episode Number" required>
            </div>
             <div class="form-group">
                <input type="text" name="title" class="form-control" placeholder="Episode Title" required>
            </div>
            <!-- More fields... -->
            <button type="submit" class="btn btn-primary">Add Episode</button>
        </form>
    </div>
</div>

<!-- Existing episodes list -->
<div class="card">
    <div class="card-header"><h4>Existing Episodes</h4></div>
    <div class="card-body">
        <?php
        $stmt_episodes = $conn->prepare("SELECT * FROM episodes WHERE season_id = ? ORDER BY episode_number");
        $stmt_episodes->bind_param("i", $season_id);
        $stmt_episodes->execute();
        $episodes_result = $stmt_episodes->get_result();
        while($episode = $episodes_result->fetch_assoc()) {
        ?>
        <div class="episode-item mb-3 p-3 border rounded">
            <h5><?php echo htmlspecialchars($episode['episode_number']); ?>. <?php echo htmlspecialchars($episode['title']); ?></h5>
            <!-- Edit/Delete buttons for episode -->
            <div class="float-right">
                <a href="edit_episode.php?id=<?php echo $episode['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                <form action="manage_episodes.php?season_id=<?php echo $season_id; ?>" method="post" style="display:inline;">
                    <input type="hidden" name="action" value="delete_episode">
                    <input type="hidden" name="episode_id" value="<?php echo $episode['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </div>

            <!-- Server management for this episode -->
            <p><strong>Servers:</strong></p>
            <ul>
                <?php
                $stmt_servers = $conn->prepare("SELECT * FROM servers WHERE episode_id = ?");
                $stmt_servers->bind_param("i", $episode['id']);
                $stmt_servers->execute();
                $servers_result = $stmt_servers->get_result();
                while($server = $servers_result->fetch_assoc()) {
                    echo '<li>' . htmlspecialchars($server['name']) . '</li>';
                }
                $stmt_servers->close();
                ?>
            </ul>
            <form action="manage_episodes.php?season_id=<?php echo $season_id; ?>" method="post" class="form-inline">
                <input type="hidden" name="action" value="add_episode_server">
                <input type="hidden" name="episode_id" value="<?php echo $episode['id']; ?>">
                <input type="text" name="name" class="form-control form-control-sm mr-2" placeholder="Server Name" required>
                <input type="url" name="url" class="form-control form-control-sm mr-2" placeholder="Server URL" required>
                <button type="submit" class="btn btn-sm btn-primary">Add Server</button>
            </form>
        </div>
        <?php
        }
        $stmt_episodes->close();
        ?>
    </div>
</div>


<?php
$conn->close();
include '../footer.php';
?>
