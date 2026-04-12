<?php
session_start();
require 'db_config.php';

$wybrana_stacja = $_GET['id_stacji'] ?? 29;

// --- NOWOŚĆ: Szybki sprawdzacz, czy doszły nowe ekrany (dla skryptu auto-odświeżania) ---
if (isset($_GET['check_count'])) {
    $q = mysqli_query($conn, "SELECT DISTINCT peron, tor FROM szczegoly_rozkladu WHERE id_stacji = $wybrana_stacja AND peron IS NOT NULL AND tor IS NOT NULL AND peron != '' AND tor != ''");
    echo mysqli_num_rows($q);
    exit;
}

// Pobieramy listę stacji do listy rozwijanej
$stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje WHERE typ_stacji_id IN (1,2,3,5) ORDER BY nazwa_stacji");

// Szukamy nazwy wybranej stacji do ładnego nagłówka
$nazwa_stacji_wybranej = "Nieznana stacja";
if($stacje_res) {
    mysqli_data_seek($stacje_res, 0);
    while($s = mysqli_fetch_assoc($stacje_res)) {
        if($s['id_stacji'] == $wybrana_stacja) {
            $nazwa_stacji_wybranej = $s['nazwa_stacji'];
            break;
        }
    }
}

// Pobieramy listę wszystkich kombinacji Peron-Tor dla tej stacji
$ekrany_res = mysqli_query($conn, "SELECT DISTINCT peron, tor FROM szczegoly_rozkladu WHERE id_stacji = $wybrana_stacja AND peron IS NOT NULL AND tor IS NOT NULL AND peron != '' AND tor != '' ORDER BY peron, tor");
$ilosc_ekranow = mysqli_num_rows($ekrany_res);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Tablic Peronowych</title>
    <style>
        body { background: #111; color: white; font-family: Tahoma, sans-serif; padding: 20px; margin: 0; }
        
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #222;
            padding: 15px 20px;
            border-radius: 6px;
            border: 1px solid #444;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
        }
        .top-header h2 { margin: 0; color: #fff; font-size: 22px; }
        .station-select {
            padding: 8px 12px;
            font-size: 14px;
            background: #333;
            color: white;
            border: 1px solid #555;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .station-select:focus { outline: none; border-color: #3b82f6; }

        .grid { display: flex; flex-wrap: wrap; gap: 20px; justify-content: flex-start; }
        .ekran-box { background: #222; padding: 10px; border-radius: 5px; border: 2px solid #444; text-align: center; }
        .ekran-title { font-weight: bold; margin-bottom: 10px; font-size: 14px; color: #4ade80; }
        
        .iframe-container {
            width: 320px;
            height: 180px;
            position: relative;
            overflow: hidden;
            border: 2px solid #000;
            background: #000;
            margin: 0 auto 10px auto;
        }
        .iframe-container iframe {
            width: 1280px; 
            height: 720px;
            transform: scale(0.25); 
            transform-origin: 0 0;
            border: none;
            pointer-events: none; 
        }
        .btn { padding: 6px 12px; cursor: pointer; border: none; font-weight: bold; border-radius: 4px; font-size: 12px; margin: 2px; }
        .btn-open { background-color: #3b82f6; color: white; }
        .btn-off { background-color: #ef4444; color: white; }
    </style>
</head>
<body>

<div class="top-header">
    <h2>Monitoring: <?= $nazwa_stacji_wybranej ?></h2>
    <form method="GET" id="formStacja" style="margin: 0;">
        <label style="font-weight: bold; margin-right: 10px; color: #aaa;">Zmień stację:</label>
        <select name="id_stacji" class="station-select" onchange="document.getElementById('formStacja').submit()">
            <?php 
            mysqli_data_seek($stacje_res, 0);
            while($s = mysqli_fetch_assoc($stacje_res)): ?>
                <option value="<?= $s['id_stacji'] ?>" <?= $s['id_stacji'] == $wybrana_stacja ? 'selected' : '' ?>>
                    <?= $s['nazwa_stacji'] ?>
                </option>
            <?php endwhile; ?>
        </select>
    </form>
</div>

<div class="grid">
    <?php 
    mysqli_data_seek($ekrany_res, 0);
    while($e = mysqli_fetch_assoc($ekrany_res)): 
        $p = $e['peron']; $t = $e['tor']; ?>
    
    <div class="ekran-box">
        <div class="ekran-title">🖥️ Peron <?= $p ?> | Tor <?= $t ?></div>
        
        <div class="iframe-container">
            <iframe src="peron.html?peron=<?= $p ?>&tor=<?= $t ?>"></iframe>
        </div>
        
        <div>
            <button class="btn btn-open" onclick="window.open('peron.html?peron=<?= $p ?>&tor=<?= $t ?>', '_blank')">Uruchom w nowym oknie</button>
            <button class="btn btn-off" onclick="wygasEkran('<?= $p ?>', '<?= $t ?>')">Wygaś (Brak pociągu)</button>
        </div>
    </div>
    
    <?php endwhile; ?>
</div>

<script>
    const aktualnaIlosc = <?= $ilosc_ekranow ?>;
    const stacjaId = <?= $wybrana_stacja ?>;

    function wygasEkran(peron, tor) {
        if(!confirm("Na pewno usunąć pociąg z tego wyświetlacza?")) return;
        
        const fd = new FormData();
        fd.append('peron', peron);
        fd.append('tor', tor);
        fd.append('akcja', 'wygas');

        fetch('ustaw_wyswietlacz.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(txt => {
                if(txt === "OK") {
                    document.querySelectorAll('iframe').forEach(ifr => {
                        if(ifr.src.includes('peron=' + peron) && ifr.src.includes('tor=' + tor)) {
                            ifr.src = ifr.src; 
                        }
                    });
                } else alert("Błąd: " + txt);
            });
    }

    // Inteligentne odświeżanie
    setInterval(() => {
        // Pytamy w tle: czy liczba aktywnych peronów/torów w bazie się zmieniła?
        fetch(`panel_tablic.php?id_stacji=${stacjaId}&check_count=1`)
            .then(res => res.text())
            .then(count => {
                let nowyWynik = parseInt(count);
                if (nowyWynik > 0 && nowyWynik !== aktualnaIlosc) {
                    // Jeśli przypisano pociąg do zupełnie nowego peronu - przeładuj stronę, aby zbudować nową ramkę!
                    window.location.reload();
                } else {
                    // Jeśli liczba peronów jest ta sama - tylko odśwież obraz w istniejących ramkach
                    document.querySelectorAll('iframe').forEach(ifr => { ifr.src = ifr.src; });
                }
            });
    }, 10000);
</script>

</body>
</html>