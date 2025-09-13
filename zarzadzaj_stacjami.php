<?php
require 'db_config.php';

// Obsługa usuwania stacji
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_do_usuniecia = (int)$_GET['id'];
    
    // Używamy try-catch, aby obsłużyć błąd klucza obcego (gdy stacja jest w użyciu)
    try {
        $stmt = mysqli_prepare($conn, "DELETE FROM stacje WHERE id_stacji = ?");
        mysqli_stmt_bind_param($stmt, "i", $id_do_usuniecia);
        if (mysqli_stmt_execute($stmt)) {
            $message = "Stacja została pomyślnie usunięta.";
        } else {
            throw new Exception(mysqli_error($conn));
        }
    } catch (Exception $e) {
        if (mysqli_errno($conn) == 1451) {
             $message = "BŁĄD: Nie można usunąć stacji, ponieważ jest już używana w trasach, odcinkach lub zapisanych rozkładach.";
        } else {
            $message = "Błąd podczas usuwania: " . $e->getMessage();
        }
    }
    header("Location: zarzadzaj_stacjami.php?msg=" . urlencode($message));
    exit();
}

$stacje = mysqli_query($conn, "SELECT s.*, ts.nazwa_typu_stacji FROM stacje s LEFT JOIN typy_stacji ts ON s.typ_stacji_id = ts.id_typu_stacji ORDER BY s.nazwa_stacji");
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzanie Stacjami</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
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
        <h1>Zarządzanie Stacjami</h1>
        
        <?php if (isset($_GET['msg'])) echo "<p style='font-weight:bold; color: #dc3545;'>" . htmlspecialchars($_GET['msg']) . "</p>"; ?>

        <a href="edytor_stacji.php" class="button">Dodaj nową stację</a>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nazwa Stacji</th>
                    <th>Typ</th>
                    <th>Uwagi</th>
                    <th>Linia Kolejowa</th>
                    <th style="width: 120px;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($stacje)): ?>
                <tr>
                    <td><?= $row['id_stacji'] ?></td>
                    <td><?= htmlspecialchars($row['nazwa_stacji']) ?></td>
                    <td><?= htmlspecialchars($row['nazwa_typu_stacji']) ?></td>
                    <td><?= htmlspecialchars($row['uwagi']) ?></td>
                    <td><?= htmlspecialchars($row['linia_kolejowa']) ?></td>
                    <td class="actions">
                        <a href="edytor_stacji.php?id=<?= $row['id_stacji'] ?>">Edytuj</a>
                        <a href="?action=delete&id=<?= $row['id_stacji'] ?>" class="delete" onclick="return confirm('UWAGA! Usunięcie stacji jest niemożliwe, jeśli jest ona częścią jakiejkolwiek trasy lub odcinka. Czy na pewno chcesz spróbować ją usunąć?')">Usuń</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>