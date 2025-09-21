<?php
require_once 'auth_check.php';
require_once '../db.php';

if (isset($_GET['id'])) {
    $entry_id = $_GET['id'];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // 1. Get all season IDs for the entry
        $seasons_to_delete = [];
        $stmt = $conn->prepare("SELECT id FROM seasons WHERE entry_id = ?");
        $stmt->bind_param("i", $entry_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $seasons_to_delete[] = $row['id'];
        }
        $stmt->close();

        if (!empty($seasons_to_delete)) {
            // 2. Get all episode IDs for those seasons
            $episodes_to_delete = [];
            $season_ids_str = implode(',', array_map('intval', $seasons_to_delete));
            $result = $conn->query("SELECT id FROM episodes WHERE season_id IN ($season_ids_str)");
            while ($row = $result->fetch_assoc()) {
                $episodes_to_delete[] = $row['id'];
            }

            if (!empty($episodes_to_delete)) {
                 // 3. Delete servers linked to the episodes
                $episode_ids_str = implode(',', array_map('intval', $episodes_to_delete));
                $conn->query("DELETE FROM servers WHERE episode_id IN ($episode_ids_str)");

                // 4. Delete the episodes themselves
                $conn->query("DELETE FROM episodes WHERE id IN ($episode_ids_str)");
            }

            // 5. Delete the seasons
            $conn->query("DELETE FROM seasons WHERE id IN ($season_ids_str)");
        }

        // 6. Delete servers linked directly to the entry
        $stmt = $conn->prepare("DELETE FROM servers WHERE entry_id = ?");
        $stmt->bind_param("i", $entry_id);
        $stmt->execute();
        $stmt->close();

        // 7. Finally, delete the entry itself
        $stmt = $conn->prepare("DELETE FROM entries WHERE id = ?");
        $stmt->bind_param("i", $entry_id);
        $stmt->execute();
        $stmt->close();

        // If all queries were successful, commit the transaction
        $conn->commit();

    } catch (mysqli_sql_exception $exception) {
        // If any query fails, roll back the transaction
        $conn->rollback();
        // Optionally, handle the error, e.g., log it or show an error message
        // For now, we just redirect
    }
}

header("Location: manage_entries.php");
exit();
?>
