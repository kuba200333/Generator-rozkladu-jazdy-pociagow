<?php
require 'db_config.php';

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_do_usuniecia = (int)$_GET['id'];
    $stmt = mysqli_prepare($conn, "DELETE FROM ostrzezenia WHERE id_ostrzezenia = ?");
    mysqli_stmt_bind_param($stmt, "i", $id_do_usuniecia);
    mysqli_stmt_execute($stmt);
    header("Location: zarzadzaj_ostrzezeniami.php?msg=Ostrzeżenie usunięte.");
    exit();
}

$ostrzezenia = mysqli_query($conn, "SELECT * FROM ostrzezenia ORDER BY data_waznosci_od DESC");
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzanie Ostrzeżeniami (Rozkazy O)</title>
    </head>
<body>
    <div class="container">
        <a href="index.php">Powrót do menu</a>
        <h1>Zarządzanie Ostrzeżeniami (Rozkazy O)</h1>
        <a href="edytor_ostrzezenia.php" class="button">Dodaj nowe ostrzeżenie</a>
        
        <table>
            <thead>
                <tr>
                    <th>Lp.</th>
                    <th>Miejsce</th>
                    <th>Tor</th>
                    <th>Ograniczenie</th>
                    <th>Ważność</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($ostrzezenia)): ?>
                <tr>
                    <td><?= $row['id_ostrzezenia'] ?></td>
                    <td><?= htmlspecialchars($row['miejsce_opis']) ?></td>
                    <td><?= htmlspecialchars($row['nr_toru']) ?></td>
                    <td><?= $row['predkosc_max'] ?> km/h</td>
                    <td>od <?= $row['data_waznosci_od'] ?><?= $row['do_odwolania'] ? ' do odwołania' : ' do ' . $row['data_waznosci_do'] ?></td>
                    <td class="actions">
                        <a href="edytor_ostrzezenia.php?id=<?= $row['id_ostrzezenia'] ?>">Edytuj</a>
                        <a href="?action=delete&id=<?= $row['id_ostrzezenia'] ?>" class="delete" onclick="return confirm('Czy na pewno chcesz usunąć to ostrzeżenie?')">Usuń</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>