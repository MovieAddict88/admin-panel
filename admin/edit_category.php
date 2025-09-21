<?php
require_once 'auth_check.php';
require_once '../db.php';

$category_id = $_GET['id'];
$category_name = '';

// Fetch the current category name
$stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $category_name = $result->fetch_assoc()['name'];
} else {
    // Optional: handle case where category is not found
    header("Location: index.php");
    exit();
}
$stmt->close();


// Handle form submission for updating the category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_category'])) {
    $new_category_name = $_POST['category_name'];
    if (!empty($new_category_name)) {
        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $new_category_name, $category_id);
        $stmt->execute();
        $stmt->close();
        header("Location: index.php");
        exit();
    }
}

include '../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Category</h2>
    <a href="index.php" class="btn btn-secondary">Back to Categories</a>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h4>Editing Category #<?php echo $category_id; ?></h4>
            </div>
            <div class="card-body">
                <form action="edit_category.php?id=<?php echo $category_id; ?>" method="post">
                    <div class="form-group">
                        <label for="category_name">Category Name</label>
                        <input type="text" name="category_name" id="category_name" class="form-control" value="<?php echo htmlspecialchars($category_name); ?>" required>
                    </div>
                    <button type="submit" name="edit_category" class="btn btn-primary btn-block">Update Category</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
include '../footer.php';
?>
