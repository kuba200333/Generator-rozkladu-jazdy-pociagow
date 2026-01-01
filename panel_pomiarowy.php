<?php
require 'db_config.php';

// Pobierz listę aktywnych przejazdów do wyboru
$sql_trasy = "SELECT p.id_przejazdu, p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy 
              FROM przejazdy p 
              JOIN trasy t ON p.id_trasy = t.id_trasy 
              ORDER BY p.data_utworzenia DESC";
$res_trasy = mysqli_query($conn, $sql_trasy);

$id_przejazdu = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;
$punkty = [];

if ($id_przejazdu) {
    // Pobieramy WSZYSTKIE punkty (nawet te bez 'ph')
    $sql = "SELECT sr.id_szczegolu, sr.kolejnosc, s.nazwa_stacji, sr.uwagi_postoju, sr.przyjazd, sr.odjazd, sr.przyjazd_rzecz, sr.odjazd_rzecz 
            FROM szczegoly_rozkladu sr
            JOIN stacje s ON sr.id_stacji = s.id_stacji
            WHERE sr.id_przejazdu = ?
            ORDER BY sr.kolejnosc ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_przejazdu);
    mysqli_stmt_execute($stmt);
    $punkty = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel Pomiarowy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: sans-serif; background: #222; color: #fff; padding: 10px; max-width: 800px; margin: 0 auto; }
        select { padding: 10px; font-size: 16px; width: 100%; margin-bottom: 20px; background: #333; color: #fff; border: 1px solid #555; }
        .row { display: flex; align-items: center; border-bottom: 1px solid #444; padding: 12px 0; }
        .row:nth-child(even) { background: #2a2a2a; }
        .row.active { background: #004400; border: 2px solid #0f0; padding-left: 5px; } 
        
        .col-info { flex: 1; }
        .stacja { font-size: 18px; font-weight: bold; display: block; }
        .plan { font-size: 12px; color: #aaa; }
        
        .col-actions { flex: 0 0 140px; text-align: right; display: flex; gap: 5px; justify-content: flex-end; }
        
        button { border: none; padding: 12px 15px; font-weight: bold; cursor: pointer; border-radius: 4px; font-size: 16px; }
        .btn-przyjazd { background: #e67e22; color: white; }
        .btn-odjazd { background: #27ae60; color: white; }
        .btn-przelot { background: #3498db; color: white; width: 100%; font-size: 14px; }
        
        .timer-box { background:#333; border:1px solid #555; padding:5px; margin-top:5px; color:yellow; font-weight:bold; text-align:center; display: inline-block; }
    </style>
</head>
<body>

    <form method="GET">
        <select name="id_przejazdu" onchange="this.form.submit()">
            <option value="">-- Wybierz trasę do pomiarów --</option>
            <?php while($t = mysqli_fetch_assoc($res_trasy)): ?>
                <option value="<?= $t['id_przejazdu'] ?>" <?= $id_przejazdu == $t['id_przejazdu'] ? 'selected' : '' ?>>
                    <?= $t['numer_pociagu'] ?> (<?= $t['nazwa_trasy'] ?>)
                </option>
            <?php endwhile; ?>
        </select>
    </form>

    <?php if ($id_przejazdu): ?>
        <div id="lista-punktow">
            <?php foreach ($punkty as $index => $p): 
                $czy_postoj = ($p['uwagi_postoju'] == 'ph');
                
                // Podświetlenie pierwszego nieodjechanego punktu
                $klasa_aktywna = '';
                if (!$p['odjazd_rzecz'] && ($index == 0 || $punkty[$index-1]['odjazd_rzecz'])) {
                    $klasa_aktywna = 'active';
                }
            ?>
                <div class="row <?= $klasa_aktywna ?>" id="row-<?= $p['id_szczegolu'] ?>">
                    <div class="col-info">
                        <span class="stacja"><?= $p['nazwa_stacji'] ?></span>
                        <span class="plan">
                            <?= substr($p['przyjazd'] ?: $p['odjazd'], 0, 5) ?> 
                            (<?= $czy_postoj ? 'POSTÓJ' : 'PRZELOT' ?>)
                        </span>
                        <div id="status-<?= $p['id_szczegolu'] ?>" style="font-size:13px; color:#bbb;">
                            <?php 
                                // Wyświetlanie istniejących czasów (np. po odświeżeniu strony)
                                if($p['przyjazd_rzecz']) {
                                    // Jeśli czasy są identyczne, to był przelot
                                    if($p['przyjazd_rzecz'] == $p['odjazd_rzecz']) {
                                         echo "<span style='color:#3498db; font-weight:bold;'>PRZELOT: ".substr($p['przyjazd_rzecz'],0,8)."</span>";
                                    } else {
                                         echo "<span style='color:#e67e22'>P: ".substr($p['przyjazd_rzecz'],0,8)."</span> ";
                                    }
                                }
                                if($p['odjazd_rzecz'] && $p['przyjazd_rzecz'] != $p['odjazd_rzecz']) {
                                     echo "<span style='color:#27ae60'>O: ".substr($p['odjazd_rzecz'],0,8)."</span>";
                                }
                            ?>
                        </div>
                    </div>
                    
                    <div class="col-actions">
                        <?php if ($czy_postoj): ?>
                            <button class="btn-przyjazd" onclick="kliknij(<?= $p['id_szczegolu'] ?>, 'p')">P</button>
                            <button class="btn-odjazd" onclick="kliknij(<?= $p['id_szczegolu'] ?>, 'o')">O</button>
                        <?php else: ?>
                            <button class="btn-przelot" onclick="kliknij(<?= $p['id_szczegolu'] ?>, 'przelot')">PRZELOT</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<script>
    function kliknij(id, typ) {
        const formData = new FormData();
        formData.append('id_szczegolu', id);
        formData.append('typ', typ); 

        // Komunikacja z PHP
        fetch('zapisz_czas_auto.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'OK') {
                    // Aktualizujemy widok na podstawie odpowiedzi serwera
                    aktualizujWidok(id, typ, data.godzina, data.postoj_sekundy);
                } else {
                    alert("Błąd: " + data.msg);
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert("Błąd połączenia z serwerem.");
            });
    }

    function aktualizujWidok(id, typ, godzina, sekundyPostoju) {
        const statusDiv = document.getElementById('status-' + id);
        
        // Usuwamy stary licznik, jeśli istnieje
        const oldTimer = document.getElementById('timer-' + id);
        if(oldTimer) oldTimer.remove();

        if (typ === 'przelot') {
            // === WIDOK: PRZELOT ===
            statusDiv.innerHTML = ` <span style='color:#3498db; font-weight:bold;'>PRZELOT: ${godzina}</span>`;
            // Przesuwamy aktywny wiersz od razu
            przesunNaastepna(id);

        } else if (typ === 'p') {
            // === WIDOK: PRZYJAZD ===
            statusDiv.innerHTML += ` <span style='color:#e67e22; font-weight:bold; margin-left:5px;'>P: ${godzina}</span>`;
            
            // Logika licznika postoju
            let czas = sekundyPostoju ? parseInt(sekundyPostoju) : 0;
            if (czas > 0) {
                const timerId = 'timer-' + id;
                statusDiv.innerHTML += ` <div id="${timerId}" class="timer-box"> POSTÓJ: ${czas}s </div>`;
                
                const interval = setInterval(() => {
                    czas--;
                    const el = document.getElementById(timerId);
                    if (!el) { clearInterval(interval); return; }

                    if (czas > 0) {
                        el.innerText = `CZEKAJ: ${czas}s`;
                    } else {
                        // Koniec czasu postoju
                        clearInterval(interval);
                        el.innerText = ">>> ODJAZD <<<";
                        el.style.backgroundColor = "green";
                        el.style.color = "white";
                    }
                }, 1000);
            } else {
                statusDiv.innerHTML += ` <div style="color:#aaa; font-size:0.8em; margin-top:5px;">(Brak planowego postoju)</div>`;
            }

        } else {
            // === WIDOK: ODJAZD ===
            statusDiv.innerHTML += ` <span style='color:#27ae60; font-weight:bold; margin-left:5px;'>O: ${godzina}</span>`;
            przesunNaastepna(id);
        }
    }

    // Funkcja pomocnicza do przesuwania podświetlenia wiersza
    function przesunNaastepna(id) {
        const currentRow = document.getElementById('row-' + id);
        const nextRow = currentRow.nextElementSibling;
        
        if (currentRow) {
            currentRow.classList.remove('active');
            currentRow.style.opacity = "0.6"; // Wygaszamy zaliczoną stację
        }
        if (nextRow) {
            nextRow.classList.add('active');
            nextRow.scrollIntoView({behavior: "smooth", block: "center"});
        }
    }
</script>
</body>
</html>