<?php
require_once 'auth_check.php';
require_once '../db.php';

if (isset($_GET['id'])) {
    $category_id = $_GET['id'];

    // Before deleting the category, we should consider what to do with entries that belong to it.
    // For now, we will just delete the category. In a real application, you might want to set
    // the category_id of the entries to NULL or prevent deletion if entries exist.

    // For simplicity, we will first set the category_id of associated entries to NULL.
    $stmt = $conn->prepare("UPDATE entries SET category_id = NULL WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $stmt->close();

    // Now, delete the category
    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $stmt->close();
}

header("Location: index.php");
exit();
?>
