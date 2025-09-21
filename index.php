<?php include 'header.php'; ?>

<div class="card">
    <div class="card-header">
        <h1>Categories</h1>
    </div>
    <div class="card-body">
        <div class="list-group">
            <?php
            include 'db.php';
            $sql = "SELECT id, name FROM categories";
            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    echo '<a href="category.php?id=' . $row["id"] . '" class="list-group-item list-group-item-action">' . htmlspecialchars($row["name"]) . '</a>';
                }
            } else {
                echo "<p>No categories found.</p>";
            }
            $conn->close();
            ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
