<?php
require 'db_config.php';

$message = '';

// Funkcja pomocnicza do formatowania czasu (dodaje sekundy, jeśli podano tylko HH:MM)
function formatTime($timeStr) {
    $parts = explode(':', $timeStr);
    if (count($parts) == 2) return $timeStr . ':00';
    return $timeStr;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'wcisnij_stacje') {
    $stacja_przed = (int)$_POST['stacja_przed'];
    $stacja_nowa = (int)$_POST['stacja_nowa'];
    $stacja_po = (int)$_POST['stacja_po'];
    
    // Parametry dla nowych odcinków
    $czas_A_N = formatTime($_POST['czas_A_N']);
    $vmax_A_N = mysqli_real_escape_string($conn, $_POST['vmax_A_N']);
    
    $czas_N_B = formatTime($_POST['czas_N_B']);
    $vmax_N_B = mysqli_real_escape_string($conn, $_POST['vmax_N_B']);

    if ($stacja_przed == $stacja_nowa || $stacja_po == $stacja_nowa || $stacja_przed == $stacja_po) {
        $message = "Błąd: Wybrano te same stacje!";
    } else {
        mysqli_begin_transaction($conn);
        try {
            // ==========================================
            // KROK 1: AKTUALIZACJA SZABLONÓW TRAS (stacje_na_trasie)
            // ==========================================
            $sql_trasy = "SELECT t1.id_trasy, t1.kolejnosc as kol_A 
                          FROM stacje_na_trasie t1
                          JOIN stacje_na_trasie t2 ON t1.id_trasy = t2.id_trasy 
                          WHERE t1.id_stacji = $stacja_przed 
                          AND t2.id_stacji = $stacja_po 
                          AND t2.kolejnosc = t1.kolejnosc + 1";
            
            $res_trasy = mysqli_query($conn, $sql_trasy);
            $zaktualizowano_tras = 0;

            while ($row_trasa = mysqli_fetch_assoc($res_trasy)) {
                $id_trasy = $row_trasa['id_trasy'];
                $kol_A = $row_trasa['kol_A'];
                $kol_nowa = $kol_A + 1;

                // Przesuwamy kolejne stacje w dół, żeby zrobić miejsce
                mysqli_query($conn, "UPDATE stacje_na_trasie SET kolejnosc = kolejnosc + 1 WHERE id_trasy = $id_trasy AND kolejnosc > $kol_A");
                
                // Wstawiamy nową stację do szablonu trasy
                mysqli_query($conn, "INSERT INTO stacje_na_trasie (id_trasy, id_stacji, kolejnosc) VALUES ($id_trasy, $stacja_nowa, $kol_nowa)");
                
                $zaktualizowano_tras++;
            }

            // ==========================================
            // KROK 2: DODANIE NOWYCH ODCINKÓW DO BAZY (tabela odcinki)
            // ==========================================
            // Odcinek: Stacja A -> Nowa Stacja (oraz powrót)
            mysqli_query($conn, "INSERT INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES ($stacja_przed, $stacja_nowa, '$czas_A_N', '$vmax_A_N') ON DUPLICATE KEY UPDATE czas_przejazdu = '$czas_A_N', predkosc_max = '$vmax_A_N'");
            mysqli_query($conn, "INSERT INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES ($stacja_nowa, $stacja_przed, '$czas_A_N', '$vmax_A_N') ON DUPLICATE KEY UPDATE czas_przejazdu = '$czas_A_N', predkosc_max = '$vmax_A_N'");

            // Odcinek: Nowa Stacja -> Stacja B (oraz powrót)
            mysqli_query($conn, "INSERT INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES ($stacja_nowa, $stacja_po, '$czas_N_B', '$vmax_N_B') ON DUPLICATE KEY UPDATE czas_przejazdu = '$czas_N_B', predkosc_max = '$vmax_N_B'");
            mysqli_query($conn, "INSERT INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES ($stacja_po, $stacja_nowa, '$czas_N_B', '$vmax_N_B') ON DUPLICATE KEY UPDATE czas_przejazdu = '$czas_N_B', predkosc_max = '$vmax_N_B'");

            mysqli_commit($conn);
            $message = "Gotowe! Zaktualizowano szablony tras ($zaktualizowano_tras) i dodano czasy dla nowych odcinków.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Błąd podczas aktualizacji bazy: " . $e->getMessage();
        }
    }
}

