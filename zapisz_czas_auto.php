<?php
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_szczegolu = (int)$_POST['id_szczegolu'];
    $typ = $_POST['typ']; // 'przyjazd' lub 'odjazd'
    
    $timestamp = time();
    
    if ($typ == 'przyjazd') {
        $timestamp += 15;
        // DODANO: zatwierdzony = 1
        $sql = "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?";
    } elseif ($typ == 'odjazd') {
        $timestamp -= 15;
        // DODANO: zatwierdzony = 1
        $sql = "UPDATE szczegoly_rozkladu SET odjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?";
    } else {
        die('Błędny typ');
    }
    
    $aktualny_czas = date('H:i:s', $timestamp);
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $aktualny_czas, $id_szczegolu);
    mysqli_stmt_execute($stmt);
    echo "Zapisano " . $typ . ": " . $aktualny_czas;
}
?>