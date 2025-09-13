<?php
require 'db_config.php';

// Obsługa usuwania trasy
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_do_usuniecia = (int)$_GET['id'];
    
    // Transakcja, aby bezpiecznie usunąć wszystko
    mysqli_begin_transaction($conn);
    try {
        // Usuń wpisy z przejazdów powiązane z trasą (aby uniknąć błędu klucza obcego)
        $stmt1 = mysqli_prepare($conn, "DELETE FROM przejazdy WHERE id_trasy = ?");
        mysqli_stmt_bind_param($stmt1, "i", $id_do_usuniecia);
        mysqli_stmt_execute($stmt1);

        // Usuń stacje z trasy
        $stmt2 = mysqli_prepare($conn, "DELETE FROM stacje_na_trasie WHERE id_trasy = ?");
        mysqli_stmt_bind_param($stmt2, "i", $id_do_usuniecia);
        mysqli_stmt_execute($stmt2);
        
        // Usuń samą trasę
        $stmt3 = mysqli_prepare($conn, "DELETE FROM trasy WHERE id_trasy = ?");
        mysqli_stmt_bind_param($stmt3, "i", $id_do_usuniecia);
        mysqli_stmt_execute($stmt3);
        
        mysqli_commit($conn);
        $message = "Trasa i powiązane z nią przejazdy zostały usunięte.";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $message = "Błąd podczas usuwania: " . $e->getMessage();
    }
    header("Location: kreator_tras.php?msg=" . urlencode($message));
    exit();
}

$trasy = mysqli_query($conn, "SELECT * FROM trasy ORDER BY nazwa_trasy");
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Kreator Tras</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #007bff; color: white; }
        .actions a { margin-right: 10px; text-decoration: none; color: #007bff; }
        .actions a.delete { color: #dc3545; }
        .button { display: inline-block; background-color: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php">Powrót do menu</a>
        <h1>Zarządzanie Trasami</h1>
        
        <?php if (isset($_GET['msg'])) echo "<p><b>" . htmlspecialchars($_GET['msg']) . "</b></p>"; ?>

        <a href="edytor_tras.php" class="button">Stwórz nową trasę</a>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nazwa Trasy</th>
                    <th style="width: 150px;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($trasy)): ?>
                <tr>
                    <td><?= $row['id_trasy'] ?></td>
                    <td><?= htmlspecialchars($row['nazwa_trasy']) ?></td>
                    <td class="actions">
                        <a href="edytor_tras.php?id=<?= $row['id_trasy'] ?>">Edytuj</a>
                        <a href="?action=delete&id=<?= $row['id_trasy'] ?>" class="delete" onclick="return confirm('Czy na pewno chcesz usunąć tę trasę i WSZYSTKIE zapisane dla niej rozkłady?')">Usuń</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>