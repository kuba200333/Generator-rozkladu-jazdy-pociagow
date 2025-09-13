<?php
// api.php
header('Content-Type: application/json; charset=utf-8'); // Informujemy, że zwracamy dane JSON
require 'db_config.php';

// Sprawdzamy, czy podano ID przejazdu
if (!isset($_GET['id_przejazdu'])) {
    echo json_encode(['error' => 'Nie podano ID przejazdu']);
    exit();
}

$id_przejazdu = (int)$_GET['id_przejazdu'];
$schedule_data = [];

// Pobieramy szczegóły rozkładu dla danego przejazdu
$sql = "SELECT sr.przyjazd, sr.odjazd, s.nazwa_stacji, ts.skrot_typu_stacji, s.uwagi
        FROM szczegoly_rozkladu sr
        JOIN stacje s ON sr.id_stacji = s.id_stacji
        JOIN typy_stacji ts ON s.typ_stacji_id = ts.id_typu_stacji
        WHERE sr.id_przejazdu = ?
        ORDER BY sr.kolejnosc ASC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $id_przejazdu);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $schedule_data[] = $row; // Dodajemy każdy wiersz do tablicy
}

// Zwracamy całą tablicę jako odpowiedź JSON
echo json_encode($schedule_data);
?>