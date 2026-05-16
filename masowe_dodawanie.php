<?php
session_start();
require 'db_config.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_przed = (int)$_POST['id_przed'];
    $id_nowa = (int)$_POST['id_nowa'];
    $id_po = (int)$_POST['id_po'];
    
    $czas_przed = $_POST['czas_przed'];
    $vmax_przed = (int)$_POST['vmax_przed'];
    $czas_po = $_POST['czas_po'];
    $vmax_po = (int)$_POST['vmax_po'];

    // 1. Zapisujemy parametry odcinków (zawsze)
    mysqli_query($conn, "INSERT INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES ($id_przed, $id_nowa, '$czas_przed', $vmax_przed) ON DUPLICATE KEY UPDATE czas_przejazdu='$czas_przed', predkosc_max=$vmax_przed");
    mysqli_query($conn, "INSERT INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES ($id_nowa, $id_po, '$czas_po', $vmax_po) ON DUPLICATE KEY UPDATE czas_przejazdu='$czas_po', predkosc_max=$vmax_po");

    $zaktualizowano = 0;
    
    // 2. Pobieramy wszystkie id tras, żeby sprawdzić je jedna po drugiej
    $res = mysqli_query($conn, "SELECT id_trasy FROM trasy");
    while ($row = mysqli_fetch_assoc($res)) {
        $id_t = $row['id_trasy'];
        
        // Pobieramy stacje na tej trasie, posortowane według kolejności
        $q_st = mysqli_query($conn, "SELECT id_stacji, kolejnosc FROM stacje_na_trasie WHERE id_trasy = $id_t ORDER BY CAST(kolejnosc AS SIGNED) ASC");
        $stacje = [];
        while ($s = mysqli_fetch_assoc($q_st)) {
            $stacje[] = $s;
        }
        
        // Szukamy, czy stacja PRZED i stacja PO występują BEZPOŚREDNIO po sobie
        $found = false;
        $kol_po = 0;
        for ($i = 0; $i < count($stacje) - 1; $i++) {
            if ($stacje[$i]['id_stacji'] == $id_przed && $stacje[$i+1]['id_stacji'] == $id_po) {
                $found = true;
                $kol_po = $stacje[$i+1]['kolejnosc'];
                break;
            }
        }
        
        if ($found) {
            // Przesuwamy w dół (kolejnosc + 1) wszystko od stacji PO do samego końca
            mysqli_query($conn, "UPDATE stacje_na_trasie SET kolejnosc = kolejnosc + 1 WHERE id_trasy = $id_t AND kolejnosc >= $kol_po");
            
            // Wstawiamy nową stację na zwolnione miejsce
            mysqli_query($conn, "INSERT INTO stacje_na_trasie (id_trasy, id_stacji, kolejnosc) VALUES ($id_t, $id_nowa, $kol_po)");
            $zaktualizowano++;
        }
    }

    $msg = "Zapisano parametry odcinków. Wciśnięto nową stację do <b>$zaktualizowano tras</b>.";
}

$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
$stacje = [];
while ($r = mysqli_fetch_assoc($stacje_res)) {
    $stacje[] = $r;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Wciskacz Stacji</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; background-color: #f4f6f9; color: #333; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h1 { color: #004080; margin-top: 0; }
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .form-group { flex: 1; display: flex; flex-direction: column; }
        label { font-weight: bold; margin-bottom: 5px; font-size: 14px; }
        select, input { padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; }
        .highlight { border-color: #28a745; box-shadow: 0 0 5px rgba(40,167,69,0.3); }
        .section { background: #f8f9fa; padding: 20px; border: 1px solid #eee; border-radius: 6px; margin-bottom: 20px; }
        .btn { background: #007bff; color: white; border: none; padding: 15px; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; width: 100%; transition: 0.2s; }
        .btn:hover { background: #0056b3; }
        .alert { padding: 15px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px; }
        a { color: #007bff; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php">⬅ Powrót do menu</a>
    <h1>Wciskacz Stacji</h1>
    <p>Narzędzie dodaje nową stację do istniejących szablonów tras i zapisuje parametry odcinków w bazie.</p>

    <?php if ($msg): ?>
        <div class="alert"><?= $msg ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label>1. Stacja PRZED</label>
                <select name="id_przed" required>
                    <option value="">Wybierz...</option>
                    <?php foreach ($stacje as $s): ?>
                        <option value="<?= $s['id_stacji'] ?>"><?= htmlspecialchars($s['nazwa_stacji']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label style="color: #28a745;">2. NOWA STACJA</label>
                <select name="id_nowa" class="highlight" required>
                    <option value="">Wybierz...</option>
                    <?php foreach ($stacje as $s): ?>
                        <option value="<?= $s['id_stacji'] ?>"><?= htmlspecialchars($s['nazwa_stacji']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>3. Stacja PO</label>
                <select name="id_po" required>
                    <option value="">Wybierz...</option>
                    <?php foreach ($stacje as $s): ?>
                        <option value="<?= $s['id_stacji'] ?>"><?= htmlspecialchars($s['nazwa_stacji']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="section">
            <h4 style="margin-top:0;">Odcinek: Stacja PRZED do NOWA STACJA</h4>
            <div class="form-row" style="margin-bottom:0;">
                <div class="form-group">
                    <label>Czas przejazdu (np. 00:03:00)</label>
                    <input type="time" name="czas_przed" step="1" value="00:01:00" required>
                </div>
                <div class="form-group">
                    <label>Prędkość max (Vmax)</label>
                    <input type="number" name="vmax_przed" value="100" required>
                </div>
            </div>
        </div>

        <div class="section">
            <h4 style="margin-top:0;">Odcinek: NOWA STACJA do Stacja PO</h4>
            <div class="form-row" style="margin-bottom:0;">
                <div class="form-group">
                    <label>Czas przejazdu (np. 00:04:00)</label>
                    <input type="time" name="czas_po" step="1" value="00:01:00" required>
                </div>
                <div class="form-group">
                    <label>Prędkość max (Vmax)</label>
                    <input type="number" name="vmax_po" value="100" required>
                </div>
            </div>
        </div>

        <button type="submit" class="btn">Zapisz stację i odcinki</button>
    </form>
</div>

</body>
</html>