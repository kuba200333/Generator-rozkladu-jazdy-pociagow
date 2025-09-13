<?php
require 'db_config.php';
$id_stacji = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stacja = [
    'nazwa_stacji' => '',
    'typ_stacji_id' => '',
    'uwagi' => '',
    'linia_kolejowa' => ''
];

if ($id_stacji > 0) {
    // Tryb edycji - pobieramy dane stacji
    $stmt = mysqli_prepare($conn, "SELECT * FROM stacje WHERE id_stacji = ?");
    mysqli_stmt_bind_param($stmt, "i", $id_stacji);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stacja = mysqli_fetch_assoc($result);
}

// Pobieramy listę typów stacji do listy rozwijanej
$typy_stacji_res = mysqli_query($conn, "SELECT * FROM typy_stacji ORDER BY nazwa_typu_stacji");
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?= $id_stacji ? 'Edycja' : 'Dodawanie' ?> Stacji</title>
     </head>
<body>
    <div class="container" style="max-width: 600px;">
        <a href="zarzadzaj_stacjami.php">Powrót do listy stacji</a>
        <h1><?= $id_stacji ? 'Edycja Stacji' : 'Dodaj Nową Stację' ?></h1>
        
        <form action="zapisz_stacje.php" method="POST">
            <input type="hidden" name="id_stacji" value="<?= $id_stacji ?>">
            
            <p><label>Nazwa stacji: <br><input type="text" name="nazwa_stacji" value="<?= htmlspecialchars($stacja['nazwa_stacji']) ?>" required size="50"></label></p>
            
            <p><label>Typ stacji: <br>
                <select name="typ_stacji_id" required>
                    <option value="">-- wybierz typ --</option>
                    <?php while($typ = mysqli_fetch_assoc($typy_stacji_res)): 
                        $selected = ($typ['id_typu_stacji'] == $stacja['typ_stacji_id']) ? 'selected' : '';
                    ?>
                        <option value="<?= $typ['id_typu_stacji'] ?>" <?= $selected ?>><?= htmlspecialchars($typ['nazwa_typu_stacji']) ?></option>
                    <?php endwhile; ?>
                </select>
            </label></p>
            
            <p><label>Uwagi (np. R1 H PP): <br><input type="text" name="uwagi" value="<?= htmlspecialchars($stacja['uwagi']) ?>" size="50"></label></p>
            <p><label>Linia kolejowa: <br><input type="text" name="linia_kolejowa" value="<?= htmlspecialchars($stacja['linia_kolejowa']) ?>" size="50"></label></p>
            
            <button type="submit">Zapisz zmiany</button>
        </form>
    </div>
</body>
</html>