$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
$wszystkie_stacje = mysqli_fetch_all($stacje_res, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Masowe dodawanie stacji</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f4f4f4; }
        .container { max-width: 700px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        select, input { width: 100%; padding: 8px; box-sizing: border-box; }
        button { background: #007bff; color: white; border: none; padding: 12px 15px; cursor: pointer; border-radius: 4px; font-size: 16px; margin-top: 20px; width: 100%; font-weight: bold; }
        button:hover { background: #0056b3; }
        .alert { padding: 15px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px; border-left: 5px solid #28a745; }
        .box { background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 15px; }
        .row { display: flex; gap: 15px; }
        .col { flex: 1; }
    </style>
</head>
<body>

<div class="container">
    <a href="kreator_tras.php" style="text-decoration: none; color: #007bff;">Powrót do menu</a>
    <h2 style="margin-top: 10px;">Wciskacz Stacji</h2>
    <p>Narzędzie dodaje nową stację do istniejących szablonów tras i zapisuje parametry odległości w bazie odcinków.</p>

    <?php if ($message): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" onsubmit="return confirm('Czy zaktualizować szablony tras i czasy odcinków?');">
        <input type="hidden" name="action" value="wcisnij_stacje">

        <div class="row">
            <div class="col form-group">
                <label>1. Stacja PRZED</label>
                <select name="stacja_przed" required>
                    <option value="">Wybierz...</option>
                    <?php foreach($wszystkie_stacje as $s) echo "<option value='{$s['id_stacji']}'>{$s['nazwa_stacji']}</option>"; ?>
                </select>
            </div>
            <div class="col form-group">
                <label>2. NOWA STACJA</label>
                <select name="stacja_nowa" required style="border: 2px solid #28a745;">
                    <option value="">Wybierz...</option>
                    <?php foreach($wszystkie_stacje as $s) echo "<option value='{$s['id_stacji']}'>{$s['nazwa_stacji']}</option>"; ?>
                </select>
            </div>
            <div class="col form-group">
                <label>3. Stacja PO</label>
                <select name="stacja_po" required>
                    <option value="">Wybierz...</option>
                    <?php foreach($wszystkie_stacje as $s) echo "<option value='{$s['id_stacji']}'>{$s['nazwa_stacji']}</option>"; ?>
                </select>
            </div>
        </div>

        <div class="box">
            <h4>Odcinek: Stacja PRZED do NOWA STACJA</h4>
            <div class="row">
                <div class="col form-group">
                    <label>Czas przejazdu (np. 00:03:00)</label>
                    <input type="time" name="czas_A_N" step="1" required>
                </div>
                <div class="col form-group">
                    <label>Prędkość max (Vmax)</label>
                    <input type="text" name="vmax_A_N" placeholder="np. 120" required>
                </div>
            </div>
        </div>

        <div class="box">
            <h4>Odcinek: NOWA STACJA do Stacja PO</h4>
            <div class="row">
                <div class="col form-group">
                    <label>Czas przejazdu (np. 00:04:00)</label>
                    <input type="time" name="czas_N_B" step="1" required>
                </div>
                <div class="col form-group">
                    <label>Prędkość max (Vmax)</label>
                    <input type="text" name="vmax_N_B" placeholder="np. 120" required>
                </div>
            </div>
        </div>

        <button type="submit">Zapisz stację i odcinki</button>
    </form>
</div>

</body>
</html>