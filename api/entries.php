<?php
// Set header to return JSON
header('Content-Type: application/json');

// Include database connection
require_once '../db.php';

// Array to hold entries
$entries = array();

// Check if category_id is provided
if (isset($_GET['category_id'])) {
    $category_id = $_GET['category_id'];

    // Prepare and execute the statement
    $stmt = $conn->prepare("SELECT id, title, poster, thumbnail, description, year FROM entries WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $entries[] = $row;
        }
    }
    $stmt->close();
} else {
    // If no category_id is provided, return an error message
    $entries = array('error' => 'No category_id provided.');
}

// Close the connection
$conn->close();

// Echo the JSON response
echo json_encode($entries);
?>
