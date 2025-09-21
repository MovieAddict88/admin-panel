<?php include 'header.php'; ?>

<?php
include 'db.php';
$category_id = $_GET['id'];

// Fetch category name
$stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();
$category_name = "Category";
if ($result->num_rows > 0) {
    $category_name = $result->fetch_assoc()['name'];
}
$stmt->close();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><?php echo htmlspecialchars($category_name); ?></h1>
    <a href="index.php" class="btn btn-secondary">Back to Categories</a>
</div>


<div class="row">
    <?php
    // Fetch entries
    $stmt = $conn->prepare("SELECT id, title, poster, description FROM entries WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
    ?>
    <div class="col-md-4 col-sm-6 mb-4">
        <div class="card h-100">
            <img src="<?php echo htmlspecialchars($row['poster']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($row['title']); ?>" style="height: 300px; object-fit: cover;">
            <div class="card-body">
                <h5 class="card-title"><?php echo htmlspecialchars($row['title']); ?></h5>
                <p class="card-text"><?php echo substr(htmlspecialchars($row['description']), 0, 100); ?>...</p>
            </div>
            <div class="card-footer">
                <a href="entry.php?id=<?php echo $row['id']; ?>" class="btn btn-primary btn-block">View Details</a>
            </div>
        </div>
    </div>
    <?php
        }
    } else {
        echo "<p>No entries found in this category.</p>";
    }
    $stmt->close();
    $conn->close();
    ?>
</div>

<?php include 'footer.php'; ?>
