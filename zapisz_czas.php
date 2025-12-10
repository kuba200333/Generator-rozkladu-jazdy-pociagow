<?php
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id_szczegolu'];
    
    // Dodajemy sekundy :00 jeśli input time przysłał HH:MM
    $prz = !empty($_POST['przyjazd_rzecz']) ? $_POST['przyjazd_rzecz'] . ":00" : null;
    $odj = !empty($_POST['odjazd_rzecz']) ? $_POST['odjazd_rzecz'] . ":00" : null;

    // DODANO: zatwierdzony = 1
    $stmt = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, odjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $prz, $odj, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo "OK";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}
?>