<?php
session_start();
require 'db_config.php';

// Definicja dostępnych symboli (piktogramów) do edytora
$available_symbols = [
    'klasa_1' => '1 klasa', 'klasa_2' => '2 klasa', 'rower' => 'Przewóz rowerów', 'rezerwacja' => 'Rezerwacja',
    'wozek_rampa' => 'Wózek (rampa)', 'wozek_bez_rampy' => 'Wózek (bez rampy)', 'kuszetka' => 'Kuszetka',
    'sypialny' => 'Sypialny', 'bar' => 'Barowy', 'restauracyjny' => 'Restauracyjny',
    'automat' => 'Przekąski', 'wifi' => 'WiFi', 'klima' => 'Klimatyzacja',
    'przewijak' => 'Przewijak', 'duzy_bagaz' => 'Duży bagaż'
];

// Helper do formatowania czasu opóźnienia dla MySQL
function calculateDelayStr($timestamp_rzecz, $timestamp_plan) {
    if ($timestamp_rzecz - $timestamp_plan > 43200) $timestamp_rzecz -= 86400;
    if ($timestamp_plan - $timestamp_rzecz > 43200) $timestamp_rzecz += 86400;

    $diff = $timestamp_rzecz - $timestamp_plan;
    
    $sign = $diff < 0 ? '-' : '';
    $diff = abs($diff);
    $h = floor($diff / 3600);
    $m = floor(($diff / 60) % 60);
    $s = $diff % 60;
    
    return sprintf("%s%02d:%02d:%02d", $sign, $h, $m, $s);
}

