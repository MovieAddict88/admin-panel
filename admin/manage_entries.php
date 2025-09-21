<?php
require_once 'auth_check.php';
require_once '../db.php';
include '../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Admin Panel - Manage Entries</h2>
    <div>
        <a href="add_entry.php" class="btn btn-success">Add New Entry</a>
        <a href="index.php" class="btn btn-secondary">Manage Categories</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h4>All Entries</h4>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Year</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Pagination logic
                $limit = 10; // Entries per page
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $offset = ($page - 1) * $limit;

                // Get total number of entries
                $total_result = $conn->query("SELECT COUNT(id) AS total FROM entries");
                $total_entries = $total_result->fetch_assoc()['total'];
                $total_pages = ceil($total_entries / $limit);

                // Fetch entries for the current page
                $sql = "SELECT e.id, e.title, c.name AS category_name, e.year
                        FROM entries e
                        LEFT JOIN categories c ON e.category_id = c.id
                        ORDER BY e.id DESC
                        LIMIT $limit OFFSET $offset";
                $result = $conn->query($sql);
                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['year']); ?></td>
                    <td>
                        <a href="edit_entry.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                        <a href="delete_entry.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this entry? This will also delete all associated seasons, episodes, and servers.');">Delete</a>
                    </td>
                </tr>
                <?php
                    }
                } else {
                    echo "<tr><td colspan='5'>No entries found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <nav>
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php if ($page == $i) echo 'active'; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
</div>

<?php
$conn->close();
include '../footer.php';
?>
