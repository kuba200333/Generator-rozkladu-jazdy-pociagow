<?php
require 'db_config.php';

// Obsługa aktualizacji
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_odcinka'])) {
    $id_odcinka = $_POST['id_odcinka'];
    $czas_przejazdu = $_POST['czas_przejazdu'];
    $predkosc_max = $_POST['predkosc_max'];

    $stmt = mysqli_prepare($conn, "UPDATE odcinki SET czas_przejazdu = ?, predkosc_max = ? WHERE id_odcinka = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $czas_przejazdu, $predkosc_max, $id_odcinka);
    mysqli_stmt_execute($stmt);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzaj Odcinkami</title>
     <style>
        body { font-family: sans-serif; padding: 10px; }
        table{ border-collapse: collapse; width: 100%; max-width: 900px; margin-top: 20px; }
        td, th{ border: 1px solid black; padding: 8px; }
        th { background-color: #e9e9e9; }
        input { width: 90%; }
        a { color: #007bff; }
    </style>
</head>
<body>
    <a href="index.php">Powrót do menu</a>
    <h1>Zarządzanie Odcinkami</h1>
    <p>W tym miejscu możesz edytować domyślne czasy przejazdu i prędkości między sąsiadującymi stacjami.</p>
    <table>
        <tr>
            <th>Stacja A</th>
            <th>Stacja B</th>
            <th>Czas przejazdu</th>
            <th>Vmax</th>
            <th>Akcja</th>
        </tr>
        <?php
        $query = "SELECT o.id_odcinka, sA.nazwa_stacji AS stacja_a, sB.nazwa_stacji AS stacja_b, o.czas_przejazdu, o.predkosc_max 
                  FROM odcinki o
                  JOIN stacje sA ON o.id_stacji_A = sA.id_stacji
                  JOIN stacje sB ON o.id_stacji_B = sB.id_stacji
                  ORDER BY stacja_a, stacja_b";
        $result = mysqli_query($conn, $query);
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<form method='POST' action=''><tr>";
            echo "<input type='hidden' name='id_odcinka' value='{$row['id_odcinka']}'>";
            echo "<td>" . htmlspecialchars($row['stacja_a']) . "</td>";
            echo "<td>" . htmlspecialchars($row['stacja_b']) . "</td>";
            echo "<td><input type='time' name='czas_przejazdu' value='{$row['czas_przejazdu']}' step='1'></td>";
            echo "<td><input type='text' name='predkosc_max' value='" . htmlspecialchars($row['predkosc_max']) . "'></td>";
            echo "<td><button type='submit'>Zapisz</button></td>";
            echo "</tr></form>";
        }
        ?>
    </table>
</body>
</html>