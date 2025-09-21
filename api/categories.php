<?php
// Set header to return JSON
header('Content-Type: application/json');

// Include database connection
require_once '../db.php';

// Array to hold categories
$categories = array();

// Fetch categories from the database
$sql = "SELECT id, name FROM categories";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Close the connection
$conn->close();

// Echo the JSON response
echo json_encode($categories);
?>