// --- LOGIKA OBSŁUGI ZMIAN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // NOWOŚĆ: MASOWE USUWANIE POCIĄGÓW
    if (isset($_POST['action']) && $_POST['action'] == 'delete_trains') {
        if (!empty($_POST['train_ids'])) {
            $ids = array_map('intval', $_POST['train_ids']);
            $ids_str = implode(',', $ids);
            
            // Najpierw usuwamy szczegóły (stacje), potem główny wpis, żeby baza nie wywaliła błędu klucza
            mysqli_query($conn, "DELETE FROM szczegoly_rozkladu WHERE id_przejazdu IN ($ids_str)");
            mysqli_query($conn, "DELETE FROM przejazdy WHERE id_przejazdu IN ($ids_str)");
        }
        header("Location: zarzadzanie_trasa.php");
        exit;
    }

    // NOWOŚĆ: AKTUALIZACJA GŁÓWNYCH DANYCH POCIĄGU
    if (isset($_POST['action']) && $_POST['action'] == 'update_train_info') {
        $id_przejazdu = (int)$_POST['id_przejazdu'];
        $numer = $_POST['numer_pociagu'];
        $nazwa = $_POST['nazwa_pociagu'];
        $id_typu = (int)$_POST['id_typu_pociagu'];
        $daty = $_POST['daty_kursowania'];
        $dni = $_POST['dni_kursowania'];
        
        // Zbieramy zaznaczone piktogramy
        $symbole_zaznaczone = isset($_POST['symbole']) ? $_POST['symbole'] : [];
        $symbole_json = json_encode($symbole_zaznaczone, JSON_UNESCAPED_UNICODE);

        $stmt = mysqli_prepare($conn, "UPDATE przejazdy SET numer_pociagu = ?, nazwa_pociagu = ?, id_typu_pociagu = ?, daty_kursowania = ?, dni_kursowania = ?, symbole = ? WHERE id_przejazdu = ?");
        mysqli_stmt_bind_param($stmt, "ssisssi", $numer, $nazwa, $id_typu, $daty, $dni, $symbole_json, $id_przejazdu);
        mysqli_stmt_execute($stmt);
        
        header("Location: zarzadzanie_trasa.php?id_przejazdu=" . $id_przejazdu);
        exit;
    }

    // 1. ZMIANA STATUSU (Odwołany / ZKA / Przywracanie)
    if (isset($_POST['action']) && $_POST['action'] == 'update_status') {
        $id_szczegolu = (int)$_POST['id_szczegolu'];
        $czy_odwolany = (int)$_POST['czy_odwolany']; 
        $typ_transportu = $_POST['typ_transportu']; 
        
        $stmt = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET czy_odwolany = ?, typ_transportu = ? WHERE id_szczegolu = ?");
        mysqli_stmt_bind_param($stmt, "isi", $czy_odwolany, $typ_transportu, $id_szczegolu);
        mysqli_stmt_execute($stmt);
        
        mysqli_query($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = przyjazd, odjazd_rzecz = odjazd WHERE id_szczegolu = $id_szczegolu");
        
        header("Location: zarzadzanie_trasa.php?id_przejazdu=" . $_POST['id_przejazdu']);
        exit;
    }

    // 2. MASOWY ZAPIS STACJI (zastępuje też zapis pojedynczy w tym widoku)
    if (isset($_POST['action']) && $_POST['action'] == 'save_updates') {
        $id_przejazdu = (int)$_POST['id_przejazdu'];
        $rows = $_POST['rows'];
        
        foreach ($rows as $id_szczegolu => $data) {
            $id_szczegolu = (int)$id_szczegolu;
            $przyjazd_rzecz = !empty($data['przyjazd']) ? $data['przyjazd'] . ":00" : null;
            $odjazd_rzecz = !empty($data['odjazd']) ? $data['odjazd'] . ":00" : null;
            $peron = $data['peron'];
            $tor = $data['tor'];
            $uwagi_postoju = $data['uwagi_postoju'];
            
            $stmt = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, odjazd_rzecz = ?, peron = ?, tor = ?, uwagi_postoju = ? WHERE id_szczegolu = ?");
            mysqli_stmt_bind_param($stmt, "sssssi", $przyjazd_rzecz, $odjazd_rzecz, $peron, $tor, $uwagi_postoju, $id_szczegolu);
            mysqli_stmt_execute($stmt);
        }
        header("Location: zarzadzanie_trasa.php?id_przejazdu=" . $id_przejazdu);
        exit;
    }

    // 3. RESETOWANIE CZASÓW
    if (isset($_POST['action']) && $_POST['action'] == 'reset_times') {
        $id_przejazdu = (int)$_POST['id_przejazdu'];
        $sql_reset = "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = przyjazd, odjazd_rzecz = odjazd WHERE id_przejazdu = ?";
        $stmt_reset = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = przyjazd, odjazd_rzecz = odjazd WHERE id_przejazdu = ?");
        mysqli_stmt_bind_param($stmt_reset, "i", $id_przejazdu);
        mysqli_stmt_execute($stmt_reset);
        
        header("Location: zarzadzanie_trasa.php?id_przejazdu=" . $id_przejazdu);
        exit;
    }

    // 4. USUWANIE STACJI
    if (isset($_POST['action']) && $_POST['action'] == 'delete_station') {
        $id_szczegolu = (int)$_POST['id_szczegolu'];
        $id_przejazdu = (int)$_POST['id_przejazdu'];
        
        mysqli_query($conn, "DELETE FROM szczegoly_rozkladu WHERE id_szczegolu = $id_szczegolu");
        
        $res = mysqli_query($conn, "SELECT id_szczegolu FROM szczegoly_rozkladu WHERE id_przejazdu = $id_przejazdu ORDER BY kolejnosc ASC");
        $i = 1;
        while($row = mysqli_fetch_assoc($res)) {
            $ids = $row['id_szczegolu'];
            mysqli_query($conn, "UPDATE szczegoly_rozkladu SET kolejnosc = $i WHERE id_szczegolu = $ids");
            $i++;
        }
        header("Location: zarzadzanie_trasa.php?id_przejazdu=" . $id_przejazdu);
        exit;
    }

    // 5. DODAWANIE STACJI (Oryginalna logika)
    if (isset($_POST['action']) && $_POST['action'] == 'add_station') {
        $id_przejazdu = (int)$_POST['id_przejazdu'];
        $insert_after = (int)$_POST['insert_after'];
        $new_station_id = (int)$_POST['new_station_id'];
        $new_peron = $_POST['new_peron'];
        $new_tor = $_POST['new_tor'];
        $new_postoj_typ = $_POST['new_postoj_typ'];
        $new_postoj_czas = (int)$_POST['new_postoj_czas'];
        if ($new_postoj_czas < 0) $new_postoj_czas = 0;
        
        $prev_time_ref = "00:00:00"; 
        $id_prev_station = 0;
        
        if ($insert_after > 0) {
            $q_prev = mysqli_query($conn, "SELECT id_stacji, odjazd, odjazd_rzecz, przyjazd, przyjazd_rzecz FROM szczegoly_rozkladu WHERE id_przejazdu = $id_przejazdu AND kolejnosc = $insert_after");
            if ($row_prev = mysqli_fetch_assoc($q_prev)) {
                $id_prev_station = $row_prev['id_stacji'];
                if ($row_prev['odjazd_rzecz']) $prev_time_ref = $row_prev['odjazd_rzecz'];
                elseif ($row_prev['odjazd']) $prev_time_ref = $row_prev['odjazd'];
                elseif ($row_prev['przyjazd_rzecz']) $prev_time_ref = $row_prev['przyjazd_rzecz'];
                else $prev_time_ref = $row_prev['przyjazd'];
            }
        }

        $travel_time_sec = 300; 
        if ($id_prev_station > 0) {
            $q_odcinek1 = mysqli_query($conn, "SELECT czas_przejazdu FROM odcinki WHERE (id_stacji_A = $id_prev_station AND id_stacji_B = $new_station_id) OR (id_stacji_A = $new_station_id AND id_stacji_B = $id_prev_station) LIMIT 1");
            if ($row_o1 = mysqli_fetch_assoc($q_odcinek1)) {
                $parts = explode(':', $row_o1['czas_przejazdu']);
                $travel_time_sec = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
            }
        }

        $new_przyjazd_ts = strtotime($prev_time_ref) + $travel_time_sec;
        $duration_sec = ($new_postoj_czas > 0) ? ($new_postoj_czas * 60) : 60;
        $new_odjazd_ts = $new_przyjazd_ts + $duration_sec; 

        $form_przyjazd_rzecz = !empty($_POST['new_przyjazd']) ? $_POST['new_przyjazd'] . ":00" : date("H:i:s", $new_przyjazd_ts);
        $form_odjazd_rzecz = !empty($_POST['new_odjazd']) ? $_POST['new_odjazd'] . ":00" : date("H:i:s", $new_odjazd_ts);
        $form_przyjazd_plan = $form_przyjazd_rzecz; 
        $form_odjazd_plan = $form_odjazd_rzecz;

        mysqli_query($conn, "UPDATE szczegoly_rozkladu SET kolejnosc = kolejnosc + 1 WHERE id_przejazdu = $id_przejazdu AND kolejnosc > $insert_after");
        $new_kolejnosc = $insert_after + 1;
        
        $stmt_ins = mysqli_prepare($conn, "INSERT INTO szczegoly_rozkladu (id_przejazdu, id_stacji, kolejnosc, przyjazd, odjazd, przyjazd_rzecz, odjazd_rzecz, uwagi_postoju, peron, tor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt_ins, "iissssssss", $id_przejazdu, $new_station_id, $new_kolejnosc, $form_przyjazd_plan, $form_odjazd_plan, $form_przyjazd_rzecz, $form_odjazd_rzecz, $new_postoj_typ, $new_peron, $new_tor);
        mysqli_stmt_execute($stmt_ins);

        $q_next = mysqli_query($conn, "SELECT id_stacji, przyjazd FROM szczegoly_rozkladu WHERE id_przejazdu = $id_przejazdu AND kolejnosc = " . ($new_kolejnosc + 1));
        if ($row_next = mysqli_fetch_assoc($q_next)) {
            $id_next_station = $row_next['id_stacji'];
            $travel_time_2_sec = 300; 
            
            $q_odcinek2 = mysqli_query($conn, "SELECT czas_przejazdu FROM odcinki WHERE (id_stacji_A = $new_station_id AND id_stacji_B = $id_next_station) OR (id_stacji_A = $id_next_station AND id_stacji_B = $new_station_id) LIMIT 1");
            if ($row_o2 = mysqli_fetch_assoc($q_odcinek2)) {
                $parts = explode(':', $row_o2['czas_przejazdu']);
                $travel_time_2_sec = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
            }
            
            $rzecz_arrival_at_next = strtotime($form_odjazd_rzecz) + $travel_time_2_sec;
            $plan_arrival_at_next = strtotime($row_next['przyjazd']);
            $delay_str = calculateDelayStr($rzecz_arrival_at_next, $plan_arrival_at_next);
            
            $sql_prop = "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ADDTIME(przyjazd, ?), odjazd_rzecz = ADDTIME(odjazd, ?) WHERE id_przejazdu = $id_przejazdu AND kolejnosc > $new_kolejnosc";
            
            $stmt_prop = mysqli_prepare($conn, $sql_prop);
            mysqli_stmt_bind_param($stmt_prop, "ss", $delay_str, $delay_str);
            mysqli_stmt_execute($stmt_prop);
        }
        
        header("Location: zarzadzanie_trasa.php?id_przejazdu=" . $id_przejazdu);
        exit;
    }
}

$id_przejazdu = $_GET['id_przejazdu'] ?? null;
$trasa_data = [];
$info = [];

if ($id_przejazdu) {
    $res_info = mysqli_query($conn, "SELECT p.*, t.nazwa_trasy FROM przejazdy p JOIN trasy t ON p.id_trasy = t.id_trasy WHERE p.id_przejazdu = $id_przejazdu");
    $info = mysqli_fetch_assoc($res_info);

    $sql = "SELECT sr.*, s.nazwa_stacji 
            FROM szczegoly_rozkladu sr 
            JOIN stacje s ON sr.id_stacji = s.id_stacji 
            WHERE sr.id_przejazdu = $id_przejazdu 
            ORDER BY sr.kolejnosc ASC";
    $res = mysqli_query($conn, $sql);
    $trasa_data = mysqli_fetch_all($res, MYSQLI_ASSOC);
}

$all_stations = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Dyspozytor - Zarządzanie Trasą</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f0f2f5; font-size: 13px; }
        
        .top-bar { background-color: #004080; color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); position: sticky; top: 0; z-index: 100; }
        .top-bar h1 { margin: 0; font-size: 18px; }
        .top-bar a { color: #fff; text-decoration: none; margin-left: 20px; font-weight: bold; background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 4px; }
        .top-bar a:hover { background: rgba(255,255,255,0.3); }

        .container { padding: 20px; display: flex; gap: 20px; height: calc(100vh - 80px); }
        
        .left-panel { width: 350px; background: white; border: 1px solid #ccc; border-radius: 5px; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; box-shadow: 2px 2px 10px rgba(0,0,0,0.05); }
        .right-panel { flex: 1; background: white; border: 1px solid #ccc; border-radius: 5px; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; box-shadow: 2px 2px 10px rgba(0,0,0,0.05); position: relative; }

        h2 { margin-top: 0; color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; font-size: 16px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 60px; }
        th { background: #eee; padding: 8px; border: 1px solid #ccc; text-align: left; position: sticky; top: 0; z-index: 10; font-size: 12px; }
        td { padding: 4px; border: 1px solid #ccc; vertical-align: middle; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        
        .status-cancelled { background-color: #ffe6e6 !important; }
        .status-cancelled td b { color: #cc0000; text-decoration: line-through; }
        .status-zka { background-color: #fff8cc !important; }
        
        input.time-input { width: 70px; text-align: center; border: 1px solid #aaa; padding: 4px; font-weight: bold; font-size: 12px; }
        input.text-input { width: 40px; text-align: center; border: 1px solid #aaa; padding: 4px; }
        select.mini-select { font-size: 11px; padding: 2px; border: 1px solid #aaa; }
        
        .btn { padding: 4px 8px; cursor: pointer; border: none; font-size: 11px; font-weight: bold; color: white; border-radius: 3px; }
        .btn-save { background: #28a745; } .btn-save:hover { background: #218838; }
        .btn-del { background: #dc3545; } .btn-del:hover { background: #c82333; }
        .btn-add { background: #007bff; width: 100%; padding: 8px; margin-top: 10px; }
        
        .bulk-save-bar { position: sticky; bottom: 0; left: 0; right: 0; background: #e9ecef; padding: 10px; border-top: 1px solid #ccc; text-align: right; z-index: 20; }
        .btn-bulk { padding: 10px 20px; font-size: 14px; background: #004080; color: white; border: none; border-radius: 4px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .btn-bulk:hover { background: #003366; }

        .add-box { background: #e9ecef; padding: 10px; border: 1px solid #ced4da; margin-top: 20px; border-radius: 4px; }
        .add-row { display: flex; gap: 5px; align-items: flex-end; }
        .add-group { display: flex; flex-direction: column; }
        .add-group label { font-size: 10px; font-weight: bold; margin-bottom: 2px; }

        .switch { position: relative; display: inline-block; width: 30px; height: 16px; margin-top: 3px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 10px; width: 10px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #dc3545; }
        input:checked + .slider:before { transform: translateX(14px); }

        /* Style dla listy pociągów po lewej */
        .train-item { display:flex; align-items:center; padding: 6px; border-bottom:1px solid #eee; transition: background 0.2s; }
        .train-item:hover { background-color: #f1f8ff; }
        .train-item.active { background-color: #dbeafe; border-left: 4px solid #007bff; }
        .train-item a { text-decoration:none; color:#333; flex-grow:1; margin-left: 8px; display: block;}
        .train-item a b { color: #004080; }

        /* Edytor Danych Głównych */
        .main-editor-box { background: #eef7ff; padding: 15px; border: 1px solid #b8daff; border-radius: 5px; margin-bottom: 20px; }
        .main-editor-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .main-editor-grid input[type="text"], .main-editor-grid select { width: 100%; padding: 5px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box; }
        .symbols-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; margin-top: 10px; font-size: 11px; }
    </style>
</head>
<body>

<div class="top-bar">
    <h1>🛠️ Centrum Zarządzania Ruchem</h1>
    <div>
        <a href="index.php">Menu Główne</a>
        <a href="panel_dyzurnego.php">Panel Dyżurnego</a>
    </div>
</div>

<div class="container">
    
    <div class="left-panel">
        <h2>Wybór i Usuwanie Pociągów</h2>
        
        <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('Czy na pewno usunąć wszystkie zaznaczone pociągi? Tego nie da się cofnąć!')">
            <input type="hidden" name="action" value="delete_trains">
            
            <div style="display:flex; justify-content:space-between; align-items: center; margin-bottom: 10px; background: #f8f9fa; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <label style="cursor: pointer; font-weight: bold;">
                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"> Zaznacz wszystkie
                </label>
                <button type="submit" class="btn btn-del" style="padding: 6px 12px; font-size: 12px;">🗑️ Usuń wybrane</button>
            </div>

            <div style="flex-grow: 1; overflow-y: auto; border: 1px solid #ddd; background: #fff;">
                <?php
                $sql_list = "SELECT p.id_przejazdu, p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy 
                             FROM przejazdy p JOIN trasy t ON p.id_trasy = t.id_trasy 
                             ORDER BY p.data_utworzenia DESC";
                $res_list = mysqli_query($conn, $sql_list);
                while($r = mysqli_fetch_assoc($res_list)) {
                    $active_class = ($id_przejazdu == $r['id_przejazdu']) ? 'active' : '';
                    echo "<div class='train-item {$active_class}'>";
                    echo "<input type='checkbox' name='train_ids[]' value='{$r['id_przejazdu']}' class='train-cb'>";
                    echo "<a href='?id_przejazdu={$r['id_przejazdu']}'>
                            <b>{$r['numer_pociagu']}</b> " . ($r['nazwa_pociagu'] ? "\"{$r['nazwa_pociagu']}\"" : "") . "<br>
                            <span style='color:#777; font-size: 11px;'>🚏 {$r['nazwa_trasy']}</span>
                          </a>";
                    echo "</div>";
                }
                ?>
            </div>
        </form>
    </div>

    <div class="right-panel">
        <?php if($id_przejazdu): ?>
            
            <div class="main-editor-box">
                <h4 style="margin: 0 0 10px 0; color: #004080;">📝 Edycja głównych parametrów pociągu (Tabela Przejazdy)</h4>
                <form action="zarzadzanie_trasa.php" method="POST">
                    <input type="hidden" name="action" value="update_train_info">
                    <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu ?>">
                    
                    <div class="main-editor-grid">
                        <div>
                            <label style="font-weight:bold; font-size:11px;">Numer pociągu:</label><br>
                            <input type="text" name="numer_pociagu" value="<?= htmlspecialchars($info['numer_pociagu']) ?>" required>
                        </div>
                        <div>
                            <label style="font-weight:bold; font-size:11px;">Nazwa pociągu:</label><br>
                            <input type="text" name="nazwa_pociagu" value="<?= htmlspecialchars($info['nazwa_pociagu'] ?? '') ?>">
                        </div>
                        <div>
                            <label style="font-weight:bold; font-size:11px;">Kategoria pociągu:</label><br>
                            <select name="id_typu_pociagu" required>
                                <?php
                                $query_typy = "SELECT id_typu, skrot FROM typy_pociagow ORDER BY skrot";
                                $result_typy = mysqli_query($conn, $query_typy);
                                while ($row = mysqli_fetch_assoc($result_typy)) {
                                    $sel = ($row['id_typu'] == $info['id_typu_pociagu']) ? 'selected' : '';
                                    echo "<option value='{$row['id_typu']}' $sel>{$row['skrot']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div style="grid-column: span 3; display: flex; gap: 15px;">
                            <div style="flex: 1;">
                                <label style="font-weight:bold; font-size:11px;">Daty obowiązywania:</label><br>
                                <input type="text" name="daty_kursowania" value="<?= htmlspecialchars($info['daty_kursowania'] ?? '') ?>" placeholder="np. 15 VI - 30 VIII">
                            </div>
                            <div style="flex: 1;">
                                <label style="font-weight:bold; font-size:11px;">Dni/Uwagi (do tablic):</label><br>
                                <input type="text" name="dni_kursowania" value="<?= htmlspecialchars($info['dni_kursowania'] ?? '') ?>" placeholder="np. kursuje w 1,2,3">
                            </div>
                        </div>
                    </div>
                    
                    <label style="font-weight:bold; font-size:11px; display:block; margin-top: 10px;">Zaznaczone piktogramy/symbole:</label>
                    <div class="symbols-grid">
                        <?php
                        $zapisane_symbole = json_decode($info['symbole'] ?? '[]', true);
                        if(!is_array($zapisane_symbole)) {
                            // Kompatybilność wsteczna jeśli były trzymane po przecinku
                            $zapisane_symbole = explode(',', str_replace(['"', '[', ']', '\\'], '', $info['symbole'] ?? ''));
                        }
                        $zapisane_symbole = array_map('trim', $zapisane_symbole);

                        foreach ($available_symbols as $key => $label) {
                            $checked = in_array($key, $zapisane_symbole) ? "checked" : "";
                            echo "<label><input type='checkbox' name='symbole[]' value='{$key}' {$checked}> {$label}</label>";
                        }
                        ?>
                    </div>

                    <div style="text-align: right; margin-top: 10px;">
                        <button type="submit" class="btn btn-save" style="padding: 8px 15px; font-size: 12px;">💾 Zapisz Główne Parametry</button>
                    </div>
                </form>
            </div>

            <h2>Szczegółowa Trasa (Godziny, Perony, Postoje)</h2>
            
            <form method="POST" id="mainForm">
                <input type="hidden" name="action" value="save_updates">
                <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu ?>">
                
                <table>
                    <thead>
                        <tr>
                            <th width="30">Lp.</th>
                            <th>Stacja</th>
                            <th>Typ Transp.</th>
                            <th>Przyjazd Rzecz.</th> <th>Odjazd Rzecz.</th>
                            <th>Per.</th>
                            <th>Tor</th>
                            <th width="50">Typ Post.</th>
                            <th width="40" align="center">Odw.</th>
                            <th width="40">Zapisz</th>
                            <th width="30">Usuń</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($trasa_data as $row): 
                            $rowClass = '';
                            if ($row['czy_odwolany'] == 1) $rowClass = 'status-cancelled';
                            elseif ($row['typ_transportu'] == 'ZKA') $rowClass = 'status-zka';
                            $ids = $row['id_szczegolu'];
                            
                            $val_przyjazd = $row['przyjazd_rzecz'] ? substr($row['przyjazd_rzecz'], 0, 5) : '';
                            $val_odjazd = $row['odjazd_rzecz'] ? substr($row['odjazd_rzecz'], 0, 5) : '';
                            $ph_przyjazd = $row['przyjazd'] ? substr($row['przyjazd'], 0, 5) : '';
                            $ph_odjazd = $row['odjazd'] ? substr($row['odjazd'], 0, 5) : '';
                            $uwagi = $row['uwagi_postoju'];
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><?= $row['kolejnosc'] ?></td>
                            <td>
                                <b><?= $row['nazwa_stacji'] ?></b>
                            </td>

                            <td>
                                <select class="mini-select" onchange="submitStatus(<?= $ids ?>, this.value, <?= $row['czy_odwolany'] ?>)">
                                    <option value="POCIAG" <?= $row['typ_transportu'] == 'POCIAG' ? 'selected' : '' ?>>Pociąg</option>
                                    <option value="ZKA" <?= $row['typ_transportu'] == 'ZKA' ? 'selected' : '' ?>>Autobus</option>
                                </select>
                            </td>

                            <input type="hidden" name="rows[<?= $ids ?>][kolejnosc]" value="<?= $row['kolejnosc'] ?>">
                            <td><input type="time" name="rows[<?= $ids ?>][przyjazd]" value="<?= $val_przyjazd ?>" placeholder="<?= $ph_przyjazd ?>" class="time-input"></td>
                            <td><input type="time" name="rows[<?= $ids ?>][odjazd]" value="<?= $val_odjazd ?>" placeholder="<?= $ph_odjazd ?>" class="time-input"></td>
                            <td><input type="text" name="rows[<?= $ids ?>][peron]" value="<?= $row['peron'] ?>" class="text-input"></td>
                            <td><input type="text" name="rows[<?= $ids ?>][tor]" value="<?= $row['tor'] ?>" class="text-input"></td>
                            
                            <td>
                                <select name="rows[<?= $ids ?>][uwagi_postoju]" class="mini-select">
                                    <option value="" <?= $uwagi == '' ? 'selected' : '' ?>>-</option>
                                    <option value="ph" <?= $uwagi == 'ph' ? 'selected' : '' ?>>ph</option>
                                    <option value="pt" <?= $uwagi == 'pt' ? 'selected' : '' ?>>pt</option>
                                    <option value="pm" <?= $uwagi == 'pm' ? 'selected' : '' ?>>pm</option>
                                </select>
                            </td>
                            
                            <td align="center">
                                <label class="switch">
                                    <input type="checkbox" onchange="submitStatus(<?= $ids ?>, '<?= $row['typ_transportu'] ?>', this.checked ? 1 : 0)" <?= $row['czy_odwolany'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            
                            <td>
                                <button type="submit" name="save_single_id" value="<?= $ids ?>" class="btn btn-save" title="Zapisz tylko ten wiersz">💾</button>
                            </td>
                            
                            <td align="center">
                                <button type="button" class="btn btn-del" onclick="deleteStation(<?= $ids ?>)">X</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="bulk-save-bar">
                    <button type="button" class="btn-bulk" style="background: #e67e22;" onclick="confirmResetTimes(<?= $id_przejazdu ?>)">🗑️ RESETUJ CZASY RZECZYWISTE</button>
                    <button type="submit" class="btn-bulk">💾 ZAPISZ ROZKŁAD TRASY (MASOWO)</button>
                </div>
            </form>

            <form id="resetForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="reset_times">
                <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu ?>">
            </form>
            
            <form id="statusForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu ?>">
                <input type="hidden" name="id_szczegolu" id="status_id">
                <input type="hidden" name="typ_transportu" id="status_typ">
                <input type="hidden" name="czy_odwolany" id="status_odw">
            </form>

            <form id="deleteForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="delete_station">
                <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu ?>">
                <input type="hidden" name="id_szczegolu" id="del_id">
            </form>

            <div class="add-box">
                <h4>➕ Dodaj stację / Objazd</h4>
                <form method="POST" class="add-row">
                    <input type="hidden" name="action" value="add_station">
                    <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu ?>">
                    
                    <div class="add-group" style="flex: 2;">
                        <label>Wstaw PO:</label>
                        <select name="insert_after">
                            <option value="0">-- NA POCZĄTKU --</option>
                            <?php foreach($trasa_data as $t): ?>
                                <option value="<?= $t['kolejnosc'] ?>"><?= $t['kolejnosc'] ?>. <?= $t['nazwa_stacji'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="add-group" style="flex: 2;">
                        <label>Nowa Stacja:</label>
                        <select name="new_station_id" required>
                            <?php 
                            mysqli_data_seek($all_stations, 0);
                            while($s = mysqli_fetch_assoc($all_stations)): ?>
                                <option value="<?= $s['id_stacji'] ?>"><?= $s['nazwa_stacji'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="add-group" style="width: 50px;">
                        <label>Typ</label>
                        <select name="new_postoj_typ" class="mini-select" style="width:100%;">
                            <option value="ph">ph</option>
                            <option value="pt">pt</option>
                            <option value="">-</option>
                        </select>
                    </div>

                    <div class="add-group" style="width: 50px;">
                        <label>Min.</label>
                        <input type="number" name="new_postoj_czas" value="1" min="0" class="text-input" style="width:100%;">
                    </div>

                    <div class="add-group" style="width: 40px;">
                        <label>Peron</label>
                        <input type="text" name="new_peron">
                    </div>
                    <div class="add-group" style="width: 40px;">
                        <label>Tor</label>
                        <input type="text" name="new_tor">
                    </div>

                    <div class="add-group">
                        <label>Przyj.</label>
                        <input type="time" name="new_przyjazd">
                    </div>
                    <div class="add-group">
                        <label>Odj.</label>
                        <input type="time" name="new_odjazd">
                    </div>

                    <div class="add-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-add" style="margin-top:0;">Dodaj</button>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <div style="text-align: center; margin-top: 100px; color: #999;">
                <h2>&larr; Wybierz pociąg z listy po lewej, aby edytować trasę lub zaznacz pociągi do usunięcia.</h2>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function submitStatus(id, typ, odw) {
        document.getElementById('status_id').value = id;
        document.getElementById('status_typ').value = typ;
        document.getElementById('status_odw').value = odw;
        document.getElementById('statusForm').submit();
    }
    
    function deleteStation(id) {
        if(confirm('Czy na pewno chcesz usunąć tę stację z trasy?')) {
            document.getElementById('del_id').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    function confirmResetTimes(id_przejazdu) {
        if(confirm('Czy na pewno chcesz ZRESETOWAĆ WSZYSTKIE CZASY RZECZYWISTE dla tej trasy? Spowoduje to powrót do czasów PLANOWYCH.')) {
            document.getElementById('resetForm').submit();
        }
    }

    // Logika do masowego zaznaczania checkboxów na liście pociągów
    function toggleSelectAll(source) {
        let checkboxes = document.querySelectorAll('.train-cb');
        for(let i=0; i<checkboxes.length; i++) {
            checkboxes[i].checked = source.checked;
        }
    }
</script>

</body>
</html>