<?php
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_stacji = (int)$_POST['id_stacji'];
    $nazwa_stacji = $_POST['nazwa_stacji'];
    $typ_stacji_id = (int)$_POST['typ_stacji_id'];
    $uwagi = $_POST['uwagi'];
    $linia_kolejowa = $_POST['linia_kolejowa'];

    if (empty($nazwa_stacji) || empty($typ_stacji_id)) {
        die("Błąd: Nazwa i typ stacji są polami wymaganymi.");
    }

    if ($id_stacji > 0) {
        // Aktualizujemy istniejącą stację
        $sql = "UPDATE stacje SET nazwa_stacji = ?, typ_stacji_id = ?, uwagi = ?, linia_kolejowa = ? WHERE id_stacji = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sissi", $nazwa_stacji, $typ_stacji_id, $uwagi, $linia_kolejowa, $id_stacji);
    } else {
        // Dodajemy nową stację
        $sql = "INSERT INTO stacje (nazwa_stacji, typ_stacji_id, uwagi, linia_kolejowa) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "siss", $nazwa_stacji, $typ_stacji_id, $uwagi, $linia_kolejowa);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        header("Location: zarzadzaj_stacjami.php?msg=Stacja zapisana pomyślnie.");
    } else {
        echo "Błąd zapisu: " . mysqli_error($conn);
    }
    exit();
}
?>