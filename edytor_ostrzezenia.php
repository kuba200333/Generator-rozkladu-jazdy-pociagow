<?php
require 'db_config.php';
$id_ostrzezenia = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ostrzezenie = [
    'data_waznosci_od' => date('Y-m-d'), 'do_odwolania' => 0, 'data_waznosci_do' => '',
    'miejsce_opis' => '', 'nr_toru' => '', 'km_poczatek' => '', 'km_koniec' => '',
    'predkosc_max' => '', 'powod' => '', 'uwagi' => ''
];

if ($id_ostrzezenia > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM ostrzezenia WHERE id_ostrzezenia = ?");
    mysqli_stmt_bind_param($stmt, "i", $id_ostrzezenia);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ostrzezenie = mysqli_fetch_assoc($result);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?= $id_ostrzezenia ? 'Edycja' : 'Dodawanie' ?> Ostrzeżenia</title>
</head>
<body>
    <a href="zarzadzaj_ostrzezeniami.php">Powrót do listy ostrzeżeń</a>
    <h1><?= $id_ostrzezenia ? 'Edycja' : 'Dodaj' ?> Ostrzeżenia</h1>
    
    <form action="zapisz_ostrzezenie.php" method="POST">
        <input type="hidden" name="id_ostrzezenia" value="<?= $id_ostrzezenia ?>">
        
        <p><label>Ważny od: <input type="date" name="data_waznosci_od" value="<?= $ostrzezenie['data_waznosci_od'] ?>" required></label></p>
        <p><label><input type="checkbox" name="do_odwolania" value="1" <?= $ostrzezenie['do_odwolania'] ? 'checked' : '' ?>> Do odwołania</label></p>
        <p><label>Ważny do: <input type="date" name="data_waznosci_do" value="<?= $ostrzezenie['data_waznosci_do'] ?>"></label></p>
        <p><label>Miejsce (szlak, stacja, linia): <input type="text" name="miejsce_opis" value="<?= htmlspecialchars($ostrzezenie['miejsce_opis']) ?>" required></label></p>
        <p><label>Nr toru: <input type="text" name="nr_toru" value="<?= htmlspecialchars($ostrzezenie['nr_toru']) ?>"></label></p>
        <p><label>Od km: <input type="text" name="km_poczatek" value="<?= htmlspecialchars($ostrzezenie['km_poczatek']) ?>"></label></p>
        <p><label>Do km: <input type="text" name="km_koniec" value="<?= htmlspecialchars($ostrzezenie['km_koniec']) ?>"></label></p>
        <p><label>Ograniczenie prędkości do (km/h): <input type="number" name="predkosc_max" value="<?= $ostrzezenie['predkosc_max'] ?>" required></label></p>
        <p><label>Powód: <textarea name="powod"><?= htmlspecialchars($ostrzezenie['powod']) ?></textarea></label></p>
        <p><label>Uwagi: <textarea name="uwagi"><?= htmlspecialchars($ostrzezenie['uwagi']) ?></textarea></label></p>
        
        <button type="submit">Zapisz ostrzeżenie</button>
    </form>
</body>
</html>