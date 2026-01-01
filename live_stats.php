<?php require 'db_config.php'; ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Live Stats - Eksperyment</title>
    <style>
        body { background: #111; color: #fff; font-family: monospace; padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #444; padding: 8px; text-align: center; }
        th { background: #222; }
        .win { color: #0f0; } /* Czas na zielono - jechaliśmy szybciej/zgodnie z planem */
        .fail { color: #f00; } /* Czas na czerwono - jechaliśmy za długo */
        .neutral { color: #aaa; }
        h1 { text-align: center; color: #ffcc00; }
    </style>
    <meta http-equiv="refresh" content="5"> </head>
<body>
    <h1>MONITORING CZASÓW PRZEJAZDU (LIVE)</h1>
    <table>
        <thead>
            <tr>
                <th>ID Przejazdu</th>
                <th>Odcinek (Skąd -> Dokąd)</th>
                <th>Start (Rzecz.)</th>
                <th>Stop (Rzecz.)</th>
                <th>Czas JAZDY (Rzecz.)</th>
                <th>Czas JAZDY (Plan)</th>
                <th>RÓŻNICA</th>
                <th>Analiza</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Pobieramy ostatnie 20 logów
            $sql = "SELECT * FROM logi_przejazdu ORDER BY id DESC LIMIT 200";
            $res = mysqli_query($conn, $sql);
            
            while ($row = mysqli_fetch_assoc($res)) {
                $rzecz = $row['czas_podrozy_sekundy'];
                $plan = $row['planowany_czas_sekundy'];
                $diff = $row['roznica_sekundy']; // Dodatnie = jechał dłużej, Ujemne = jechał szybciej
                
                // Interpretacja kolorów:
                // Jeśli różnica jest duża na plus (np. > 60s) -> Czerwony (Za wolno / za ciasny rozkład)
                // Jeśli różnica jest duża na minus (np. < -60s) -> Niebieski (Za szybko / za luźny rozkład)
                $class = 'neutral';
                $analiza = 'OK';
                
                if ($diff > 15) { 
                    $class = 'fail'; 
                    $analiza = 'ZA WOLNO (+'.round($diff/60, 1).' min)'; 
                } elseif ($diff < -15) { 
                    $class = 'win'; 
                    $analiza = 'ZA SZYBKO (Luz '.round(abs($diff)/60, 1).' min)'; 
                }

                echo "<tr>";
                echo "<td>{$row['id_przejazdu']}</td>";
                echo "<td>{$row['stacja_start']} -> {$row['stacja_koniec']}</td>";
                echo "<td>" . substr($row['czas_start_rzecz'], 0, 5) . "</td>";
                echo "<td>" . substr($row['czas_stop_rzecz'], 0, 5) . "</td>";
                
                // Formatowanie sekund na minuty:sekundy
                echo "<td>" . gmdate("i:s", $rzecz) . "</td>";
                echo "<td>" . gmdate("i:s", $plan) . "</td>";
                
                echo "<td class='$class'>{$diff} sek</td>";
                echo "<td class='$class'>{$analiza}</td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
</body>
</html>