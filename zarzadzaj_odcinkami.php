<?php
require 'db_config.php';

// --- AUTO-MIGRACJA BAZY ---
$check_lat = mysqli_query($conn, "SHOW COLUMNS FROM stacje LIKE 'lat'");
if(mysqli_num_rows($check_lat) == 0) {
    mysqli_query($conn, "ALTER TABLE stacje ADD lat DECIMAL(10,6) NULL, ADD lng DECIMAL(10,6) NULL");
}

$check_sciezka = mysqli_query($conn, "SHOW COLUMNS FROM odcinki LIKE 'sciezka'");
if(mysqli_num_rows($check_sciezka) == 0) {
    mysqli_query($conn, "ALTER TABLE odcinki ADD sciezka TEXT NULL");
}

// --- OBSŁUGA ZAPISU POZYCJI W TLE (AJAX) ---
if (isset($_POST['ajax_action'])) {
    
    if ($_POST['ajax_action'] == 'save_pos') {
        $id = (int)$_POST['id_stacji'];
        $lat = (float)$_POST['lat'];
        $lng = (float)$_POST['lng'];
        mysqli_query($conn, "UPDATE stacje SET lat = $lat, lng = $lng WHERE id_stacji = $id");
        exit('OK');
    }
    
    if ($_POST['ajax_action'] == 'save_path') {
        $id1 = (int)$_POST['id1'];
        $id2 = (int)$_POST['id2'];
        $sciezka = mysqli_real_escape_string($conn, $_POST['sciezka']);
        if ($id1 > 0) mysqli_query($conn, "UPDATE odcinki SET sciezka = '$sciezka' WHERE id_odcinka = $id1");
        if ($id2 > 0) mysqli_query($conn, "UPDATE odcinki SET sciezka = '$sciezka' WHERE id_odcinka = $id2");
        exit('OK');
    }
    
    if ($_POST['ajax_action'] == 'reset_path') {
        $id1 = (int)$_POST['id1'];
        $id2 = (int)$_POST['id2'];
        if ($id1 > 0) mysqli_query($conn, "UPDATE odcinki SET sciezka = NULL WHERE id_odcinka = $id1");
        if ($id2 > 0) mysqli_query($conn, "UPDATE odcinki SET sciezka = NULL WHERE id_odcinka = $id2");
        exit('OK');
    }
    
    if ($_POST['ajax_action'] == 'reset_all_paths') {
        mysqli_query($conn, "UPDATE odcinki SET sciezka = NULL");
        exit('OK');
    }
}

// --- OBSŁUGA FORMULARZA EDYCJI/USUWANIA (OBA KIERUNKI) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_action'])) {
    
    if (isset($_POST['usun_odcinek'])) {
        $id1 = (int)($_POST['id1'] ?? 0);
        $id2 = (int)($_POST['id2'] ?? 0);
        if ($id1 > 0) mysqli_query($conn, "DELETE FROM odcinki WHERE id_odcinka = $id1");
        if ($id2 > 0) mysqli_query($conn, "DELETE FROM odcinki WHERE id_odcinka = $id2");
        
        header("Location: zarzadzaj_odcinkami.php");
        exit;
    }
    
    if (isset($_POST['zapisz_odcinek'])) {
        $id1 = (int)($_POST['id1'] ?? 0);
        $id2 = (int)($_POST['id2'] ?? 0);
        
        if ($id1 > 0) {
            $czas1 = $_POST['czas1'];
            $vmax1 = (int)$_POST['vmax1'];
            $stmt1 = mysqli_prepare($conn, "UPDATE odcinki SET czas_przejazdu = ?, predkosc_max = ? WHERE id_odcinka = ?");
            mysqli_stmt_bind_param($stmt1, "ssi", $czas1, $vmax1, $id1);
            mysqli_stmt_execute($stmt1);
        }
        
        if ($id2 > 0) {
            $czas2 = $_POST['czas2'];
            $vmax2 = (int)$_POST['vmax2'];
            $stmt2 = mysqli_prepare($conn, "UPDATE odcinki SET czas_przejazdu = ?, predkosc_max = ? WHERE id_odcinka = ?");
            mysqli_stmt_bind_param($stmt2, "ssi", $czas2, $vmax2, $id2);
            mysqli_stmt_execute($stmt2);
        }
        
        header("Location: zarzadzaj_odcinkami.php");
        exit;
    }
}

