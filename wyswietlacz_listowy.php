<?php
require 'db_config.php';
$id_przejazdu_wybranego = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;
$start_index = isset($_GET['start_index']) ? (int)$_GET['start_index'] : null;

$theme = $_COOKIE['theme'] ?? 'dark'; 

$stacje_list = [];
$info_pociagu = '';
$nazwa_pociagu = '';
$kierunek = '';

// Formularz ukrywamy tylko gdy wybrano pociąg ORAZ stację startową
$formularz_ukryty = ($id_przejazdu_wybranego && $start_index !== null);

$route_data_map = [];
$segments_data_map = [];

if ($id_przejazdu_wybranego) {
    $sql_info = "SELECT p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as typ_skrot, przew.skrot as przewoznik 
                 FROM przejazdy p
                 JOIN trasy t ON p.id_trasy = t.id_trasy
                 LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                 LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                 WHERE p.id_przejazdu = ?";
    $stmt_info = mysqli_prepare($conn, $sql_info);
    mysqli_stmt_bind_param($stmt_info, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_info);
    $przejazd_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_info));
    
    if ($przejazd_info) {
        $info_pociagu = ($przejazd_info['przewoznik'] ? $przejazd_info['przewoznik'] . ' ' : '') . $przejazd_info['typ_skrot'] . ' ' . $przejazd_info['numer_pociagu'];
        $nazwa_pociagu = $przejazd_info['nazwa_pociagu'];
        
        $sql_koncowa = "SELECT s.nazwa_stacji FROM trasy t JOIN stacje s ON t.id_stacji_koncowej = s.id_stacji WHERE t.id_trasy = (SELECT id_trasy FROM przejazdy WHERE id_przejazdu = ?)";
        $stmt_koncowa = mysqli_prepare($conn, $sql_koncowa);
        mysqli_stmt_bind_param($stmt_koncowa, "i", $id_przejazdu_wybranego);
        mysqli_stmt_execute($stmt_koncowa);
        $koncowa_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_koncowa));
        $kierunek = $koncowa_info['nazwa_stacji'] ?? 'Brak danych';
    }

    $sql_wyswietlane = "SELECT sr.id_szczegolu, s.nazwa_stacji, sr.przyjazd, sr.odjazd, sr.uwagi_postoju 
                        FROM szczegoly_rozkladu sr 
                        JOIN stacje s ON sr.id_stacji = s.id_stacji 
                        WHERE sr.id_przejazdu = ? AND (sr.uwagi_postoju = 'ph' OR sr.przyjazd IS NULL OR sr.odjazd IS NULL) AND sr.czy_odwolany = 0
                        ORDER BY sr.kolejnosc ASC";
    $stmt_wyswietlane = mysqli_prepare($conn, $sql_wyswietlane);
    mysqli_stmt_bind_param($stmt_wyswietlane, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_wyswietlane);
    $result_wyswietlane = mysqli_stmt_get_result($stmt_wyswietlane);
    $stacje_wyswietlane = mysqli_fetch_all($result_wyswietlane, MYSQLI_ASSOC);

    $sql_trasa = "SELECT sr.id_szczegolu, sr.kolejnosc, sr.id_stacji, s.nazwa_stacji, s.lat, s.lng, 
                         sr.przyjazd, sr.odjazd, sr.przyjazd_rzecz, sr.odjazd_rzecz 
                  FROM szczegoly_rozkladu sr 
                  JOIN stacje s ON sr.id_stacji = s.id_stacji 
                  WHERE sr.id_przejazdu = ? AND sr.czy_odwolany = 0
                  ORDER BY sr.kolejnosc ASC";
    $stmt_trasa = mysqli_prepare($conn, $sql_trasa);
    mysqli_stmt_bind_param($stmt_trasa, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_trasa);
    $res_trasa = mysqli_stmt_get_result($stmt_trasa);
    
    while($r = mysqli_fetch_assoc($res_trasa)) {
        $route_data_map[] = [
            'id_szczegolu' => $r['id_szczegolu'],
            'id_stacji' => $r['id_stacji'],
            'nazwa' => $r['nazwa_stacji'],
            'lat' => (float)$r['lat'],
            'lng' => (float)$r['lng'],
            'plan_p' => $r['przyjazd'],
            'plan_o' => $r['odjazd'],
            'rzecz_p' => $r['przyjazd_rzecz'],
            'rzecz_o' => $r['odjazd_rzecz']
        ];
    }

    for ($i = 0; $i < count($route_data_map) - 1; $i++) {
        $idA = $route_data_map[$i]['id_stacji'];
        $idB = $route_data_map[$i+1]['id_stacji'];
        
        $q_odc = mysqli_query($conn, "SELECT sciezka FROM odcinki WHERE (id_stacji_A = $idA AND id_stacji_B = $idB) OR (id_stacji_A = $idB AND id_stacji_B = $idA) LIMIT 1");
        $sciezka = [];
        if ($r_odc = mysqli_fetch_assoc($q_odc)) {
            if (!empty($r_odc['sciezka']) && $r_odc['sciezka'] !== 'null') {
                $sciezka = json_decode($r_odc['sciezka'], true);
                if (!empty($sciezka)) {
                    $distA = pow($sciezka[0][0] - $route_data_map[$i]['lat'], 2) + pow($sciezka[0][1] - $route_data_map[$i]['lng'], 2);
                    $distB = pow(end($sciezka)[0] - $route_data_map[$i]['lat'], 2) + pow(end($sciezka)[1] - $route_data_map[$i]['lng'], 2);
                    if ($distB < $distA) {
                        $sciezka = array_reverse($sciezka);
                    }
                }
            }
        }
        if (empty($sciezka)) {
            $sciezka = [
                [$route_data_map[$i]['lat'], $route_data_map[$i]['lng']],
                [$route_data_map[$i+1]['lat'], $route_data_map[$i+1]['lng']]
            ];
        }
        $segments_data_map[] = $sciezka;
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Nowoczesny Wyświetlacz Pociągu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        body { 
            font-family: 'Roboto', sans-serif; 
            margin: 0; padding: 0; 
            overflow: hidden; 
            background-color: #eef2f5; 
            color: #333;
        }
        .screen-container { width: 100vw; height: 100vh; display: flex; flex-direction: column; background: #fff; }
        .content-area { display: flex; height: calc(100% - 90px); overflow: hidden; }
        
        .left-panel { width: 60%; position: relative; z-index: 1; border-right: 2px solid #ddd; box-shadow: inset -5px 0 10px rgba(0,0,0,0.03); }
        #map { width: 100%; height: 100%; position: absolute; top: 0; left: 0; }
        .train-icon-container { background: transparent; border: none; }

        .right-panel { width: 40%; overflow: hidden; position: relative; display: flex; flex-direction: column; background-color: #ffffff; z-index: 2; }
        .header-info { background: #004080; color: #fff; font-size: 1.4em; font-weight: 700; padding: 20px 30px; flex-shrink: 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-transform: uppercase; letter-spacing: 1px; z-index: 10; }

        .station-list-container { overflow: hidden; flex-grow: 1; position: relative; padding: 20px; background: #fff; display: flex; flex-direction: column; }
        
        .station-list { list-style: none; padding: 0; margin: 0; position: relative; width: 100%; display: flex; flex-direction: column; height: 100%; }
        
        .station-list::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 20px;
            bottom: 20px;
            width: 4px;
            background: #004080;
            opacity: 0.15;
            z-index: 0;
        }

        .station-item { 
            padding: 10px 10px 10px 45px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            font-size: 1.25em; 
            position: relative; 
            border-bottom: 1px solid #f5f5f5;
            min-height: 45px; /* Kontrola wysokości dla spójnych obliczeń slotów */
        }

        .station-name { font-weight: 500; flex-grow: 1; color: #333; text-transform: uppercase; }
        .station-time { font-weight: 700; width: 120px; text-align: right; color: #555; line-height: 1.3; }

        .indicator { 
            width: 16px; height: 16px; border-radius: 50%; position: absolute; 
            left: 14px; top: 50%; transform: translateY(-50%); 
            background: #fff; border: 3px solid #ccc; z-index: 1; 
        }

        .status-past .station-name, .status-past .station-time { color: #a0a0a0; }
        .status-past .indicator { border-color: #a0a0a0; background: #f0f0f0; }
        
        .status-current { background-color: #e6f2ff; border-radius: 5px; border-bottom: none; box-shadow: 0 2px 10px rgba(0,64,128,0.1); margin: 10px 0;}
        .status-current .station-name { color: #004080; font-weight: 700; font-size: 1.3em; }
        .status-current .station-time { color: #004080; font-size: 1.3em;}
        .status-current .indicator { border-color: #004080; background: #004080; width: 22px; height: 22px; left: 11px; box-shadow: 0 0 10px rgba(0,64,128,0.4); }

        .status-future .station-name, .status-future .station-time { color: #222; }
        .status-future .indicator { border-color: #004080; }

        .fade-transition { transition: opacity 0.5s ease-in-out; }

        .bottom-bar { height: 90px; display: flex; width: 100%; background-color: #1a252f; color: #fff; box-shadow: 0 -3px 10px rgba(0,0,0,0.1); position: relative; z-index: 10; flex-shrink: 0;}
        .bottom-left { width: 75%; padding: 5px 25px; display: flex; flex-direction: column; justify-content: center; overflow: hidden; border-right: 1px solid #2c3e50; font-size: 1.6em; font-weight: 700; color: #ffcc00; letter-spacing: 1px; }
        .led-line { height: 35px; line-height: 35px; white-space: nowrap; display: flex; align-items: center; overflow: hidden; }
        .led-line-white { color: #fff; font-weight: 400; font-size: 0.9em; } 

        .ticker-wrapper { flex-grow: 1; overflow: hidden; min-width: 0; }
        .scrolling-text { display: inline-block; padding-left: 100%; animation: marquee 12s linear infinite; } 
        @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }

        .bottom-right { width: 25%; padding: 0 25px; display: flex; justify-content: space-between; align-items: center; }
        .clock-container { display: flex; flex-direction: column; align-items: flex-end; }
        #bottom-time { font-weight: 700; font-size: 2.2em; line-height: 1; margin-bottom: 5px;}
        #bottom-date { font-size: 1.1em; color: #aaa; }

        .form-container { position: absolute; top: 0; left: 0; width: 100%; z-index: 20; background-color: rgba(255, 255, 255, 0.95); padding: 20px; box-shadow: 0 5px 10px rgba(0,0,0,0.2); text-align: center; }
        .ukryty { display: none !important; }
        
        .controls-btn { position: absolute; top: 15px; right: 15px; z-index: 100; background: rgba(0,0,0,0.5); color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer; }
        .nav-buttons button { background: #2c3e50; border: none; color: #fff; border-radius: 5px; font-size: 1.2em; cursor: pointer; margin-left: 5px; width: 45px; height: 45px; transition: background 0.2s; }
        .nav-buttons button:hover { background: #34495e; }
        .nav-buttons button:disabled { opacity: 0.3; cursor: not-allowed; }

        body.dark-theme { background-color: #000; color: #fff; }
        body.dark-theme .screen-container { width: 100vw; height: 100vh; background: #000; box-shadow: none; border-radius: 0; }
        body.dark-theme .left-panel { background-color: #000; border-right: 2px solid #333; }
        body.dark-theme .leaflet-tile-pane { filter: invert(100%) hue-rotate(180deg) brightness(85%) contrast(90%); }
        body.dark-theme .right-panel { background-color: #001533; color: #fff; }
        body.dark-theme .header-info { color: #fff; border-bottom: 1px solid #004a80; background: #002244; }
        body.dark-theme .station-list-container { background: transparent; }
        body.dark-theme .station-item { border-color: #112244; }
        body.dark-theme .station-name, body.dark-theme .status-future .station-name { color: #fff; }
        body.dark-theme .station-time, body.dark-theme .status-future .station-time { color: #ccc; }
        body.dark-theme .status-past .station-name, body.dark-theme .status-past .station-time { color: #666; }
        body.dark-theme .status-past .indicator { background: #333; border-color: #666; }
        body.dark-theme .indicator { background: #555; border-color: #333; }
        body.dark-theme .status-current { background-color: rgba(255, 215, 0, 0.15); }
        body.dark-theme .status-current .station-name, body.dark-theme .status-current .station-time { color: #FFD700; }
        body.dark-theme .status-current .indicator { background-color: #FFD700; border-color: #001533; }
    </style>
</head>
<body class="<?php echo $theme; ?>-theme">

    <button id="btn-settings" class="controls-btn" onclick="document.querySelector('.form-container').classList.remove('ukryty')">⚙️ Ustawienia</button>

    <div class="form-container <?php if ($formularz_ukryty) echo 'ukryty'; ?>">
        <form method="GET" action="">
            <label for="id_przejazdu"><strong>1. Wybierz pociąg:</strong></label><br>
            <select name="id_przejazdu" id="id_przejazdu" onchange="this.form.submit()" style="padding: 5px; font-size: 16px; margin: 10px;">
                <option value="">-- Wybierz z listy --</option>
                <?php
                $sql_przejazdy = "SELECT p.id_przejazdu, p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as typ_skrot FROM przejazdy p JOIN trasy t ON p.id_trasy = t.id_trasy LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu ORDER BY p.data_utworzenia DESC";
                $res = mysqli_query($conn, $sql_przejazdy);
                while ($row = mysqli_fetch_assoc($res)) {
                    $opis = "Pociąg {$row['typ_skrot']} {$row['numer_pociagu']} ({$row['nazwa_pociagu']}) | {$row['nazwa_trasy']}";
                    $selected = ($id_przejazdu_wybranego == $row['id_przejazdu']) ? "selected" : "";
                    echo "<option value='{$row['id_przejazdu']}' {$selected}>{$opis}</option>";
                }
                ?>
            </select>
        </form>

        <?php if ($id_przejazdu_wybranego && !empty($stacje_wyswietlane)): ?>
        <form method="GET" action="" style="margin-top: 15px; border-top: 1px solid #ccc; padding-top: 15px;">
            <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu_wybranego ?>">
            <label for="start_index"><strong>2. Rozpocznij od stacji:</strong></label><br>
            <select name="start_index" id="start_index" style="padding: 5px; font-size: 16px; margin: 10px;">
                <?php foreach ($stacje_wyswietlane as $index => $stacja): ?>
                    <option value="<?= $index ?>"><?= ($index + 1) . ". " . $stacja['nazwa_stacji'] ?></option>
                <?php endforeach; ?>
            </select>
            <br><br>
            <button type="submit" onclick="document.getElementById('btn-settings').style.display='none';" style="background: #004080; color: white; padding: 10px 20px; border: none; font-size: 16px; border-radius: 5px; cursor: pointer;">URUCHOM EKRAN</button>
        </form>
        <?php endif; ?>
        
        <br>
        <button type="button" onclick="toggleTheme()" style="margin-right:10px; padding: 5px 10px;">Zmień motyw</button>
        <button onclick="document.querySelector('.form-container').classList.add('ukryty')" style="background: #ccc; border: none; padding: 5px 10px; cursor: pointer;">Zamknij panel</button>
    </div>
    
    <?php if ($id_przejazdu_wybranego && !empty($stacje_wyswietlane)): ?>
    <div class="screen-container">
        <div class="content-area">
            
            <div class="left-panel" id="map"></div>
            
            <div class="right-panel">
                <div class="header-info"><?= htmlspecialchars($info_pociagu) ?> ➔ <?= htmlspecialchars($kierunek) ?></div>
                <div class="station-list-container">
                    <ul id="station-list-view" class="station-list"></ul>
                </div>
            </div>
        </div>

        <div class="bottom-bar">
            <div class="bottom-left" id="bottom-left-content">
                 <div id="line1" class="led-line led-line-white"></div>
                 <div id="line2" class="led-line"></div>
            </div>
            <div class="bottom-right">
                <div class="nav-buttons">
                    <button id="btn-prev" onclick="navigateList('prev')">◀</button>
                    <button id="btn-next" onclick="navigateList('next')">▶</button>
                </div>
                <div class="clock-container">
                    <span id="bottom-time">--:--</span>
                    <span id="bottom-date">--.--.----</span>
                </div>
            </div>
        </div>
    </div>
    <audio id="announcement-audio" preload="auto"></audio>
    <?php elseif ($id_przejazdu_wybranego): ?>
        <div style="text-align: center; padding: 50px;">
            <h2>Brak danych o stacjach (Wszystkie odwołane lub brak rozkładu)</h2>
            <a href="?">Wróć do wyboru</a>
        </div>
    <?php endif; ?>

    <script>
    <?php if ($formularz_ukryty): ?>
        document.getElementById('btn-settings').style.display = 'none';
    <?php endif; ?>

    setInterval(() => {
        const now = new Date();
        document.getElementById('bottom-time').textContent = now.toTimeString().split(' ')[0].substring(0, 5);
        const day = String(now.getDate()).padStart(2, '0');
        const month = String(now.getMonth() + 1).padStart(2, '0');
        document.getElementById('bottom-date').textContent = `${day}.${month}.${now.getFullYear()}`;
    }, 1000);

    function toggleTheme() {
        const body = document.body;
        let newTheme = 'dark';
        if (body.classList.contains('dark-theme')) {
            body.classList.remove('dark-theme'); body.classList.add('light-theme'); newTheme = 'light';
        } else {
            body.classList.remove('light-theme'); body.classList.add('dark-theme'); newTheme = 'dark';
        }
        document.cookie = "theme=" + newTheme + ";path=/;max-age=31536000";
        if (typeof map !== 'undefined') { setTimeout(() => map.invalidateSize(), 100); }
    }

    if (typeof <?php echo json_encode($stacje_wyswietlane); ?> !== 'undefined' && <?php echo json_encode($stacje_wyswietlane); ?>.length > 0) {
        
        const visibleSchedule = <?php echo json_encode($stacje_wyswietlane); ?>;
        const routeDataMap = <?= json_encode($route_data_map ?? []) ?>;
        const segmentsDataMap = <?= json_encode($segments_data_map ?? []) ?>;
        let map;
        let trainMarkerMap;
        
        let simProgress = 1.0; 
        let simPath = [];
        let simTotalTimeSec = 60; 
        let lastPosForAngleMap = null;
        let dynamicRotatorInterval = null;

        const totalStations = visibleSchedule.length;
        let currentVisibleIndex = <?php echo $start_index !== null ? $start_index : 0; ?>;
        let displayMode = 1; 
        
        const stationList = document.getElementById('station-list-view');
        const btnPrev = document.getElementById('btn-prev');
        const btnNext = document.getElementById('btn-next');
        const line1 = document.getElementById('line1');
        const line2 = document.getElementById('line2');
        const audioPlayer = document.getElementById('announcement-audio');

        // BARDZO BLISKI ZOOM NA MAPIE (poziom 18)
        if (routeDataMap && routeDataMap.length > 0) {
            map = L.map('map', {zoomControl: false}).setView([routeDataMap[0].lat, routeDataMap[0].lng], 18); 
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, // Zezwalamy na głębsze przybliżenie
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            routeDataMap.forEach(st => {
                L.circleMarker([st.lat, st.lng], {radius: 5, color: '#004080', fillColor: '#fff', fillOpacity: 1}).addTo(map);
            });
            
            segmentsDataMap.forEach(path => {
                L.polyline(path, {color: '#004080', weight: 4, opacity: 0.6}).addTo(map);
            });

            const trainIcon = L.divIcon({
                html: `<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">
                          <div id="train-rotator" style="font-size: 40px; white-space: nowrap; line-height: 1; filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.6)); transition: transform 0.1s linear, font-size 0.1s ease-out; transform-origin: center center; display: inline-block;">
                              🚂🚃🚃
                          </div>
                       </div>`,
                className: 'train-icon-container',
                iconSize: [160, 70],
                iconAnchor: [80, 35]
            });
            
            trainMarkerMap = L.marker([routeDataMap[0].lat, routeDataMap[0].lng], {icon: trainIcon, zIndexOffset: 1000}).addTo(map);
            map.on('zoom', updateTrainSizesMap);
            
            setInterval(updateSimulatedRadar, 50); 
        }

        function timeToSecMap(t) {
            if(!t) return null;
            let p = t.split(':');
            return parseInt(p[0])*3600 + parseInt(p[1])*60 + (parseInt(p[2])||0);
        }

        function getSegmentLengthsMap(path) {
            let total = 0; let dists = [0];
            for(let i=0; i<path.length-1; i++) {
                let d = map.distance(path[i], path[i+1]);
                total += d; dists.push(total);
            }
            return {total: total, dists: dists};
        }
        
        function interpolatePositionMap(path, progress) {
            if(path.length === 0) return null;
            if(path.length === 1 || progress <= 0) return path[0];
            if(progress >= 1) return path[path.length-1];
            let geom = getSegmentLengthsMap(path);
            if (geom.total <= 0) return path[0]; 
            let targetDist = geom.total * progress;
            for(let i=0; i<path.length-1; i++) {
                if(targetDist >= geom.dists[i] && targetDist <= geom.dists[i+1]) {
                    let segLen = geom.dists[i+1] - geom.dists[i];
                    let segProg = (segLen === 0) ? 0 : (targetDist - geom.dists[i]) / segLen;
                    let lat = path[i][0] + (path[i+1][0] - path[i][0]) * segProg;
                    let lng = path[i][1] + (path[i+1][1] - path[i][1]) * segProg;
                    return [lat, lng];
                }
            }
            return path[path.length-1];
        }

        function getPathBetweenMapIndices(startIdx, endIdx) {
            let fullPath = [];
            if (startIdx === endIdx) return [ [routeDataMap[startIdx].lat, routeDataMap[startIdx].lng] ];
            
            let step = startIdx < endIdx ? 1 : -1;
            for (let i = startIdx; i !== endIdx; i += step) {
                let segIdx = step === 1 ? i : i - 1; 
                let segPath = segmentsDataMap[segIdx];
                if (!segPath) continue;
                if (step === -1) segPath = [...segPath].reverse();
                
                if (fullPath.length === 0) {
                    fullPath = [...segPath];
                } else {
                    fullPath = fullPath.concat(segPath.slice(1));
                }
            }
            return fullPath.length > 0 ? fullPath : [ [routeDataMap[startIdx].lat, routeDataMap[startIdx].lng] ];
        }

        function triggerSimulation(fromScheduleIdx, toScheduleIdx, mode) {
            if (!routeDataMap || routeDataMap.length === 0) return;

            let stFrom = visibleSchedule[Math.max(0, fromScheduleIdx)];
            let stTo = visibleSchedule[Math.max(0, toScheduleIdx)];

            let fromMapIdx = routeDataMap.findIndex(r => r.id_szczegolu == stFrom.id_szczegolu);
            let toMapIdx = routeDataMap.findIndex(r => r.id_szczegolu == stTo.id_szczegolu);

            if (fromMapIdx === -1) fromMapIdx = 0;
            if (toMapIdx === -1) toMapIdx = 0;

            if (mode === 1 || fromMapIdx === toMapIdx) {
                simPath = [[routeDataMap[toMapIdx].lat, routeDataMap[toMapIdx].lng]];
                simProgress = 1.0;
                simTotalTimeSec = 1;
            } else {
                simPath = getPathBetweenMapIndices(fromMapIdx, toMapIdx);
                simProgress = 0.0;

                let time1 = stFrom.odjazd || stFrom.przyjazd;
                let time2 = stTo.przyjazd || stTo.odjazd;
                let t1 = timeToSecMap(time1);
                let t2 = timeToSecMap(time2);
                
                if (t1 !== null && t2 !== null) {
                    if (t2 < t1) t2 += 86400; 
                    let diffSec = t2 - t1;
                    simTotalTimeSec = diffSec > 10 ? diffSec : 10; 
                } else {
                    simTotalTimeSec = 60; 
                }
            }
        }

        function updateSimulatedRadar() {
            if (!simPath || simPath.length === 0) return;

            if (simProgress < 1.0 && simTotalTimeSec > 0) {
                simProgress += (0.05 / simTotalTimeSec);
                if (simProgress > 1.0) simProgress = 1.0;
            }

            let pos = interpolatePositionMap(simPath, simProgress);
            if (pos) {
                trainMarkerMap.setLatLng(pos);
                map.panTo(pos, {animate: true, duration: 0.1}); 
                
                if (lastPosForAngleMap && (lastPosForAngleMap[0] !== pos[0] || lastPosForAngleMap[1] !== pos[1])) {
                    let p1 = map.project(lastPosForAngleMap);
                    let p2 = map.project(pos);
                    let currentAngle = Math.atan2(p2.y - p1.y, p2.x - p1.x) * (180 / Math.PI);
                    let rotator = document.getElementById('train-rotator');
                    if (rotator) {
                        if (Math.abs(currentAngle) <= 90) {
                            rotator.style.transform = `rotate(${currentAngle}deg) scaleX(-1)`;
                        } else {
                            rotator.style.transform = `rotate(${currentAngle - 180}deg) scaleX(1)`;
                        }
                    }
                }
                lastPosForAngleMap = pos;
            }
        }

        function updateTrainSizesMap() {
            if(!map) return;
            let z = map.getZoom();
            // Modyfikator dla jeszcze większego pociągu przy zoom 18
            let size = Math.max(16, z * 2.5); 
            let rotator = document.getElementById('train-rotator');
            if (rotator) rotator.style.fontSize = size + 'px';
        }

        // === SYSTEM TEKSTOWY I LOGIKA LISTY ===
        
        // ZAWSZE WYMUSZAMY P. i O. z br tagiem, chyba że w ogóle nie ma godzin
        function getStationTimes(station) { 
             let times = [];
             if (station.przyjazd) times.push('p. ' + station.przyjazd.substring(0, 5)); 
             if (station.odjazd) times.push('o. ' + station.odjazd.substring(0, 5)); 
             if (times.length === 0) return '--:--';
             return times.join('<br>');
        }

        function displayText(element, text) { 
            element.innerHTML = ''; 
            if (text.length <= 35) { 
                element.textContent = text; 
                return; 
            } 
            const wrapper = document.createElement('div');
            wrapper.className = 'ticker-wrapper'; 
            const scrollingSpan = document.createElement('span');
            scrollingSpan.className = 'scrolling-text';
            scrollingSpan.textContent = text; 
            const duration = Math.max(10, text.length * 0.15); 
            scrollingSpan.style.animation = `marquee ${duration}s linear infinite`; 
            wrapper.appendChild(scrollingSpan);
            element.appendChild(wrapper);
        }

        function renderScreen() {
            const currentIdx = currentVisibleIndex;
            let html = '';

            let container = document.querySelector('.station-list-container');
            let MAX_SLOTS = 6; 
            if (container && container.clientHeight > 0) {
                // Bezpieczne dzielenie wysokości kontenera na poszczególne stacje
                MAX_SLOTS = Math.floor((container.clientHeight - 40) / 65); 
            }
            if (MAX_SLOTS < 5) MAX_SLOTS = 5;

            clearInterval(dynamicRotatorInterval);

            // Kolekcja indeksów, które MOGĄ być pokazane na liście statycznie
            let staticIndices = new Set();
            
            // 1. Zawsze pokazujemy stację początkową (szara w historii)
            staticIndices.add(0); 
            
            // 2. Jeśli jesteśmy dalej, chcemy pokazać stację bezpośrednio poprzedzającą
            if (currentIdx > 0) staticIndices.add(currentIdx - 1); 
            
            // 3. Zawsze pokazujemy Aktualną (jeśli to nie meta)
            staticIndices.add(currentIdx); 
            
            // 4. Zawsze pokazujemy Stację końcową
            if (totalStations > 1) staticIndices.add(totalStations - 1);

            let slotsLeft = MAX_SLOTS - staticIndices.size;
            
            let futureStart = currentIdx + 1;
            let futureEnd = totalStations - 1; 
            let futureCount = futureEnd - futureStart;
            
            let futureStationsForDynamic = [];

            // Jeśli jest więcej przyszłych stacji niż wolnego miejsca
            if (futureCount > 0) {
                if (futureCount > slotsLeft) {
                    slotsLeft--; // Rezerwujemy 1 slot na migający blok "Zastępczy"
                    for (let i = futureStart; i < futureEnd; i++) {
                        if (slotsLeft > 0) {
                            staticIndices.add(i);
                            slotsLeft--;
                        } else {
                            futureStationsForDynamic.push(visibleSchedule[i]);
                        }
                    }
                } else {
                    // Mamy dużo miejsca - pakujemy wszystkie
                    for (let i = futureStart; i < futureEnd; i++) {
                        staticIndices.add(i);
                        slotsLeft--;
                    }
                }
            }

            // Jeśli pociąg już prawie dojechał i zostało nam puste miejsce, dopychamy starą historię, 
            // żeby ekran nie wyglądał na pusty
            let pastIdx = currentIdx - 2;
            while (slotsLeft > 0 && pastIdx > 0) {
                staticIndices.add(pastIdx);
                pastIdx--;
                slotsLeft--;
            }

            // Sortujemy chronologicznie, żeby wyrenderować po kolei
            let sortedIndices = Array.from(staticIndices).sort((a,b) => a-b);
            
            let lastRendered = -1;
            for (let i of sortedIndices) {
                
                // Przerywnik jeśli zniknęła jakaś historia między stacjami (kropeczki pionowe)
                if (lastRendered !== -1 && i - lastRendered > 1) {
                    if (lastRendered >= currentIdx) {
                        // PRZESKAKUJĄCY BLOK DYNAMICZNY (Z Przyszłości)
                        html += `<li class="station-item status-future" style="min-height: 45px;">
                                    <span class="indicator" style="background: transparent; border: none; font-size: 22px; color: #004080; left: 10px; top: 40%;">↓</span>
                                    <span class="station-name fade-transition" id="dyn-name">...</span>
                                    <span class="station-time fade-transition" id="dyn-time">...</span>
                                 </li>`;
                    } else {
                        // Trzykropek usuwanej historii
                        html += `<li style="padding: 0 0 0 51px; color: #aaa; font-size: 20px; min-height: 20px; border: none; display: flex; align-items: flex-start;">⋮</li>`;
                    }
                }

                let st = visibleSchedule[i];
                let cls = '';
                if (i < currentIdx) cls = 'status-past';
                else if (i === currentIdx) cls = 'status-current';
                else cls = 'status-future';

                let extraStyle = '';
                // Blokowanie końcowej stacji na dole
                if (i === totalStations - 1 && totalStations > 1) {
                    extraStyle = 'margin-top: auto; border-top: 2px solid #ddd; padding-top: 15px;';
                }

                html += `<li class="station-item ${cls}" style="${extraStyle}">
                            <span class="indicator"></span>
                            <span class="station-name">${st.nazwa_stacji.toUpperCase()}</span>
                            <span class="station-time">${getStationTimes(st)}</span>
                         </li>`;
                
                lastRendered = i;
            }

            stationList.innerHTML = html;

            // Inicjalizacja rotacji przedostatniego bloku
            if (futureStationsForDynamic.length > 0) {
                let fIdx = 0;
                setTimeout(() => {
                    const nEl = document.getElementById('dyn-name');
                    const tEl = document.getElementById('dyn-time');
                    if(nEl && tEl) {
                        nEl.innerText = futureStationsForDynamic[0].nazwa_stacji.toUpperCase();
                        tEl.innerHTML = getStationTimes(futureStationsForDynamic[0]);
                    }
                }, 50);

                if (futureStationsForDynamic.length > 1) {
                    dynamicRotatorInterval = setInterval(() => {
                        fIdx = (fIdx + 1) % futureStationsForDynamic.length;
                        const nEl = document.getElementById('dyn-name');
                        const tEl = document.getElementById('dyn-time');
                        if (nEl && tEl) {
                            nEl.style.opacity = 0;
                            tEl.style.opacity = 0;
                            setTimeout(() => {
                                nEl.innerText = futureStationsForDynamic[fIdx].nazwa_stacji.toUpperCase();
                                tEl.innerHTML = getStationTimes(futureStationsForDynamic[fIdx]);
                                nEl.style.opacity = 1;
                                tEl.style.opacity = 1;
                            }, 500);
                        }
                    }, 4000);
                }
            }

            // Pasek dolny
            const station = visibleSchedule[currentVisibleIndex];
            const stationName = station.nazwa_stacji.toUpperCase();
            
            let bottomBarTime = '';
            if (station.przyjazd) bottomBarTime += 'p. ' + station.przyjazd.substring(0, 5) + ' ';
            if (station.odjazd) bottomBarTime += 'o. ' + station.odjazd.substring(0, 5);
            
            if (displayMode === 0) { 
                displayText(line1, 'NASTĘPNA STACJA:');
                displayText(line2, stationName + ' (' + bottomBarTime.trim() + ')');
            } else { 
                if (currentIdx === totalStations - 1) {
                    displayText(line1, 'STACJA KOŃCOWA:');
                } else {
                    displayText(line1, 'STACJA:');
                }
                displayText(line2, stationName + ' (' + bottomBarTime.trim() + ')');
            }

            btnPrev.disabled = (currentIdx === 0 && displayMode === 1);
            btnNext.disabled = (currentIdx === totalStations - 1 && displayMode === 1);
        }

        // === SYSTEM AUDIO ===
        let audioPlaylist = [];
        let currentAudioIndex = 0;
        
        function getFileName(stationName) {
            return stationName.replace(/ł/g, 'l').replace(/Ł/g, 'L')
                              .replace(/[ąćęłńóśźż]/g, c => ({'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z'}[c]))
                              .replace(/[\. ]/g, '_');
        }
        
        function playSequential(files) {
            audioPlayer.pause();
            audioPlayer.currentTime = 0;
            audioPlayer.onended = null;
            audioPlaylist = files;
            currentAudioIndex = 0;
            if (audioPlaylist.length === 0) return;

            audioPlayer.onended = () => {
                currentAudioIndex++;
                if (currentAudioIndex < audioPlaylist.length) {
                    audioPlayer.src = audioPlaylist[currentAudioIndex];
                    audioPlayer.play().catch(e => console.log("Audio err:", e));
                } else {
                    audioPlayer.onended = null;
                    audioPlaylist = [];
                }
            };
            audioPlayer.src = audioPlaylist[0];
            audioPlayer.play().catch(e => console.log("Audio start err:", e));
        }

        function playAnnouncement(stationName, mode) {
            const prefix = mode === 0 ? 'n_' : 's_';
            const fileName = getFileName(stationName);
            const fullPath = `dzwiek/${prefix}${fileName}.mp3`;
            playSequential([fullPath]);
        }

        function playDestinationAnnouncement() {
            const destinationFileName = getFileName("<?php echo htmlspecialchars($kierunek); ?>"); 
            playSequential(['dzwiek/stacja_koncowa1.mp3', `dzwiek/s_${destinationFileName}.mp3`]);
        }
        
        // === NAWIGACJA Z KLIKNIĘĆ ===
        function navigateList(direction) {
            let prevIdx = currentVisibleIndex;

            if (direction === 'next') {
                if (displayMode === 1) { 
                    if (currentVisibleIndex < totalStations - 1) {
                        currentVisibleIndex++; 
                        displayMode = 0; 
                        triggerSimulation(prevIdx, currentVisibleIndex, 0); 
                        playAnnouncement(visibleSchedule[currentVisibleIndex].nazwa_stacji, 0); 
                    }
                } else { 
                    displayMode = 1; 
                    triggerSimulation(currentVisibleIndex, currentVisibleIndex, 1); 
                    // Sprawdzamy czy to już stacja końcowa
                    if (currentVisibleIndex === totalStations - 1) {
                        playDestinationAnnouncement();
                    } else {
                        playAnnouncement(visibleSchedule[currentVisibleIndex].nazwa_stacji, 1); 
                    }
                }
            } else if (direction === 'prev') {
                if (displayMode === 0) { 
                    displayMode = 1; 
                    triggerSimulation(currentVisibleIndex - 1, currentVisibleIndex - 1, 1); 
                } else { 
                    if (currentVisibleIndex > 0) {
                        currentVisibleIndex--; 
                        displayMode = 0; 
                        triggerSimulation(currentVisibleIndex + 1, currentVisibleIndex, 0); 
                    }
                }
            }
            renderScreen();
        }
        
        triggerSimulation(currentVisibleIndex, currentVisibleIndex, 1);
        renderScreen(); 
    }
    </script>
</body>
</html>