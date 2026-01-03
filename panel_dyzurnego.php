<?php
session_start();
require 'db_config.php';

// Ustawienie strefy czasowej
date_default_timezone_set('Europe/Warsaw');

// Pobieranie listy posterunków (stacji)
$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje WHERE typ_stacji_id IN (1,3) ORDER BY nazwa_stacji");
$wybrana_stacja = $_GET['id_stacji'] ?? 29;

$pociagi = [];
if ($wybrana_stacja) {
    // Pobieramy dane. 
    // Kluczowe: sortowanie po przyjeździe, żeby "fala" szła po kolei
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
         AND s2.typ_stacji_id IN (1, 3)
         ORDER BY CAST(sr2.kolejnosc AS SIGNED) DESC LIMIT 1) as stacja_prev,

        (SELECT s3.nazwa_stacji 
         FROM szczegoly_rozkladu sr3 
         JOIN stacje s3 ON sr3.id_stacji = s3.id_stacji
         WHERE sr3.id_przejazdu = sr.id_przejazdu 
         AND CAST(sr3.kolejnosc AS SIGNED) > CAST(sr.kolejnosc AS SIGNED)
         AND s3.typ_stacji_id IN (1, 3)
         ORDER BY CAST(sr3.kolejnosc AS SIGNED) ASC LIMIT 1) as stacja_next,

        -- Pobieramy opóźnienie z ostatniego punktu, gdzie RZECZYWISTY != PLANOWY
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
    $t1 = strtotime($plan);
    $t2 = strtotime($rzecz);
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
        
        /* Styl dla zatwierdzonych */
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
        .modal-content { background-color: #f0f0f0; margin: 5% auto; border: 1px solid #000; width: 750px; box-shadow: 4px 4px 10px rgba(0,0,0,0.5); font-family: 'Tahoma', sans-serif; }
        .modal-header { background: linear-gradient(to right, #000080, #3a6ea5); color: white; padding: 4px 8px; font-weight: bold; display: flex; justify-content: space-between; font-size: 12px; }
        .modal-info-strip { background-color: #ffffe0; border-bottom: 1px solid #ccc; padding: 5px; text-align: center; color: #006400; font-weight: bold; }
        .modal-body { padding: 15px; display: flex; gap: 15px; }
        .modal-col { flex: 1; border: 1px solid #aaa; padding: 10px; background: #fff; }
        .modal-col h4 { margin: 0 0 10px 0; color: #000080; border-bottom: 1px solid #eee; font-size: 11px; }
        .time-row { display: flex; align-items: center; margin-bottom: 10px; background:#f5f5f5; padding:5px; border:1px solid #ddd;}
        input[type="time"] { font-size: 14px; font-weight: bold; width: 90px; }
        input[type="date"] { font-size: 12px; margin-right: 5px; width: 110px;}
        .btn-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; margin-top: 10px;}
        .btn-time { font-size: 10px; padding: 4px 0; background: #fcfcfc; border: 1px solid #bbb; cursor: pointer; text-align: center; }
        .btn-time:hover { background: #e0e0ff; border-color: #000080; }
        .modal-footer { padding: 8px; background: #e0e0e0; border-top: 1px solid #999; text-align: right; }

        .announce-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .announce-field label { display: block; font-size: 10px; font-weight: bold; color: #000080; }
        .announce-field input, .announce-field select { width: 95%; font-size: 11px; padding: 2px; }
        .announce-box { border:1px solid #aaa; padding:10px; background:#fff; font-family: monospace; font-size: 12px; min-height: 80px; white-space: pre-wrap; overflow-y:auto; color: #000; }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="control-group">
        <label>Posterunek:</label>
        <form method="GET" id="formStacja">
            <select name="id_stacji" onchange="document.getElementById('formStacja').submit()">
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

                    // PHP - Przyjazd
                    if ($p['przyjazd_rzecz'] && $p['przyjazd_rzecz'] != $p['przyjazd']) {
                        $val_rp = substr($p['przyjazd_rzecz'], 0, 5);
                        $style_rp = '';
                        $diff_arr = diffMinutesPHP($p['przyjazd'], $p['przyjazd_rzecz']);
                        $opoz_min = $diff_arr; // Aktualizacja lokalna dla wyświetlania
                    } else {
                        $val_rp = $p['przyjazd'] ? addMinutesPHP($p['przyjazd'], $opoz_min) : '';
                        $style_rp = 'forecast';
                        $diff_arr = $opoz_min;
                    }
                    $cls_arr = ($diff_arr > 0) ? 'delay-red' : (($diff_arr < 0) ? 'delay-green' : '');

                    // PHP - Odjazd
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
                    <td class="<?= $type_class ?>" style="text-align: center; color: black center>"><?= $p['rodzaj'] ?></td>
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
                    <td><?= $p['przewoznik_skrot'] ?></td> </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="tab-opis" class="tab-pane" style="background:#f0f0f0;">
        <div class="info-grid">
            <div class="info-label">Numer pociągu</div><div class="info-value" id="op-nr"></div>
            <div class="info-full-row"><div class="info-label">Informacje dodatkowe</div><div class="info-textarea"></div></div>
            <div class="info-label">Nazwa pociągu</div><div class="info-value" id="op-nazwa"></div>
            <div class="info-full-row"><div class="info-label">Ładunek</div><div class="info-textarea"></div></div>
            <div class="info-label">Rodzaj pociągu</div><div class="info-value" id="op-rodzaj"></div>
            <div class="info-label">Przewoźnik</div><div class="info-value" id="op-przew"></div>
            <div class="info-label">Stacja początkowa</div><div class="info-value" id="op-start"></div>
            <div class="info-label">Stacja końcowa</div><div class="info-value" id="op-koniec"></div>
            <div class="info-full-row"><div class="info-label">Uwagi własne</div><div class="info-textarea" id="op-symbole"></div></div>
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
                    <th style="width:25px">+/-</th>
                    <th style="width:50px">Rodzaj</th>
                </tr>
            </thead>
            <tbody id="trasa-body"></tbody>
        </table>
    </div>
</div>

<div class="bottom-bar">
    <div class="btn-swdr" onclick="openModal()"><span>🕒</span> Wprowadzanie godzin (F2)</div>
    <div class="btn-swdr" onclick="openAnnounceModal()"><span>📢</span> Zapowiedź</div>
    <span style="font-size:10px; color:#555;">Ilość pociągów -> <?= count($pociagi) ?></span>
</div>

<div id="modalGodziny" class="modal">
    <div class="modal-content">
        <div class="modal-header"><span>Rzeczywisty czas przyjazdu i odjazdu pociągu</span><span onclick="closeModal()" style="cursor:pointer;">X</span></div>
        <div class="modal-info-strip" id="modal-title"></div>
        <form id="formTimes" onsubmit="saveTimes(event)">
            <input type="hidden" id="modal-id" name="id_szczegolu">
            <div class="modal-body">
                <div class="modal-col">
                    <h4>Pociąg przyjechał:</h4>
                    <div class="time-row">Planowo: <b id="lbl-plan-p" style="margin-right:10px;"></b></div>
                    <div class="time-control"><input type="date" value="<?= date('Y-m-d') ?>"><input type="time" name="przyjazd_rzecz" id="inp-p"></div>
                    <fieldset style="border:1px solid #ccc; padding:5px; margin-top:10px;"><legend style="font-size:10px; color:navy;">Dodaj opóźnienie</legend><div class="btn-grid"><?php foreach([5,10,15,20,25,30,45,60] as $m) echo "<div class='btn-time' onclick=\"addMin('inp-p', $m)\">$m min.</div>"; ?></div></fieldset>
                </div>
                <div class="modal-col">
                    <h4>Pociąg odjechał:</h4>
                    <div class="time-row">Planowo: <b id="lbl-plan-o" style="margin-right:10px;"></b></div>
                    <div class="time-control"><input type="date" value="<?= date('Y-m-d') ?>"><input type="time" name="odjazd_rzecz" id="inp-o"><button type="button" onclick="copyTime()" style="margin-left:5px; font-size:10px;">Przepisz czas przyjazdu</button></div>
                    <fieldset style="border:1px solid #ccc; padding:5px; margin-top:10px;"><legend style="font-size:10px; color:navy;">Dodaj opóźnienie</legend><div class="btn-grid"><?php foreach([5,10,15,20,25,30,45,60] as $m) echo "<div class='btn-time' onclick=\"addMin('inp-o', $m)\">$m min.</div>"; ?></div></fieldset>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn-swdr" style="float:left; width:200px;">Wprowadź postój (STÓJ)</button><button type="submit" class="btn-swdr" style="display:inline-block;">Zapisz</button><button type="button" class="btn-swdr" style="display:inline-block;" onclick="closeModal()">Anuluj</button></div>
        </form>
    </div>
</div>

<div id="modalZapowiedz" class="modal">
    <div class="modal-content" style="width: 800px;">
        <div class="modal-header">
            <span>📢 Generator Zapowiedzi (Wg Wytycznych PLK)</span>
            <span onclick="closeAnnounceModal()" style="cursor:pointer;">X</span>
        </div>
        <div class="modal-info-strip" id="zapowiedz-info"></div>
        
        <div class="modal-body" style="display:block;">
            <div class="announce-controls">
                <div class="announce-field">
                    <label>Kategoria komunikatu:</label>
                    <select id="annCat" onchange="updateTemplates()">
                        <option value="wjazd">Wjazd / Przyjazd</option>
                        <option value="odjazd">Odjazd / Postój</option>
                        <option value="opoznienie">Opóźnienia</option>
                        <option value="zaklocenia">Zakłócenia / Zmiany</option>
                        <option value="bezpieczenstwo">Bezpieczeństwo / Inne</option>
                    </select>
                </div>
                <div class="announce-field">
                    <label>Szczegółowy wariant:</label>
                    <select id="annVar" onchange="generateAnnouncement()"></select>
                </div>
                
                <div class="announce-field">
                    <label>Peron:</label>
                    <input type="text" id="annPeron" oninput="generateAnnouncement()">
                </div>
                <div class="announce-field">
                    <label>Tor:</label>
                    <input type="text" id="annTor" oninput="generateAnnouncement()">
                </div>
                <div class="announce-field">
                    <label>Opóźnienie (min):</label>
                    <input type="number" id="annDelay" oninput="generateAnnouncement()">
                </div>
                <div class="announce-field">
                    <label>Godzina (Plan/Rzecz):</label>
                    <input type="time" id="annTime" oninput="generateAnnouncement()">
                </div>
                <div class="announce-field">
                    <label>Skrócona do stacji:</label>
                    <input type="text" id="annShort" oninput="generateAnnouncement()" placeholder="np. Stargard">
                </div>
            </div>
            
            <div class="announce-field">
                <label>Treść zapowiedzi:</label>
                <div class="announce-box" id="announceText"></div>
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-swdr" onclick="copyAnnouncement()">Kopiuj tekst</button>
            <button type="button" class="btn-swdr" onclick="closeAnnounceModal()">Zamknij</button>
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
        if (currentPrzejazdId) pobierzDaneTrasy(currentPrzejazdId);
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

        document.getElementById('lbl-z').innerText = tr.dataset.z;
        document.getElementById('lbl-do').innerText = tr.dataset.do;

        pobierzDaneTrasy(idPrzejazdu);
    }

    // --- KLUCZOWA POPRAWKA LOGIKI JS ---
    // --- FUNKCJA OBLICZAJĄCA I WYŚWIETLAJĄCA TRASĘ (Z POPRAWKĄ NA IGNOROWANIE STARYCH DANYCH) ---
    // --- POPRAWIONA FUNKCJA Z "PODTRZYMANIEM" PROGNOZY NA STACJI ---
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
                let foundFirstUnapprovedRow = false; 

                if (data.trasa) {
                    data.trasa.forEach((t, i) => {
                        let delayP = '', styleP = 'bg-blue t-cell', classDiffP = '';
                        let delayO = '', styleO = 'bg-blue t-cell', classDiffO = '';
                        
                        let displayP = '';
                        let displayO = '';

                        let isApproved = (t.zatwierdzony == 1);
                        
                        // Zmienna przechowująca opóźnienie przyjazdu w TYM wierszu, 
                        // żeby wiedzieć czy "podtrzymać" opóźnienie przy odjeździe.
                        let currentArrivalDelay = 0;

                        // Decyzja: Czy ufamy danym z bazy w tym wierszu?
                        let trustDB = false;
                        if (isApproved) {
                            trustDB = true;
                        } else {
                            if (!foundFirstUnapprovedRow) {
                                trustDB = true; // To jest stacja, na której stoi pociąg (Aktywna)
                                foundFirstUnapprovedRow = true;
                            } else {
                                trustDB = false; // Przyszłość -> prognozujemy
                            }
                        }

                        // === PRZYJAZD ===
                        if (trustDB && t.przyjazd_rzecz) {
                            let diff = diffMinutes(t.przyjazd, t.przyjazd_rzecz);
                            biezaceOpoznienie = diff;
                            currentArrivalDelay = diff; // Zapamiętujemy opóźnienie przyjazdu
                            
                            displayP = t.przyjazd_rzecz.substr(0,5);
                            if(diff != 0) {
                                delayP = (diff > 0 ? '+' : '') + diff;
                                classDiffP = diff > 0 ? 'delay-red' : 'delay-green';
                            }
                        } else if (t.przyjazd) {
                            displayP = addMinutes(t.przyjazd, biezaceOpoznienie);
                            styleP += ' forecast';
                            if (biezaceOpoznienie != 0) {
                                delayP = (biezaceOpoznienie > 0 ? '+' : '') + biezaceOpoznienie;
                                classDiffP = biezaceOpoznienie > 0 ? 'delay-red' : 'delay-green';
                            }
                        }

                        // === ODJAZD (TUTAJ JEST POPRAWKA DLA RURKI) ===
                        if (trustDB && t.odjazd_rzecz) {
                            let diff = diffMinutes(t.odjazd, t.odjazd_rzecz);
                            
                            // SPECJALNY WARUNEK:
                            // Jeśli system pokazuje 0 opóźnienia na odjeździe, ALE przyjazd był opóźniony,
                            // to znaczy, że pociąg stoi, a "odjazd_rzecz" to tylko domyślny plan.
                            // Wtedy IGNORUJEMY to 0 i narzucamy opóźnienie z przyjazdu.
                            if (diff === 0 && currentArrivalDelay !== 0) {
                                // Podtrzymujemy opóźnienie
                                biezaceOpoznienie = currentArrivalDelay;
                                
                                // Wyświetlamy jako prognozę (kursywa), bo pociąg jeszcze nie ruszył
                                displayO = addMinutes(t.odjazd, currentArrivalDelay);
                                styleO += ' forecast';
                                
                                delayO = (currentArrivalDelay > 0 ? '+' : '') + currentArrivalDelay;
                                classDiffO = currentArrivalDelay > 0 ? 'delay-red' : 'delay-green';
                                
                            } else {
                                // Normalna sytuacja (opóźnienie jest inne niż 0, albo przyjazd też był o czasie)
                                biezaceOpoznienie = diff; 
                                displayO = t.odjazd_rzecz.substr(0,5);
                                if(diff != 0) {
                                    delayO = (diff > 0 ? '+' : '') + diff;
                                    classDiffO = diff > 0 ? 'delay-red' : 'delay-green';
                                }
                            }
                        } else if (t.odjazd) {
                            displayO = addMinutes(t.odjazd, biezaceOpoznienie);
                            styleO += ' forecast';
                            if (biezaceOpoznienie != 0) {
                                delayO = (biezaceOpoznienie > 0 ? '+' : '') + biezaceOpoznienie;
                                classDiffO = biezaceOpoznienie > 0 ? 'delay-red' : 'delay-green';
                            }
                        }

                        // === POSTOJE (bez zmian) ===
                        let postojZam = '';
                        if (t.przyjazd && t.odjazd) {
                            let t1 = t.przyjazd.split(':');
                            let t2 = t.odjazd.split(':');
                            let secPrzyj = parseInt(t1[0], 10)*3600 + parseInt(t1[1], 10)*60 + (t1[2] ? parseInt(t1[2], 10) : 0);
                            let secOdj = parseInt(t2[0], 10)*3600 + parseInt(t2[1], 10)*60 + (t2[2] ? parseInt(t2[2], 10) : 0);
                            let diffSec = secOdj - secPrzyj;
                            if (diffSec < 0) diffSec += 86400;
                            let diffMin = diffSec / 60;
                            if (diffMin > 0) postojZam = parseFloat(diffMin.toFixed(1));
                        }

                        let postojObl = '';
                        if (displayP && displayO) {
                             let min = diffMinutes(displayP, displayO);
                             if (min > 0) postojObl = min;
                        }

                        let rowClass = isApproved ? 'row-approved' : '';

                        let row = `<tr class="${rowClass}">
                            <td class="${classDiffP}">${delayP}</td>
                            <td class="center"><input type="checkbox" ${isApproved ? 'checked' : ''} disabled></td>
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
                            <td class="center" style="text-align: center; color: black;">${data.opis ? data.opis.rodzaj_skrot : ''}</td>
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
        fetch('zapisz_czas.php', { method:'POST', body:fd }).then(r=>r.text()).then(res=>{
            if(res==='OK') { 
                closeModal(); 
                if(currentPrzejazdId) pobierzDaneTrasy(currentPrzejazdId);
            } else { alert(res); }
        });
    }

    document.addEventListener('keydown', e => { if(e.key === 'F2') openModal(); });

    const trainTypes = {
        'IC': 'InterCity', 'TLK': 'Twoje Linie Kolejowe', 'EIP': 'Express InterCity Premium', 
        'EIC': 'Express InterCity', 'R': 'Regio', 'Os': 'Osobowy', 'RP': 'Przyspieszony', 
        'KD': 'Kolei Dolnośląskich', 'KS': 'Kolei Śląskich', 'SKM': 'Szybkiej Kolei Miejskiej',
        'LS': 'Łódzkiej Kolei Aglomeracyjnej Sprinter', 'IR': 'InterRegio'
    };

    const TEMPLATES = {
        'wjazd': {
            'std': 'Pociąg {rodzaj} {nazwa} ze stacji {z} do stacji {do} przez stacje {posrednie}, wjedzie na tor {tor} przy peronie {peron}. Prosimy zachować ostrożność i nie zbliżać się do krawędzi peronu.',
            'przelot': 'Uwaga! Po torze {tor} przy peronie {peron} przejedzie pociąg {rodzaj} {nazwa} bez zatrzymania. Prosimy zachować ostrożność.',
            'konczy': 'Pociąg {rodzaj} {nazwa} ze stacji {z} wjedzie na tor {tor} przy peronie {peron}. Pociąg kończy bieg. Prosimy zachować ostrożność.',
            'zmiana_peronu': 'Uwaga! Zmiana peronu. Pociąg {rodzaj} {nazwa} ze stacji {z} do stacji {do} wjedzie wyjątkowo na tor {tor} przy peronie {peron}. Za zmianę peronu przepraszamy.'
        },
        'odjazd': {
            'std': 'Pociąg {rodzaj} {nazwa} do stacji {do} przez stacje {posrednie}, odjedzie z toru {tor} przy peronie {peron}. Życzymy Państwu przyjemnej podróży.',
            'stoi': 'Pociąg {rodzaj} {nazwa} do stacji {do} stoi na torze {tor} przy peronie {peron}. Planowy odjazd pociągu o godzinie {czas}.',
            'opozniony_odjazd': 'Pociąg {rodzaj} {nazwa} do stacji {do} odjedzie z toru {tor} przy peronie {peron} z opóźnieniem około {opoznienie} minut. Za opóźnienie przepraszamy.'
        },
        'opoznienie': {
            'wjazd': 'Pociąg {rodzaj} {nazwa} ze stacji {z} do stacji {do}, planowy przyjazd godzina {czas}, przyjedzie z opóźnieniem około {opoznienie} minut. Opóźnienie może ulec zmianie.',
            'odjazd': 'Pociąg {rodzaj} {nazwa} do stacji {do}, planowy odjazd godzina {czas}, odjedzie z opóźnieniem około {opoznienie} minut. Za opóźnienie przepraszamy.',
            'techniczne': 'Z przyczyn technicznych pociąg {rodzaj} {nazwa} do stacji {do} odjedzie z opóźnieniem około {opoznienie} minut.',
            'skomunikowanie': 'Pociąg {rodzaj} {nazwa} do stacji {do} odjedzie z opóźnieniem około {opoznienie} minut z powodu oczekiwania na pociąg skomunikowany.'
        },
        'zaklocenia': {
            'skrocona': 'Uwaga! Pociąg {rodzaj} {nazwa} do stacji {do} kursuje w relacji skróconej do stacji {skrocona_stacja}. Za utrudnienia przepraszamy.',
            'odwolanie': 'Pociąg {rodzaj} {nazwa} do stacji {do} planowy odjazd {czas} został odwołany. Przepraszamy za utrudnienia.',
            'kkz': 'Informujemy, że na odcinku {z} - {do} przewóz realizowany jest autobusową komunikacją zastępczą. Autobusy odjeżdżają z placu przed dworcem.'
        },
        'bezpieczenstwo': {
            'bagaz': 'W trosce o bezpieczeństwo prosimy o niepozostawianie bagażu bez opieki.',
            'palenie': 'Przypominamy, że na terenie dworca i peronów obowiązuje całkowity zakaz palenia tytoniu.',
            'odstep': 'Prosimy o zachowanie bezpiecznej odległości od krawędzi peronu.'
        }
    };

    function openAnnounceModal() {
        if (!currentId) { alert("Najpierw wybierz pociąg z listy!"); return; }
        document.getElementById('modalZapowiedz').style.display = 'block';
        document.getElementById('zapowiedz-info').innerText = `${currentData.rodzaj} ${currentData.numer} (${currentData.z} - ${currentData.do})`;
        
        document.getElementById('annPeron').value = currentData.peron || '';
        document.getElementById('annTor').value = currentData.tor || '';
        document.getElementById('annDelay').value = currentData.opoznienie > 0 ? currentData.opoznienie : 5;
        document.getElementById('annTime').value = currentData.planO || '';

        updateTemplates();
    }

    function closeAnnounceModal() { document.getElementById('modalZapowiedz').style.display = 'none'; }

    function updateTemplates() {
        const cat = document.getElementById('annCat').value;
        const varSelect = document.getElementById('annVar');
        varSelect.innerHTML = '';
        
        const variants = TEMPLATES[cat];
        for (const key in variants) {
            let label = key.replace(/_/g, ' ').toUpperCase();
            let opt = document.createElement('option');
            opt.value = key;
            opt.innerText = label;
            varSelect.appendChild(opt);
        }
        generateAnnouncement();
    }

    function generateAnnouncement() {
        const cat = document.getElementById('annCat').value;
        const variant = document.getElementById('annVar').value;
        
        if (!TEMPLATES[cat] || !TEMPLATES[cat][variant]) return;

        let tpl = TEMPLATES[cat][variant];
        const d = currentData;

        const peron = document.getElementById('annPeron').value;
        const tor = document.getElementById('annTor').value;
        const delay = document.getElementById('annDelay').value;
        const time = document.getElementById('annTime').value;
        const shortInput = document.getElementById('annShort');
        const skrocona = shortInput ? shortInput.value : d.do;

        let stacjeTekst = "";
        
        if (d.trasa && d.trasa.length > 0) {
            let currentIndex = -1;
            const currentStationNameElement = document.querySelector(`option[value="${stacjaId}"]`);
            const currentStationName = currentStationNameElement ? currentStationNameElement.text.trim() : "";

            d.trasa.forEach((t, i) => {
                if (t.id_stacji == stacjaId || t.nazwa_stacji == currentStationName) {
                    currentIndex = i;
                }
            });

            if (currentIndex > -1 && currentIndex < d.trasa.length - 1) {
                let futureStops = d.trasa.slice(currentIndex + 1, d.trasa.length - 1);
                futureStops = futureStops.filter(s => s.uwagi_postoju === 'ph');
                let selectedStations = futureStops.filter(s => s.czy_zapowiadac == 1);

                if (selectedStations.length < 5) {
                    let candidatesType1 = futureStops.filter(s => s.czy_zapowiadac == 0 && s.typ_stacji_id == 1);
                    candidatesType1.sort(() => Math.random() - 0.5);
                    while (selectedStations.length < 5 && candidatesType1.length > 0) {
                        selectedStations.push(candidatesType1.pop());
                    }
                }

                if (selectedStations.length < 5) {
                    let candidatesType2 = futureStops.filter(s => s.czy_zapowiadac == 0 && s.typ_stacji_id == 2);
                    candidatesType2.sort(() => Math.random() - 0.5);
                    while (selectedStations.length < 5 && candidatesType2.length > 0) {
                        selectedStations.push(candidatesType2.pop());
                    }
                }

                selectedStations.sort((a, b) => parseInt(a.kolejnosc) - parseInt(b.kolejnosc));

                let names = selectedStations.map(s => s.nazwa_stacji);
                if (names.length > 0) {
                    stacjeTekst = names.join(", ");
                }
            }
        }
        
        if (stacjeTekst === "") stacjeTekst = "(główne stacje pośrednie)";

        let rodzajPelna = d.rodzajPelna || d.rodzaj;
        if (trainTypes[d.rodzaj]) rodzajPelna = trainTypes[d.rodzaj];
        let nazwaPociagu = d.nazwa ? `"${d.nazwa.toUpperCase()}"` : `numer ${d.numer}`;

        let text = tpl
            .replace(/{rodzaj}/g, rodzajPelna)
            .replace(/{nazwa}/g, nazwaPociagu)
            .replace(/{nr}/g, d.numer)
            .replace(/{z}/g, d.z)
            .replace(/{do}/g, d.do)
            .replace(/{peron}/g, peron)
            .replace(/{tor}/g, tor)
            .replace(/{opoznienie}/g, delay)
            .replace(/{czas}/g, time)
            .replace(/{posrednie}/g, stacjeTekst)
            .replace(/{skrocona_stacja}/g, skrocona)
            .replace(/{miejsce_kz}/g, 'przystanku przed dworcem');

        document.getElementById('announceText').innerText = text;
    }

    function copyAnnouncement() {
        const text = document.getElementById('announceText').innerText;
        navigator.clipboard.writeText(text).then(() => alert("Treść skopiowana do schowka!"));
    }
</script>
</body>
</html>