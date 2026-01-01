<?php
session_start();
require 'db_config.php';

// Obsługa dodawania
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $id_stacji = intval($_POST['id_stacji']);
    $id_kierunku = intval($_POST['id_kierunku']);
    
    // Obsługa poprzedniej stacji (może być NULL)
    $id_poprzedniej = !empty($_POST['id_poprzedniej']) ? intval($_POST['id_poprzedniej']) : null;
    
    $peron = $_POST['peron'];
    $tor = $_POST['tor'];

    // Sprawdzanie duplikatów
    // Musimy sprawdzić wariant z NULL i bez NULL
    if ($id_poprzedniej) {
        $check = mysqli_query($conn, "SELECT id FROM domyslne_perony WHERE id_stacji=$id_stacji AND id_kierunku=$id_kierunku AND id_poprzedniej=$id_poprzedniej");
    } else {
        $check = mysqli_query($conn, "SELECT id FROM domyslne_perony WHERE id_stacji=$id_stacji AND id_kierunku=$id_kierunku AND id_poprzedniej IS NULL");
    }

    if (mysqli_num_rows($check) > 0) {
        $msg = "Taka reguła już istnieje! Usuń ją najpierw.";
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO domyslne_perony (id_stacji, id_poprzedniej, id_kierunku, peron, tor) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iiiss", $id_stacji, $id_poprzedniej, $id_kierunku, $peron, $tor);
        mysqli_stmt_execute($stmt);
        $msg = "Dodano nową regułę.";
    }
}

// Obsługa usuwania
if (isset($_GET['delete'])) {
    $id_del = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM domyslne_perony WHERE id=$id_del");
    header("Location: zarzadzanie_peronami.php");
    exit;
}

// Pobieranie stacji do list
$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
$stacje = mysqli_fetch_all($stacje_res, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Konfiguracja Peronów (Węzły)</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background-color: #f4f4f4; font-size: 14px; }
        .container { background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 1000px; margin: 0 auto; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; border-bottom: 2px solid #007bff; padding-bottom: 10px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; color: #333; }
        .form-row { display: flex; gap: 10px; align-items: flex-end; background: #e9ecef; padding: 15px; border-radius: 5px; border: 1px solid #ced4da; }
        .form-group { flex: 1; }
        label { display: block; font-size: 11px; font-weight: bold; margin-bottom: 5px; color: #555; }
        select, input { width: 100%; padding: 6px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 3px; }
        button { background-color: #28a745; color: white; border: none; padding: 8px 15px; cursor: pointer; border-radius: 3px; font-weight: bold; }
        button:hover { background-color: #218838; }
        .btn-del { background-color: #dc3545; color: white; text-decoration: none; padding: 4px 8px; font-size: 11px; border-radius: 3px; font-weight: bold; }
        .msg { padding: 10px; margin-bottom: 10px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; }
        a.back { display: inline-block; margin-bottom: 15px; color: #007bff; text-decoration: none; font-weight: bold; }
        .arrow { color: #888; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back">&larr; Powrót do menu</a>
        <h2>📍 Konfiguracja Peronów (Obsługa Węzłów)</h2>
        <p>Zdefiniuj perony zależnie od tego SKĄD przyjeżdża pociąg i DOKĄD jedzie.</p>

        <?php if(isset($msg)) echo "<div class='msg'>$msg</div>"; ?>

        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                
                <div class="form-group" style="flex: 2;">
                    <label>1. Z kierunku (Poprzednia):</label>
                    <select name="id_poprzedniej">
                        <option value="">(Dowolna / Brak)</option>
                        <?php foreach($stacje as $s) echo "<option value='{$s['id_stacji']}'>{$s['nazwa_stacji']}</option>"; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 0.2; text-align:center; padding-bottom: 8px;">
                    <span class="arrow">&rarr;</span>
                </div>

                <div class="form-group" style="flex: 2;">
                    <label>2. Stacja (Postój):</label>
                    <select name="id_stacji" required>
                        <option value="">-- Wybierz --</option>
                        <?php foreach($stacje as $s) echo "<option value='{$s['id_stacji']}'>{$s['nazwa_stacji']}</option>"; ?>
                    </select>
                </div>
                
                <div class="form-group" style="flex: 0.2; text-align:center; padding-bottom: 8px;">
                    <span class="arrow">&rarr;</span>
                </div>

                <div class="form-group" style="flex: 2;">
                    <label>3. W kierunku (Następna):</label>
                    <select name="id_kierunku" required>
                        <option value="">-- Wybierz --</option>
                        <?php foreach($stacje as $s) echo "<option value='{$s['id_stacji']}'>{$s['nazwa_stacji']}</option>"; ?>
                    </select>
                </div>
                
                <div class="form-group" style="width: 70px;">
                    <label>Peron:</label>
                    <input type="text" name="peron" required>
                </div>
                <div class="form-group" style="width: 70px;">
                    <label>Tor:</label>
                    <input type="text" name="tor" required>
                </div>
                <div class="form-group" style="width: auto;">
                    <button type="submit">Dodaj</button>
                </div>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Z kierunku (Skąd)</th>
                    <th>Stacja (Gdzie)</th>
                    <th>W kierunku (Dokąd)</th>
                    <th>Peron</th>
                    <th>Tor</th>
                    <th style="width:50px; text-align:center;">Usuń</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // LEFT JOIN dla id_poprzedniej, bo może być NULL
                $sql = "SELECT dp.id, dp.peron, dp.tor, 
                               s1.nazwa_stacji as stacja, 
                               s2.nazwa_stacji as kierunek,
                               s3.nazwa_stacji as poprzednia
                        FROM domyslne_perony dp 
                        JOIN stacje s1 ON dp.id_stacji = s1.id_stacji 
                        JOIN stacje s2 ON dp.id_kierunku = s2.id_stacji
                        LEFT JOIN stacje s3 ON dp.id_poprzedniej = s3.id_stacji
                        ORDER BY s1.nazwa_stacji, s3.nazwa_stacji, s2.nazwa_stacji";
                $res = mysqli_query($conn, $sql);
                while($row = mysqli_fetch_assoc($res)):
                    $from = $row['poprzednia'] ? $row['poprzednia'] : '<span style="color:#aaa; font-style:italic;">(Dowolna)</span>';
                ?>
                <tr>
                    <td><?= $from ?> &rarr;</td>
                    <td><b><?= $row['stacja'] ?></b></td>
                    <td>&rarr; <?= $row['kierunek'] ?></td>
                    <td style="text-align:center; font-weight:bold;"><?= $row['peron'] ?></td>
                    <td style="text-align:center; font-weight:bold;"><?= $row['tor'] ?></td>
                    <td style="text-align:center;"><a href="?delete=<?= $row['id'] ?>" class="btn-del" onclick="return confirm('Usunąć tę regułę?')">X</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>