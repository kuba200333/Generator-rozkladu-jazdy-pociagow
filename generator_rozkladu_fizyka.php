<?php
session_start();
require 'db_config.php';

// --- KONFIGURACJA FIZYKI ---
$MARGINES_BEZPIECZENSTWA = 1.05; // Mnożnik 1.05 = +5% czasu zapasu na lagi serwera

// Definicja dostępnych symboli
$available_symbols = [
    'klasa_1' => '1 klasa', 'klasa_2' => '2 klasa', 'rower' => 'Przewóz rowerów', 'rezerwacja' => 'Rezerwacja obowiązkowa',
    'wozek_rampa' => 'Dla os. na wózkach (z rampą)', 'wozek_bez_rampy' => 'Dla os. na wózkach (bez rampy)', 'kuszetka' => 'Kuszetka',
    'sypialny' => 'Wagon sypialny', 'bar' => 'Wagon barowy / mini-bar', 'restauracyjny' => 'Wagon restauracyjny',
    'automat' => 'Automat z przekąskami', 'wifi' => 'Dostęp do WiFi', 'klima' => 'Klimatyzacja',
    'przewijak' => 'Miejsce do przewijania dziecka', 'duzy_bagaz' => 'Miejsce na duży bagaż'
];

// --- FUNKCJA SYMULUJĄCA FIZYKĘ RUCHU ---
function obliczCzasFizyczny($dystans, $v_max_train, $v_max_track, $acc, $dec, $czy_start_zatrzymany, $czy_koniec_zatrzymany) {
    if ($dystans <= 0) return 30; // Zabezpieczenie dla błędnych danych

    // 1. Ustalenie prędkości docelowej (V_target) - najmniejsza z limitów
    $v_target = min($v_max_train, $v_max_track) / 3.6; // km/h -> m/s
    
    // Warunki początkowe
    $v_start = $czy_start_zatrzymany ? 0 : $v_target;
    $v_end   = $czy_koniec_zatrzymany ? 0 : $v_target;

    $czas_total = 0;
    $dystans_pozostaly = $dystans;

    // FAZA 1: PRZYSPIESZANIE
    if ($v_start < $v_target) {
        $czas_acc = ($v_target - $v_start) / $acc;
        $dystans_acc = (($v_start + $v_target) / 2) * $czas_acc;
        $czas_total += $czas_acc;
        $dystans_pozostaly -= $dystans_acc;
    }

    // FAZA 3: HAMOWANIE (liczymy wcześniej, żeby sprawdzić miejsce)
    $czas_dec = 0;
    $dystans_dec = 0;
    if ($v_end < $v_target) {
        $czas_dec = ($v_target - $v_end) / $dec;
        $dystans_dec = (($v_target + $v_end) / 2) * $czas_dec;
    }

    // SPRAWDZENIE: Czy odcinek jest wystarczająco długi?
    if ($dystans_pozostaly < $dystans_dec) {
        // Odcinek za krótki - uproszczony model (trójkąt prędkości)
        // Zakładamy, że nie osiągamy V_target, więc średnia prędkość jest niska
        $v_srednia = $v_target * 0.6; 
        $czas_total = $dystans / $v_srednia;
    } else {
        // Odcinek wystarczający (trapez prędkości)
        $dystans_pozostaly -= $dystans_dec; // Rezerwujemy miejsce na hamowanie
        $czas_total += $czas_dec;           // Dodajemy czas hamowania

        // FAZA 2: JAZDA STAŁA
        if ($dystans_pozostaly > 0) {
            $czas_cruise = $dystans_pozostaly / $v_target;
            $czas_total += $czas_cruise;
        }
    }

    return round($czas_total);
}


