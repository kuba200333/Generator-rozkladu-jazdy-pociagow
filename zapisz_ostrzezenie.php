<?php
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_ostrzezenia = (int)$_POST['id_ostrzezenia'];
    
    $data_waznosci_od = $_POST['data_waznosci_od'];
    $do_odwolania = isset($_POST['do_odwolania']) ? 1 : 0;
    $data_waznosci_do = $do_odwolania ? NULL : $_POST['data_waznosci_do'];
    $miejsce_opis = $_POST['miejsce_opis'];
    $nr_toru = $_POST['nr_toru'];
    $km_poczatek = $_POST['km_poczatek'];
    $km_koniec = $_POST['km_koniec'];
    $predkosc_max = (int)$_POST['predkosc_max'];
    $powod = $_POST['powod'];
    $uwagi = $_POST['uwagi'];
    
    if ($id_ostrzezenia > 0) {
        $sql = "UPDATE ostrzezenia SET data_waznosci_od=?, do_odwolania=?, data_waznosci_do=?, miejsce_opis=?, nr_toru=?, km_poczatek=?, km_koniec=?, predkosc_max=?, powod=?, uwagi=? WHERE id_ostrzezenia=?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sisssssissi", $data_waznosci_od, $do_odwolania, $data_waznosci_do, $miejsce_opis, $nr_toru, $km_poczatek, $km_koniec, $predkosc_max, $powod, $uwagi, $id_ostrzezenia);
    } else {
        $sql = "INSERT INTO ostrzezenia (data_waznosci_od, do_odwolania, data_waznosci_do, miejsce_opis, nr_toru, km_poczatek, km_koniec, predkosc_max, powod, uwagi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sisssssiss", $data_waznosci_od, $do_odwolania, $data_waznosci_do, $miejsce_opis, $nr_toru, $km_poczatek, $km_koniec, $predkosc_max, $powod, $uwagi);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        header("Location: zarzadzaj_ostrzezeniami.php?msg=Ostrzeżenie zapisane pomyślnie.");
    } else {
        echo "Błąd zapisu: " . mysqli_error($conn);
    }
    exit();
}
?>