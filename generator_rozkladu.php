<?php
session_start();
require 'db_config.php';

// Definicja dostępnych symboli (piktogramów)
$available_symbols = [
    'klasa_1' => '1 klasa', 'klasa_2' => '2 klasa', 'rower' => 'Przewóz rowerów', 'rezerwacja' => 'Rezerwacja obowiązkowa',
    'wozek_rampa' => 'Dla os. na wózkach (z rampą)', 'wozek_bez_rampy' => 'Dla os. na wózkach (bez rampy)', 'kuszetka' => 'Kuszetka',
    'sypialny' => 'Wagon sypialny', 'bar' => 'Wagon barowy / mini-bar', 'restauracyjny' => 'Wagon restauracyjny',
    'automat' => 'Automat z przekąskami', 'wifi' => 'Dostęp do WiFi', 'klima' => 'Klimatyzacja',
    'przewijak' => 'Miejsce do przewijania dziecka', 'duzy_bagaz' => 'Miejsce na duży bagaż'
];

// --- LOGIKA RESETOWANIA SESJI PRZY ZMIANIE TRASY ---
$czy_zmiana_trasy = false;

// Pobieramy ID z POST (jeśli wysłano) lub z SESJI
$nowe_id_trasy = $_POST['id_trasy'] ?? null;
$stare_id_trasy = $_SESSION['id_trasy'] ?? null;

// Sprawdzamy, czy nastąpiła zmiana trasy w formularzu
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_trasy'])) {
    if ($nowe_id_trasy != $stare_id_trasy) {
        $czy_zmiana_trasy = true;
        
        // KOMPLETNE CZYSZCZENIE SESJI ("DO GOLASA")
        unset($_SESSION['postoje']);
        unset($_SESSION['czas_odjazdu']);
        unset($_SESSION['nr_poc']);
        unset($_SESSION['id_typu_pociagu']);
        unset($_SESSION['nazwa_pociagu']);
        unset($_SESSION['daty_kursowania']);
        unset($_SESSION['dni_kursowania']);
        unset($_SESSION['symbole']);
        
        // Ustawiamy nowe ID trasy
        $_SESSION['id_trasy'] = $nowe_id_trasy;
    }
}

// Ustawiamy aktualne ID trasy do użycia w reszcie skryptu
$id_trasy = $_SESSION['id_trasy'] ?? null;


// --- OBSŁUGA FORMULARZA ---
// Wykonujemy zapamiętywanie danych TYLKO jeśli NIE zmieniamy trasy.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$czy_zmiana_trasy) {
    
    $_SESSION['nr_poc'] = $_POST['nr_poc'] ?? ($_SESSION['nr_poc'] ?? '');
    $_SESSION['id_typu_pociagu'] = $_POST['id_typu_pociagu'] ?? ($_SESSION['id_typu_pociagu'] ?? null);
    $_SESSION['nazwa_pociagu'] = $_POST['nazwa_pociagu'] ?? ($_SESSION['nazwa_pociagu'] ?? '');
    $_SESSION['daty_kursowania'] = $_POST['daty_kursowania'] ?? ($_SESSION['daty_kursowania'] ?? '');
    $_SESSION['dni_kursowania'] = $_POST['dni_kursowania'] ?? ($_SESSION['dni_kursowania'] ?? '');
    $_SESSION['symbole'] = $_POST['symbole'] ?? [];
    $_SESSION['czas_odjazdu'] = $_POST['czas'] ?? ($_SESSION['czas_odjazdu'] ?? '');

    // Aktualizacja postojów
    if (isset($_POST['postoje'])) {
        foreach ($_POST['postoje'] as $index => $dane) {
            if (!isset($_SESSION['postoje'][$index])) {
                $_SESSION['postoje'][$index] = [];
            }
            $_SESSION['postoje'][$index] = array_merge($_SESSION['postoje'][$index], $dane);
        }
    }
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'generuj':
                // Odświeżenie widoku z zachowaniem danych sesji
                break;
            
            case 'set_all':
                $stacje_list_for_action = [];
                if ($id_trasy) {
                    $stmt_stacje = mysqli_prepare($conn, "SELECT s.id_stacji, ts.id_typu_stacji FROM stacje_na_trasie snt JOIN stacje s ON snt.id_stacji = s.id_stacji JOIN typy_stacji ts ON s.typ_stacji_id = ts.id_typu_stacji WHERE snt.id_trasy = ? ORDER BY snt.kolejnosc");
                    mysqli_stmt_bind_param($stmt_stacje, "i", $id_trasy);
                    mysqli_stmt_execute($stmt_stacje);
                    $result_snt = mysqli_stmt_get_result($stmt_stacje);
                    $stacje_list_for_action = mysqli_fetch_all($result_snt, MYSQLI_ASSOC);
                }
                
                $global_stop_type = $_POST['global_stop_type'];
                $global_stop_time = $_POST['global_stop_time'];

                foreach ($stacje_list_for_action as $index => $stacja) {
                    $is_eligible = !($stacja['id_typu_stacji'] >= 3 || $index == 0 || $index == count($stacje_list_for_action) - 1);
                    if ($is_eligible) {
                        $_SESSION['postoje'][$index]['typ'] = $global_stop_type;
                        $_SESSION['postoje'][$index]['czas'] = $global_stop_time;
                    }
                }
                break;
        }
    }
}

