<?php
session_start();
require 'db_config.php';

// Ustawienie strefy czasowej
date_default_timezone_set('Europe/Warsaw');

// --- OBSŁUGA KODÓW OPÓŹNIEŃ (AJAX) ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // Pobieranie stacji, na których pociąg ma opóźnienie >= 3 minuty
    if ($_POST['ajax_action'] == 'get_delays') {
        $id_przej = (int)$_POST['id_przejazdu'];
        $q = mysqli_query($conn, "SELECT id_szczegolu, id_stacji, przyjazd, przyjazd_rzecz, odjazd, odjazd_rzecz, kod_opoznienia, 
            (SELECT nazwa_stacji FROM stacje WHERE id_stacji=szczegoly_rozkladu.id_stacji) as stacja 
            FROM szczegoly_rozkladu WHERE id_przejazdu = $id_przej ORDER BY kolejnosc");
        $data = [];
        while($r = mysqli_fetch_assoc($q)) {
            $delay = 0;
            if($r['odjazd_rzecz'] && $r['odjazd']) {
                $diff = (strtotime($r['odjazd_rzecz']) - strtotime($r['odjazd'])) / 60;
                if($diff >= 3) $delay = $diff;
            } elseif ($r['przyjazd_rzecz'] && $r['przyjazd']) {
                $diff = (strtotime($r['przyjazd_rzecz']) - strtotime($r['przyjazd'])) / 60;
                if($diff >= 3) $delay = $diff;
            }
            if($delay >= 3) {
                $r['opoznienie_min'] = round($delay);
                $data[] = $r;
            }
        }
        echo json_encode($data);
        exit;
    }
    
    // Zapisywanie wybranych kodów do bazy
    if ($_POST['ajax_action'] == 'save_delays') {
        $codes = json_decode($_POST['codes'], true);
        if(is_array($codes)) {
            foreach($codes as $id_szczeg => $kod) {
                $id = (int)$id_szczeg;
                $k = mysqli_real_escape_string($conn, $kod);
                mysqli_query($conn, "UPDATE szczegoly_rozkladu SET kod_opoznienia = '$k' WHERE id_szczegolu = $id");
            }
        }
        echo json_encode(['success'=>true]);
        exit;
    }
}
// --- KONIEC OBSŁUGI KODÓW OPÓŹNIEŃ ---

// Pobieranie listy posterunków (stacji)
$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje WHERE typ_stacji_id IN (1,2,3,5) ORDER BY nazwa_stacji");
$wybrana_stacja = $_GET['id_stacji'] ?? 29;

$pociagi = [];
if ($wybrana_stacja) {
    // Pobieramy dane. 
    $sql = "
    SELECT 
        sr.id_szczegolu, sr.id_przejazdu, sr.przyjazd, sr.odjazd, 
        sr.przyjazd_rzecz, sr.odjazd_rzecz, sr.tor, sr.peron, sr.status_dyzurnego, sr.uwagi_postoju,
        sr.kolejnosc,
        p.numer_pociagu, p.nazwa_pociagu, 
        tp.skrot as rodzaj, tp.pelna_nazwa as rodzaj_pelna, tp.kolor_czcionki, sr.czy_odwolany, sr.zatwierdzony,
        pr.pelna_nazwa as przewoznik_skrot,
        
        (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = t.id_stacji_poczatkowej) as stacja_pocz,
        (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = t.id_stacji_koncowej) as stacja_konc,
        
        (SELECT s2.nazwa_stacji 
         FROM szczegoly_rozkladu sr2 
         JOIN stacje s2 ON sr2.id_stacji = s2.id_stacji
         WHERE sr2.id_przejazdu = sr.id_przejazdu 
         AND CAST(sr2.kolejnosc AS SIGNED) < CAST(sr.kolejnosc AS SIGNED)
         AND s2.typ_stacji_id IN (1, 3,5)
         ORDER BY CAST(sr2.kolejnosc AS SIGNED) DESC LIMIT 1) as stacja_prev,

        (SELECT s3.nazwa_stacji 
         FROM szczegoly_rozkladu sr3 
         JOIN stacje s3 ON sr3.id_stacji = s3.id_stacji
         WHERE sr3.id_przejazdu = sr.id_przejazdu 
         AND CAST(sr3.kolejnosc AS SIGNED) > CAST(sr.kolejnosc AS SIGNED)
         AND s3.typ_stacji_id IN (1, 3,5)
         ORDER BY CAST(sr3.kolejnosc AS SIGNED) ASC LIMIT 1) as stacja_next,

        (SELECT CASE 
            WHEN sr_hist.odjazd_rzecz IS NOT NULL AND sr_hist.odjazd_rzecz != sr_hist.odjazd THEN TIMESTAMPDIFF(MINUTE, sr_hist.odjazd, sr_hist.odjazd_rzecz)
            WHEN sr_hist.przyjazd_rzecz IS NOT NULL AND sr_hist.przyjazd_rzecz != sr_hist.przyjazd THEN TIMESTAMPDIFF(MINUTE, sr_hist.przyjazd, sr_hist.przyjazd_rzecz)
            ELSE 0 END
         FROM szczegoly_rozkladu sr_hist
         WHERE sr_hist.id_przejazdu = sr.id_przejazdu 
           AND CAST(sr_hist.kolejnosc AS SIGNED) < CAST(sr.kolejnosc AS SIGNED)
           AND (
                (sr_hist.odjazd_rzecz IS NOT NULL AND sr_hist.odjazd_rzecz != sr_hist.odjazd) 
                OR 
                (sr_hist.przyjazd_rzecz IS NOT NULL AND sr_hist.przyjazd_rzecz != sr_hist.przyjazd)
               )
         ORDER BY CAST(sr_hist.kolejnosc AS SIGNED) DESC LIMIT 1
        ) as opoznienie_aktywne

    FROM szczegoly_rozkladu sr
    JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu
    JOIN trasy t ON p.id_trasy = t.id_trasy
    JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
    LEFT JOIN przewoznicy pr ON tp.id_przewoznika = pr.id_przewoznika
    WHERE sr.id_stacji = ?
    ORDER BY sr.przyjazd ASC
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $wybrana_stacja);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pociagi = mysqli_fetch_all($res, MYSQLI_ASSOC);
}

// Funkcje pomocnicze PHP
function fmtFull($time) { return $time ? date('m-d H:i', strtotime($time)) : ''; }
function fmtShort($time) { return $time ? date('H:i', strtotime($time)) : ''; }

function addMinutesPHP($time, $minutes) { 
    if (!$time) return ''; 
    return date('H:i', strtotime($time . " +$minutes minutes")); 
}

