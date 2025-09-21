<?php
require_once 'auth_check.php';
require_once '../db.php';
include '../header.php'; // Using the main site header

// Handle form submission for adding a new category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    $category_name = $_POST['category_name'];
    if (!empty($category_name)) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $category_name);
        $stmt->execute();
        $stmt->close();
        // Redirect to the same page to see the new category and prevent form resubmission
        header("Location: index.php");
        exit();
    }
}

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Admin Panel - Manage Categories</h2>
    <div>
        <a href="manage_entries.php" class="btn btn-info">Manage Entries</a>
        <a href="bulk_import_tmdb.php" class="btn btn-success">Bulk Import from TMDB</a>
        <a href="logout.php" class="btn btn-danger">Logout</a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h4>Existing Categories</h4>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT id, name FROM categories ORDER BY id";
                        $result = $conn->query($sql);
                        if ($result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) {
                        ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td>
                                <a href="edit_category.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="delete_category.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this category?');">Delete</a>
                            </td>
                        </tr>
                        <?php
                            }
                        } else {
                            echo "<tr><td colspan='3'>No categories found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h4>Add New Category</h4>
            </div>
            <div class="card-body">
                <form action="index.php" method="post">
                    <div class="form-group">
                        <label for="category_name">Category Name</label>
                        <input type="text" name="category_name" id="category_name" class="form-control" required>
                    </div>
                    <button type="submit" name="add_category" class="btn btn-primary btn-block">Add Category</button>
                </form>
            </div>
        </div>
    </div>
</div>


<?php
$conn->close();
include '../footer.php';
?>
