<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SWDR - Centrum Zarządzania</title>
    <style>
        :root {
            --primary-color: #004080;
            --secondary-color: #0056b3;
            --accent-color: #f8f9fa;
            --text-color: #333;
            --admin-color: #6c757d;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #e9ecef;
            margin: 0;
            padding: 0;
            color: var(--text-color);
        }

        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        header p {
            margin: 5px 0 0;
            font-size: 14px;
            opacity: 0.9;
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        .card h2 {
            font-size: 18px;
            margin-top: 0;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1;
        }

        .menu-list li {
            margin-bottom: 8px;
        }

        .menu-list a {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            text-decoration: none;
            color: #444;
            background-color: #f8f9fa;
            border-radius: 5px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
            font-weight: 500;
        }

        .menu-list a:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .menu-list a span.icon {
            margin-right: 10px;
            font-size: 1.2em;
        }

        /* Styl dla sekcji Admin */
        .card.admin h2 {
            color: var(--admin-color);
            border-color: #eee;
        }

        .card.admin .menu-list a:hover {
            background-color: var(--admin-color);
            border-color: var(--admin-color);
        }

        footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>

    <header>
        <h1>System Wspomagania Dyżurnego Ruchu</h1>
        <p>Panel Zarządzania Rozkładami Jazdy</p>
    </header>

    <div class="container">
        
        <div class="card">
            <h2>🚦 Ruch i Sterowanie</h2>
            <ul class="menu-list">
                <li><a href="panel_dyzurnego.php"><span class="icon">🖥️</span> Panel Dyżurnego (SWDR)</a></li>
                <li><a href="generator_rozkladu.php"><span class="icon">⚙️</span> Generator Rozkładu</a></li>
                <li><a href="zarzadzanie_trasa.php"><span class="icon">✏️</span> Edycja / Korekta Trasy</a></li>
                <li><a href="zarzadzanie_peronami.php"><span class="icon">📍</span> Zarządzanie Peronami</a></li>
            </ul>
        </div>

        <div class="card">
            <h2>👀 Podgląd i Pasażer</h2>
            <ul class="menu-list">
                <li><a href="podglad_maszynisty.php"><span class="icon">📄</span> Podgląd Maszynisty (SKR)</a></li>
                <li><a href="przegladarka_rozkladow.php"><span class="icon">📋</span> Plakaty Stacyjne</a></li>
                <li><a href="wyswietlacz_led.php"><span class="icon">🟧</span> Wyświetlacz LED (Pociąg)</a></li>
            </ul>
        </div>

        <div class="card admin">
            <h2>🛠️ Infrastruktura i Dane</h2>
            <ul class="menu-list">
                <li><a href="zarzadzaj_stacjami.php"><span class="icon">🚉</span> Baza Stacji</a></li>
                <li><a href="kreator_tras.php"><span class="icon">🗺️</span> Baza Tras</a></li>
                <li><a href="zarzadzaj_odcinkami.php"><span class="icon">📏</span> Odcinki i Prędkości</a></li>
                <li><a href="zarzadzaj_ostrzezeniami.php"><span class="icon">⚠️</span> Ostrzeżenia (Rozkazy O)</a></li>
            </ul>
        </div>

    </div>

    <footer>
        &copy; <?php echo date("Y"); ?> System Kolejowy. Wszelkie prawa zastrzeżone.
    </footer>

</body>
</html>