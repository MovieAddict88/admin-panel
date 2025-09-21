<?php include 'header.php'; ?>

<?php
include 'db.php';
$entry_id = $_GET['id'];

// Fetch entry details
$stmt = $conn->prepare("SELECT * FROM entries WHERE id = ?");
$stmt->bind_param("i", $entry_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $entry = $result->fetch_assoc();
?>

<div class="row">
    <div class="col-md-4">
        <img src="<?php echo htmlspecialchars($entry['poster']); ?>" class="img-fluid rounded" alt="<?php echo htmlspecialchars($entry['title']); ?>">
    </div>
    <div class="col-md-8">
        <h1><?php echo htmlspecialchars($entry['title']); ?></h1>
        <p class="text-muted">
            <?php echo htmlspecialchars($entry['year']); ?> |
            <?php echo htmlspecialchars($entry['duration']); ?> |
            <span class="badge badge-secondary"><?php echo htmlspecialchars($entry['parental_rating']); ?></span>
        </p>
        <p><?php echo htmlspecialchars($entry['description']); ?></p>

        <?php
        // Fetch servers for the entry
        $stmt_servers = $conn->prepare("SELECT * FROM servers WHERE entry_id = ?");
        $stmt_servers->bind_param("i", $entry_id);
        $stmt_servers->execute();
        $result_servers = $stmt_servers->get_result();
        if ($result_servers->num_rows > 0) {
        ?>
        <h4 class="mt-4">Servers</h4>
        <div class="list-group">
            <?php while($server = $result_servers->fetch_assoc()) { ?>
                <a href="<?php echo htmlspecialchars($server['url']); ?>" class="list-group-item list-group-item-action" target="_blank">
                    <?php echo htmlspecialchars($server['name']); ?>
                </a>
            <?php } ?>
        </div>
        <?php
        }
        $stmt_servers->close();
        ?>
    </div>
</div>

<?php
// Fetch seasons and episodes if it is a TV Series
$stmt_seasons = $conn->prepare("SELECT * FROM seasons WHERE entry_id = ? ORDER BY season_number");
$stmt_seasons->bind_param("i", $entry_id);
$stmt_seasons->execute();
$result_seasons = $stmt_seasons->get_result();
if ($result_seasons->num_rows > 0) {
?>
<div class="row mt-4">
    <div class="col-12">
        <h2>Seasons</h2>
        <div id="accordion">
            <?php while($season = $result_seasons->fetch_assoc()) { ?>
            <div class="card">
                <div class="card-header" id="heading<?php echo $season['id']; ?>">
                    <h5 class="mb-0">
                        <button class="btn btn-link" data-toggle="collapse" data-target="#collapse<?php echo $season['id']; ?>" aria-expanded="true" aria-controls="collapse<?php echo $season['id']; ?>">
                            Season <?php echo htmlspecialchars($season['season_number']); ?>
                        </button>
                    </h5>
                </div>
                <div id="collapse<?php echo $season['id']; ?>" class="collapse" aria-labelledby="heading<?php echo $season['id']; ?>" data-parent="#accordion">
                    <div class="card-body">
                        <?php
                        $stmt_episodes = $conn->prepare("SELECT * FROM episodes WHERE season_id = ? ORDER BY episode_number");
                        $stmt_episodes->bind_param("i", $season['id']);
                        $stmt_episodes->execute();
                        $result_episodes = $stmt_episodes->get_result();
                        if ($result_episodes->num_rows > 0) {
                        ?>
                        <ul class="list-unstyled">
                            <?php while($episode = $result_episodes->fetch_assoc()) { ?>
                            <li class="media mb-3">
                                <img src="<?php echo htmlspecialchars($episode['thumbnail']); ?>" class="mr-3" alt="..." width="150">
                                <div class="media-body">
                                    <h5 class="mt-0 mb-1"><?php echo htmlspecialchars($episode['episode_number']); ?>. <?php echo htmlspecialchars($episode['title']); ?></h5>
                                    <?php echo htmlspecialchars($episode['description']); ?>
                                    <?php
                                    // Fetch servers for the episode
                                    $stmt_episode_servers = $conn->prepare("SELECT * FROM servers WHERE episode_id = ?");
                                    $stmt_episode_servers->bind_param("i", $episode['id']);
                                    $stmt_episode_servers->execute();
                                    $result_episode_servers = $stmt_episode_servers->get_result();
                                    if ($result_episode_servers->num_rows > 0) {
                                    ?>
                                    <p class="mt-2"><strong>Servers:</strong>
                                        <?php while($server = $result_episode_servers->fetch_assoc()) { ?>
                                            <a href="<?php echo htmlspecialchars($server['url']); ?>" class="badge badge-info" target="_blank"><?php echo htmlspecialchars($server['name']); ?></a>
                                        <?php } ?>
                                    </p>
                                    <?php
                                    }
                                    $stmt_episode_servers->close();
                                    ?>
                                </div>
                            </li>
                            <?php } ?>
                        </ul>
                        <?php
                        } else {
                            echo "<p>No episodes found for this season.</p>";
                        }
                        $stmt_episodes->close();
                        ?>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php
}
$stmt_seasons->close();
?>

<?php
} else {
    echo "<div class='alert alert-danger'>Entry not found.</div>";
}
$stmt->close();
$conn->close();
?>

<div class="mt-4">
    <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
</div>

<?php include 'footer.php'; ?>