// --- POBIERANIE DANYCH DLA MAPY ---
$q_nodes = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji, lat, lng FROM stacje");
$stacje_js = [];
$angle = 0; 
while($r = mysqli_fetch_assoc($q_nodes)) {
    $is_dummy = false;
    if (empty($r['lat']) || empty($r['lng'])) {
        $r['lat'] = 53.4285 + (sin($angle) * 0.2);
        $r['lng'] = 14.5528 + (cos($angle) * 0.3);
        $angle += 0.5;
        $is_dummy = true;
    }
    $stacje_js[$r['id_stacji']] = [
        'nazwa' => $r['nazwa_stacji'],
        'lat' => (float)$r['lat'],
        'lng' => (float)$r['lng'],
        'is_dummy' => $is_dummy
    ];
}

$q_edges = mysqli_query($conn, "SELECT o.id_odcinka, o.id_stacji_A, o.id_stacji_B, o.czas_przejazdu, o.predkosc_max, o.sciezka, sA.nazwa_stacji as stA, sB.nazwa_stacji as stB FROM odcinki o JOIN stacje sA ON o.id_stacji_A = sA.id_stacji JOIN stacje sB ON o.id_stacji_B = sB.id_stacji");
$grouped_edges = [];

while($r = mysqli_fetch_assoc($q_edges)) {
    $idA = $r['id_stacji_A'];
    $idB = $r['id_stacji_B'];
    
    $min_id = min($idA, $idB);
    $max_id = max($idA, $idB);
    $pair_key = $min_id . '_' . $max_id;

    if (!isset($grouped_edges[$pair_key])) {
        $grouped_edges[$pair_key] = [
            'st1_id' => $min_id,
            'st2_id' => $max_id,
            'st1_nazwa' => ($min_id == $idA) ? $r['stA'] : $r['stB'],
            'st2_nazwa' => ($max_id == $idB) ? $r['stB'] : $r['stA'],
            'sciezka' => $r['sciezka'], 
            'dir1' => null, 
            'dir2' => null  
        ];
    }

    if ($idA == $min_id) {
        $grouped_edges[$pair_key]['dir1'] = [
            'id' => $r['id_odcinka'],
            'czas' => $r['czas_przejazdu'],
            'vmax' => $r['predkosc_max']
        ];
        if ($r['sciezka']) $grouped_edges[$pair_key]['sciezka'] = $r['sciezka'];
    } else {
        $grouped_edges[$pair_key]['dir2'] = [
            'id' => $r['id_odcinka'],
            'czas' => $r['czas_przejazdu'],
            'vmax' => $r['predkosc_max']
        ];
        if ($r['sciezka'] && !$grouped_edges[$pair_key]['sciezka']) {
            $grouped_edges[$pair_key]['sciezka'] = $r['sciezka']; 
        }
    }
}
$edges_js = array_values($grouped_edges);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzaj Odcinkami - Mapa Geograficzna</title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.css" />
    <script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.min.js"></script>
    
    <style>
        body { font-family: sans-serif; padding: 20px; background-color: #f4f4f4; margin: 0; }
        .header { background: #004080; color: white; padding: 15px 20px; border-radius: 5px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header a { color: white; text-decoration: none; font-weight: bold; background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 4px; }
        
        #map { width: 100%; height: 75vh; border: 2px solid #ccc; border-radius: 5px; box-shadow: 0 4px 10px rgba(0,0,0,0.2); margin-bottom: 20px; }
        
        /* UKRYWANIE ETYKIET PRZY ODDALENIU MAPY 
           Ta klasa (.map-zoomed-out) będzie dodawana z automatu przez JavaScript 
        */
        .stacja-label { background: rgba(255,255,255,0.9); border: 1px solid #004080; font-weight: bold; font-size: 11px; padding: 2px 4px; color: #004080; transition: opacity 0.2s; }
        .map-zoomed-out .stacja-label { opacity: 0 !important; pointer-events: none; }
        
        .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); }
        .modal-content { background-color: #fff; margin: 8% auto; padding: 25px; border-radius: 5px; width: 650px; box-shadow: 0 5px 15px rgba(0,0,0,0.5); }
        .modal-content h3 { margin-top: 0; color: #004080; border-bottom: 2px solid #eee; padding-bottom: 10px; text-align: center;}
        
        .form-flex { display: flex; gap: 20px; }
        .dir-box { flex: 1; border: 1px solid #ccc; padding: 15px; border-radius: 5px; background: #fdfdfd; }
        .dir-title { margin-top: 0; color: #333; font-size: 14px; text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 8px; margin-bottom: 15px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 12px; color: #555; }
        .form-group input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box; font-family: monospace; font-size: 15px; }
        
        .btn-save { background: #28a745; color: white; padding: 12px 15px; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; }
        .btn-del { background: #dc3545; color: white; padding: 12px 15px; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; }
        .btn-cancel { background: #6c757d; color: white; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; }
        .btn-reset { background: #ffc107; color: #000; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; }
    </style>
</head>
<body>

<div class="header">
    <h1>🗺️ Edytor Fizycznej Mapy Linii Kolejowych</h1>
    <a href="index.php">Powrót do menu</a>
</div>

<div style="background: #fff3cd; color: #856404; padding: 15px; border: 1px solid #ffeeba; border-radius: 5px; margin-bottom: 20px;">
    <strong>Instrukcja:</strong><br>
    📍 Złap czerwoną ikonkę stacji i przesuń ją na miejsce – system od razu zapisze jej pozycję.<br>
    〰️ Aby wygiąć odcinek między stacjami, włącz z lewej <b>Ikonkę Ołówka</b> i łap wierzchołki linii.<br>
    ⚙️ Kliknij linię <b>PRAWYM przyciskiem myszy</b>, aby edytować czasy przejazdu lub wyprostować/usunąć odcinek.<br><br>
    
    <!-- <div style="display: flex; gap: 10px;">
        <button type="button" id="btn-geocode" onclick="autoGeocode()" style="background:#004080; color:white; border:none; padding:8px 15px; cursor:pointer; border-radius:3px; font-weight:bold;">🌍 Znajdź i nanieś brakujące stacje</button>
        <button type="button" onclick="resetAllPaths()" style="background:#6c757d; color:white; border:none; padding:8px 15px; cursor:pointer; border-radius:3px; font-weight:bold;">📏 Wyprostuj wszystkie linie (usuń wiszące zgięcia)</button>
    </div> -->
</div>

<div id="map"></div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <h3 id="modalTitle">Edycja odcinka</h3>
        <form method="POST" action="">
            
            <div class="form-flex">
                <div class="dir-box" id="dir1-box">
                    <h4 class="dir-title" id="title1">Kierunek 1</h4>
                    <input type="hidden" name="id1" id="id1">
                    <div class="form-group">
                        <label>Czas przejazdu (GG:MM:SS)</label>
                        <input type="time" name="czas1" id="czas1" step="1">
                    </div>
                    <div class="form-group">
                        <label>Prędkość max (Vmax)</label>
                        <input type="number" name="vmax1" id="vmax1">
                    </div>
                </div>

                <div class="dir-box" id="dir2-box">
                    <h4 class="dir-title" id="title2">Kierunek 2</h4>
                    <input type="hidden" name="id2" id="id2">
                    <div class="form-group">
                        <label>Czas przejazdu (GG:MM:SS)</label>
                        <input type="time" name="czas2" id="czas2" step="1">
                    </div>
                    <div class="form-group">
                        <label>Prędkość max (Vmax)</label>
                        <input type="number" name="vmax2" id="vmax2">
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" name="zapisz_odcinek" class="btn-save" style="flex: 2;">💾 Zapisz zmiany</button>
                <button type="submit" name="usun_odcinek" class="btn-del" style="flex: 1;" onclick="return confirm('Czy na pewno usunąć to połączenie (oba kierunki)?')">🗑️ Usuń odcinek</button>
            </div>
            
            <button type="button" class="btn-reset" onclick="resetSinglePath()">📏 Wyprostuj tę linię (usuń zgięcia)</button>
            <button type="button" class="btn-cancel" onclick="closeModal()">❌ Zamknij okno</button>
        </form>
    </div>
</div>

<script>
    const stacje = <?= json_encode($stacje_js) ?>;
    const odcinkiGrouped = <?= json_encode($edges_js) ?>;

    const map = L.map('map').setView([53.4285, 14.5528], 9);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    // --- LOGIKA UKRYWANIA ETYKIET PRZY ODDALENIU ---
    // Ustawiamy próg zooma (poniżej 11 nazwy znikają, powyżej się pojawiają)
    const ZOOM_THRESHOLD = 11;
    function toggleLabels() {
        if (map.getZoom() < ZOOM_THRESHOLD) {
            document.getElementById('map').classList.add('map-zoomed-out');
        } else {
            document.getElementById('map').classList.remove('map-zoomed-out');
        }
    }
    // Podpinamy funkcję pod każde przewinięcie kółkiem (zoomend)
    map.on('zoomend', toggleLabels);
    // Wywołujemy ją raz na start
    toggleLabels();
    // -----------------------------------------------

    map.pm.addControls({
        position: 'topleft',
        drawMarker: false, drawCircleMarker: false, drawPolyline: false, 
        drawRectangle: false, drawPolygon: false, drawCircle: false, drawText: false,
        editMode: true, dragMode: false, cutPolygon: false, removalMode: false, rotateMode: false
    });

    for (let id in stacje) {
        let s = stacje[id];
        let marker = L.marker([s.lat, s.lng], { draggable: true }).addTo(map);
        marker.bindTooltip(s.nazwa, { permanent: true, direction: 'bottom', offset: [0, 10], className: 'stacja-label' });
        s.marker = marker;

        marker.on('drag', function() { updateLinesOnStationDrag(id); });
        marker.on('dragend', function() {
            let pos = marker.getLatLng();
            savePosition(id, pos.lat, pos.lng);
            odcinkiGrouped.forEach(o => {
                if((o.st1_id == id || o.st2_id == id) && o.polyline) savePath(o, o.polyline.getLatLngs());
            });
        });
    }

    odcinkiGrouped.forEach(o => {
        let sA = stacje[o.st1_id];
        let sB = stacje[o.st2_id];
        
        if(sA && sB) {
            let latlngs = [];
            if (o.sciezka && o.sciezka !== 'null' && o.sciezka !== '') {
                try { latlngs = JSON.parse(o.sciezka); } catch(e) {}
            }
            if (latlngs.length === 0) latlngs = [ [sA.lat, sA.lng], [sB.lat, sB.lng] ];
            
            let line = L.polyline(latlngs, { color: '#004080', weight: 4, opacity: 0.7 }).addTo(map);
            
            let tooltipHTML = "<b>" + o.st1_nazwa + " ↔ " + o.st2_nazwa + "</b><br>";
            if (o.dir1) tooltipHTML += "➡️ Do " + o.st2_nazwa + ": " + o.dir1.czas.substring(0,8) + " | " + o.dir1.vmax + " km/h<br>";
            if (o.dir2) tooltipHTML += "⬅️ Do " + o.st1_nazwa + ": " + o.dir2.czas.substring(0,8) + " | " + o.dir2.vmax + " km/h";
            line.bindTooltip(tooltipHTML, { sticky: true });
            
            line.on('contextmenu', function(e) {
                L.DomEvent.stopPropagation(e);
                document.getElementById('modalTitle').innerText = o.st1_nazwa + " ↔ " + o.st2_nazwa;
                
                const box1 = document.getElementById('dir1-box');
                if (o.dir1) {
                    box1.style.display = 'block';
                    document.getElementById('title1').innerText = o.st1_nazwa + " ➔ " + o.st2_nazwa;
                    document.getElementById('id1').value = o.dir1.id;
                    document.getElementById('czas1').value = o.dir1.czas;
                    document.getElementById('vmax1').value = o.dir1.vmax;
                    document.getElementById('czas1').required = true;
                    document.getElementById('vmax1').required = true;
                } else {
                    box1.style.display = 'none';
                    document.getElementById('id1').value = '';
                    document.getElementById('czas1').required = false;
                    document.getElementById('vmax1').required = false;
                }
                
                const box2 = document.getElementById('dir2-box');
                if (o.dir2) {
                    box2.style.display = 'block';
                    document.getElementById('title2').innerText = o.st2_nazwa + " ➔ " + o.st1_nazwa;
                    document.getElementById('id2').value = o.dir2.id;
                    document.getElementById('czas2').value = o.dir2.czas;
                    document.getElementById('vmax2').value = o.dir2.vmax;
                    document.getElementById('czas2').required = true;
                    document.getElementById('vmax2').required = true;
                } else {
                    box2.style.display = 'none';
                    document.getElementById('id2').value = '';
                    document.getElementById('czas2').required = false;
                    document.getElementById('vmax2').required = false;
                }

                document.getElementById('editModal').style.display = 'block';
            });
            
            line.on('pm:markerdragend', e => savePath(o, line.getLatLngs())); 
            line.on('pm:vertexadded', e => savePath(o, line.getLatLngs()));   
            line.on('pm:vertexremoved', e => savePath(o, line.getLatLngs())); 
            
            o.polyline = line;
        }
    });

    function updateLinesOnStationDrag(stacjaId) {
        odcinkiGrouped.forEach(o => {
            if(o.st1_id == stacjaId || o.st2_id == stacjaId) {
                let sA = stacje[o.st1_id];
                let sB = stacje[o.st2_id];
                if(sA && sB && o.polyline) {
                    let obecnaSciezka = o.polyline.getLatLngs();
                    if (o.st1_id == stacjaId) obecnaSciezka[0] = sA.marker.getLatLng();
                    if (o.st2_id == stacjaId) obecnaSciezka[obecnaSciezka.length - 1] = sB.marker.getLatLng();
                    o.polyline.setLatLngs(obecnaSciezka);
                }
            }
        });
    }

    function savePosition(id, lat, lng) {
        let fd = new FormData();
        fd.append('ajax_action', 'save_pos');
        fd.append('id_stacji', id);
        fd.append('lat', lat);
        fd.append('lng', lng);
        fetch('zarzadzaj_odcinkami.php', { method: 'POST', body: fd });
    }
    
    function savePath(odcinekObj, latlngsObj) {
        let coordsArray = latlngsObj.map(pos => [pos.lat, pos.lng]);
        let fd = new FormData();
        fd.append('ajax_action', 'save_path');
        fd.append('id1', odcinekObj.dir1 ? odcinekObj.dir1.id : 0);
        fd.append('id2', odcinekObj.dir2 ? odcinekObj.dir2.id : 0);
        fd.append('sciezka', JSON.stringify(coordsArray));
        fetch('zarzadzaj_odcinkami.php', { method: 'POST', body: fd });
    }
    
    function resetSinglePath() {
        if(confirm("Wyprostować ten odcinek? System usunie zapisane zgięcia, a linia znów połączy stacje w linii prostej.")) {
            let fd = new FormData();
            fd.append('ajax_action', 'reset_path');
            fd.append('id1', document.getElementById('id1').value || 0);
            fd.append('id2', document.getElementById('id2').value || 0);
            fetch('zarzadzaj_odcinkami.php', { method: 'POST', body: fd })
            .then(() => location.reload());
        }
    }
    
    function resetAllPaths() {
        if(confirm("Czy na pewno chcesz wyprostować WSZYSTKIE linie na mapie? Wszystkie narysowane zakręty zostaną skasowane!")) {
            let fd = new FormData();
            fd.append('ajax_action', 'reset_all_paths');
            fetch('zarzadzaj_odcinkami.php', { method: 'POST', body: fd })
            .then(() => location.reload());
        }
    }

    async function autoGeocode() {
        const btn = document.getElementById('btn-geocode');
        btn.innerText = "⏳ Szukam stacji... to potrwa chwilę.";
        btn.disabled = true;

        let dummies = Object.keys(stacje).filter(id => stacje[id].is_dummy);
        if(dummies.length === 0) {
            alert("Wszystkie stacje z bazy mają już określoną pozycję na mapie!");
            btn.innerText = "🌍 Znajdź i nanieś brakujące stacje";
            btn.disabled = false;
            return;
        }

        for(let id of dummies) {
            let s = stacje[id];
            let found = false;
            
            let queries = [
                s.nazwa + " stacja kolejowa, Polska",
                s.nazwa + " dworzec PKP, Polska",
                s.nazwa + ", Polska"
            ];

            for(let q of queries) {
                if (found) break;

                try {
                    let res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=1`);
                    let data = await res.json();
                    
                    if(data && data.length > 0) {
                        let newLat = parseFloat(data[0].lat);
                        let newLng = parseFloat(data[0].lon);
                        
                        s.marker.setLatLng([newLat, newLng]);
                        s.lat = newLat;
                        s.lng = newLng;
                        s.is_dummy = false; 
                        
                        updateLinesOnStationDrag(id);
                        savePosition(id, newLat, newLng);
                        found = true;
                    }
                } catch(e) {
                    console.error("Błąd dla: " + s.nazwa, e);
                }
                
                await new Promise(resolve => setTimeout(resolve, 1200));
            }
        }
        
        btn.innerText = "✅ Szukanie zakończone!";
        alert("Skończyłem szukać. Kliknij 'Wyprostuj wszystkie linie', aby wyczyścić bałagan po przemieszczeniu stacji.");
        btn.innerText = "🌍 Znajdź i nanieś brakujące stacje";
        btn.disabled = false;
    }

    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }
</script>

</body>
</html>