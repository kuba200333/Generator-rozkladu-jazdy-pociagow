<?php
require 'db_config.php';

// --- OBSŁUGA AJAX: Pobieranie najświeższego rozkładu dla WSZYSTKICH pociągów w tle ---
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_schedules') {
    // KLUCZOWA ZMIANA: Dodajemy warunek czy_odwolany = 0, żeby radar pobierał tylko aktywne stacje
    $sql = "SELECT sr.id_przejazdu, sr.id_stacji, s.nazwa_stacji, s.lat, s.lng, 
                   sr.przyjazd, sr.odjazd, sr.przyjazd_rzecz, sr.odjazd_rzecz 
            FROM szczegoly_rozkladu sr 
            JOIN stacje s ON sr.id_stacji = s.id_stacji 
            WHERE sr.czy_odwolany = 0
            ORDER BY sr.id_przejazdu, sr.kolejnosc ASC";
    $res = mysqli_query($conn, $sql);
    $data = [];
    while($r = mysqli_fetch_assoc($res)) {
        $data[$r['id_przejazdu']][] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// --- POBIERANIE WSZYSTKICH POCIĄGÓW I ICH TRAS ---
$all_trains = [];

$q_poc = mysqli_query($conn, "SELECT p.id_przejazdu, p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as kat_skrot 
                              FROM przejazdy p 
                              JOIN trasy t ON p.id_trasy = t.id_trasy
                              JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu");
while($p = mysqli_fetch_assoc($q_poc)) {
    $all_trains[$p['id_przejazdu']] = [
        'info' => $p,
        'schedule' => [],
        'segments' => []
    ];
}

// KLUCZOWA ZMIANA: Filtracja stacji przy starcie - pomijamy odwołane
$q_st = mysqli_query($conn, "SELECT sr.id_przejazdu, sr.kolejnosc, sr.id_stacji, s.nazwa_stacji, s.lat, s.lng, 
                             sr.przyjazd, sr.odjazd, sr.przyjazd_rzecz, sr.odjazd_rzecz 
                             FROM szczegoly_rozkladu sr 
                             JOIN stacje s ON sr.id_stacji = s.id_stacji 
                             WHERE sr.czy_odwolany = 0
                             ORDER BY sr.id_przejazdu, sr.kolejnosc ASC");
while($r = mysqli_fetch_assoc($q_st)) {
    $id_p = $r['id_przejazdu'];
    if(isset($all_trains[$id_p])) {
        $all_trains[$id_p]['schedule'][] = $r;
    }
}

// Pobieranie odcinków dla logiki krzywych linii
$q_odc = mysqli_query($conn, "SELECT id_stacji_A, id_stacji_B, sciezka FROM odcinki");
$odcinki_lookup = [];
while($r = mysqli_fetch_assoc($q_odc)) {
    $k1 = $r['id_stacji_A'] . '_' . $r['id_stacji_B'];
    $k2 = $r['id_stacji_B'] . '_' . $r['id_stacji_A'];
    $sciezka = (!empty($r['sciezka']) && $r['sciezka'] !== 'null') ? json_decode($r['sciezka'], true) : [];
    $odcinki_lookup[$k1] = $sciezka;
    $odcinki_lookup[$k2] = $sciezka; 
}

// Dopasowywanie ścieżek
foreach($all_trains as $id_p => &$train) {
    $sched = $train['schedule'];
    // Jeśli pociąg został tak odwołany, że ma mniej niż 2 stacje, to usuwamy go z mapy
    if(count($sched) < 2) {
        $train['schedule'] = [];
        continue;
    }
    
    for($i=0; $i<count($sched)-1; $i++) {
        $idA = $sched[$i]['id_stacji'];
        $idB = $sched[$i+1]['id_stacji'];
        $k1 = $idA . '_' . $idB;
        $k2 = $idB . '_' . $idA;
        
        $path = [];
        if(isset($odcinki_lookup[$k1]) && !empty($odcinki_lookup[$k1])) {
            $path = $odcinki_lookup[$k1];
        } elseif(isset($odcinki_lookup[$k2]) && !empty($odcinki_lookup[$k2])) {
            $path = $odcinki_lookup[$k2];
        }
        
        if(!empty($path)) {
            $distA = pow($path[0][0] - $sched[$i]['lat'], 2) + pow($path[0][1] - $sched[$i]['lng'], 2);
            $distB = pow(end($path)[0] - $sched[$i]['lat'], 2) + pow(end($path)[1] - $sched[$i]['lng'], 2);
            if ($distB < $distA) {
                $path = array_reverse($path);
            }
        } else {
            $path = [
                [$sched[$i]['lat'], $sched[$i]['lng']],
                [$sched[$i+1]['lat'], $sched[$i+1]['lng']]
            ];
        }
        $train['segments'][] = $path;
    }
}
unset($train);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Globalny Radar Pociągów na Żywo</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; flex-direction: column; height: 100vh; }
        .top-bar { background: #004080; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3); z-index: 1000; position: relative; }
        .top-bar a { color: white; text-decoration: none; font-weight: bold; background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 4px; }
        
        .controls-group { display: flex; gap: 20px; align-items: center; }
        .track-checkbox { display: flex; align-items: center; gap: 5px; font-size: 14px; font-weight: bold; background: rgba(255,255,255,0.1); padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .track-checkbox input { cursor: pointer; width: 16px; height: 16px; }

        #map { flex-grow: 1; width: 100%; }
        
        .train-icon-container { background: transparent; border: none; cursor: pointer !important; }
        
        .info-box { position: absolute; bottom: 20px; left: 20px; background: rgba(255,255,255,0.95); padding: 15px 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.3); z-index: 1000; border-left: 5px solid #004080; min-width: 300px; display: none; }
        .info-box h3 { margin: 0 0 10px 0; color: #004080; font-size: 18px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
        .info-box div { font-size: 14px; margin-bottom: 6px; color: #333; }
        
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-weight: bold; font-size: 13px; color: white; margin-left: 5px; }
        .status-jazda { background: #28a745; }
        .status-postoj { background: #ffc107; color: black; }
        
        .station-block { background: #f8f9fa; border: 1px solid #ddd; padding: 10px; border-radius: 4px; margin-top: 10px; }
        .station-name { font-size: 16px; font-weight: bold; color: #004080; margin-bottom: 8px; }
        .time-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .delay-row { margin-top: 8px; font-weight: bold; font-size: 13px; text-align: right; }
        .delay-red { color: #dc3545; }
        .delay-green { color: #28a745; }

        #instruction-box { position: absolute; top: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,64,128,0.9); color: white; padding: 10px 20px; border-radius: 20px; font-weight: bold; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.3); pointer-events: none; }
        
        /* Chowanie nazw stacji przy oddaleniu */
        .stacja-label { background: rgba(255,255,255,0.9); border: 1px solid #004080; font-weight: bold; font-size: 11px; padding: 2px 4px; color: #004080; transition: opacity 0.2s; }
        .map-zoomed-out .stacja-label { opacity: 0 !important; pointer-events: none; }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="controls-group">
        <label style="font-weight:bold; font-size: 18px; margin-right: 10px;">📡 Globalny Radar Sieci</label>
        <label class="track-checkbox">
            <input type="checkbox" id="track-cam" checked>
            Śledź wybrany pociąg kamerą
        </label>
    </div>
    <div>
        <span id="zegar" style="font-size: 20px; font-family: monospace; font-weight: bold; margin-right: 20px;">00:00:00</span>
        <a href="index.php">Powrót do menu</a>
    </div>
</div>

<div id="instruction-box">Kliknij pociąg na mapie, aby sprawdzić szczegóły i za nim podążać!</div>

<div id="map"></div>

<div class="info-box" id="info-box">
    <h3 id="panel-nr">Pociąg ---</h3>
    <div>Status:<span id="panel-status" class="status-badge">---</span></div>
    <div id="panel-data" class="station-block">
        <div class="station-name" id="st-name">---</div>
        <div class="time-row"><span>Przyjazd:</span> <span id="st-arr">---</span></div>
        <div class="time-row"><span>Odjazd:</span> <span id="st-dep">---</span></div>
        <div class="delay-row" id="st-delay"></div>
    </div>
</div>

<script>
    setInterval(() => { document.getElementById('zegar').innerText = new Date().toLocaleTimeString('pl-PL', {hour12:false}); }, 1000);

    const map = L.map('map').setView([53.4285, 14.5528], 9);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap' }).addTo(map);

    const ZOOM_THRESHOLD = 11;
    function toggleLabels() {
        if (map.getZoom() < ZOOM_THRESHOLD) {
            document.getElementById('map').classList.add('map-zoomed-out');
        } else {
            document.getElementById('map').classList.remove('map-zoomed-out');
        }
    }
    map.on('zoomend', toggleLabels);
    toggleLabels();

    let allTrains = <?= json_encode($all_trains) ?>;
    let markers = {}; 
    let lastPosForAngle = {};
    let trackedTrainId = null;

    let drawnStations = new Set();
    let bounds = [];
    Object.values(allTrains).forEach(train => {
        train.schedule.forEach(st => {
            if(!drawnStations.has(st.id_stacji)) {
                L.circleMarker([st.lat, st.lng], {radius: 4, color: '#666', fillColor: '#fff', fillOpacity: 1})
                 .addTo(map).bindTooltip(st.nazwa_stacji, {permanent: true, direction: 'top', className: 'stacja-label'});
                drawnStations.add(st.id_stacji);
                bounds.push([st.lat, st.lng]);
            }
        });
        train.segments.forEach(path => {
            L.polyline(path, {color: '#888', weight: 2, opacity: 0.3}).addTo(map);
        });
    });
    
    if(bounds.length > 0) map.fitBounds(bounds, {padding: [50, 50]});

    Object.values(allTrains).forEach(train => {
        if(train.schedule.length === 0) return;
        
        const trainIcon = L.divIcon({
            html: `<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">
                      <div id="rotator-${train.info.id_przejazdu}" style="font-size: 26px; white-space: nowrap; line-height: 1; filter: drop-shadow(2px 2px 2px rgba(0,0,0,0.5)); transition: transform 0.2s linear, font-size 0.1s ease-out; transform-origin: center center; display: inline-block;">
                          🚂🚃🚃
                      </div>
                   </div>`,
            className: 'train-icon-container',
            iconSize: [120, 50],
            iconAnchor: [60, 25]
        });
        
        let marker = L.marker([0, 0], {icon: trainIcon, zIndexOffset: 1000});
        
        marker.on('click', function() {
            trackedTrainId = train.info.id_przejazdu;
            document.getElementById('info-box').style.display = 'block';
            document.getElementById('instruction-box').style.display = 'none';
            Object.values(markers).forEach(m => m.setZIndexOffset(1000));
            marker.setZIndexOffset(2000);
            updateRadar();
        });

        markers[train.info.id_przejazdu] = marker;
    });

    function timeToSec(t) {
        if(!t) return null;
        let p = t.split(':');
        return parseInt(p[0])*3600 + parseInt(p[1])*60 + (parseInt(p[2])||0);
    }

    function calcDelayMin(plan, rzecz) {
        if (!plan || !rzecz) return 0;
        let pSec = timeToSec(plan);
        let rSec = timeToSec(rzecz);
        if (rSec < pSec && pSec - rSec > 43200) rSec += 86400; 
        if (pSec < rSec && rSec - pSec > 43200) pSec += 86400; 
        return Math.round((rSec - pSec) / 60);
    }

    function formatTimeHTML(plan, rzecz) {
        if (!plan) return '-';
        let p = plan.substring(0,5);
        if (rzecz && rzecz !== plan) {
            let r = rzecz.substring(0,5);
            return `<s style="color:#dc3545;">${p}</s> <b style="color:#28a745;">${r}</b>`;
        }
        return `<b>${p}</b>`;
    }

    function parseSchedule(schedData) {
        let temp = [];
        let lastSec = -1;
        
        schedData.forEach(st => {
            let effArr = st.rzecz_p || st.przyjazd_rzecz || st.plan_p || st.przyjazd;
            let effDep = st.rzecz_o || st.odjazd_rzecz || st.plan_o || st.odjazd;
            
            let arr = timeToSec(effArr);
            let dep = timeToSec(effDep);
            
            if (arr !== null) {
                if (lastSec !== -1 && arr < lastSec) {
                    if (lastSec - arr > 43200) arr += 86400; 
                    else arr = lastSec; 
                }
                lastSec = arr;
            }
            
            if (dep !== null) {
                if (lastSec !== -1 && dep < lastSec) {
                    if (lastSec - dep > 43200) dep += 86400; 
                    else dep = lastSec; 
                }
                lastSec = dep;
            }
            
            temp.push({
                nazwa: st.nazwa || st.nazwa_stacji,
                arr: arr,
                dep: dep,
                lat: parseFloat(st.lat),
                lng: parseFloat(st.lng),
                plan_p: st.plan_p || st.przyjazd,
                rzecz_p: st.rzecz_p || st.przyjazd_rzecz,
                plan_o: st.plan_o || st.odjazd,
                rzecz_o: st.rzecz_o || st.odjazd_rzecz
            });
        });
        return temp;
    }

    function syncSchedules() {
        fetch('radar_pociagu.php?ajax=get_schedules')
        .then(res => res.json())
        .then(data => {
            for(let id_p in data) {
                if(allTrains[id_p]) {
                    allTrains[id_p].schedule = data[id_p];
                }
            }
        })
        .catch(err => console.error("Błąd synchronizacji:", err));
    }
    setInterval(syncSchedules, 5000);

    function getSegmentLengths(path) {
        let total = 0;
        let dists = [0];
        for(let i=0; i<path.length-1; i++) {
            let d = map.distance(path[i], path[i+1]);
            total += d;
            dists.push(total);
        }
        return {total: total, dists: dists};
    }

    function interpolatePosition(path, progress) {
        if(path.length === 0) return null;
        if(path.length === 1 || progress <= 0) return path[0];
        if(progress >= 1) return path[path.length-1];

        let geom = getSegmentLengths(path);
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

    function updateUI(trainInfo, statusText, statusClass, stacjaNazwa, arrPlan, arrRzecz, depPlan, depRzecz) {
        document.getElementById('panel-nr').innerText = `${trainInfo.kat_skrot} ${trainInfo.numer_pociagu} ${trainInfo.nazwa_pociagu ? `"${trainInfo.nazwa_pociagu}"` : ''}`;
        
        let statusEl = document.getElementById('panel-status');
        statusEl.innerText = statusText;
        statusEl.className = "status-badge " + statusClass;
        
        document.getElementById('st-name').innerText = stacjaNazwa;
        document.getElementById('st-arr').innerHTML = formatTimeHTML(arrPlan, arrRzecz);
        document.getElementById('st-dep').innerHTML = formatTimeHTML(depPlan, depRzecz);
        
        let delay = 0;
        if (depRzecz) delay = calcDelayMin(depPlan, depRzecz);
        else if (arrRzecz) delay = calcDelayMin(arrPlan, arrRzecz);
        
        let delayEl = document.getElementById('st-delay');
        if (delay > 0) {
            delayEl.innerHTML = `Opóźnienie: <span class="delay-red">+${delay} min</span>`;
        } else if (delay < 0) {
            delayEl.innerHTML = `Przyspieszenie: <span class="delay-green">${delay} min</span>`;
        } else {
            delayEl.innerHTML = `<span class="delay-green">Punktualnie</span>`;
        }
    }

    function updateTrainSizes() {
        let z = map.getZoom();
        let size = Math.max(10, z * 1.8);
        Object.keys(allTrains).forEach(id => {
            let rotator = document.getElementById('rotator-' + id);
            if (rotator) rotator.style.fontSize = size + 'px';
        });
    }
    map.on('zoom', updateTrainSizes);

    function updateRadar() {
        let now = new Date();
        let currentSec = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();

        Object.values(allTrains).forEach(train => {
            if (train.schedule.length === 0) return;
            
            let parsedSchedule = parseSchedule(train.schedule);
            if(parsedSchedule.length < 2) return; // Zabezpieczenie przed jednoelementowymi trasami
            
            let marker = markers[train.info.id_przejazdu];
            if (!marker) return;
            
            let tempCurrentSec = currentSec;
            let startSec = parsedSchedule[0].dep !== null ? parsedSchedule[0].dep : parsedSchedule[0].arr;
            
            if (startSec > 43200 && tempCurrentSec < 43200) {
                tempCurrentSec += 86400;
            }

            let endSec = parsedSchedule[parsedSchedule.length-1].arr !== null ? parsedSchedule[parsedSchedule.length-1].arr : parsedSchedule[parsedSchedule.length-1].dep;

            let isActive = false;
            let currentPos = null;
            let currentAngle = null;

            if (tempCurrentSec >= startSec && tempCurrentSec <= endSec) {
                isActive = true;
                
                for(let i=0; i<parsedSchedule.length; i++) {
                    let st = parsedSchedule[i];
                    
                    if (st.arr !== null && st.dep !== null && tempCurrentSec >= st.arr && tempCurrentSec <= st.dep) {
                        currentPos = [st.lat, st.lng];
                        
                        if(trackedTrainId === train.info.id_przejazdu) {
                            updateUI(train.info, "Na stacji", "status-postoj", "Obecnie stacja: " + st.nazwa, st.plan_p, st.rzecz_p, st.plan_o, st.rzecz_o);
                            if(document.getElementById('track-cam').checked) map.panTo(currentPos, {animate: true, duration: 1});
                        }
                        break;
                    }
                    
                    if (i < parsedSchedule.length - 1) {
                        let nextSt = parsedSchedule[i+1];
                        let odjazd = st.dep !== null ? st.dep : st.arr;
                        let przyjazd = nextSt.arr !== null ? nextSt.arr : nextSt.dep;
                        
                        if (tempCurrentSec > odjazd && tempCurrentSec < przyjazd) {
                            let totalTime = przyjazd - odjazd;
                            let elapsed = tempCurrentSec - odjazd;
                            let progress = elapsed / totalTime;
                            
                            // Zabezpieczenie, czy ten segment trasy w ogóle istnieje w tablicy segments
                            if(train.segments && train.segments[i]) {
                                let path = train.segments[i];
                                currentPos = interpolatePosition(path, progress);
                                
                                if(trackedTrainId === train.info.id_przejazdu) {
                                    updateUI(train.info, "W trasie", "status-jazda", "Następna stacja: " + nextSt.nazwa, nextSt.plan_p, nextSt.rzecz_p, nextSt.plan_o, nextSt.rzecz_o);
                                    if(document.getElementById('track-cam').checked) map.panTo(currentPos, {animate: true, duration: 1});
                                }
                                
                                let lastPos = lastPosForAngle[train.info.id_przejazdu];
                                if (lastPos && (lastPos[0] !== currentPos[0] || lastPos[1] !== currentPos[1])) {
                                    let p1 = map.project(lastPos);
                                    let p2 = map.project(currentPos);
                                    currentAngle = Math.atan2(p2.y - p1.y, p2.x - p1.x) * (180 / Math.PI);
                                }
                            }
                            lastPosForAngle[train.info.id_przejazdu] = currentPos;
                            break;
                        }
                    }
                }
            }

            if (isActive && currentPos) {
                if (!map.hasLayer(marker)) marker.addTo(map);
                marker.setLatLng(currentPos);
                
                if (currentAngle !== null) {
                    let rotator = document.getElementById('rotator-' + train.info.id_przejazdu);
                    if (rotator) {
                        if (Math.abs(currentAngle) <= 90) {
                            rotator.style.transform = `rotate(${currentAngle}deg) scaleX(-1)`;
                        } else {
                            rotator.style.transform = `rotate(${currentAngle - 180}deg) scaleX(1)`;
                        }
                    }
                }
            } else {
                if (map.hasLayer(marker)) map.removeLayer(marker);
            }
        });
        
        updateTrainSizes(); 
    }

    setInterval(updateRadar, 1000);
    updateRadar();

</script>

</body>
</html>