// --- LOGIKA SESJI I FORMULARZA ---
$czy_zmiana_trasy = false;
$nowe_id_trasy = $_POST['id_trasy'] ?? null;
$stare_id_trasy = $_SESSION['id_trasy'] ?? null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_trasy'])) {
    if ($nowe_id_trasy != $stare_id_trasy) {
        $czy_zmiana_trasy = true;
        // Reset sesji przy zmianie trasy
        unset($_SESSION['postoje']);
        unset($_SESSION['czas_odjazdu']);
        unset($_SESSION['nr_poc']);
        unset($_SESSION['id_typu_pociagu']);
        unset($_SESSION['nazwa_pociagu']);
        unset($_SESSION['daty_kursowania']);
        unset($_SESSION['dni_kursowania']);
        unset($_SESSION['symbole']);
        $_SESSION['id_trasy'] = $nowe_id_trasy;
    }
}
$id_trasy = $_SESSION['id_trasy'] ?? null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$czy_zmiana_trasy) {
    // Zapisujemy wszystkie pola do sesji
    $_SESSION['nr_poc'] = $_POST['nr_poc'] ?? ($_SESSION['nr_poc'] ?? '');
    $_SESSION['id_typu_pociagu'] = $_POST['id_typu_pociagu'] ?? ($_SESSION['id_typu_pociagu'] ?? null);
    $_SESSION['nazwa_pociagu'] = $_POST['nazwa_pociagu'] ?? ($_SESSION['nazwa_pociagu'] ?? '');
    $_SESSION['daty_kursowania'] = $_POST['daty_kursowania'] ?? ($_SESSION['daty_kursowania'] ?? '');
    $_SESSION['dni_kursowania'] = $_POST['dni_kursowania'] ?? ($_SESSION['dni_kursowania'] ?? '');
    $_SESSION['symbole'] = $_POST['symbole'] ?? [];
    $_SESSION['czas_odjazdu'] = $_POST['czas'] ?? ($_SESSION['czas_odjazdu'] ?? '');

    // Aktualizacja postojów ręczna
    if (isset($_POST['postoje'])) {
        foreach ($_POST['postoje'] as $index => $dane) {
            if (!isset($_SESSION['postoje'][$index])) $_SESSION['postoje'][$index] = [];
            $_SESSION['postoje'][$index] = array_merge($_SESSION['postoje'][$index], $dane);
        }
    }
    
    // Obsługa przycisku "Zastosuj dla wszystkich"
    if (isset($_POST['action']) && $_POST['action'] == 'set_all') {
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
            // Pomijamy: PODG (typ >=3), pierwszą i ostatnią stację
            $is_eligible = !($stacja['id_typu_stacji'] >= 3 || $index == 0 || $index == count($stacje_list_for_action) - 1);
            if ($is_eligible) {
                $_SESSION['postoje'][$index]['typ'] = $global_stop_type;
                $_SESSION['postoje'][$index]['czas'] = $global_stop_time;
            }
        }
    }
}