// Obsługa komunikatu po zapisie
$status_msg = '';
if (isset($_GET['status'])) {
    $status_class = $_GET['status'] == 'success' ? 'status-success' : 'status-error';
    $status_msg = "<div class='{$status_class}'>" . htmlspecialchars($_GET['msg']) . "</div>";
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zaawansowany Generator Rozkładu</title>
    <style>
        body{ font-family: sans-serif; padding: 10px; font-size: 15px; }
        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-items: start;}
        table{ border-collapse: collapse; width: 100%; margin-top: 20px; }
        td, th{ border: 1px solid black; padding: 4px; text-align: left;}
        th { background-color: #e9e9e9; text-align: center; }
        .form-container { background-color: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; max-width: 1200px; }
        .form-section { margin-bottom: 10px; }
        .form-section label { display: block; font-weight: bold; margin-bottom: 5px;}
        input, select, button { padding: 5px; margin: 0 5px 0 0; vertical-align: middle; box-sizing: border-box;}
        input[type="text"], input[type="number"], select { width: 100%; }
        .set-all-container { border-top: 1px solid #ccc; margin-top: 15px; padding-top: 15px; }
        #postoj_select, #postoj_input { border: none; background: transparent; text-align: center; font-weight: bold; width: 100px; padding: 0;}
        input.platform-track { width: 100%; text-align: center; border:none; background:transparent; }
        .godzina-cell { text-align: center; white-space: pre; font-weight: bold; }
        .button { background-color: #28a745; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 1em; }
        .button:hover { background-color: #218838; }
        .action-button { background-color: #007bff; }
        .action-button:hover { background-color: #0056b3; }
        a { color: #007bff; }
        .symbols-container { border: 1px solid #ccc; padding: 10px; margin-top: 10px; background: #fff; }
        .symbols-container label { display: inline-block; margin-right: 15px; font-weight: normal;}
        .status-success { padding: 10px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; margin-bottom: 15px; }
        .status-error { padding: 10px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <a href="index.php">Powrót do menu</a><br><br>

    <?= $status_msg ?>

    <form action="generator_rozkladu.php" method="POST">
        <div class="form-container">
            <div class="grid-container">
                 <div class="form-section">
                    <label>Trasa:</label>
                    <select name="id_trasy" onchange="this.form.submit()">
                        <option value="">-- Wybierz --</option>
                        <?php
                        $query_trasy = "SELECT id_trasy, nazwa_trasy FROM trasy ORDER BY nazwa_trasy";
                        $result_trasy = mysqli_query($conn, $query_trasy);
                        while ($row = mysqli_fetch_assoc($result_trasy)) {
                            $selected = ($row['id_trasy'] == $id_trasy) ? "selected" : "";
                            echo "<option value='{$row['id_trasy']}' $selected>{$row['nazwa_trasy']}</option>";
                        }
                        ?>
                    </select>
                </div>
                 <div class="form-section">
                    <label>Godzina odjazdu:</label>
                    <input type="time" name="czas" value="<?= @$_SESSION['czas_odjazdu'] ?>" step="1">
                </div>
                <div class="form-section">
                    <label>Kategoria / Pociąg:</label>
                    <select name="id_typu_pociagu" required>
                        <option value="">-- Wybierz rodzaj pociągu --</option>
                        <?php
                        $query_typy = "SELECT t.id_typu, t.skrot, t.pelna_nazwa, p.skrot as przewoznik 
                                       FROM typy_pociagow t 
                                       JOIN przewoznicy p ON t.id_przewoznika = p.id_przewoznika 
                                       ORDER BY p.skrot, t.skrot";
                        $result_typy = mysqli_query($conn, $query_typy);
                        while ($row = mysqli_fetch_assoc($result_typy)) {
                            $selected = ($row['id_typu'] == @$_SESSION['id_typu_pociagu']) ? "selected" : "";
                            echo "<option value='{$row['id_typu']}' {$selected}>{$row['przewoznik']}-{$row['skrot']} ({$row['pelna_nazwa']})</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-section">
                    <label>Numer pociągu:</label>
                    <input type="number" name="nr_poc" value="<?= @$_SESSION['nr_poc'] ?>" required>
                </div>
                 <div class="form-section">
                    <label>Nazwa pociągu (np. ORKAN):</label>
                    <input type="text" name="nazwa_pociagu" value="<?= @$_SESSION['nazwa_pociagu'] ?>">
                </div>
                <div class="form-section">
                    <label>Daty obowiązywania (np. 15 VI – 30 VIII 2025):</label>
                    <input type="text" name="daty_kursowania" value="<?= @$_SESSION['daty_kursowania'] ?>">
                </div>
                 <div class="form-section" style="grid-column: span 2;">
                    <label>Dni/Uwagi do kursowania (np. 28 VI-30 VIII ⑥⑦ + 15 VIII.):</label>
                    <input type="text" name="dni_kursowania" value="<?= @$_SESSION['dni_kursowania'] ?>" style="width: 100%;">
                </div>
                <div class="form-section symbols-container" style="grid-column: span 2;">
                    <label>Piktogramy/Symbole:</label><br>
                    <?php
                        foreach ($available_symbols as $key => $label) {
                            $checked = (isset($_SESSION['symbole']) && in_array($key, $_SESSION['symbole'])) ? "checked" : "";
                            echo "<label><input type='checkbox' name='symbole[]' value='{$key}' {$checked}> {$label}</label>";
                        }
                    ?>
                </div>
            </div>
            <br>
            <button type="submit" name="action" value="generuj" class="button action-button">Generuj / Odśwież</button>

            <div class="set-all-container">
                <strong>Ustaw dla wszystkich:</strong>
                Typ postoju:
                <select name="global_stop_type">
                    <option value="">- (Przelot)</option>
                    <?php
                        $postoje_res = mysqli_query($conn, "SELECT typ_postoj FROM postoje");
                        while($p_row = mysqli_fetch_assoc($postoje_res)) {
                            echo "<option value='{$p_row['typ_postoj']}'>{$p_row['typ_postoj']}</option>";
                        }
                    ?>
                </select>
                Czas postoju:
                <input type="time" name="global_stop_time" value="00:01:00" step="30">
                <button type="submit" name="action" value="set_all" class="button action-button">Zastosuj</button>
            </div>
        </div>
        
        <?php if ($id_trasy): ?>
        <table>
            <tr>
                <th style="width:3%">Lp.</th>
                <th>Stacja</th>
                <th style="width:8%">Vmax</th>
                <th style="width:10%">Godzina</th>
                <th style="width:5%">Peron</th>
                <th style="width:5%">Tor</th>
                <th style="width:8%">Typ post.</th>
                <th style="width:10%">Czas post.</th>
            </tr>
            <?php
            $full_stacje_list_res = mysqli_query($conn, "SELECT snt.kolejnosc, s.*, ts.skrot_typu_stacji, ts.id_typu_stacji FROM stacje_na_trasie snt JOIN stacje s ON snt.id_stacji = s.id_stacji JOIN typy_stacji ts ON s.typ_stacji_id = ts.id_typu_stacji WHERE snt.id_trasy = $id_trasy ORDER BY snt.kolejnosc");
            $full_stacje_list = mysqli_fetch_all($full_stacje_list_res, MYSQLI_ASSOC);
            $liczba_stacji = count($full_stacje_list);

            $godzina_biezaca = isset($_SESSION['czas_odjazdu']) && !empty($_SESSION['czas_odjazdu']) ? strtotime($_SESSION['czas_odjazdu']) : strtotime("00:00");
            $czas_przejazdu_poprzedni = 0;
            $poprzednia_stacja_przelot = false;

            foreach ($full_stacje_list as $index => $stacja) {
                $id_stacji_biezacej = $stacja['id_stacji'];

                // --- LOGIKA OBLICZANIA CZASU ---
                $czas_do_dodania = $czas_przejazdu_poprzedni;
                if ($poprzednia_stacja_przelot && $index > 0 && $czas_przejazdu_poprzedni > 0) { 
                    $czas_do_dodania -=30;
                }
                $godzina_biezaca += $czas_do_dodania;
                
                $przyjazd_ts = $godzina_biezaca;

                // Odczyt danych postoju z sesji
                $postoj_data = $_SESSION['postoje'][$index] ?? [];
                
                $postoj_val = $postoj_data['czas'] ?? '00:00:30';
                $typ_postoju_val = $postoj_data['typ'] ?? '';
                $peron_val = $postoj_data['peron'] ?? '';
                $tor_val = $postoj_data['tor'] ?? '';

                // --- AUTOUZUPEŁNIANIE PERONU I TORU (LOGIKA WĘZŁOWA) ---
                if (empty($peron_val) && empty($tor_val)) {
                    $id_stacji_poprzedniej = ($index > 0) ? $full_stacje_list[$index - 1]['id_stacji'] : null;
                    $id_stacji_nastepnej = ($index < $liczba_stacji - 1) ? $full_stacje_list[$index + 1]['id_stacji'] : null;
                    
                    $found_history = false;

                    // 1. NAJLEPSZA OPCJA: Mamy Poprzednią i Następną (Jesteśmy w środku trasy, np. na węźle)
                    // Szukamy przebiegu: PREV -> CURR -> NEXT
                    if ($id_stacji_poprzedniej && $id_stacji_nastepnej) {
                        $sql_wezel = "SELECT t2.peron, t2.tor 
                                      FROM szczegoly_rozkladu t1 
                                      JOIN szczegoly_rozkladu t2 ON t1.id_przejazdu = t2.id_przejazdu 
                                      JOIN szczegoly_rozkladu t3 ON t2.id_przejazdu = t3.id_przejazdu 
                                      WHERE t1.id_stacji = ? 
                                        AND t2.id_stacji = ? 
                                        AND t3.id_stacji = ?
                                        AND t2.kolejnosc = t1.kolejnosc + 1
                                        AND t3.kolejnosc = t2.kolejnosc + 1
                                        AND t2.peron != '' 
                                      ORDER BY t2.id_przejazdu DESC LIMIT 1";
                        
                        $stmt = mysqli_prepare($conn, $sql_wezel);
                        mysqli_stmt_bind_param($stmt, "iii", $id_stacji_poprzedniej, $id_stacji_biezacej, $id_stacji_nastepnej);
                        mysqli_stmt_execute($stmt);
                        $res = mysqli_stmt_get_result($stmt);
                        if ($row = mysqli_fetch_assoc($res)) {
                            $peron_val = $row['peron'];
                            $tor_val = $row['tor'];
                            $found_history = true;
                        }
                    }

                    // 2. OPCJA: Startowa lub brak dopasowania węzła - szukamy po kierunku CURR -> NEXT
                    if (!$found_history && $id_stacji_nastepnej) {
                        $sql_start = "SELECT t1.peron, t1.tor 
                                      FROM szczegoly_rozkladu t1 
                                      JOIN szczegoly_rozkladu t2 ON t1.id_przejazdu = t2.id_przejazdu 
                                      WHERE t1.id_stacji = ? 
                                        AND t2.id_stacji = ? 
                                        AND t2.kolejnosc = t1.kolejnosc + 1
                                        AND t1.peron != '' 
                                      ORDER BY t1.id_przejazdu DESC LIMIT 1";
                        $stmt = mysqli_prepare($conn, $sql_start);
                        mysqli_stmt_bind_param($stmt, "ii", $id_stacji_biezacej, $id_stacji_nastepnej);
                        mysqli_stmt_execute($stmt);
                        $res = mysqli_stmt_get_result($stmt);
                        if ($row = mysqli_fetch_assoc($res)) {
                            $peron_val = $row['peron'];
                            $tor_val = $row['tor'];
                            $found_history = true;
                        }
                    }

                    // 3. OPCJA: Końcowa lub brak innych - szukamy po przyjeździe z PREV -> CURR
                    if (!$found_history && $id_stacji_poprzedniej) {
                        $sql_end = "SELECT t2.peron, t2.tor 
                                    FROM szczegoly_rozkladu t1 
                                    JOIN szczegoly_rozkladu t2 ON t1.id_przejazdu = t2.id_przejazdu 
                                    WHERE t1.id_stacji = ? 
                                      AND t2.id_stacji = ? 
                                      AND t2.kolejnosc = t1.kolejnosc + 1
                                      AND t2.peron != '' 
                                    ORDER BY t2.id_przejazdu DESC LIMIT 1";
                        $stmt = mysqli_prepare($conn, $sql_end);
                        mysqli_stmt_bind_param($stmt, "ii", $id_stacji_poprzedniej, $id_stacji_biezacej);
                        mysqli_stmt_execute($stmt);
                        $res = mysqli_stmt_get_result($stmt);
                        if ($row = mysqli_fetch_assoc($res)) {
                            $peron_val = $row['peron'];
                            $tor_val = $row['tor'];
                        }
                    }
                }
                // --- KONIEC LOGIKI WĘZŁOWEJ ---

                $is_postoj = !($stacja['id_typu_stacji'] >= 3 || $index == 0 || $index == $liczba_stacji - 1 || empty($typ_postoju_val));
                $postoj_sec = $is_postoj ? (strtotime($postoj_val) - strtotime("00:00:00")) : 0;
                $odjazd_ts = $przyjazd_ts + $postoj_sec;

                $czas_przejazdu_do_nastepnej = 0;
                $predkosc_max = "-";
                if ($index < $liczba_stacji - 1) {
                    $id_stacji_nastepnej = $full_stacje_list[$index + 1]['id_stacji'];
                    $query_odcinek = "SELECT czas_przejazdu, predkosc_max FROM odcinki WHERE (id_stacji_A = $id_stacji_biezacej AND id_stacji_B = $id_stacji_nastepnej) OR (id_stacji_A = $id_stacji_nastepnej AND id_stacji_B = $id_stacji_biezacej)";
                    $odcinek_res = mysqli_query($conn, $query_odcinek);
                    if($odcinek_data = mysqli_fetch_assoc($odcinek_res)) {
                        $czas_przejazdu_do_nastepnej = strtotime($odcinek_data['czas_przejazdu']) - strtotime("00:00:00");
                        $predkosc_max = $odcinek_data['predkosc_max'];
                    }
                }
                $czas_przejazdu_poprzedni = $czas_przejazdu_do_nastepnej;
                
                echo "<tr>";
                echo "<td style='text-align:center;'>" . ($index + 1) . "</td>";
                echo "<td><b>{$stacja['nazwa_stacji']}</b> {$stacja['skrot_typu_stacji']}<br><small>{$stacja['uwagi']}</small></td>";
                echo "<td style='text-align:center;'>{$predkosc_max}</td>";
                
                if ($index == 0) {
                    echo "<td class='godzina-cell'>|\n" . date("H:i:s", $odjazd_ts) . "</td>";
                    $przyjazd_do_zapisu = null; $odjazd_do_zapisu = date("H:i:s", $odjazd_ts);
                } else {
                    $odjazd_formatted = ($is_postoj || $index == $liczba_stacji - 1) ? date("H:i:s", $odjazd_ts) : "|";
                    if ($index == $liczba_stacji-1) $odjazd_formatted = "|";
                    $przyjazd_formatted = date("H:i:s", $przyjazd_ts);

                    echo "<td class='godzina-cell'>{$przyjazd_formatted}\n{$odjazd_formatted}</td>";
                    $przyjazd_do_zapisu = date("H:i:s", $przyjazd_ts);
                    $odjazd_do_zapisu = ($index == $liczba_stacji - 1) ? null : date("H:i:s", $odjazd_ts);
                }
                
                echo "<td><input type='text' class='platform-track' name='postoje[{$index}][peron]' value='{$peron_val}'></td>";
                echo "<td><input type='text' class='platform-track' name='postoje[{$index}][tor]' value='{$tor_val}'></td>";

                echo "<td style='text-align:center;'>";
                if ($stacja['id_typu_stacji'] < 3 && $index > 0 && $index < $liczba_stacji - 1) {
                    echo "<select id='postoj_select' name='postoje[{$index}][typ]' onchange='this.form.submit()'>";
                    echo "<option value='' " . ($typ_postoju_val == "" ? "selected" : "") . ">-</option>";
                    $postoje_res = mysqli_query($conn, "SELECT typ_postoj FROM postoje");
                    while($p_row = mysqli_fetch_assoc($postoje_res)) {
                        $selected = ($p_row['typ_postoj'] == $typ_postoju_val) ? "selected" : "";
                        echo "<option value='{$p_row['typ_postoj']}' $selected>{$p_row['typ_postoj']}</option>";
                    }
                    echo "</select>";
                } else { echo "|"; }
                echo "</td>";

                echo "<td style='text-align:center;'>";
                if ($is_postoj) {
                    echo "<input id='postoj_input' type='time' step='30' name='postoje[{$index}][czas]' value='{$postoj_val}' onchange='this.form.submit()'>";
                } else { echo "|"; }
                echo "</td>";
                echo "</tr>";
                
                if ($index == 0 || $index == $liczba_stacji - 1) {
                    $typ_postoju_do_zapisu = "";
                } 
                else if (empty($typ_postoju_val)) {
                    $typ_postoju_do_zapisu = "";
                } 
                else {
                    $typ_postoju_do_zapisu = $typ_postoju_val;
                }

                // Ukryte pola do zapisu
                echo "<input type='hidden' name='zapis[{$index}][id_stacji]' value='{$id_stacji_biezacej}'>";
                echo "<input type='hidden' name='zapis[{$index}][kolejnosc]' value='" . ($index+1) . "'>";
                echo "<input type='hidden' name='zapis[{$index}][przyjazd]' value='{$przyjazd_do_zapisu}'>";
                echo "<input type='hidden' name='zapis[{$index}][odjazd]' value='{$odjazd_do_zapisu}'>";
                echo "<input type='hidden' name='zapis[{$index}][uwagi_postoju]' value='{$typ_postoju_do_zapisu}'>";
                echo "<input type='hidden' name='zapis[{$index}][peron]' value='{$peron_val}'>";
                echo "<input type='hidden' name='zapis[{$index}][tor]' value='{$tor_val}'>";
                
                $godzina_biezaca = $odjazd_ts;
                $poprzednia_stacja_przelot = !$is_postoj;
            }
            ?>
        </table>
        <br>
        <button type="submit" class="button" formaction="zapisz_rozklad.php">💾 Zapisz ten rozkład do bazy</button>
        <?php endif; ?>
    </form>
</body>
</html>