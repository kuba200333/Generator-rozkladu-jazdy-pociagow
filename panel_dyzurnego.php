<?php
session_start();
require 'db_config.php';

date_default_timezone_set('Europe/Warsaw');

$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje WHERE typ_stacji_id IN (1,3) ORDER BY nazwa_stacji");
$wybrana_stacja = $_GET['id_stacji'] ?? 29;

$pociagi = [];
if ($wybrana_stacja) {
    // Zapytanie SQL z poprawnym JOINem do przewoźników
    $sql = "
    SELECT 
        sr.id_szczegolu, sr.id_przejazdu, sr.przyjazd, sr.odjazd, 
        sr.przyjazd_rzecz, sr.odjazd_rzecz, sr.tor, sr.peron, sr.status_dyzurnego, sr.uwagi_postoju,
        sr.kolejnosc,
        p.numer_pociagu, p.nazwa_pociagu, 
        tp.skrot as rodzaj, tp.kolor_czcionki, sr.czy_odwolany, sr.zatwierdzony,
        pr.pelna_nazwa as przewoznik_skrot, -- Tutaj pobieramy skrót przewoźnika
        
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

        (SELECT CASE 
            WHEN sr_hist.odjazd_rzecz IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sr_hist.odjazd, sr_hist.odjazd_rzecz)
            WHEN sr_hist.przyjazd_rzecz IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sr_hist.przyjazd, sr_hist.przyjazd_rzecz)
            ELSE 0 END
         FROM szczegoly_rozkladu sr_hist
         WHERE sr_hist.id_przejazdu = sr.id_przejazdu 
           AND CAST(sr_hist.kolejnosc AS SIGNED) < CAST(sr.kolejnosc AS SIGNED)
           AND (sr_hist.odjazd_rzecz IS NOT NULL OR sr_hist.przyjazd_rzecz IS NOT NULL)
         ORDER BY CAST(sr_hist.kolejnosc AS SIGNED) DESC LIMIT 1
        ) as opoznienie_aktywne

    FROM szczegoly_rozkladu sr
    JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu
    JOIN trasy t ON p.id_trasy = t.id_trasy
    JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
    LEFT JOIN przewoznicy pr ON tp.id_przewoznika = pr.id_przewoznika -- JOIN TABELI PRZEWOŹNICY
    WHERE sr.id_stacji = ?
    ORDER BY sr.przyjazd ASC
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $wybrana_stacja);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pociagi = mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function fmtFull($time) { return $time ? date('m-d H:i', strtotime($time)) : ''; }
function fmtShort($time) { return $time ? date('H:i', strtotime($time)) : ''; }
function addMinutes($time, $minutes) { if (!$time) return ''; return date('H:i', strtotime($time . " +$minutes minutes")); }
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
        
        /* ZATWIERDZONE */
        tr.row-approved td { background-color: #ccffcc !important; color: #000000 !important; }
        tr.row-approved.selected td { background-color: #000080 !important; color: #ffffff !important; }
        
        /* TYP POCIĄGU ZATWIERDZONY (ZIELONE TŁO SAMEJ KOMÓRKI) */
        td.type-approved { background-color: #00cc00 !important; color: black !important; font-weight: bold; text-align: center;}

        tr:nth-child(even) { background-color: #f8f8f8; }
        tr.selected td { background-color: #000080 !important; color: #ffffff !important; }
        tr.selected td.bg-blue { color: #ffffff !important; } 
        
        .info-grid { display: grid; grid-template-columns: 150px 1fr; gap: 10px; padding: 20px; max-width: 900px; }
        .info-label { font-weight: bold; color: #000080; text-align: right; padding-right: 10px;}
        .info-value { background: #FFFFE0; border: 1px solid #ccc; padding: 4px; font-weight: bold; min-height: 20px; }
        .info-full-row { grid-column: span 2; display: flex; flex-direction: column; }
        .info-textarea { background: #FFFFE0; border: 1px solid #ccc; padding: 4px; height: 60px; overflow-y: auto;}
        .bottom-bar { height: 28px; background-color: #f0f0f0; border-top: 1px solid #888; padding: 2px 5px; display: flex; align-items: center; justify-content: space-between; }
        .btn-swdr { border: 1px solid #888; background: linear-gradient(to bottom, #fff, #e0e0e0); padding: 3px 10px; font-size: 11px; font-weight: bold; cursor: pointer; margin-right: 5px; display: flex; align-items: center; gap: 5px; }
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
                <?php foreach ($pociagi as $p): 
                    $opoz_min = isset($p['opoznienie_aktywne']) ? intval($p['opoznienie_aktywne']) : 0;
                    $is_approved = ($p['zatwierdzony'] == 1); // Tylko jeśli flaga zatwierdzony = 1

                    if ($p['przyjazd_rzecz']) {
                        $val_rp = $p['przyjazd_rzecz'] ? substr($p['przyjazd_rzecz'], 0, 5) : '';
                        $style_rp = '';
                        $diff_arr = round((strtotime($p['przyjazd_rzecz']) - strtotime($p['przyjazd']))/60);
                    } else {
                        $val_rp = $p['przyjazd'] ? addMinutes($p['przyjazd'], $opoz_min) : '';
                        $style_rp = 'color:gray; font-style:italic; font-weight:normal;';
                        $diff_arr = $opoz_min;
                    }
                    $cls_arr = ($diff_arr > 0) ? 'delay-red' : (($diff_arr < 0) ? 'delay-green' : '');

                    if ($p['odjazd_rzecz']) {
                        $val_ro = $p['odjazd_rzecz'] ? substr($p['odjazd_rzecz'], 0, 5) : '';
                        $style_ro = '';
                        $diff_dep = round((strtotime($p['odjazd_rzecz']) - strtotime($p['odjazd']))/60);
                    } else {
                        $val_ro = $p['odjazd'] ? addMinutes($p['odjazd'], $opoz_min) : '';
                        $style_ro = 'color:gray; font-style:italic; font-weight:normal;';
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
                    
                    // KLASA DLA RODZAJU (ZIELONE TŁO JESLI ZATWIERDZONY)
                    $type_class = $is_approved ? 'type-approved' : 'center';
                ?>
                <tr onclick="selectRow(this, <?= $p['id_szczegolu'] ?>, <?= $p['id_przejazdu'] ?>)"
                    ondblclick="openModal()"
                    data-info="<?= $p['numer_pociagu'] ?> (<?= $p['stacja_pocz'] ?> - <?= $p['stacja_konc'] ?>)"
                    data-plan-p="<?= $short_pp ?>" data-plan-o="<?= $short_po ?>"
                    data-rzecz-p="<?= $val_rp ?>" data-rzecz-o="<?= $val_ro ?>"
                    data-z="<?= $p['stacja_pocz'] ?>" data-do="<?= $p['stacja_konc'] ?>">
                    
                    <td class="center"><input type="checkbox" <?= $p['czy_odwolany'] ? '' : 'checked' ?> disabled></td>
                    <td class="center"><?= $p['czy_odwolany'] ? '<input type="checkbox" checked disabled>' : '' ?></td>

                    <td class="bg-time t-cell" data-short="<?= $short_pp ?>" data-full="<?= $full_pp ?>"><?= $short_pp ?></td>
                    <td class="<?= $cls_arr ?>"><?= $diff_arr != 0 ? $diff_arr : '' ?></td>
                    <td class="bg-blue t-cell" style="<?= $style_rp ?>" data-short="<?= $val_rp ?>" data-full="<?= fmtFull($val_rp) ?>"><?= $val_rp ?></td>
                    
                    <td class="<?= $type_class ?>" style="color: black center>"><?= $p['rodzaj'] ?></td>
                    
                    <td class="bg-green center"><?= $nr_left ?></td>
                    <td><?= $stacja_z ?></td>
                    <td class="bg-green center"><?= $nr_right ?></td>
                    <td><?= $stacja_do ?></td>
                    <td class="bg-gray"><?= $p['tor'] ?></td>
                    <td class="bg-gray"><?= $p['peron'] ?></td>
                    <td class="center"><?= $p['uwagi_postoju'] ?></td>
                    <td class="bg-time t-cell" data-short="<?= $short_po ?>" data-full="<?= $full_po ?>"><?= $short_po ?></td>
                    <td class="<?= $cls_dep ?>"><?= $diff_dep != 0 ? $diff_dep : '' ?></td>
                    <td class="bg-blue t-cell" style="<?= $style_ro ?>" data-short="<?= $val_ro ?>" data-full="<?= fmtFull($val_ro) ?>"><?= $val_ro ?></td>
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

<script>
    setInterval(() => document.getElementById('clock').innerText = new Date().toLocaleTimeString('pl-PL', {hour12:false}), 1000);

    let currentId = null;
    let currentPrzejazdId = null;
    let currentData = {};
    const stacjaId = new URLSearchParams(window.location.search).get('id_stacji') || 29;

    setInterval(function() {
        odswiezWykaz();
        if (currentPrzejazdId) pobierzDaneTrasy(currentPrzejazdId);
    }, 5000); 

    function odswiezWykaz() {
        fetch('api_wykaz.php?id_stacji=' + stacjaId)
            .then(r => r.json())
            .then(data => {
                const tbody = document.querySelector('#tab-wykaz tbody');
                let selectedRow = document.querySelector('tr.selected');
                let selectedId = selectedRow ? selectedRow.getAttribute('onclick').match(/(\d+),/)[1] : null;

                let html = '';
                data.forEach(p => {
                    let opoz_min = p.opoznienie_aktywne ? parseInt(p.opoznienie_aktywne) : 0;
                    let is_approved = (p.zatwierdzony == 1); // Czy zatwierdzony?

                    let val_rp, style_rp, diff_arr, cls_arr;
                    if (p.przyjazd_rzecz) {
                        val_rp = p.przyjazd_rzecz.substr(0,5);
                        style_rp = '';
                        diff_arr = Math.round((new Date('1970-01-01T'+p.przyjazd_rzecz) - new Date('1970-01-01T'+p.przyjazd))/60000);
                    } else {
                        val_rp = p.przyjazd ? addMinutes(p.przyjazd, opoz_min) : '';
                        style_rp = 'color:gray; font-style:italic; font-weight:normal;';
                        diff_arr = opoz_min;
                    }
                    cls_arr = diff_arr > 0 ? 'delay-red' : (diff_arr < 0 ? 'delay-green' : '');

                    let val_ro, style_ro, diff_dep, cls_dep;
                    if (p.odjazd_rzecz) {
                        val_ro = p.odjazd_rzecz.substr(0,5);
                        style_ro = '';
                        diff_dep = Math.round((new Date('1970-01-01T'+p.odjazd_rzecz) - new Date('1970-01-01T'+p.odjazd))/60000);
                    } else {
                        val_ro = p.odjazd ? addMinutes(p.odjazd, opoz_min) : '';
                        style_ro = 'color:gray; font-style:italic; font-weight:normal;';
                        diff_dep = opoz_min;
                    }
                    cls_dep = diff_dep > 0 ? 'delay-red' : (diff_dep < 0 ? 'delay-green' : '');

                    let numer = parseInt(p.numer_pociagu.replace(/\D/g,''));
                    let nr_left = (numer % 2 != 0) ? p.numer_pociagu : '';
                    let nr_right = (numer % 2 == 0) ? p.numer_pociagu : '';
                    let isSelected = (p.id_szczegolu == selectedId) ? 'selected' : '';
                    
                    // Klasa Rodzaju (Zielone tło)
                    let type_class = is_approved ? 'type-approved' : 'center';
                    let type_color = is_approved ? 'white' : p.kolor_czcionki;

                    html += `<tr class="${isSelected}" onclick="selectRow(this, ${p.id_szczegolu}, ${p.id_przejazdu})"
                        ondblclick="openModal()"
                        data-info="${p.numer_pociagu} (${p.stacja_pocz} - ${p.stacja_konc})"
                        data-plan-p="${p.przyjazd ? p.przyjazd.substr(0,5) : ''}" 
                        data-plan-o="${p.odjazd ? p.odjazd.substr(0,5) : ''}"
                        data-rzecz-p="${val_rp}" 
                        data-rzecz-o="${val_ro}"
                        data-z="${p.stacja_pocz}" data-do="${p.stacja_konc}">
                        <td class="center"><input type="checkbox" ${p.czy_odwolany == 1 ? '' : 'checked'} disabled></td>
                        <td class="center">${p.czy_odwolany == 1 ? '<input type="checkbox" checked disabled>' : ''}</td>

                        <td class="bg-time t-cell">${p.przyjazd ? p.przyjazd.substr(0,5) : ''}</td>
                        <td class="${cls_arr}">${diff_arr != 0 ? diff_arr : ''}</td>
                        <td class="bg-blue t-cell" style="${style_rp}">${val_rp}</td>
                        <td class="${type_class}" style="color:${type_color}">${p.rodzaj}</td>
                        <td class="bg-green center">${nr_left}</td>
                        <td>${p.stacja_prev || ''}</td>
                        <td class="bg-green center">${nr_right}</td>
                        <td>${p.stacja_next || ''}</td>
                        <td class="bg-gray">${p.tor || ''}</td>
                        <td class="bg-gray">${p.peron || ''}</td>
                        <td class="center">${p.uwagi_postoju || ''}</td>
                        <td class="bg-time t-cell">${p.odjazd ? p.odjazd.substr(0,5) : ''}</td>
                        <td class="${cls_dep}">${diff_dep != 0 ? diff_dep : ''}</td>
                        <td class="bg-blue t-cell" style="${style_ro}">${val_ro}</td>
                        <td>${p.stacja_pocz}</td>
                        <td>${p.stacja_konc}</td>
                        <td>${p.przewoznik_skrot}</td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            });
    }

    function addMinutes(timeStr, mins) {
        if(!timeStr) return '';
        let [h, m] = timeStr.substr(0,5).split(':').map(Number);
        let d = new Date(); d.setHours(h); d.setMinutes(m + mins);
        return d.toLocaleTimeString('pl-PL', {hour:'2-digit', minute:'2-digit'});
    }

    function openTab(name) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + name).classList.add('active');
        if (document.querySelector('tr.selected')) document.querySelector('tr.selected').click();
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
            rzeczP: tr.dataset.rzeczP, rzeczO: tr.dataset.rzeczO
        };

        document.getElementById('lbl-z').innerText = tr.dataset.z;
        document.getElementById('lbl-do').innerText = tr.dataset.do;

        pobierzDaneTrasy(idPrzejazdu);
    }

function pobierzDaneTrasy(idPrzejazdu) {
        fetch('pobierz_dane.php?id_przejazdu=' + idPrzejazdu)
            .then(res => res.json())
            .then(data => {
                // --- CZĘŚĆ 1: Opis pociągu (bez zmian) ---
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
                
                if (data.trasa) {
                    // KROK A: Wstępne przeliczenie minut z bazy
                    let points = data.trasa.map(t => {
                        return { 
                            ...t, 
                            planP: t.przyjazd ? timeToMins(t.przyjazd) : null,
                            planO: t.odjazd ? timeToMins(t.odjazd) : null,
                            rzeczP: t.przyjazd_rzecz ? timeToMins(t.przyjazd_rzecz) : null,
                            rzeczO: t.odjazd_rzecz ? timeToMins(t.odjazd_rzecz) : null,
                            delayArr: 0, // Domyślnie
                            delayDep: 0, // Domyślnie
                            isEstimated: false 
                        };
                    });

                    // KROK B: KASKADA OPÓŹNIENIA (TO JEST KLUCZOWE)
                    let activeDelay = 0; // Przechowuje ostatnie znane opóźnienie (np. 12)

                    for (let i = 0; i < points.length; i++) {
                        // 1. Obliczamy, co mówi baza o opóźnieniu na wejściu
                        let dbDelayArr = 0;
                        if (points[i].rzeczP !== null && points[i].planP !== null) {
                            dbDelayArr = points[i].rzeczP - points[i].planP;
                        }

                        // LOGIKA PRZYJAZDU
                        if (dbDelayArr !== 0) {
                            // Jeśli baza ma konkretne opóźnienie (różne od 0), to jest to nasza nowa wytyczna
                            activeDelay = dbDelayArr;
                            points[i].delayArr = dbDelayArr;
                        } else {
                            // Jeśli baza pokazuje 0, ale mamy aktywne opóźnienie z poprzednich stacji -> NADPISUJEMY
                            if (activeDelay !== 0 && points[i].planP !== null) {
                                points[i].rzeczP = points[i].planP + activeDelay;
                                points[i].delayArr = activeDelay;
                                points[i].isEstimated = true; // Oznaczamy, że my to wyliczyliśmy
                            }
                        }

                        // 2. Obliczamy, co mówi baza o opóźnieniu na wyjściu
                        let dbDelayDep = 0;
                        if (points[i].rzeczO !== null && points[i].planO !== null) {
                            dbDelayDep = points[i].rzeczO - points[i].planO;
                        }

                        // LOGIKA ODJAZDU
                        if (dbDelayDep !== 0) {
                            // Baza ma konkretne opóźnienie -> aktualizujemy wiedzę
                            activeDelay = dbDelayDep;
                            points[i].delayDep = dbDelayDep;
                        } else {
                            // Baza ma 0 -> wymuszamy nasze opóźnienie
                            if (activeDelay !== 0 && points[i].planO !== null) {
                                points[i].rzeczO = points[i].planO + activeDelay;
                                points[i].delayDep = activeDelay;
                                points[i].isEstimated = true;
                            }
                        }
                    }

                    // KROK C: Renderowanie tabeli
                    points.forEach((t, i) => {
                        let pP = t.przyjazd ? t.przyjazd.substr(0,5) : '';
                        let pO = t.odjazd ? t.odjazd.substr(0,5) : '';
                        
                        // Konwersja minut z powrotem na HH:MM
                        let rP = t.rzeczP !== null ? minsToTime(t.rzeczP) : pP; 
                        let rO = t.rzeczO !== null ? minsToTime(t.rzeczO) : pO; 

                        // Stylizacja
                        // Jeśli wyliczyliśmy to sami (isEstimated) albo są to twarde dane z opóźnieniem -> pogrubione
                        let styleReal = 'color:#000000; font-weight:bold;';
                        
                        // Jeśli nie ma opóźnienia (0) i nie jest to estymacja (czyli czysty plan), na szaro
                        if (!t.isEstimated && t.delayArr === 0 && t.delayDep === 0 && (!t.przyjazd_rzecz && !t.odjazd_rzecz)) {
                             styleReal = 'color:#888; font-style:italic; font-weight:normal;';
                        }
                        
                        let rowStyleExtra = '';
                        if (t.czy_odwolany == 1) {
                            rowStyleExtra = 'background-color: #ffcccc !important; text-decoration: line-through; color: #cc0000 !important;';
                            styleReal = 'text-decoration: line-through; color: #cc0000;';
                        }

                        // Postoje
                        const calcStop = (startStr, endStr) => {
                            if (!startStr || !endStr) return '';
                            if (startStr.length === 5) startStr += ':00';
                            if (endStr.length === 5) endStr += ':00';
                            let start = new Date('1970-01-01T' + startStr);
                            let end = new Date('1970-01-01T' + endStr);
                            let diffMs = end - start;
                            if (diffMs <= 0) return ''; 
                            return (diffMs / 60000).toString().replace('.', ',');
                        };

                        let postojZam = calcStop(t.przyjazd, t.odjazd);
                        let postojObl = '';
                        if (t.rzeczP !== null && t.rzeczO !== null) {
                            let diff = t.rzeczO - t.rzeczP;
                            if(diff < 0) diff += 1440;
                            if (diff > 0.01) postojObl = Math.round(diff);
                        }
                        if (postojObl === '' && postojZam !== '') postojObl = postojZam;

                        let opozArr = Math.round(t.delayArr);
                        let opozDep = Math.round(t.delayDep);
                        // Zabezpieczenie: puste stringi jeśli 0 (opcjonalnie, zależy jak wolisz - tu zostawiam puste jak w oryginale)
                        let strOpArr = opozArr !== 0 ? opozArr : '';
                        let strOpDep = opozDep !== 0 ? opozDep : '';

                        let stArr = opozArr > 0 ? 'background:red; color:white; font-weight:bold;' : (opozArr < 0 ? 'background:green; color:white; font-weight:bold;' : '');
                        let stDep = opozDep > 0 ? 'background:red; color:white; font-weight:bold;' : (opozDep < 0 ? 'background:green; color:white; font-weight:bold;' : '');
                        
                        // Jeśli opóźnienie jest przeniesione (activeDelay > 0), a w tej komórce wyliczyło 0, to wymuś kolor
                        // (choć logika wyżej już powinna wpisać wartość do opozArr)

                        let rowClass = (t.zatwierdzony == 1) ? 'row-approved' : '';
                        let type_class = (t.przyjazd_rzecz || t.odjazd_rzecz) && !t.isEstimated ? 'type-approved' : 'center';
                        let rodzajPociagu = data.opis ? data.opis.rodzaj_skrot : '';

                        let row = `<tr class="${rowClass}" style="${rowStyleExtra}">
                            <td class="center" style="${stArr}">${strOpArr}</td>
                            <td class="center"><input type="checkbox" checked disabled></td>
                            <td class="center">${i+1}</td>
                            <td><b>${t.nazwa_stacji}</b></td>
                            <td class="bg-gray">${t.tor || ''}</td>
                            <td class="bg-gray">${t.peron || ''}</td>
                            <td class="center">${postojZam}</td>
                            <td class="center">${postojObl}</td>
                            <td class="center">${t.uwagi_postoju || ''}</td>
                            <td class="bg-time t-cell">${pP}</td>
                            <td class="bg-blue t-cell" style="${styleReal}">${rP}</td>
                            <td class="bg-time t-cell">${pO}</td>
                            <td class="bg-blue t-cell" style="${styleReal}">${rO}</td>
                            <td class="center" style="${stDep}">${strOpDep}</td>
                            <td class="${type_class}">${rodzajPociagu}</td>
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
                odswiezWykaz();
                if(currentPrzejazdId) pobierzDaneTrasy(currentPrzejazdId);
            } else { alert(res); }
        });
    }

    document.addEventListener('keydown', e => { if(e.key === 'F2') openModal(); });

    function timeToMins(timeStr) {
        if(!timeStr) return null;
        let [h, m] = timeStr.split(':').map(Number);
        return h * 60 + m;
    }
    function minsToTime(mins) {
        if(mins === null) return '';
        while(mins >= 1440) mins -= 1440;
        while(mins < 0) mins += 1440;
        let h = Math.floor(mins / 60);
        let m = Math.floor(mins % 60);
        return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
    }
</script>
</body>
</html>