// Pobieranie danych fizycznych wybranego pociągu (z nowej tabeli)
$pociag_fizyka = ['v_max_tech' => 120, 'a_acc' => 0.5, 'b_dec' => 1.0]; // Domyślne
if (isset($_SESSION['id_typu_pociagu']) && $_SESSION['id_typu_pociagu']) {
    $stmt_fiz = mysqli_prepare($conn, "SELECT v_max_tech, a_acc, b_dec FROM typy_pociagow_fizyka WHERE id_typu = ?");
    mysqli_stmt_bind_param($stmt_fiz, "i", $_SESSION['id_typu_pociagu']);
    mysqli_stmt_execute($stmt_fiz);
    $res_fiz = mysqli_stmt_get_result($stmt_fiz);
    if ($row_fiz = mysqli_fetch_assoc($res_fiz)) {
        $pociag_fizyka = $row_fiz;
    }
}

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
    <title>Generator Fizyczny (Testowy)</title>
    <style>
        body{ font-family: sans-serif; padding: 10px; font-size: 15px; background-color: #f0f0f0;}
        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-items: start;}
        table{ border-collapse: collapse; width: 100%; margin-top: 20px; background-color: white;}
        td, th{ border: 1px solid black; padding: 4px; text-align: left;}
        th { background-color: #222; color: #fff; text-align: center; }
        .form-container { background-color: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; max-width: 1200px; }
        .form-section { margin-bottom: 10px; }
        .form-section label { display: block; font-weight: bold; margin-bottom: 5px;}
        input, select, button { padding: 5px; margin: 0 5px 0 0; box-sizing: border-box; }
        input[type="text"], input[type="number"], select { width: 100%; }
        .godzina-cell { text-align: center; white-space: pre; font-weight: bold; }
        .button { background-color: #d35400; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; } 
        .action-button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; }
        .action-button:hover { background-color: #0056b3; }
        .info-box { background: #e8f6f3; padding: 10px; border-left: 5px solid #1abc9c; margin-bottom: 10px;}
        .set-all-container { border-top: 1px solid #ccc; margin-top: 15px; padding-top: 15px; background: #fafafa; padding: 10px; border-radius: 5px;}
        .symbols-container { border: 1px solid #ccc; padding: 10px; margin-top: 10px; background: #fff; }
        .symbols-container label { display: inline-block; margin-right: 15px; font-weight: normal;}
    </style>
</head>
<body>
    <a href="index.php">Powrót do menu</a> | <a href="generator_rozkladu.php">Wróć do starego generatora</a><br><br>

    <div class="info-box">
        <strong>TRYB FIZYCZNY (Nowa Tabela)</strong><br>
        Korzysta z tabeli <code>odcinki_fizyka</code> i <code>typy_pociagow_fizyka</code>.<br>
        Aktualne parametry: <strong>Vmax=<?= $pociag_fizyka['v_max_tech'] ?> km/h</strong>, 
        <strong>Start=<?= $pociag_fizyka['a_acc'] ?> m/s²</strong>, 
        <strong>Hamowanie=<?= $pociag_fizyka['b_dec'] ?> m/s²</strong>.
    </div>

    <?= $status_msg ?>

    <form action="generator_rozkladu_fizyka.php" method="POST">
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
                    <label>Kategoria / Pociąg (Wpływa na fizykę!):</label>
                    <select name="id_typu_pociagu" required onchange="this.form.submit()">
                        <option value="">-- Wybierz --</option>
                        <?php
                        // Pobieramy z nowej tabeli typy_pociagow_fizyka
                        $query_typy = "SELECT t.id_typu, t.skrot, t.pelna_nazwa, p.skrot as przewoznik 
                                       FROM typy_pociagow_fizyka t 
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
            <button type="submit" name="action" value="generuj" class="button">Przelicz Fizykę / Odśwież</button>

            <div class="set-all-container">
                <strong>Ustaw dla wszystkich:</strong>
                Typ postoju:
                <select name="global_stop_type" style="width:auto; display:inline-block;">
                    <option value="">- (Przelot)</option>
                    <?php
                        $postoje_res = mysqli_query($conn, "SELECT typ_postoj FROM postoje");
                        while($p_row = mysqli_fetch_assoc($postoje_res)) {
                            echo "<option value='{$p_row['typ_postoj']}'>{$p_row['typ_postoj']}</option>";
                        }
                    ?>
                </select>
                Czas postoju:
                <input type="time" name="global_stop_time" value="00:01:00" step="30" style="width:auto; display:inline-block;">
                <button type="submit" name="action" value="set_all" class="button action-button">Zastosuj</button>
            </div>
        </div>
        
        <?php if ($id_trasy): ?>
        <table>
            <tr>
                <th style="width:3%">Lp.</th>
                <th>Stacja</th>
                <th style="width:8%">Vmax Szlaku</th>
                <th style="width:8%">Dystans (m)</th>
                <th style="width:8%">Czas jazdy (s)</th>
                <th style="width:10%">Godzina</th>
                <th style="width:8%">Typ post.</th>
                <th style="width:10%">Czas post.</th>
            </tr>
            <?php
            $full_stacje_list_res = mysqli_query($conn, "SELECT snt.kolejnosc, s.*, ts.skrot_typu_stacji, ts.id_typu_stacji FROM stacje_na_trasie snt JOIN stacje s ON snt.id_stacji = s.id_stacji JOIN typy_stacji ts ON s.typ_stacji_id = ts.id_typu_stacji WHERE snt.id_trasy = $id_trasy ORDER BY snt.kolejnosc");
            $full_stacje_list = mysqli_fetch_all($full_stacje_list_res, MYSQLI_ASSOC);
            $liczba_stacji = count($full_stacje_list);

            $godzina_biezaca = isset($_SESSION['czas_odjazdu']) && !empty($_SESSION['czas_odjazdu']) ? strtotime($_SESSION['czas_odjazdu']) : strtotime("00:00");
            
            $czas_przejazdu_odcinka = 0; 
            
            foreach ($full_stacje_list as $index => $stacja) {
                $id_stacji_biezacej = $stacja['id_stacji'];
                
                // Dane postojowe z sesji
                $postoj_data = $_SESSION['postoje'][$index] ?? [];
                $postoj_val = $postoj_data['czas'] ?? '00:00:30';
                $typ_postoju_val = $postoj_data['typ'] ?? '';
                
                // Sprawdzamy czy na TEJ stacji jest postój (dla hamowania i wyświetlania)
                $czy_postoj_tutaj = !($stacja['id_typu_stacji'] >= 3 || $index == 0 || $index == $liczba_stacji - 1 || empty($typ_postoju_val));
                
                $v_0_here = ($index == 0 || $index == $liczba_stacji - 1 || $czy_postoj_tutaj);

                // --- OBLICZANIE CZASU OD POPRZEDNIEJ STACJI ---
                if ($index > 0) {
                    $godzina_biezaca += $czas_przejazdu_odcinka; 
                }
                $przyjazd_ts = $godzina_biezaca;

                // Obliczamy postój
                $postoj_sec = $czy_postoj_tutaj ? (strtotime($postoj_val) - strtotime("00:00:00")) : 0;
                $odjazd_ts = $przyjazd_ts + $postoj_sec;

                // --- SYMULACJA NASTĘPNEGO ODCINKA ---
                $dystans_next = 0;
                $v_szlakowa_next = 120; // Domyślnie
                $czas_wyliczony_fizycznie = 0;

                if ($index < $liczba_stacji - 1) {
                    $id_stacji_nastepnej = $full_stacje_list[$index + 1]['id_stacji'];
                    
                    // Pobieramy z odcinki_fizyka
                    $query_odcinek = "SELECT dystans_metry, predkosc_max FROM odcinki_fizyka WHERE (id_stacji_A = $id_stacji_biezacej AND id_stacji_B = $id_stacji_nastepnej) OR (id_stacji_A = $id_stacji_nastepnej AND id_stacji_B = $id_stacji_biezacej)";
                    $odcinek_res = mysqli_query($conn, $query_odcinek);
                    
                    if($odcinek_data = mysqli_fetch_assoc($odcinek_res)) {
                        $dystans_next = $odcinek_data['dystans_metry'];
                        $v_szlakowa_string = $odcinek_data['predkosc_max'];
                        $v_szlakowa_next = intval($v_szlakowa_string);
                        if ($v_szlakowa_next == 0) $v_szlakowa_next = 80;
                    }

                    // Sprawdzamy postój na następnej
                    $postoj_data_next = $_SESSION['postoje'][$index+1] ?? [];
                    $typ_postoju_next = $postoj_data_next['typ'] ?? '';
                    $typ_stacji_next = $full_stacje_list[$index + 1]['id_typu_stacji'];
                    
                    $is_last = ($index + 1 == $liczba_stacji - 1);
                    $czy_postoj_next = !($typ_stacji_next >= 3 || empty($typ_postoju_next));
                    $v_0_next = ($is_last || $czy_postoj_next);

                    // --- MAGICZNA FUNKCJA FIZYKI ---
                    $czas_surowy = obliczCzasFizyczny(
                        $dystans_next, 
                        $pociag_fizyka['v_max_tech'], 
                        $v_szlakowa_next, 
                        $pociag_fizyka['a_acc'], 
                        $pociag_fizyka['b_dec'], 
                        $v_0_here,
                        $v_0_next 
                    );

                    $czas_przejazdu_odcinka = round($czas_surowy * $MARGINES_BEZPIECZENSTWA);
                }
                
                // --- TABELA ---
                echo "<tr>";
                echo "<td style='text-align:center;'>" . ($index + 1) . "</td>";
                echo "<td><b>{$stacja['nazwa_stacji']}</b> {$stacja['skrot_typu_stacji']}</td>";
                
                if ($index < $liczba_stacji - 1) {
                    echo "<td style='text-align:center;'>{$v_szlakowa_next} km/h</td>";
                    echo "<td style='text-align:center; color: #555;'>{$dystans_next} m</td>";
                    echo "<td style='text-align:center; font-weight:bold; color:blue;'>{$czas_przejazdu_odcinka}s</td>";
                } else {
                    echo "<td>-</td><td>-</td><td>-</td>";
                }
                
                if ($index == 0) {
                    echo "<td class='godzina-cell'>|\n" . date("H:i:s", $odjazd_ts) . "</td>";
                } else {
                    $odjazd_formatted = ($czy_postoj_tutaj || $index == $liczba_stacji - 1) ? date("H:i:s", $odjazd_ts) : "|";
                    if ($index == $liczba_stacji-1) $odjazd_formatted = "|";
                    $przyjazd_formatted = date("H:i:s", $przyjazd_ts);

                    echo "<td class='godzina-cell'>{$przyjazd_formatted}\n{$odjazd_formatted}</td>";
                }
                
                // Select wyboru postoju
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

                // Input czasu postoju
                echo "<td style='text-align:center;'>";
                if ($czy_postoj_tutaj) {
                    echo "<input id='postoj_input' type='time' step='30' name='postoje[{$index}][czas]' value='{$postoj_val}' onchange='this.form.submit()'>";
                } else { echo "|"; }
                echo "</td>";
                echo "</tr>";
                
                $godzina_biezaca = $odjazd_ts;
            }
            ?>
        </table>
        <br>
        <button type="button" class="button" onclick="alert('To jest tryb testowy fizyki. Wyniki widzisz w tabeli (niebieski kolor).')">Zapisz (Symulacja)</button>
        <?php endif; ?>
    </form>
</body>
</html>