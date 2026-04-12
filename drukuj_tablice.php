<?php
require 'db_config.php';

$id_przejazdu = $_GET['id_przejazdu'] ?? 0;

if (!$id_przejazdu) {
    die("Brak ID przejazdu.");
}

// 1. Pobieramy główne informacje o pociągu z bazy
$sql = "
    SELECT 
        p.numer_pociagu, 
        p.nazwa_pociagu, 
        tp.skrot as rodzaj,
        (SELECT s.nazwa_stacji FROM stacje s JOIN trasy t ON s.id_stacji = t.id_stacji_poczatkowej WHERE t.id_trasy = p.id_trasy) as stacja_pocz,
        (SELECT s.nazwa_stacji FROM stacje s JOIN trasy t ON s.id_stacji = t.id_stacji_koncowej WHERE t.id_trasy = p.id_trasy) as stacja_konc
    FROM przejazdy p
    JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
    WHERE p.id_przejazdu = ?
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $id_przejazdu);
mysqli_stmt_execute($stmt);
$info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$info) { 
    die("Nie znaleziono pociągu w bazie."); 
}

// Formatujemy dane do wyświetlenia
$nr_poc = $info['rodzaj'] . " " . $info['numer_pociagu'];
$nazwa = $info['nazwa_pociagu'] ? '' . mb_strtoupper($info['nazwa_pociagu']) . '' : '';
$stacja_z = mb_strtoupper($info['stacja_pocz']);
$stacja_do = mb_strtoupper($info['stacja_konc']);

// 2. Pobieramy stacje pośrednie (tylko z uwagami_postoju = 'ph')
$sql_via = "
    SELECT s.nazwa_stacji 
    FROM szczegoly_rozkladu sr
    JOIN stacje s ON sr.id_stacji = s.id_stacji
    WHERE sr.id_przejazdu = ? AND sr.uwagi_postoju = 'ph'
    ORDER BY CAST(sr.kolejnosc AS SIGNED) ASC
";
$stmt_via = mysqli_prepare($conn, $sql_via);
mysqli_stmt_bind_param($stmt_via, "i", $id_przejazdu);
mysqli_stmt_execute($stmt_via);
$res_via = mysqli_stmt_get_result($stmt_via);

$via_stacje = [];
while ($row = mysqli_fetch_assoc($res_via)) {
    // Pomijamy stację początkową i końcową, żeby nie dublowały się w środkowym tekście
    if (mb_strtoupper($row['nazwa_stacji']) != $stacja_z && mb_strtoupper($row['nazwa_stacji']) != $stacja_do) {
        $via_stacje[] = $row['nazwa_stacji'];
    }
}

// Grupujemy stacje po 4 w jednym wierszu, żeby tekst na tablicy ładnie się układał i łamał
$rows = [];
$chunked = array_chunk($via_stacje, 4);
$total_chunks = count($chunked); // Liczymy ile w sumie będzie linii

foreach ($chunked as $idx => $chunk) {
    $line = implode(" - ", $chunk);
    
    // Dodajemy myślnik na końcu tylko jeśli to NIE JEST ostatnia linia stacji pośrednich
    if ($idx < $total_chunks - 1) {
        $line .= " -";
    }
    
    $rows[] = $line;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Tablica - <?= $nr_poc ?></title>
<style>
  body { margin: 0; background: white; }
  #board-container { display: flex; justify-content: left; }
  
  #board {
    width: 297mm; height: 210mm; /* A4 poziom */
    background: white; position: relative;
    padding: 35mm 23.5mm 10mm 23.5mm; 
    box-sizing: border-box; border: none;
    font-family: 'Arial Narrow', Arial, sans-serif;
    display: flex;
    flex-direction: column;
  }

  .top-row { display: flex; justify-content: space-between; color: #cc0000; font-size: 46px; font-weight: bold; margin-bottom: 2mm; text-transform: uppercase; letter-spacing: -1px; }
  .line { height: 6px; background: black; width: 100%; margin-bottom: 6mm; }
  .station-start { font-size: 65px; font-weight: bold; color: black; text-transform: uppercase; margin-bottom: 2mm; letter-spacing: -1.5px; }
  
  #middle-container {
    height: 75mm;
    display: flex; flex-direction: column; justify-content: center; align-items: center;
    overflow: hidden; 
  }

  #out-middle {
    text-align: center; color: black; width: 100%;
    line-height: 1.15; letter-spacing: -0.5px;
  }
  
  .middle-row { white-space: nowrap; }

  .station-end { position: absolute; bottom: 30mm; right: 23.5mm; font-size: 65px; font-weight: bold; color: black; text-transform: uppercase; letter-spacing: -1.5px; }

  @media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: A4 landscape; margin: 0; }
  }
</style>
</head>
<body>

<div id="board-container">
  <div id="board">
    <div class="top-row">
        <div><?= htmlspecialchars($nr_poc) ?></div>
        <div><?= htmlspecialchars($nazwa) ?></div>
    </div>
    <div class="line"></div>
    <div class="station-start"><?= htmlspecialchars($stacja_z) ?></div>
    <div id="middle-container">
      <div id="out-middle">
         <?php foreach ($rows as $r): ?>
            <div class="middle-row"><?= htmlspecialchars($r) ?></div>
         <?php endforeach; ?>
      </div>
    </div>
    <div class="station-end"><?= htmlspecialchars($stacja_do) ?></div>
  </div>
</div>

<script>
  // Funkcja dopasowująca czcionkę, żeby stacje pośrednie zawsze zmieściły się na środku
  function adjustFontSize() {
    const container = document.getElementById('middle-container');
    const textBlock = document.getElementById('out-middle');
    let size = 100;
    textBlock.style.fontSize = size + 'px';
    while ((textBlock.scrollHeight > container.clientHeight || textBlock.scrollWidth > container.clientWidth) && size > 10) {
      size -= 1; 
      textBlock.style.fontSize = size + 'px';
    }
  }

  window.onload = function() {
    adjustFontSize();
    // Odpalamy okno drukowania pół sekundy po załadowaniu tekstu
    setTimeout(() => {
        window.print();
    }, 500);
  };
</script>
</body>
</html>