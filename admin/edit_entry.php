<?php
require_once 'auth_check.php';
require_once '../db.php';

$entry_id = $_GET['id'];

// Handle POST requests for various actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    // Update main entry details
    if ($action == 'update_entry') {
        $stmt = $conn->prepare("UPDATE entries SET title=?, description=?, poster=?, thumbnail=?, category_id=?, country=?, rating=?, duration=?, year=?, parental_rating=? WHERE id=?");
        $thumbnail = $_POST['poster'];
        $stmt->bind_param("ssssisdsisi", $_POST['title'], $_POST['description'], $_POST['poster'], $thumbnail, $_POST['category_id'], $_POST['country'], $_POST['rating'], $_POST['duration'], $_POST['year'], $_POST['parental_rating'], $entry_id);
        $stmt->execute();
        $stmt->close();
    }

    // Add a new season
    if ($action == 'add_season') {
        $stmt = $conn->prepare("INSERT INTO seasons (entry_id, season_number, poster) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $entry_id, $_POST['season_number'], $_POST['season_poster']);
        $stmt->execute();
        $stmt->close();
    }

    // Add a new server
    if ($action == 'add_server') {
        $stmt = $conn->prepare("INSERT INTO servers (entry_id, name, url, license, drm) VALUES (?, ?, ?, ?, ?)");
        $drm = isset($_POST['drm']) ? 1 : 0;
        $stmt->bind_param("isssi", $entry_id, $_POST['name'], $_POST['url'], $_POST['license'], $drm);
        $stmt->execute();
        $stmt->close();
    }

    // Delete a server
    if ($action == 'delete_server') {
        $server_id = $_POST['server_id'];
        $stmt = $conn->prepare("DELETE FROM servers WHERE id = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }

    // Redirect to the same page to see changes and prevent form resubmission
    header("Location: edit_entry.php?id=" . $entry_id);
    exit();
}


// Fetch entry details
$stmt = $conn->prepare("SELECT e.*, c.name as category_name FROM entries e LEFT JOIN categories c ON e.category_id = c.id WHERE e.id = ?");
$stmt->bind_param("i", $entry_id);
$stmt->execute();
$result = $stmt->get_result();
$entry = $result->fetch_assoc();
$stmt->close();

if (!$entry) {
    // Handle case where entry is not found
    header("Location: manage_entries.php");
    exit();
}


include '../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Entry: <?php echo htmlspecialchars($entry['title']); ?></h2>
    <a href="manage_entries.php" class="btn btn-secondary">Back to Entries</a>
</div>

<!-- Nav tabs -->
<ul class="nav nav-tabs" id="myTab" role="tablist">
  <li class="nav-item">
    <a class="nav-link active" id="details-tab" data-toggle="tab" href="#details" role="tab">Details</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="servers-tab" data-toggle="tab" href="#servers" role="tab">Servers</a>
  </li>
  <?php if ($entry['category_name'] == 'TV Series'): ?>
  <li class="nav-item">
    <a class="nav-link" id="seasons-tab" data-toggle="tab" href="#seasons" role="tab">Seasons & Episodes</a>
  </li>
  <?php endif; ?>
</ul>

<!-- Tab panes -->
<div class="tab-content mt-4">
    <!-- Main Details Tab -->
    <div class="tab-pane active" id="details" role="tabpanel">
        <div class="card">
            <div class="card-header"><h4>Main Details</h4></div>
            <div class="card-body">
                <form action="edit_entry.php?id=<?php echo $entry_id; ?>" method="post">
                    <input type="hidden" name="action" value="update_entry">
                    <!-- Form fields for entry details -->
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" name="title" id="title" class="form-control" value="<?php echo htmlspecialchars($entry['title']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"><?php echo htmlspecialchars($entry['description']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="poster">Poster URL</label>
                        <input type="url" name="poster" id="poster" class="form-control" value="<?php echo htmlspecialchars($entry['poster']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <?php
                            $sql = "SELECT id, name FROM categories ORDER BY name";
                            $cat_result = $conn->query($sql);
                            while($cat_row = $cat_result->fetch_assoc()) {
                                $selected = ($cat_row['id'] == $entry['category_id']) ? 'selected' : '';
                                echo '<option value="' . $cat_row['id'] . '" ' . $selected . '>' . htmlspecialchars($cat_row['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <!-- More form fields... -->
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Servers Tab -->
    <div class="tab-pane" id="servers" role="tabpanel">
        <div class="card">
            <div class="card-header"><h4>Manage Servers</h4></div>
            <div class="card-body">
                <h5>Existing Servers</h5>
                <table class="table table-striped">
                    <!-- Table header -->
                    <tbody>
                        <?php
                        $stmt_servers = $conn->prepare("SELECT * FROM servers WHERE entry_id = ? AND episode_id IS NULL");
                        $stmt_servers->bind_param("i", $entry_id);
                        $stmt_servers->execute();
                        $servers_result = $stmt_servers->get_result();
                        while($server = $servers_result->fetch_assoc()) {
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($server['name']); ?></td>
                            <td><?php echo htmlspecialchars($server['url']); ?></td>
                            <td>
                                <form action="edit_entry.php?id=<?php echo $entry_id; ?>" method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_server">
                                    <input type="hidden" name="server_id" value="<?php echo $server['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php
                        }
                        $stmt_servers->close();
                        ?>
                    </tbody>
                </table>
                <hr>
                <h5>Add New Server</h5>
                <form action="edit_entry.php?id=<?php echo $entry_id; ?>" method="post">
                    <input type="hidden" name="action" value="add_server">
                    <div class="form-row">
                        <div class="col">
                            <input type="text" name="name" class="form-control" placeholder="Server Name (e.g., HD)" required>
                        </div>
                        <div class="col">
                            <input type="url" name="url" class="form-control" placeholder="Server URL" required>
                        </div>
                        <div class="col">
                            <input type="text" name="license" class="form-control" placeholder="License Key (optional)">
                        </div>
                        <div class="col-auto">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="drm" value="1" id="drm">
                                <label class="form-check-label" for="drm">DRM</label>
                            </div>
                        </div>
                        <div class="col">
                            <button type="submit" class="btn btn-primary">Add Server</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Seasons & Episodes Tab -->
    <?php if ($entry['category_name'] == 'TV Series'): ?>
    <div class="tab-pane" id="seasons" role="tabpanel">
        <div class="card">
            <div class="card-header"><h4>Manage Seasons & Episodes</h4></div>
            <div class="card-body">
                <h5>Existing Seasons</h5>
                <table class="table table-striped">
                    <!-- Table header -->
                    <tbody>
                        <?php
                        $stmt_seasons = $conn->prepare("SELECT * FROM seasons WHERE entry_id = ? ORDER BY season_number");
                        $stmt_seasons->bind_param("i", $entry_id);
                        $stmt_seasons->execute();
                        $seasons_result = $stmt_seasons->get_result();
                        while($season = $seasons_result->fetch_assoc()) {
                        ?>
                        <tr>
                            <td>Season <?php echo htmlspecialchars($season['season_number']); ?></td>
                            <td>
                                <!--  Link to a future page for managing episodes -->
                                <a href="manage_episodes.php?season_id=<?php echo $season['id']; ?>" class="btn btn-sm btn-info">Manage Episodes</a>
                                <!-- Edit and Delete for season can be added here -->
                            </td>
                        </tr>
                        <?php
                        }
                        $stmt_seasons->close();
                        ?>
                    </tbody>
                </table>
                <hr>
                <h5>Add New Season</h5>
                <form action="edit_entry.php?id=<?php echo $entry_id; ?>" method="post">
                    <input type="hidden" name="action" value="add_season">
                    <div class="form-row">
                        <div class="col">
                            <input type="number" name="season_number" class="form-control" placeholder="Season Number" required>
                        </div>
                        <div class="col">
                             <input type="url" name="season_poster" class="form-control" placeholder="Season Poster URL">
                        </div>
                        <div class="col">
                            <button type="submit" class="btn btn-primary">Add Season</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$conn->close();
include '../footer.php';
?>