function diffMinutesPHP($plan, $rzecz) {
    if (!$plan || !$rzecz) return 0;
    $t1 = strtotime(substr($plan, 0, 5));
    $t2 = strtotime(substr($rzecz, 0, 5));
    $diff = round(($t2 - $t1) / 60);
    if ($diff < -720) $diff += 1440;
    if ($diff > 720) $diff -= 1440;
    return $diff;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>SWDR - Panel Dyżurnego</title>
    <style>
        body { font-family: 'Tahoma', 'Segoe UI', sans-serif; font-size: 11px; margin: 0; padding: 0; background-color: #f0f0f0; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .top-bar { background-color: #f0f0f0; padding: 5px 10px; border-bottom: 1px solid #a0a0a0; display: flex; justify-content: space-between; align-items: flex-start; height: 75px; }
        .control-group label { display: block; font-weight: bold; margin-bottom: 2px; }
        select { font-size: 11px; padding: 2px; width: 250px; border: 1px solid #777; }
        .info-panel { font-size: 11px; margin-left: 20px; flex-grow: 1; }
        .info-panel b { color: #000080; }
        .clock-wrapper { text-align: right; }
        .clock-label { font-size: 10px; color: navy; font-weight: bold; display: block; margin-bottom: 2px; text-align: right;}
        .clock-display { background-color: #000080; color: #fff; font-family: 'Arial', sans-serif; font-size: 42px; font-weight: bold; padding: 0 10px; border: 2px solid #fff; box-shadow: 2px 2px 5px rgba(0,0,0,0.5); display: inline-block; line-height: 1; }
        
        .tabs-strip { background-color: #f0f0f0; padding: 5px 5px 0 5px; display: flex; margin-top: 5px; }
        .tab { padding: 4px 15px; margin-right: 2px; background: linear-gradient(to bottom, #f0f0f0, #d4d0c8); border: 1px solid #888; border-bottom: none; cursor: pointer; border-radius: 3px 3px 0 0; color: #000; }
        .tab.active { background: #fff; font-weight: bold; position: relative; top: 1px; z-index: 2; padding-bottom: 5px; }
        
        .content-area { flex: 1; background-color: #fff; border: 1px solid #888; border-top: 1px solid #888; margin: 0 5px 5px 5px; overflow: hidden; position: relative; }
        .tab-pane { display: none; height: 100%; overflow: auto; }
        .tab-pane.active { display: block; }
        
        table.swdr-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; cursor: default; }
        table.swdr-table th { background: linear-gradient(to bottom, #ffffff 0%, #d4d0c8 100%); border-right: 1px solid #808080; border-bottom: 1px solid #808080; border-top: 1px solid #fff; padding: 3px; font-weight: bold; font-size: 11px; text-align: center; white-space: nowrap; position: sticky; top: 0; z-index: 10; color: #000; }
        table.swdr-table td { border-right: 1px solid #d0d0d0; border-bottom: 1px solid #d0d0d0; padding: 1px 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; height: 19px; font-size: 11px; color: #000; }
        
        .bg-time { background-color: #FFFFE0; text-align: center; color: #000; }
        .bg-green { background-color: #E0FFE0; text-align: center; font-weight: bold; border-right: 2px solid #999 !important; }
        .bg-blue { background-color: #FFFFFF; text-align: center; color: #000080; font-weight: bold; }
        .bg-gray { background-color: #e0e0e0; text-align: center; }
        .center { text-align: center; }
        .delay-red { background-color: #ff0000; color: white; text-align: center; font-weight: bold; }
        .delay-green { background-color: #008000; color: white; text-align: center; font-weight: bold; }
        .forecast { font-style: italic; color: #555; font-weight: normal; }
        
        tr.row-approved td { background-color: #ccffcc !important; }
        tr.row-approved td.bg-time { background-color: #ccffcc !important; }
        tr.row-approved td.bg-blue { background-color: #ccffcc !important; }
        tr.row-approved td.delay-red { background-color: #ff0000 !important; color: white !important; }
        tr.row-approved td.delay-green { background-color: #008000 !important; color: white !important; }

        tr:nth-child(even) { background-color: #f8f8f8; }
        
        tr.selected td { background-color: #000080 !important; color: #ffffff !important; }
        tr.selected td.bg-blue { color: #ffffff !important; } 
        tr.selected td.forecast { color: #ccc !important; }
        
        .info-grid { display: grid; grid-template-columns: 150px 1fr; gap: 10px; padding: 20px; max-width: 900px; }
        .info-label { font-weight: bold; color: #000080; text-align: right; padding-right: 10px;}
        .info-value { background: #FFFFE0; border: 1px solid #ccc; padding: 4px; font-weight: bold; min-height: 20px; }
        .info-full-row { grid-column: span 2; display: flex; flex-direction: column; }
        .info-textarea { background: #FFFFE0; border: 1px solid #ccc; padding: 4px; height: 60px; overflow-y: auto;}
        
        .bottom-bar { height: 28px; background-color: #f0f0f0; border-top: 1px solid #888; padding: 2px 5px; display: flex; align-items: center; justify-content: space-between; }
        .btn-swdr { border: 1px solid #888; background: linear-gradient(to bottom, #fff, #e0e0e0); padding: 3px 10px; font-size: 11px; font-weight: bold; cursor: pointer; margin-right: 5px; display: flex; align-items: center; gap: 5px; text-decoration: none; color: black; }
        .btn-swdr:hover { background: #d0d0d0; }
        
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #f0f0f0; margin: 3% auto; border: 1px solid #000; width: 850px; box-shadow: 4px 4px 10px rgba(0,0,0,0.5); font-family: 'Tahoma', sans-serif; }
        .modal-header { background: linear-gradient(to right, #000080, #3a6ea5); color: white; padding: 4px 8px; font-weight: bold; display: flex; justify-content: space-between; font-size: 12px; }
        .modal-info-strip { background-color: #ffffe0; border-bottom: 1px solid #ccc; padding: 5px; text-align: center; color: #006400; font-weight: bold; }
        .modal-body { padding: 15px; }
        .modal-col { flex: 1; border: 1px solid #aaa; padding: 10px; background: #fff; margin-bottom: 10px;}
        .modal-col h4 { margin: 0 0 10px 0; color: #000080; border-bottom: 1px solid #eee; font-size: 11px; }
        .time-row { display: flex; align-items: center; margin-bottom: 10px; background:#f5f5f5; padding:5px; border:1px solid #ddd;}
        input[type="time"] { font-size: 14px; font-weight: bold; width: 90px; }
        input[type="date"] { font-size: 12px; margin-right: 5px; width: 110px;}
        .btn-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; margin-top: 10px;}
        .btn-time { font-size: 10px; padding: 4px 0; background: #fcfcfc; border: 1px solid #bbb; cursor: pointer; text-align: center; }
        .btn-time:hover { background: #e0e0ff; border-color: #000080; }
        .modal-footer { padding: 8px; background: #e0e0e0; border-top: 1px solid #999; text-align: right; }

        .announce-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; background: #fff; padding: 10px; border: 1px solid #ccc;}
        .announce-field label { display: block; font-size: 10px; font-weight: bold; color: #000080; margin-bottom: 2px;}
        .announce-field input, .announce-field select { width: 100%; box-sizing: border-box; font-size: 11px; padding: 3px; border: 1px solid #aaa;}
        .announce-box { border:1px solid #aaa; padding:10px; background:#fff; font-family: monospace; font-size: 13px; min-height: 80px; white-space: pre-wrap; overflow-y:auto; color: #000; margin-top: 10px;}
    </style>
</head>
<body>

<div class="top-bar">
    <div class="control-group">
        <label>Posterunek:</label>
        <form method="GET" id="formStacja">
            <select name="id_stacji" id="selectStacja" onchange="document.getElementById('formStacja').submit()">
                <?php 
                mysqli_data_seek($stacje_res, 0);
                while($s = mysqli_fetch_assoc($stacje_res)): ?>
                    <option value="<?= $s['id_stacji'] ?>" <?= $s['id_stacji'] == $wybrana_stacja ? 'selected' : '' ?>>
                        <?= $s['nazwa_stacji'] ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </form>
        <div class="radio-group" style="margin-top: 5px;">
            <label><input type="radio" name="view" checked> wszystkie</label>
            <label><input type="radio" name="view"> potwierdz.</label>
        </div>
    </div>

    <div class="info-panel">
        Informacje dodatkowe<br>
        <label><input type="checkbox" id="chk-daty" onchange="toggleDates()"> Prezentuj daty</label><br>
        <b>Z:</b> <span id="lbl-z">---</span><br>
        <b>Do:</b> <span id="lbl-do">---</span>
    </div>

    <div class="clock-wrapper">
        <span class="clock-label">Aktualny czas:</span>
        <div class="clock-display" id="clock">00:00:00</div>
    </div>
</div>

<div class="tabs-strip">
    <div class="tab active" onclick="openTab('wykaz')">Wykaz pociągów</div>
    <div class="tab" onclick="openTab('opis')">Opis pociągu</div>
    <div class="tab" onclick="openTab('trasa')">Trasa pociągu</div>
    <div class="tab"  onclick="openTab('opoznienia')">Kody opóźnień</div>
</div>

<div class="content-area">
    <div id="tab-wykaz" class="tab-pane active">
        <table class="swdr-table">
            <thead>
                <tr>
                    <th style="width:20px">K</th>
                    <th style="width:20px">NK</th>
                    <th style="width:70px">Prz. plan.</th>
                    <th style="width:25px">+/-</th>
                    <th style="width:70px">Prz. rzecz.</th>
                    <th style="width:40px">Rodz.</th>
                    <th style="width:50px">Nr poc.</th>
                    <th style="width:120px">Z kierunku post.</th>
                    <th style="width:50px">Nr poc.</th>
                    <th style="width:120px">W kierunku post.</th>
                    <th style="width:30px">Tor</th>
                    <th style="width:30px">Per.</th>
                    <th style="width:30px">Typ</th>
                    <th style="width:70px">Odj. plan.</th>
                    <th style="width:25px">+/-</th>
                    <th style="width:70px">Odj. rzecz.</th>
                    <th>Stacja początkowa</th>
                    <th>Stacja końcowa</th>
                    <th>Przewoźnik</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($pociagi as $p): 
                    $opoz_min = isset($p['opoznienie_aktywne']) ? intval($p['opoznienie_aktywne']) : 0;
                    $is_approved = ($p['zatwierdzony'] == 1); 
                    $row_class = $is_approved ? 'row-approved' : '';

                    if ($p['przyjazd_rzecz'] && $p['przyjazd_rzecz'] != $p['przyjazd']) {
                        $val_rp = substr($p['przyjazd_rzecz'], 0, 5);
                        $style_rp = '';
                        $diff_arr = diffMinutesPHP($p['przyjazd'], $p['przyjazd_rzecz']);
                        $opoz_min = $diff_arr; 
                    } else {
                        $val_rp = $p['przyjazd'] ? addMinutesPHP($p['przyjazd'], $opoz_min) : '';
                        $style_rp = 'forecast';
                        $diff_arr = $opoz_min;
                    }
                    $cls_arr = ($diff_arr > 0) ? 'delay-red' : (($diff_arr < 0) ? 'delay-green' : '');

                    if ($p['odjazd_rzecz'] && $p['odjazd_rzecz'] != $p['odjazd']) {
                        $val_ro = substr($p['odjazd_rzecz'], 0, 5);
                        $style_ro = '';
                        $diff_dep = diffMinutesPHP($p['odjazd'], $p['odjazd_rzecz']);
                        $opoz_min = $diff_dep;
                    } else {
                        $val_ro = $p['odjazd'] ? addMinutesPHP($p['odjazd'], $opoz_min) : '';
                        $style_ro = 'forecast';
                        $diff_dep = $opoz_min;
                    }
                    $cls_dep = ($diff_dep > 0) ? 'delay-red' : (($diff_dep < 0) ? 'delay-green' : '');

                    $numer = intval(preg_replace('/\D/', '', $p['numer_pociagu']));
                    $nr_left = ($numer % 2 != 0) ? $p['numer_pociagu'] : '';
                    $stacja_z = $p['stacja_prev'];
                    $nr_right = ($numer % 2 == 0) ? $p['numer_pociagu'] : '';
                    $stacja_do = $p['stacja_next'];
                    
                    $full_pp = fmtFull($p['przyjazd']); $short_pp = fmtShort($p['przyjazd']);
                    $full_po = fmtFull($p['odjazd']); $short_po = fmtShort($p['odjazd']);
                    $type_class = $is_approved ? 'type-approved' : 'center';
                ?>
                <tr class="<?= $row_class ?>" onclick="selectRow(this, <?= $p['id_szczegolu'] ?>, <?= $p['id_przejazdu'] ?>)"
                    ondblclick="openModal()"
                    data-info="<?= $p['numer_pociagu'] ?> (<?= $p['stacja_pocz'] ?> - <?= $p['stacja_konc'] ?>)"
                    data-plan-p="<?= $short_pp ?>" data-plan-o="<?= $short_po ?>"
                    data-rzecz-p="<?= $val_rp ?>" data-rzecz-o="<?= $val_ro ?>"
                    data-z="<?= $p['stacja_pocz'] ?>" data-do="<?= $p['stacja_konc'] ?>"
                    data-rodzaj="<?= $p['rodzaj'] ?>"
                    data-rodzaj-pelna="<?= $p['rodzaj_pelna'] ?? $p['rodzaj'] ?>"
                    data-peron="<?= $p['peron'] ?>"
                    data-tor="<?= $p['tor'] ?>"
                    data-numer="<?= $p['numer_pociagu'] ?>"
                    data-nazwa="<?= $p['nazwa_pociagu'] ?>"
                    data-opoznienie="<?= $opoz_min ?>">
                    
                    <td class="center"><input type="checkbox" <?= $p['czy_odwolany'] ? '' : 'checked' ?> disabled></td>
                    <td class="center"><?= $p['czy_odwolany'] ? '<input type="checkbox" checked disabled>' : '' ?></td>

                    <td class="bg-time t-cell" data-short="<?= $short_pp ?>" data-full="<?= $full_pp ?>"><?= $short_pp ?></td>
                    <td class="<?= $cls_arr ?>"><?= $diff_arr != 0 ? ($diff_arr > 0 ? '+'.$diff_arr : $diff_arr) : '' ?></td>
                    <td class="bg-blue t-cell <?= $style_rp ?>" data-short="<?= $val_rp ?>" data-full="<?= fmtFull($val_rp) ?>"><?= $val_rp ?></td>
                    <td class="<?= $type_class ?>" style="text-align: center; color: black;"><?= $p['rodzaj'] ?></td>
                    <td class="bg-green center"><?= $nr_left ?></td>
                    <td><?= $stacja_z ?></td>
                    <td class="bg-green center"><?= $nr_right ?></td>
                    <td><?= $stacja_do ?></td>
                    <td class="bg-gray"><?= $p['tor'] ?></td>
                    <td class="bg-gray"><?= $p['peron'] ?></td>
                    <td class="center"><?= $p['uwagi_postoju'] ?></td>
                    <td class="bg-time t-cell" data-short="<?= $short_po ?>" data-full="<?= $full_po ?>"><?= $short_po ?></td>
                    <td class="<?= $cls_dep ?>"><?= $diff_dep != 0 ? ($diff_dep > 0 ? '+'.$diff_dep : $diff_dep) : '' ?></td>
                    <td class="bg-blue t-cell <?= $style_ro ?>" data-short="<?= $val_ro ?>" data-full="<?= fmtFull($val_ro) ?>"><?= $val_ro ?></td>
                    <td><?= $p['stacja_pocz'] ?></td>
                    <td><?= $p['stacja_konc'] ?></td>
                    <td><?= $p['przewoznik_skrot'] ?></td> 
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="tab-opis" class="tab-pane" style="background:#f0f0f0;">
        <div class="info-grid">
            <div class="info-label">Numer pociągu</div><div class="info-value" id="op-nr"></div>
            <div class="info-full-row"><div class="info-label" style="text-align:left;">Informacje dodatkowe</div><div class="info-textarea"></div></div>
            <div class="info-label">Nazwa pociągu</div><div class="info-value" id="op-nazwa"></div>
            <div class="info-full-row"><div class="info-label" style="text-align:left;">Ładunek</div><div class="info-textarea"></div></div>
            <div class="info-label">Rodzaj pociągu</div><div class="info-value" id="op-rodzaj"></div>
            <div class="info-label">Przewoźnik</div><div class="info-value" id="op-przew"></div>
            <div class="info-label">Stacja początkowa</div><div class="info-value" id="op-start"></div>
            <div class="info-label">Stacja końcowa</div><div class="info-value" id="op-koniec"></div>
            <div class="info-full-row"><div class="info-label" style="text-align:left;">Uwagi własne</div><div class="info-textarea" id="op-symbole"></div></div>
            
            <div class="info-full-row" style="margin-top: 10px; text-align: center;">
                <button type="button" class="btn-swdr" onclick="drukujTablice()" style="padding: 8px 15px; font-size: 13px; display: inline-block; cursor: pointer; background: #e0f2fe; border-color: #0284c7;">🖨️ Wydrukuj tablicę dla tego pociągu</button>
            </div>
        </div>
    </div>

    <div id="tab-trasa" class="tab-pane">
        <table class="swdr-table">
            <thead>
                <tr>
                    <th style="width:25px">+/-</th>
                    <th style="width:20px">Z</th>
                    <th style="width:30px">Lp.</th>
                    <th>Stacja</th>
                    <th style="width:30px">Tor</th>
                    <th style="width:30px">Peron</th>
                    <th style="width:50px">P. zam.</th>
                    <th style="width:50px">P. obl.</th>
                    <th style="width:40px">Typ p.</th>
                    <th style="width:70px">Przyjazd plan.</th>
                    <th style="width:70px">Przyjazd rzecz.</th>
                    <th style="width:70px">Odjazd plan.</th>
                    <th style="width:70px">Odjazd rzecz.</th>
                    <th style="width:35px">+/-</th>
                    <th style="width:50px">Rodzaj</th>
                </tr>
            </thead>
            <tbody id="trasa-body"></tbody>
        </table>
    </div>
    <div id="tab-opoznienia" class="tab-pane">
            <div style="padding: 20px;">
                <h3 style="margin-top:0; color: #003366;">Wprowadzanie przyczyn opóźnień (Ir-14)</h3>
                <p style="font-size: 13px; color: #555;">System wykazuje tylko posterunki, na których pociąg posiada opóźnienie wynoszące minimum 3 minuty.</p>
                
                <table class="swdr-table" style="width: 100%; margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Posterunek / Stacja</th>
                            <th style="width:100px;">Opóźnienie</th>
                            <th>Wybór kodu przyczyny PLK</th>
                        </tr>
                    </thead>
                    <tbody id="lista-kodow-tbody">
                        <tr><td colspan="3" style="text-align:center; padding: 20px;">Najpierw wybierz pociąg z Wykazu Pociągów...</td></tr>
                    </tbody>
                </table>
                <div style="text-align: right; margin-top: 15px;">
                    <button class="btn-swdr" style="background: #28a745; color: white;" onclick="zapiszKodyOpoznien()">💾 Zapisz Kody w Systemie</button>
                </div>
            </div>
        </div>
</div>

<div class="bottom-bar">
    <div class="btn-swdr" onclick="openModal()"><span>🕒</span> Wprowadzanie godzin (F2)</div>
    <div class="btn-swdr" onclick="openAnnounceModal()"><span>📢</span> Zapowiedź</div>
    <div class="btn-swdr" onclick="openWyswietlaczModal()" style="background: #e0f2fe; border-color: #0284c7;"><span>🖥️</span> Wyświetl na peronie</div>
    <a href="panel_tablic.php?id_stacji=<?= $wybrana_stacja ?>" target="_blank" class="btn-swdr" style="margin-left:auto;"><span>👁️</span> Podgląd wszystkich tablic</a>
    <span style="font-size:10px; color:#555; margin-left:10px;">Ilość pociągów -> <?= count($pociagi) ?></span>
</div>

<div id="modalGodziny" class="modal">
    <div class="modal-content" style="width: 500px;">
        <div class="modal-header"><span>Rzeczywisty czas przyjazdu i odjazdu pociągu</span><span onclick="closeModal()" style="cursor:pointer;">X</span></div>
        <div class="modal-info-strip" id="modal-title"></div>
        <form id="formTimes" onsubmit="saveTimes(event)">
            <input type="hidden" id="modal-id" name="id_szczegolu">
            <div class="modal-body" style="display:flex; gap: 10px;">
                <div class="modal-col">
                    <h4>Pociąg przyjechał:</h4>
                    <div class="time-row">Planowo: <b id="lbl-plan-p" style="margin-right:10px;"></b></div>
                    <div class="time-control"><input type="date" value="<?= date('Y-m-d') ?>"><input type="time" name="przyjazd_rzecz" id="inp-p"></div>
                    <fieldset style="border:1px solid #ccc; padding:5px; margin-top:10px;"><legend style="font-size:10px; color:navy;">Dodaj opóźnienie</legend><div class="btn-grid"><?php foreach([5,10,15,20,25,30,45,60] as $m) echo "<div class='btn-time' onclick=\"addMin('inp-p', $m)\">$m min.</div>"; ?></div></fieldset>
                </div>
                <div class="modal-col">
                    <h4>Pociąg odjechał:</h4>
                    <div class="time-row">Planowo: <b id="lbl-plan-o" style="margin-right:10px;"></b></div>
                    <div class="time-control"><input type="date" value="<?= date('Y-m-d') ?>"><input type="time" name="odjazd_rzecz" id="inp-o"><button type="button" onclick="copyTime()" style="margin-top:5px; font-size:10px; width:100%;">Przepisz czas przyjazdu</button></div>
                    <fieldset style="border:1px solid #ccc; padding:5px; margin-top:10px;"><legend style="font-size:10px; color:navy;">Dodaj opóźnienie</legend><div class="btn-grid"><?php foreach([5,10,15,20,25,30,45,60] as $m) echo "<div class='btn-time' onclick=\"addMin('inp-o', $m)\">$m min.</div>"; ?></div></fieldset>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn-swdr" style="float:left; width:200px;">Wprowadź postój (STÓJ)</button><button type="submit" class="btn-swdr" style="display:inline-block;">Zapisz</button><button type="button" class="btn-swdr" style="display:inline-block;" onclick="closeModal()">Anuluj</button></div>
        </form>
    </div>
</div>

<div id="modalZapowiedz" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span>📢 Generator Zapowiedzi Megafonowych (PLK)</span>
            <span onclick="closeAnnounceModal()" style="cursor:pointer; font-size:14px;">X</span>
        </div>
        <div class="modal-info-strip" id="zapowiedz-info"></div>
        
        <div class="modal-body" style="display:block;">
            <div class="announce-controls">
                <div class="announce-field">
                    <label>Kategoria komunikatu:</label>
                    <select id="annCat" onchange="updateTemplates()">
                        <option value="wjazd">Wjazd / Przyjazd</option>
                        <option value="odjazd_postoj">Odjazd / Postój</option>
                        <option value="rezerwacja">Rezerwacja miejsc / Wagony</option>
                        <option value="opoznienie">Opóźnienia</option>
                        <option value="zaklocenia">Zakłócenia / Odwołania</option>
                        <option value="komunikacja_zastepcza">Komunikacja Zastępcza (ZKA)</option>
                        <option value="bezpieczenstwo_inne">Bezpieczeństwo / Inne</option>
                    </select>
                </div>
                <div class="announce-field">
                    <label>Szczegółowy wariant:</label>
                    <select id="annVar" onchange="generateAnnouncement()"></select>
                </div>
                
                <div class="announce-field">
                    <label>Peron / Tor (wymusza odmianę):</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="annPeron" placeholder="Peron" oninput="generateAnnouncement()">
                        <input type="text" id="annTor" placeholder="Tor" oninput="generateAnnouncement()">
                    </div>
                </div>
                <div class="announce-field">
                    <label>Godzina plan. / Opóźnienie (min):</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="time" id="annTime" oninput="generateAnnouncement()">
                        <input type="number" id="annDelay" placeholder="Minuty opóźn." oninput="generateAnnouncement()">
                    </div>
                </div>

                <div class="announce-field">
                    <label>Stacja skrócona / Stacja dla grupy wagonów:</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="annShort" oninput="generateAnnouncement()" placeholder="Skrócona (np. Stargard)">
                        <input type="text" id="annGroupStation" oninput="generateAnnouncement()" placeholder="Grupa do (np. Kołobrzeg)">
                    </div>
                </div>
                
                <div class="announce-field">
                    <label>Zmiana kategorii (Gdzie / Na jaką):</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="annChangeCatStation" oninput="generateAnnouncement()" placeholder="Od stacji (np. Poznań Gł.)">
                        <input type="text" id="annNewCat" oninput="generateAnnouncement()" placeholder="Nowa (np. InterCity)">
                    </div>
                </div>

                <div class="announce-field">
                    <label>Położenie wagonów (wielogrupowe/1 kl.):</label>
                    <select id="annWagonsPos" onchange="generateAnnouncement()">
                        <option value="na początku">Na początku</option>
                        <option value="w środku">W środku</option>
                        <option value="na końcu">Na końcu</option>
                    </select>
                </div>
                
                <div class="announce-field">
                    <label>Numery wagonów (Rezerwacja / Braki):</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="annWagonsFront" oninput="generateAnnouncement()" placeholder="Nr początek">
                        <input type="text" id="annWagonsMiddle" oninput="generateAnnouncement()" placeholder="Nr środek">
                        <input type="text" id="annWagonsRear" oninput="generateAnnouncement()" placeholder="Nr koniec">
                    </div>
                </div>

                <div class="announce-field">
                    <label>Wagon Kierownika / Brakujący / Zastępczy:</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="annManagerWagon" oninput="generateAnnouncement()" placeholder="Kierownik nr">
                        <input type="text" id="annMissingWagon" oninput="generateAnnouncement()" placeholder="Brakujący nr">
                        <input type="text" id="annReplacementWagon" oninput="generateAnnouncement()" placeholder="Zastępczy nr">
                    </div>
                </div>

            <div class="announce-field">
                <label>Odcinek ZKA (Z jakiej stacji / Do jakiej stacji):</label>
                <div style="display: flex; gap: 5px;">
                    <input type="text" id="annZkaZ" oninput="generateAnnouncement()" placeholder="Od stacji (np. Goleniów)">
                    <input type="text" id="annZkaDo" oninput="generateAnnouncement()" placeholder="Do stacji (np. Wysoka Kamieńska)">
                </div>
            </div>

                <div class="announce-field">
                    <label>Miejsce odjazdu ZKA:</label>
                    <input type="text" id="annBusStop" oninput="generateAnnouncement()" placeholder="np. placu przed dworcem">
                </div>
            </div>
            
            <div class="announce-field" style="margin-top: 10px;">
                <label>Wygenerowana treść zapowiedzi:</label>
                <div class="announce-box" id="announceText"></div>
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-swdr" id="btnPlay" onclick="playAnnouncement()">Wygłoś 🔊</button>
            <button type="button" class="btn-swdr" onclick="copyAnnouncement()">Kopiuj tekst</button>
            <button type="button" class="btn-swdr" onclick="closeAnnounceModal()">Zamknij</button>
        </div>
    </div>
</div>

<div id="modalWyswietlacz" class="modal">
    <div class="modal-content" style="width: 450px;">
        <div class="modal-header">
            <span>🖥️ Zarządzanie Tablicą dla Pociągu</span>
            <span onclick="closeWyswietlaczModal()" style="cursor:pointer; font-size:14px;">X</span>
        </div>
        <div class="modal-info-strip" id="wyswietlacz-info"></div>
        
        <div class="modal-body">
            <div class="announce-field">
                <label>Domyślny Peron (możesz zmienić):</label>
                <input type="text" id="wysw-peron" style="font-size: 14px; font-weight: bold;">
            </div>
            <div class="announce-field" style="margin-top: 10px;">
                <label>Domyślny Tor (możesz zmienić):</label>
                <input type="text" id="wysw-tor" style="font-size: 14px; font-weight: bold;">
            </div>
            <div class="announce-field" style="margin-top: 15px;">
                <label>Żółty pasek (komunikat awaryjny - opcjonalnie):</label>
                <input type="text" id="wysw-komunikat" placeholder="np. Zmiana peronu, opóźniony...">
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-swdr" onclick="zapiszWyswietlacz()" style="background-color: #dcfce7; font-size: 12px; padding: 5px 15px;">Wyślij na tablicę</button>
            <button type="button" class="btn-swdr" onclick="closeWyswietlaczModal()">Anuluj</button>
        </div>
    </div>
</div>

<script>
    setInterval(() => document.getElementById('clock').innerText = new Date().toLocaleTimeString('pl-PL', {hour12:false}), 1000);

    let currentId = null;
    let currentPrzejazdId = null;
    let currentData = {};
    const stacjaId = new URLSearchParams(window.location.search).get('id_stacji') || 29;

    setInterval(function() {
        if (currentPrzejazdId && document.getElementById('modalGodziny').style.display !== 'block' && document.getElementById('modalZapowiedz').style.display !== 'block') {
            pobierzDaneTrasy(currentPrzejazdId);
        }
    }, 5000); 

    function addMinutes(timeStr, mins) {
        if(!timeStr) return '';
        let [h, m] = timeStr.substr(0,5).split(':').map(Number);
        let date = new Date();
        date.setHours(h);
        date.setMinutes(m + mins);
        return date.toLocaleTimeString('pl-PL', {hour:'2-digit', minute:'2-digit'});
    }

    function diffMinutes(plan, rzecz) {
        if (!plan || !rzecz) return 0;
        // Bierzemy tylko pełne godziny i minuty z pominięciem sekund
        let [hp, mp] = plan.substr(0,5).split(':').map(Number);
        let [hr, mr] = rzecz.substr(0,5).split(':').map(Number);
        let minPlan = hp * 60 + mp;
        let minRzecz = hr * 60 + mr;
        let diff = minRzecz - minPlan;
        if (diff < -720) diff += 1440;
        if (diff > 720) diff -= 1440;
        return diff;
    }

    function openTab(name) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + name).classList.add('active');
    }

    function toggleDates() {
        const isFull = document.getElementById('chk-daty').checked;
        document.querySelectorAll('.t-cell').forEach(td => {
            td.innerText = isFull ? td.dataset.full : td.dataset.short;
        });
    }

    function selectRow(tr, idSzczegolu, idPrzejazdu) {
        document.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
        tr.classList.add('selected');
        
        currentId = idSzczegolu;
        currentPrzejazdId = idPrzejazdu;

        currentData = {
            info: tr.dataset.info,
            planP: tr.dataset.planP, planO: tr.dataset.planO,
            rzeczP: tr.dataset.rzeczP, rzeczO: tr.dataset.rzeczO,
            z: tr.dataset.z,
            do: tr.dataset.do,
            rodzaj: tr.dataset.rodzaj,
            rodzajPelna: tr.getAttribute('data-rodzaj-pelna') || tr.dataset.rodzaj,
            peron: tr.getAttribute('data-peron'),
            tor: tr.getAttribute('data-tor'),
            numer: tr.getAttribute('data-numer'),
            nazwa: tr.getAttribute('data-nazwa'),
            opoznienie: tr.getAttribute('data-opoznienie')
        };

        document.getElementById('lbl-z').innerText = tr.dataset.z || '---';
        document.getElementById('lbl-do').innerText = tr.dataset.do || '---';

        pobierzDaneTrasy(idPrzejazdu);
        ladujKodyOpoznien(idPrzejazdu);
    }

   function pobierzDaneTrasy(idPrzejazdu) {
        fetch('pobierz_dane.php?id_przejazdu=' + idPrzejazdu + '&nocache=' + new Date().getTime())
            .then(res => res.json())
            .then(data => {
                currentData.trasa = data.trasa || [];

                if (data.opis) {
                    const o = data.opis;
                    document.getElementById('op-nr').innerText = o.numer_pociagu;
                    document.getElementById('op-nazwa').innerText = o.nazwa_pociagu;
                    document.getElementById('op-rodzaj').innerText = o.typ_nazwa;
                    document.getElementById('op-przew').innerText = o.przewoznik_nazwa;
                    document.getElementById('op-start').innerText = o.stacja_pocz;
                    document.getElementById('op-koniec').innerText = o.stacja_konc;
                    document.getElementById('op-symbole').innerText = o.symbole || '';
                }

                const tbody = document.getElementById('trasa-body');
                tbody.innerHTML = '';

                let biezaceOpoznienie = 0; 

                if (data.trasa) {
                    data.trasa.forEach((t, i) => {
                        let delayP = '', styleP = 'bg-blue t-cell', classDiffP = '';
                        let delayO = '', styleO = 'bg-blue t-cell', classDiffO = '';
                        let displayP = '';
                        let displayO = '';

                        // Kluczowa zmiana: ufamy TYLKO stacjom, które dyżurny fizycznie zatwierdził ptaszkiem ("Z")
                        let isApproved = (t.zatwierdzony == 1);

                        const calcDiff = (plan, rzecz) => {
                            if(!plan || !rzecz) return 0;
                            // Wycinamy tylko godziny i minuty, absolutnie ignorujemy sekundy!
                            let t1 = plan.split(':');
                            let t2 = rzecz.split(':');
                            let s1 = (parseInt(t1[0],10)||0)*60 + (parseInt(t1[1],10)||0);
                            let s2 = (parseInt(t2[0],10)||0)*60 + (parseInt(t2[1],10)||0);
                            let d = s2 - s1;
                            if(d < -720) d += 1440; if(d > 720) d -= 1440;
                            return d; // Wynik od razu w pełnych minutach
                        };

                        // --- LOGIKA PRZYJAZDU ---
                        if (isApproved) {
                            // Pociąg był tu fizycznie - bierzemy czas z bazy (tylko to nadaje ton opóźnieniu!)
                            if (t.przyjazd_rzecz && t.przyjazd_rzecz.length >= 5) {
                                displayP = t.przyjazd_rzecz.substr(0,5);
                                if (t.przyjazd) biezaceOpoznienie = calcDiff(t.przyjazd, t.przyjazd_rzecz);
                            } else if (t.przyjazd) {
                                displayP = t.przyjazd.substr(0,5);
                            }
                        } else {
                            // Przyszłość - CAŁKOWICIE IGNORUJEMY BAZĘ. 
                            // Na sztywno dodajemy wyliczone opóźnienie z poprzedniej stacji do czasu planowego.
                            if (t.przyjazd) {
                                displayP = addMinutes(t.przyjazd, Math.round(biezaceOpoznienie));
                                styleP += ' forecast';
                            }
                        }

                        // Obliczenie etykiety opóźnienia przyjazdu (+/-) do wyświetlenia
                        let finalDiffP = 0;
                        if (displayP && t.przyjazd) finalDiffP = calcDiff(t.przyjazd, displayP + ":00");
                        if (Math.round(finalDiffP) != 0) {
                            delayP = (finalDiffP > 0 ? '+' : '') + Math.round(finalDiffP);
                            classDiffP = finalDiffP > 0 ? 'delay-red' : 'delay-green';
                        }

                        // --- LOGIKA ODJAZDU ---
                        if (isApproved) {
                            // Pociąg odjechał stąd fizycznie
                            if (t.odjazd_rzecz && t.odjazd_rzecz.length >= 5) {
                                displayO = t.odjazd_rzecz.substr(0,5);
                                if (t.odjazd) biezaceOpoznienie = calcDiff(t.odjazd, t.odjazd_rzecz); 
                            } else if (t.odjazd) {
                                displayO = t.odjazd.substr(0,5);
                            }
                        } else {
                            // Przyszłość - dodajemy na sztywno opóźnienie do planu
                            if (t.odjazd) {
                                displayO = addMinutes(t.odjazd, Math.round(biezaceOpoznienie));
                                styleO += ' forecast';
                            }
                        }

                        // Obliczenie etykiety opóźnienia odjazdu (+/-)
                        let finalDiffO = 0;
                        if (displayO && t.odjazd) finalDiffO = calcDiff(t.odjazd, displayO + ":00");
                        if (Math.round(finalDiffO) != 0) {
                            delayO = (finalDiffO > 0 ? '+' : '') + Math.round(finalDiffO);
                            classDiffO = finalDiffO > 0 ? 'delay-red' : 'delay-green';
                        }

                        // Czas postoju (Zamierzony / Obliczony)
                        let postojZam = '';
                        if (t.przyjazd && t.odjazd) {
                            let p = calcDiff(t.przyjazd, t.odjazd);
                            if (p > 0) postojZam = parseFloat(p.toFixed(1)); 
                        }
                        let postojObl = '';
                        if (displayP && displayO) {
                             let p = calcDiff(displayP + ':00', displayO + ':00');
                             if (p > 0) postojObl = parseFloat(p.toFixed(1));
                        }

                        let rowClass = isApproved ? 'row-approved' : '';
                        
                        let row = `<tr class="${rowClass}" onclick="selectRow(this, ${t.id_szczegolu}, ${idPrzejazdu})" 
                                    ondblclick="openModal()"
                                    data-info="${t.numer_pociagu}" 
                                    data-plan-p="${t.przyjazd ? t.przyjazd.substr(0,5) : ''}" 
                                    data-plan-o="${t.odjazd ? t.odjazd.substr(0,5) : ''}"
                                    data-rzecz-p="${displayP}" 
                                    data-rzecz-o="${displayO}"
                                    data-z="${data.opis.stacja_pocz}" data-do="${data.opis.stacja_konc}"
                                    data-rodzaj="${t.rodzaj}"
                                    data-opoznienie="${Math.round(biezaceOpoznienie)}">
                                    
                            <td class="${classDiffP}">${delayP}</td>
                            <td class="center"><input type="checkbox" ${t.zatwierdzony == 1 ? 'checked' : ''} disabled></td>
                            <td class="center">${i+1}</td>
                            <td><b>${t.nazwa_stacji}</b></td>
                            <td class="bg-gray">${t.tor || ''}</td>
                            <td class="bg-gray">${t.peron || ''}</td>
                            <td class="center">${postojZam}</td>
                            <td class="center">${postojObl}</td>
                            <td class="center">${t.uwagi_postoju || ''}</td>
                            <td class="bg-time t-cell">${t.przyjazd ? t.przyjazd.substr(0,5) : ''}</td>
                            <td class="${styleP}">${displayP}</td>
                            <td class="bg-time t-cell">${t.odjazd ? t.odjazd.substr(0,5) : ''}</td>
                            <td class="${styleO}">${displayO}</td>
                            <td class="${classDiffO}">${delayO}</td>
                            <td class="center">${data.opis ? data.opis.rodzaj_skrot : ''}</td>
                        </tr>`;
                        tbody.innerHTML += row;
                    });
                }
            });
    }

    function openModal() {
        if (!currentId) { alert("Wybierz pociąg."); return; }
        document.getElementById('modalGodziny').style.display = 'block';
        document.getElementById('modal-id').value = currentId;
        document.getElementById('modal-title').innerText = "Pociąg: " + currentData.info;
        document.getElementById('lbl-plan-p').innerText = currentData.planP;
        document.getElementById('lbl-plan-o').innerText = currentData.planO;
        document.getElementById('inp-p').value = currentData.rzeczP || currentData.planP;
        document.getElementById('inp-o').value = currentData.rzeczO || currentData.planO;
    }

    function closeModal() { document.getElementById('modalGodziny').style.display = 'none'; }
    function copyTime() { document.getElementById('inp-o').value = document.getElementById('inp-p').value; }
    
    function addMin(id, m) {
        let el = document.getElementById(id);
        if(!el.value) return;
        let [hh, mm] = el.value.split(':').map(Number);
        let d = new Date(); d.setHours(hh); d.setMinutes(mm + m);
        el.value = d.toLocaleTimeString('pl-PL', {hour:'2-digit', minute:'2-digit'});
    }

    function saveTimes(e) {
        e.preventDefault();
        const fd = new FormData(document.getElementById('formTimes'));
        
        fetch('zapisz_czas.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(res => {
            if (res === 'OK') { 
                closeModal(); 
                
                // 1. Odświeżamy dolną tabelę "Trasa pociągu"
                if (currentPrzejazdId) pobierzDaneTrasy(currentPrzejazdId);

                // 2. MAGIA: Odświeżamy główny "Wykaz pociągów" w tle bez przeładowywania strony!
                fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Podmieniamy samą zawartość głównej tabeli na nową, prosto z bazy
                    document.querySelector('#tab-wykaz').innerHTML = doc.querySelector('#tab-wykaz').innerHTML;
                    
                    // Przywracamy granatowe podświetlenie wybranego pociągu, żeby nie zniknęło
                    if (currentId) {
                        const zaznaczonyWiersz = document.querySelector(`tr[onclick*="selectRow(this, ${currentId}"]`);
                        if (zaznaczonyWiersz) {
                            zaznaczonyWiersz.classList.add('selected');
                        }
                    }
                });
                
            } else { 
                alert(res); 
            }
        });
    }

    document.addEventListener('keydown', e => { if(e.key === 'F2') openModal(); });

    // --- SYSTEM ZAPOWIEDZI MEGAFONOWYCH ---

    const trainTypes = {
        'IC': 'InterCity', 'TLK': 'Twoje Linie Kolejowe', 'EIP': 'Express InterCity Premium', 
        'EIC': 'Express InterCity', 'R': 'Regio', 'Os': 'Osobowy', 'RP': 'Przyspieszony', 
        'KD': 'Kolei Dolnośląskich', 'KS': 'Kolei Śląskich', 'SKM': 'Szybkiej Kolei Miejskiej',
        'LS': 'Łódzkiej Kolei Aglomeracyjnej Sprinter', 'IR': 'InterRegio'
    };

    const TEMPLATES = {
        'wjazd': {
            'poczatkowa_std': 'Pociąg {rodzaj} {nazwa}, do stacji {do}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu. Planowy odjazd pociągu, o godzinie {czas}.',
            'posrednia_std': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu.',
            'posrednia_1peron_1tor': 'Uwaga! Wjedzie pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu.',
            'konczy': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, wjedzie na tor {tor}, przy peronie {peron}. Pociąg kończy bieg. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu.',
            'wielogrupowy': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, i {do_grupy}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Wagony do stacji {do}, znajdują się {gdzie_wagony}, wagony do stacji {do_grupy}, znajdują się {gdzie_wagony_grupa}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu.',
            'przyspieszony': 'Przyśpieszony pociąg osobowy {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Pociąg zatrzymuje się, na niektórych stacjach. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu.',
            'zmiana_peronu': 'Uwaga! Zmiana peronu. Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, wjedzie wyjątkowo na tor {tor}, przy peronie {peron}. Osoby oczekujące na ten pociąg, proszone są o przejście na peron {peron}. Za zmianę peronu, przepraszamy.',
            'przelot': 'Uwaga! Po torze {tor}, przy peronie {peron}, przejedzie pociąg {rodzaj} {nazwa}, bez zatrzymania. Prosimy zachować ostrożność, i odsunąć się od krawędzi peronu.',
            'zmiana_kategorii': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Od stacji {stacja_zmiany_kat}, pociąg zmienia kategorię na {nowa_kategoria}. Planowy odjazd pociągu, o godzinie {czas}.'
        },
        'odjazd_postoj': {
            'odjazd_std': 'Pociąg {rodzaj} {nazwa}, do stacji {do}, przez stacje, {posrednie}, odjedzie z toru {tor}, przy peronie {peron}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu. Życzymy Państwu, przyjemnej podróży.',
            'stoi_std': 'Pociąg {rodzaj} {nazwa}, do stacji {do}, przez stacje, {posrednie}, stoi na torze {tor}, przy peronie {peron}. Planowy odjazd pociągu, o godzinie {czas}.',
            'wielogrupowy_odjazd': 'Pociąg {rodzaj} {nazwa}, do stacji {do}, i {do_grupy}, przez stacje, {posrednie}, odjedzie z toru {tor}, przy peronie {peron}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu. Życzymy Państwu, przyjemnej podróży.',
            'wielogrupowy_stoi': 'Pociąg {rodzaj} {nazwa}, do stacji {do}, i {do_grupy}, przez stacje, {posrednie}, stoi na torze {tor}, przy peronie {peron}. Wagony do stacji {do}, znajdują się {gdzie_wagony}, wagony do stacji {do_grupy}, znajdują się {gdzie_wagony_grupa}. Planowy odjazd pociągu, o godzinie {czas}.',
            'dolaczanie_wagonow': 'Uwaga! Do pociągu {rodzaj} {nazwa}, stojącego na torze {tor}, przy peronie {peron}, zostaną dołączone wagony, do stacji {do_grupy}. Wagony będą dołączone {poczatek_koniec} składu pociągu. Prosimy zachować ostrożność.'
        },
        'rezerwacja': {
            'wjazd_cala': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Pociąg jest objęty obowiązkową rezerwacją miejsc. Wagony numer {wagony_poczatek}, znajdują się na początku składu pociągu, wagony numer {wagony_srodek}, znajdują się w środku składu pociągu, wagony numer {wagony_koniec}, znajdują się na końcu składu pociągu. Przesyłki konduktorskie, przyjmuje i wydaje kierownik pociągu, w wagonie numer {wagon_kier}. Prosimy zachować ostrożność i nie zbliżać się do krawędzi peronu.',
            'wjazd_klasa1': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}, wjedzie na tor {tor}, przy peronie {peron}. Wagony klasy pierwszej, objęte są obowiązkową rezerwacją miejsc, i znajdują się na {poczatek_koniec} składu pociągu. Przesyłki konduktorskie, przyjmuje kierownik pociągu, w wagonie numer {wagon_kier}. Prosimy zachować ostrożność i nie zbliżać się do krawędzi peronu.',
            'wagony_poza_peronem': 'Uwaga! Ze względu na długość składu, wagony z numerami {wagony_poza}, zatrzymają się poza peronem. Podróżnych posiadających miejsca w tych wagonach, prosimy o wsiadanie do pociągu poprzez wagony stojące przy peronie, i przejście przez skład na swoje miejsce.'
        },
        'opoznienie': {
            'podstawienie': 'Pociąg {rodzaj} {nazwa}, do stacji {do}, odjeżdżający o godzinie {czas}, z przyczyn technicznych, zostanie podstawiony z opóźnieniem około {opoznienie} minut. O wjeździe pociągu, zostaną Państwo powiadomieni oddzielnym komunikatem. Za opóźnienie pociągu, przepraszamy.',
            'w_trasie_wjazd': 'Uwaga! Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, planowy przyjazd o godzinie {czas}, przyjedzie z opóźnieniem około {opoznienie} minut. Opóźnienie, może ulec zmianie. Prosimy o zwracanie uwagi na komunikaty. Za opóźnienie pociągu, przepraszamy.',
            'wypadek': 'Informujemy, że pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, planowy przyjazd o godzinie {czas}, przyjedzie z opóźnieniem około {opoznienie} minut. Przyczyną opóźnienia, jest zdarzenie na torach. Opóźnienie pociągu, może ulec zmianie.',
            'pogoda': 'Informujemy, że pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, planowy przyjazd o godzinie {czas}, przyjedzie z opóźnieniem około {opoznienie} minut. Zakłócenia w ruchu, wywołane są trudnymi warunkami atmosferycznymi. Opóźnienie pociągu, może ulec zmianie.',
            'wjazd_opoznionego': 'Opóźniony pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, przez stacje, {posrednie}, planowy przyjazd o godzinie {czas}, wjedzie na tor {tor}, przy peronie {peron}. Prosimy zachować ostrożność, i nie zbliżać się do krawędzi peronu. Za opóźnienie pociągu, przepraszamy.',
            'wjazd_opozniony_konczy': 'Opóźniony pociąg {rodzaj} {nazwa}, ze stacji {z}, planowy przyjazd o godzinie {czas}, wjedzie na tor {tor}, przy peronie {peron}. Pociąg kończy bieg. Prosimy zachować ostrożność. Za opóźnienie pociągu, przepraszamy.',
            'odjazd_opoznionego': 'Opóźniony pociąg {rodzaj} {nazwa}, do stacji {do}, przez stacje, {posrednie}, planowy odjazd o godzinie {czas}, odjedzie z toru {tor}, przy peronie {peron}. Prosimy zachować ostrożność. Za opóźnienie przepraszamy.',
            'oczekiwanie_skomunikowanie': 'Uwaga! Pociąg {rodzaj} {nazwa}, do stacji {do}, odjedzie z opóźnieniem około {opoznienie} minut, z powodu oczekiwania na skomunikowany pociąg. Za opóźnienie pociągu, przepraszamy.'
        },
        'zaklocenia': {
            'odwolany_calkowicie': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, planowy odjazd o godzinie {czas}, został dziś odwołany. Za odwołanie pociągu, przepraszamy.',
            'skrocony_trasa': 'Informujemy, że dziś, z przyczyn technicznych, pociąg {rodzaj} {nazwa}, do stacji {do}, planowy odjazd o godzinie {czas}, kursuje w relacji skróconej, do stacji {skrocona_stacja}. Za utrudnienia w podróży, przepraszamy.',
            'awaria_koniec_biegu': 'Pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, stojący na torze {tor}, przy peronie {peron}, z przyczyn technicznych, skończył bieg. Podróżni proszeni są, o opuszczenie pociągu. Za utrudnienia w podróży, przepraszamy.',
            'droga_okrezna': 'Informujemy, że pociąg {rodzaj} {nazwa}, ze stacji {z}, do stacji {do}, zostanie skierowany drogą okrężną. Wydłuży to czas jazdy, o około {opoznienie} minut. Za utrudnienia w podróży, przepraszamy.',
            'brak_wagonu': 'Informujemy, że dziś z przyczyn technicznych, pociąg nie prowadzi wagonu {brak_wagonu}, w relacji do stacji {do_grupy}. Podróżnych prosimy o zajęcie miejsc w wagonie zastępczym {zastepczy_wagon}, znajdującym się na {poczatek_koniec} składu pociągu. Za utrudnienia, przepraszamy.'
        },
        'komunikacja_zastepcza': {
            'zawieszenie_ruchu': 'Informujemy, że z powodu utrudnień na szlaku, ruch pociągów na odcinku {zka_z} - {zka_do}, został zawieszony. Przewóz podróżnych realizowany jest autobusami komunikacji zastępczej. Autobusy odjeżdżają z {miejsce_kz}. Za utrudnienia przepraszamy.',
            'odjazd_kz': 'Autobus komunikacji zastępczej do stacji {do}, przez, {posrednie}, odjedzie z {miejsce_kz}. Życzymy Państwu, przyjemnej podróży.',
            'przyjazd_kz': 'Autobus komunikacji zastępczej ze stacji {z}, do stacji {do}, przez, {posrednie}, zatrzyma się, {miejsce_kz}.'
        },
        'bezpieczenstwo_inne': {
            'bagaz': 'W trosce o bezpieczeństwo, prosimy o niepozostawianie bagażu bez opieki. Bagaż pozostawiony bez opieki, zostanie usunięty, i może być zniszczony, na koszt właściciela.',
            'palenie': 'Szanowni Państwo. Informujemy, że na terenie całego dworca i peronów, obowiązuje całkowity zakaz palenia wyrobów tytoniowych, i papierosów elektronicznych.',
            'odstep': 'Prosimy o zachowanie bezpiecznej odległości, od krawędzi peronu.',
            'napoje': 'Uwaga! W związku z utrudnieniami w ruchu pociągów, pasażerowie oczekujący na opóźnione pociągi, mogą skorzystać z napojów, wydawanych bezpłatnie. Podstawą otrzymania napoju, jest okazanie ważnego biletu na przejazd.',
            'najblizszy_pociag': 'Informujemy, że najbliższy pociąg do stacji {do}, odjedzie o godzinie {czas}, z toru {tor}, przy peronie {peron}.'
        }
    };


    function closeAnnounceModal() { 
        document.getElementById('modalZapowiedz').style.display = 'none'; 
    }

    function updateTemplates() {
        const catEl = document.getElementById('annCat');
        const varSelect = document.getElementById('annVar');
        if (!catEl || !varSelect) return;

        const cat = catEl.value;
        varSelect.innerHTML = '';
        
        const variants = TEMPLATES[cat];
        if (!variants) return;

        // Rozpoznanie, czy stacja pośrednia (na wypadek ręcznej zmiany kategorii przez dyżurnego)
        let isPosrednia = false;
        if (currentData) {
            const selectStacja = document.getElementById('selectStacja');
            const obecnaStacja = selectStacja ? selectStacja.options[selectStacja.selectedIndex].text.trim() : "";
            if (currentData.z && obecnaStacja && currentData.z !== obecnaStacja) {
                isPosrednia = true;
            }
        }

        // Ładujemy warianty
        for (const key in variants) {
            let label = key.replace(/_/g, ' ').toUpperCase();
            let opt = document.createElement('option');
            opt.value = key;
            opt.innerText = label;
            
            // PRIORYTET 1: Logika wyliczona w openAnnounceModal (window.forcedVariant)
            if (window.forcedVariant && key === window.forcedVariant) {
                opt.selected = true;
            } 
            // PRIORYTET 2: Jeśli dyżurny zmienił kategorię ręcznie i nie ma wymuszonego wariantu, ratujemy się stacją pośrednią
            else if (!window.forcedVariant && isPosrednia) {
                if (cat === 'wjazd' && key === 'posrednia_std') opt.selected = true;
                else if (cat === 'opoznienie' && key === 'w_trasie_wjazd') opt.selected = true;
            }
            
            varSelect.appendChild(opt);
        }

        // PRIORYTET 3: Fallback z samej góry listy, jeśli nic się nie dopasowało
        if (varSelect.selectedIndex === -1 && varSelect.options.length > 0) {
            varSelect.options[0].selected = true;
        }

        // Czyścimy flagę, żeby dyżurny mógł normalnie klikać inne opcje, gdyby zmienił zdanie
        window.forcedVariant = null; 

        // Generujemy tekst zapowiedzi od razu
        generateAnnouncement();
    }

    function openAnnounceModal() {
        if (!currentId || !currentData || Object.keys(currentData).length === 0) { 
            alert("Najpierw wybierz pociąg z listy głównej!"); 
            return; 
        }
        
        document.getElementById('modalZapowiedz').style.display = 'block';
        document.getElementById('zapowiedz-info').innerText = `${currentData.rodzaj || ''} ${currentData.numer || ''} (${currentData.z || ''} - ${currentData.do || ''})`;
        
        document.getElementById('annPeron').value = currentData.peron || '';
        document.getElementById('annTor').value = currentData.tor || '';
        document.getElementById('annDelay').value = currentData.opoznienie > 0 ? currentData.opoznienie : '';
        document.getElementById('annTime').value = currentData.planO || currentData.planP || '';
        
        try { 
            if (typeof aktualizujWyswietlacz === 'function') aktualizujWyswietlacz(); 
        } catch(e) {}

        // --- AUTOMATYCZNY WYBÓR KATEGORII W OPARCIU O CZAS RZECZYWISTY ---
        const catEl = document.getElementById('annCat');
        const opoznienie = parseInt(currentData.opoznienie) || 0;
        const rodzaj = currentData.rodzaj || '';
        const pociagiZRezerwacja = ['IC', 'EIC', 'EIP', 'TLK']; 

        // 1. Wyciągamy aktualny czas w minutach od północy
        const now = new Date();
        const nowMins = now.getHours() * 60 + now.getMinutes();

        const parseTime = (t) => { if(!t) return null; let [h,m] = t.substr(0,5).split(':').map(Number); return h * 60 + m; };
        const getDiffMins = (target, current) => { let d = target - current; if (d < -720) d += 1440; if (d > 720) d -= 1440; return d; };

        // Realny czas pociągu (plan + opóźnienie)
        const effPMins = parseTime(currentData.planP) !== null ? parseTime(currentData.planP) + opoznienie : null;
        const effOMins = parseTime(currentData.planO) !== null ? parseTime(currentData.planO) + opoznienie : null;

        const diffP = effPMins !== null ? getDiffMins(effPMins, nowMins) : null; 
        const diffO = effOMins !== null ? getDiffMins(effOMins, nowMins) : null;

        // Określamy typ stacji
        const selectStacja = document.getElementById('selectStacja');
        const obecnaStacja = selectStacja ? selectStacja.options[selectStacja.selectedIndex].text.trim() : "";
        const isStart = (currentData.z && currentData.z === obecnaStacja);
        const isEnd = (currentData.do && currentData.do === obecnaStacja);
        const isPosrednia = (!isStart && !isEnd);

        let autoCat = 'wjazd';
        window.forcedVariant = null; 

        if (opoznienie > 5) {
            autoCat = 'opoznienie';
            // Inteligencja dla opóźnień:
            if (isEnd) {
                window.forcedVariant = 'wjazd_opozniony_konczy'; // Gdy opóźniony kończy u nas bieg
            } else if (isStart) {
                window.forcedVariant = 'podstawienie'; // Gdy opóźniony startuje od nas
            } else {
                window.forcedVariant = 'w_trasie_wjazd'; // Gdy opóźniony jest u nas w trasie (pośrednia)
            }
        } else if (pociagiZRezerwacja.includes(rodzaj)) {
            autoCat = 'rezerwacja';
        } else {
            // KORELACJA Z CZASEM
            if (isEnd) {
                autoCat = 'wjazd';
                window.forcedVariant = 'konczy';
            } 
            else if (isStart) {
                if (diffO !== null) {
                    if (diffO <= 2 && diffO >= -5) {
                        autoCat = 'odjazd_postoj';
                        window.forcedVariant = 'odjazd_std';
                    } else {
                        autoCat = 'wjazd';
                        window.forcedVariant = 'poczatkowa_std';
                    }
                }
            } 
            else if (isPosrednia) {
                if (diffP !== null && diffO !== null) {
                    let stopDuration = effOMins - effPMins;
                    if (stopDuration < -720) stopDuration += 1440; 
                    
                    if (stopDuration > 2) {
                        // POSTÓJ > 2 MINUTY - Pełna korelacja
                        if (diffP > 0) {
                            autoCat = 'wjazd';
                            window.forcedVariant = 'posrednia_std';
                        } else if (diffP <= 0 && diffO > 2) {
                            autoCat = 'odjazd_postoj';
                            window.forcedVariant = 'stoi_std';
                        } else if (diffO <= 2 && diffO >= -5) {
                            autoCat = 'odjazd_postoj';
                            window.forcedVariant = 'odjazd_std';
                        } else {
                            autoCat = 'wjazd';
                            window.forcedVariant = 'posrednia_std';
                        }
                    } else {
                        // KRÓTKI POSTÓJ (<= 2 min)
                        // Żelazna zasada: bezwzględnie wjazd, bo nie ma czasu na nic innego
                        autoCat = 'wjazd';
                        window.forcedVariant = 'posrednia_std';
                    }
                }
            }
        }
    
        catEl.value = autoCat;
        updateTemplates();
    }

    function generateAnnouncement() {
        try {
            if (!currentData || Object.keys(currentData).length === 0) {
                document.getElementById('announceText').innerText = "Wybierz pociąg z głównej tabeli.";
                return;
            }

            const catEl = document.getElementById('annCat');
            const varEl = document.getElementById('annVar');
            if (!catEl || !varEl) return;

            const cat = catEl.value;
            let variant = varEl.value;

            if (!variant && varEl.options.length > 0) {
                variant = varEl.options[0].value;
            }

            const d = currentData;

            // --- AUTOMATYKA STACJI KOŃCOWEJ ---
            const selectStacja = document.getElementById('selectStacja');
            const currentStationName = selectStacja && selectStacja.options[selectStacja.selectedIndex] ? selectStacja.options[selectStacja.selectedIndex].text.trim() : "";
            
            if (cat === 'wjazd' && currentStationName === d.do && TEMPLATES['wjazd']['konczy']) {
                variant = 'konczy'; 
                varEl.value = 'konczy';
            }

            if (!TEMPLATES[cat] || !TEMPLATES[cat][variant]) {
                document.getElementById('announceText').innerText = "Brak pasującego szablonu. Sprawdź konsolę F12.";
                return;
            }

            let tpl = TEMPLATES[cat][variant];

            // --- NAPRAWA CZYTANIA NUMERÓW WAGONÓW ---
            // Zmienia np. "526,425" na "526, 425", żeby lektor nie czytał tego jako jednego ułamka
            const formatNums = str => str ? str.replace(/,/g, ', ').replace(/\s+/g, ' ').trim() : '';

            const peronInput = document.getElementById('annPeron') ? document.getElementById('annPeron').value : '';
            const torInput = document.getElementById('annTor') ? document.getElementById('annTor').value : '';
            const delay = document.getElementById('annDelay') ? document.getElementById('annDelay').value : '';
            // Pobieramy wpisaną wartość i zamieniamy na liczbę całkowitą
            const rawDelayInput = document.getElementById('annDelay') ? document.getElementById('annDelay').value : '';
            const rawDelay = parseInt(rawDelayInput);
            let roundedDelay = "";

            if (!isNaN(rawDelay) && rawDelay > 0) {
                // Matematyczne zaokrąglenie do najbliższej wielokrotności 5
                // (11->10, 12->10, 13->15, 7->5, 9->10)
                roundedDelay = Math.round(rawDelay / 5) * 5;
                
                // Zgodnie z Twoją prośbą: jeśli opóźnienie jest mniejsze niż 5, 
                // ale większe od 0, zawsze pokazujemy "5"
                if (roundedDelay < 5) {
                    roundedDelay = 5;
                }
            }
            const time = document.getElementById('annTime') ? document.getElementById('annTime').value : '';
            
            const skrocona = document.getElementById('annShort') ? document.getElementById('annShort').value : '';
            const do_grupy = document.getElementById('annGroupStation') ? document.getElementById('annGroupStation').value : '';
            const stacja_zmiany_kat = document.getElementById('annChangeCatStation') ? document.getElementById('annChangeCatStation').value : '';
            const nowa_kategoria = document.getElementById('annNewCat') ? document.getElementById('annNewCat').value : '';
            
            const poczatek_koniec = document.getElementById('annWagonsPos') ? document.getElementById('annWagonsPos').value : 'na początku';
            
            const wagony_poczatek = formatNums(document.getElementById('annWagonsFront') ? document.getElementById('annWagonsFront').value : '');
            const wagony_srodek = formatNums(document.getElementById('annWagonsMiddle') ? document.getElementById('annWagonsMiddle').value : '');
            const wagony_koniec = formatNums(document.getElementById('annWagonsRear') ? document.getElementById('annWagonsRear').value : '');
            const wagon_kier = formatNums(document.getElementById('annManagerWagon') ? document.getElementById('annManagerWagon').value : '');
            const brak_wagonu = formatNums(document.getElementById('annMissingWagon') ? document.getElementById('annMissingWagon').value : '');
            const zastepczy_wagon = formatNums((document.getElementById('annReplacementWagon') && document.getElementById('annReplacementWagon').value) ? document.getElementById('annReplacementWagon').value : "w pozostałych wagonach"); 
            
            const miejsce_kz = (document.getElementById('annBusStop') && document.getElementById('annBusStop').value) ? document.getElementById('annBusStop').value : 'placu przed dworcem';
            
            // Pobieranie wpisanych stacji dla ZKA
            let zka_z = document.getElementById('annZkaZ') ? document.getElementById('annZkaZ').value : '';
            let zka_do = document.getElementById('annZkaDo') ? document.getElementById('annZkaDo').value : '';
            
            // Jeśli pola są puste, bierzemy domyślną relację pociągu
            if (!zka_z) zka_z = d.z || '';
            if (!zka_do) zka_do = d.do || '';

            let gdzie_wagony_grupa = 'na końcu';
            if (poczatek_koniec === 'na początku') gdzie_wagony_grupa = 'na końcu';
            if (poczatek_koniec === 'na końcu') gdzie_wagony_grupa = 'na początku';

            // --- INTELIGENTNA ODMIANA TORÓW I PERONÓW ---
            const getOdmiana = (numer) => {
                const odmiany = {
                    '1': { m: 'pierwszy', b: 'pierwszy', ms: 'pierwszym', d: 'pierwszego' },
                    '2': { m: 'drugi', b: 'drugi', ms: 'drugim', d: 'drugiego' },
                    '3': { m: 'trzeci', b: 'trzeci', ms: 'trzecim', d: 'trzeciego' },
                    '4': { m: 'czwarty', b: 'czwarty', ms: 'czwartym', d: 'czwartego' },
                    '5': { m: 'piąty', b: 'piąty', ms: 'piątym', d: 'piątego' },
                    '6': { m: 'szósty', b: 'szósty', ms: 'szóstym', d: 'szóstego' },
                    '7': { m: 'siódmy', b: 'siódmy', ms: 'siódmym', d: 'siódmego' },
                    '8': { m: 'ósmy', b: 'ósmy', ms: 'ósmym', d: 'ósmego' },
                    '9': { m: 'dziewiąty', b: 'dziewiąty', ms: 'dziewiątym', d: 'dziewiątego' },
                    '10': { m: 'dziesiąty', b: 'dziesiąty', ms: 'dziesiątym', d: 'dziesiątego' }
                };
                let num = String(numer || '').trim();
                return odmiany[num] || { m: num, b: num, ms: num, d: num };
            };

            let torOdm = getOdmiana(torInput);
            let peronOdm = getOdmiana(peronInput);

            // --- LOGIKA DOBIERANIA STACJI POŚREDNICH (MEGAFON) ---
            
            // --- IDENTYCZNA LOGIKA JAK W WYSWIETLACZU PERONOWYM (NAPRAWA PĘTLI) ---
            let stacjeTekst = "";
            if (d.trasa && Array.isArray(d.trasa) && d.trasa.length > 0) {
                
                // KLUCZOWA ZMIANA: Szukamy po unikalnym ID postoju, a nie po nazwie!
                // Dzięki temu system wie, że to pierwsze Dąbie, a nie drugie.
                let currentIndex = d.trasa.findIndex(t => t.id_szczegolu == currentId);
                
                // Zabezpieczenie: jeśli z jakiegoś powodu nie znalazł po ID, szuka po nazwie ORAZ godzinie
                if (currentIndex === -1) {
                    currentIndex = d.trasa.findIndex(t => 
                        (t.id_stacji == stacjaId || t.nazwa_stacji == currentStationName) && 
                        (t.odjazd?.substr(0,5) === d.planO || t.przyjazd?.substr(0,5) === d.planP)
                    );
                }
                
                if (currentIndex > -1 && currentIndex < d.trasa.length - 1) {
                    // Bierzemy wszystkie stacje do samego końca trasy
                    let futureStops = d.trasa.slice(currentIndex + 1); 
                    
                    // FILTR BAZOWY: Bierzemy TYLKO te, gdzie pociąg ma postój handlowy (ph) i nie są stacją końcową
                    futureStops = futureStops.filter(s => s.uwagi_postoju === 'ph' && s.nazwa_stacji !== d.do);
                    
                    let selectedStations = [];
                    let addedKolejnosc = []; 

                    // --- TWÓJ PRZEŁĄCZNIK W KODZIE ---
                    // true  = system będzie dopychał listę małymi przystankami, jeśli stacji jest mniej niż 5
                    // false = system zatrzyma się tylko na głównych stacjach i zignoruje małe przystanki
                    const DOBIERAJ_MALE_PRZYSTANKI = false; 

                    // KROK 1: Najpierw te z wymuszonym zapowiadaniem (czy_zapowiadac = 1) - BEZ LIMITU!
                    // KROK 1: Najpierw te z wymuszonym zapowiadaniem (czy_zapowiadac = 1) - BEZ LIMITU!
                    futureStops.forEach(s => {
                        if (s.czy_zapowiadac == 1 && !addedKolejnosc.includes(s.kolejnosc)) {
                            selectedStations.push(s);
                            addedKolejnosc.push(s.kolejnosc);
                        }
                    });

                    // KROK 2: Potem stacje węzłowe (typ_stacji_id = 1) - dopełniamy do 5
                    futureStops.forEach(s => {
                        if (selectedStations.length >= 5) return;
                        if (s.typ_stacji_id == 1 && !addedKolejnosc.includes(s.kolejnosc)) {
                            selectedStations.push(s);
                            addedKolejnosc.push(s.kolejnosc);
                        }
                    });

                    // KROK 3: Pozostałe stacje, mijanki itp. (wszystko co NIE JEST typem 2) - dopełniamy do 5
                    futureStops.forEach(s => {
                        if (selectedStations.length >= 5) return;
                        if (s.typ_stacji_id != 2 && !addedKolejnosc.includes(s.kolejnosc)) {
                            selectedStations.push(s);
                            addedKolejnosc.push(s.kolejnosc);
                        }
                    });

                    // KROK 4: Dopychanie małymi przystankami (typ_stacji_id = 2) - TYLKO JEŚLI ZMIENNA = TRUE
                    if (DOBIERAJ_MALE_PRZYSTANKI) {
                        futureStops.forEach(s => {
                            if (selectedStations.length >= 5) return;
                            if (s.typ_stacji_id == 2 && !addedKolejnosc.includes(s.kolejnosc)) {
                                selectedStations.push(s);
                                addedKolejnosc.push(s.kolejnosc);
                            }
                        });
                    }
                    
                    // Sortowanie chronologiczne, żeby lektor czytał je po kolei
                    selectedStations.sort((a, b) => parseInt(a.kolejnosc) - parseInt(b.kolejnosc));
                    
                    let names = selectedStations.map(s => s.nazwa_stacji);
                    if (names.length > 0) { 
                        stacjeTekst = names.join(", "); 
                    }
                }
                
            }
            
            // Jeśli pociąg naprawde nie ma stacji pośrednich, kasujemy element "przez stacje, ..." z szablonu
            if (stacjeTekst === "") {
                tpl = tpl.replace(/przez stacje, \{posrednie\}, /g, '')
                         .replace(/przez stacje, \{posrednie\}/g, '');
                stacjeTekst = ""; 
            }   
            
            // Zabezpieczenie na wypadek, gdyby trasa na prawdę nie miała stacji pośrednich (np. pociąg jedzie tylko jedną stację dalej)
            if (stacjeTekst === "") {
                // Jeśli nie ma pośrednich, czyścimy tag {posrednie} i fragment zdania, który przed nim stoi
                tpl = tpl.replace(/przez stacje, \{posrednie\}, /g, '');
                stacjeTekst = ""; 
            }

            let rodzajPelna = d.rodzajPelna || d.rodzaj || '';
            if (d.rodzaj && trainTypes[d.rodzaj]) rodzajPelna = trainTypes[d.rodzaj];
            
            // --- NAPRAWA CZYTANIA NAZWY POCIĄGU ---
            // Zmienia np. "MIESZKO" na "Mieszko" żeby lektor czytał to jako słowo, a nie literował. 
            // Cudzysłowy zostają, bo są wyciszane przy zamianie na audio, a ładnie wyglądają na podglądzie.
            let nazwaPociagu = "";
            if (d.nazwa && d.nazwa !== "null" && d.nazwa.trim() !== "") {
                let sformatowanaNazwa = d.nazwa.trim().toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
                nazwaPociagu = `"${sformatowanaNazwa}"`;
            }

            let text = tpl
                .replace(/{rodzaj}/g, rodzajPelna)
                .replace(/{nazwa}/g, nazwaPociagu)
                .replace(/{z}/g, d.z || '')
                .replace(/{do}/g, d.do || '')
                .replace(/{opoznienie}/g, roundedDelay)
                .replace(/{czas}/g, time)
                .replace(/{posrednie}/g, stacjeTekst)
                .replace(/{skrocona_stacja}/g, skrocona)
                .replace(/{do_grupy}/g, do_grupy)
                .replace(/{stacja_zmiany_kat}/g, stacja_zmiany_kat)
                .replace(/{nowa_kategoria}/g, nowa_kategoria)
                .replace(/{poczatek_koniec}/g, poczatek_koniec)
                .replace(/{gdzie_wagony}/g, poczatek_koniec)
                .replace(/{gdzie_wagony_grupa}/g, gdzie_wagony_grupa)
                .replace(/{wagony_poczatek}/g, wagony_poczatek)
                .replace(/{wagony_srodek}/g, wagony_srodek)
                .replace(/{wagony_koniec}/g, wagony_koniec)
                .replace(/{wagon_kier}/g, wagon_kier)
                .replace(/{brak_wagonu}/g, brak_wagonu)
                .replace(/{zastepczy_wagon}/g, zastepczy_wagon)
                .replace(/{miejsce_kz}/g, miejsce_kz)
                .replace(/{zka_z}/g, zka_z)     
                .replace(/{zka_do}/g, zka_do)
                .replace(/{wagony_poza}/g, brak_wagonu); 

            // --- ZASTĘPOWANIE KONTEKSTOWE DLA TORÓW I PERONÓW ---
            text = text.replace(/na tor \{tor\}/g, `na tor ${torOdm.b}`)
                       .replace(/z toru \{tor\}/g, `z toru ${torOdm.d}`)
                       .replace(/po torze \{tor\}/g, `po torze ${torOdm.ms}`)
                       .replace(/na torze \{tor\}/g, `na torze ${torOdm.ms}`)
                       .replace(/\{tor\}/g, torOdm.m) 
                       .replace(/przy peronie \{peron\}/g, `przy peronie ${peronOdm.ms}`)
                       .replace(/na peron \{peron\}/g, `na peron ${peronOdm.b}`)
                       .replace(/\{peron\}/g, peronOdm.m); 

            text = text.replace(/ ,/g, ',');    
            text = text.replace(/, ,/g, ',');   
            text = text.replace(/,,/g, ',');    
            text = text.replace(/,\./g, '.');   
            text = text.replace(/ \./g, '.');   
            text = text.replace(/\s+/g, ' ');   
            text = text.replace(/, \./g, '.');  
            text = text.trim();

            document.getElementById('announceText').innerText = text;

        } catch (error) {
            document.getElementById('announceText').innerText = "BŁĄD JS: " + error.message;
            console.error("Błąd generowania zapowiedzi:", error);
        }
    }

    function copyAnnouncement() {
        const text = document.getElementById('announceText').innerText;
        if(text.includes("BŁĄD JS")) {
            alert("Nie kopiuj błędu, sprawdź co poszło nie tak.");
            return;
        }
        navigator.clipboard.writeText(text).then(() => alert("Treść skopiowana do schowka!"));
    }
    
    function playAnnouncement() {
        const text = document.getElementById('announceText').innerText;
        if (!text || text.trim() === "" || text.includes("BŁĄD JS") || text.includes("Wybierz pociąg")) {
            alert("Brak poprawnego tekstu zapowiedzi!");
            return;
        }

        const btn = document.getElementById('btnPlay');
        const originalText = btn.innerText;
        
        btn.innerText = "⏳ Ładowanie...";
        btn.disabled = true;

        fetch('generuj_audio.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.audio_url) {
                btn.innerText = "🔊 Odtwarzanie...";
                
                // Tworzymy odtwarzacze dla gongu i lektora
                const gongAudio = new Audio('gong.mp3');
                const ttsAudio = new Audio(data.audio_url);
                
                // Najpierw próbujemy odpalić gong
                gongAudio.play().catch(e => {
                    console.log("Nie znaleziono pliku gong.mp3, odpalam samego lektora.", e);
                    ttsAudio.play(); // Jak nie znajdzie gongu, od razu gada
                });
                
                // Kiedy gong się skończy, odpalamy lektora
                gongAudio.onended = () => {
                    ttsAudio.play();
                };
                
                // Kiedy lektor skończy gadać, odblokowujemy przycisk
                ttsAudio.onended = function() {
                    btn.innerText = originalText;
                    btn.disabled = false;
                };
                
                ttsAudio.onerror = function() {
                     alert("Błąd odtwarzania pliku przez przeglądarkę.");
                     btn.innerText = originalText;
                     btn.disabled = false;
                };
            } else {
                alert("Błąd serwera TTS: " + (data.error || "Nie zwrócono pliku."));
                btn.innerText = originalText;
                btn.disabled = false;
            }
        })
        .catch(error => {
            console.error(error);
            alert("Błąd połączenia z Twoim serwerem (generuj_audio.php).");
            btn.innerText = originalText;
            btn.disabled = false;
        });
    }

    // --- NOWE FUNKCJE DO WYŚWIETLACZY PERONOWYCH ---
    function openWyswietlaczModal() {
        if (!currentId || !currentData || Object.keys(currentData).length === 0) { 
            alert("Najpierw wybierz pociąg z głównej tabeli!"); 
            return; 
        }
        document.getElementById('modalWyswietlacz').style.display = 'block';
        document.getElementById('wyswietlacz-info').innerText = `${currentData.rodzaj || ''} ${currentData.numer || ''} (${currentData.z || ''} - ${currentData.do || ''})`;
        
        // Zaciąga domyślne wartości z rozkładu (peron i tor)
        document.getElementById('wysw-peron').value = currentData.peron || '';
        document.getElementById('wysw-tor').value = currentData.tor || '';
        document.getElementById('wysw-komunikat').value = '';
    }

    function closeWyswietlaczModal() {
        document.getElementById('modalWyswietlacz').style.display = 'none';
    }

    function zapiszWyswietlacz() {
        const p = document.getElementById('wysw-peron').value.trim();
        const t = document.getElementById('wysw-tor').value.trim();
        
        if(p === '' || t === '') {
            alert("Musisz podać peron i tor!");
            return;
        }

        const fd = new FormData();
        fd.append('id_szczegolu', currentId);
        fd.append('peron', p);
        fd.append('tor', t);
        fd.append('komunikat', document.getElementById('wysw-komunikat').value);
        fd.append('akcja', 'zapisz');

        fetch('ustaw_wyswietlacz.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(txt => {
                if(txt === "OK") {
                    alert("Dane wysłane pomyślnie na wyświetlacz!");
                    closeWyswietlaczModal();
                } else {
                    alert("Wystąpił błąd: " + txt);
                }
            });
    }
    function drukujTablice() {
        if (!currentPrzejazdId) {
            alert("Najpierw wybierz pociąg z głównej tabeli!");
            return;
        }
        // Otwiera nową kartę z gotową tablicą do druku
        window.open('drukuj_tablice.php?id_przejazdu=' + currentPrzejazdId, '_blank');
    }
    // --- SŁOWNIK KODÓW OPÓŹNIEŃ PLK (Ir-14) ---
    const PLK_KODY = {
        "11": "11 - Wypadek / Kolizja",
        "13": "13 - Wypadek z człowiekiem / Samobójstwo",
        "34": "34 - Zbyt późne zgłoszenie gotowości pociągu do odjazdu",
        "40": "40 - Opóźnienie wtórne (krzyżowanie / wyprzedzanie)",
        "61": "61 - Usterka taboru (lokomotywy / EZT)",
        "63": "63 - Usterka wagonów",
        "64": "64 - Brak sprawnych hamulców / Oględziny",
        "82": "82 - Usterka urządzeń SRK",
        "83": "83 - Usterka sieci trakcyjnej",
        "86": "86 - Usterka toru / Pęknięcie szyny",
        "90": "90 - Brak maszynisty / drużyny",
        "94": "94 - Oczekiwanie na skomunikowanie"
    };

    function ladujKodyOpoznien(id_przejazdu) {
        const fd = new FormData();
        fd.append('ajax_action', 'get_delays');
        fd.append('id_przejazdu', id_przejazdu);
        
        fetch('panel_dyzurnego.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            let html = '';
            if(data.length === 0) {
                html = '<tr><td colspan="3" style="text-align:center; padding:20px; color:#555;">Pociąg jedzie planowo. Brak opóźnień do zakodowania.</td></tr>';
            } else {
                data.forEach(row => {
                    let select = '<select class="delay-select" data-id="'+row.id_szczegolu+'" style="padding: 4px; width: 100%; border-radius: 3px;"><option value="">-- Wybierz kod Ir-14 --</option>';
                    for(let k in PLK_KODY) {
                        let sel = (row.kod_opoznienia == k) ? 'selected' : '';
                        select += `<option value="${k}" ${sel}>${PLK_KODY[k]}</option>`;
                    }
                    select += '</select>';
                    
                    html += `<tr>
                        <td style="font-weight:bold; text-align:left;">${row.stacja}</td>
                        <td style="color:red; font-weight:bold; font-size:14px;">+${row.opoznienie_min} min</td>
                        <td>${select}</td>
                    </tr>`;
                });
            }
            document.getElementById('lista-kodow-tbody').innerHTML = html;
        });
    }
    
    function zapiszKodyOpoznien() {
        if(!currentPrzejazdId) return;
        
        let selects = document.querySelectorAll('.delay-select');
        let data = {};
        selects.forEach(s => {
            if(s.value !== '') data[s.getAttribute('data-id')] = s.value;
        });
        
        const fd = new FormData();
        fd.append('ajax_action', 'save_delays');
        fd.append('codes', JSON.stringify(data));
        
        fetch('panel_dyzurnego.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            if(res.success) {
                alert("Kody opóźnień zostały poprawnie zapisane w bazie danych!");
            }
        });
    }
</script>
</body>
</html>