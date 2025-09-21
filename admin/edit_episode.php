<?php
require_once 'auth_check.php';
require_once '../db.php';

$episode_id = $_GET['id'];

// Fetch episode details for context
$stmt = $conn->prepare("SELECT * FROM episodes WHERE id = ?");
$stmt->bind_param("i", $episode_id);
$stmt->execute();
$result = $stmt->get_result();
$episode = $result->fetch_assoc();
$stmt->close();

if (!$episode) {
    // Redirect if episode not found
    header("Location: manage_entries.php");
    exit();
}

$season_id = $episode['season_id'];

// Handle form submission for updating the episode
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $stmt = $conn->prepare("UPDATE episodes SET episode_number = ?, title = ?, duration = ?, description = ?, thumbnail = ? WHERE id = ?");
    $stmt->bind_param("issssi", $_POST['episode_number'], $_POST['title'], $_POST['duration'], $_POST['description'], $_POST['thumbnail'], $episode_id);
    $stmt->execute();
    $stmt->close();

    header("Location: manage_episodes.php?season_id=" . $season_id);
    exit();
}

include '../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Episode</h2>
    <a href="manage_episodes.php?season_id=<?php echo $season_id; ?>" class="btn btn-secondary">Back to Episodes</a>
</div>

<div class="card">
    <div class="card-body">
        <form action="edit_episode.php?id=<?php echo $episode_id; ?>" method="post">
            <div class="form-group">
                <label for="episode_number">Episode Number</label>
                <input type="number" name="episode_number" id="episode_number" class="form-control" value="<?php echo htmlspecialchars($episode['episode_number']); ?>" required>
            </div>
            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" name="title" id="title" class="form-control" value="<?php echo htmlspecialchars($episode['title']); ?>" required>
            </div>
            <div class="form-group">
                <label for="duration">Duration</label>
                <input type="text" name="duration" id="duration" class="form-control" value="<?php echo htmlspecialchars($episode['duration']); ?>">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea name="description" id="description" class="form-control" rows="3"><?php echo htmlspecialchars($episode['description']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="thumbnail">Thumbnail URL</label>
                <input type="url" name="thumbnail" id="thumbnail" class="form-control" value="<?php echo htmlspecialchars($episode['thumbnail']); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>

<?php
$conn->close();
include '../footer.php';
?>
