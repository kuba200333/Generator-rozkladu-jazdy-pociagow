<?php
require 'db_config.php';

$peron = $_POST['peron'] ?? null;
$tor = $_POST['tor'] ?? null;
$id_szczegolu = $_POST['id_szczegolu'] ?? null;
$komunikat = $_POST['komunikat'] ?? null;
$akcja = $_POST['akcja'] ?? 'zapisz';

if ($peron && $tor) {
    // 1. Zdejmujemy flagę wyświetlania ze wszystkich pociągów na tym peronie i torze
    $sql1 = "UPDATE szczegoly_rozkladu SET status_wyswietlacza = 0, komunikat_tablica = NULL WHERE peron = ? AND tor = ?";
    $stmt1 = mysqli_prepare($conn, $sql1);
    mysqli_stmt_bind_param($stmt1, "ss", $peron, $tor);
    mysqli_stmt_execute($stmt1);

    // 2. Jeśli nie wygaszamy, ustalamy nowy pociąg i wpisujemy komunikat
    if ($akcja === 'zapisz' && !empty($id_szczegolu)) {
        $sql2 = "UPDATE szczegoly_rozkladu SET status_wyswietlacza = 1, komunikat_tablica = ? WHERE id_szczegolu = ?";
        $stmt2 = mysqli_prepare($conn, $sql2);
        mysqli_stmt_bind_param($stmt2, "si", $komunikat, $id_szczegolu);
        mysqli_stmt_execute($stmt2);
    }
}

// Po wszystkim wracamy do panelu sterowania
header("Location: panel_tablic.php");
exit;
?>