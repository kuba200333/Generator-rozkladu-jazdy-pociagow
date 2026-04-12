<?php
// ustaw_wyswietlacz.php
require 'db_config.php';

$id_szczegolu = $_POST['id_szczegolu'] ?? null;
$peron = $_POST['peron'] ?? null;
$tor = $_POST['tor'] ?? null;
$komunikat = $_POST['komunikat'] ?? null;
$akcja = $_POST['akcja'] ?? 'zapisz'; 

if ($peron && $tor) {
    // 1. Zdejmujemy flagę ze starego pociągu na tym peronie i torze
    $sql1 = "UPDATE szczegoly_rozkladu SET status_wyswietlacza = 0, komunikat_tablica = NULL WHERE peron = ? AND tor = ?";
    $stmt1 = mysqli_prepare($conn, $sql1);
    mysqli_stmt_bind_param($stmt1, "ss", $peron, $tor);
    mysqli_stmt_execute($stmt1);

    // 2. Jeśli akcja to zapis, przypinamy nowy pociąg (aktualizując od razu jego peron i tor, jeśli zmieniłeś je w okienku!)
    if ($akcja === 'zapisz' && $id_szczegolu) {
        $sql2 = "UPDATE szczegoly_rozkladu SET status_wyswietlacza = 1, komunikat_tablica = ?, peron = ?, tor = ? WHERE id_szczegolu = ?";
        $stmt2 = mysqli_prepare($conn, $sql2);
        mysqli_stmt_bind_param($stmt2, "sssi", $komunikat, $peron, $tor, $id_szczegolu);
        mysqli_stmt_execute($stmt2);
    }
    echo "OK";
} else {
    echo "Błąd: Brak numeru peronu lub toru w zapytaniu.";
}
?>