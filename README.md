# Generator Rozkładów Jazdy Pociągów

![Strona Główna](https://github.com/kuba200333/Generator-rozkladu-jazdy-pociagow/blob/main/image/strona_glowna.png?raw=true)

Zaawansowane narzędzie webowe do tworzenia, zarządzania i wizualizacji kolejowych rozkładów jazdy, napisane w PHP z wykorzystaniem bazy danych MariaDB/MySQL.

---

## O Projekcie

Aplikacja ta została stworzona z myślą o miłośnikach kolei i symulatorów, aby umożliwić im proste i szybkie generowanie realistycznych rozkładów jazdy pociągów. Projekt pozwala na pełne zarządzanie danymi wejściowymi – od stacji i łączących je odcinków, po kompletne trasy i ostrzeżenia eksploatacyjne.

## Główne Funkcje

* **🚄 Zaawansowany Generator Rozkładu:** Twórz szczegółowe rozkłady jazdy, definiując trasę, godzinę odjazdu, typ pociągu, postoje handlowe i techniczne, a także numery peronów i torów.
* **📋 Przeglądarka Plakatów Stacyjnych:** Generuj dynamiczne, żółte plakaty odjazdów dla dowolnej stacji, wzorowane na tych z polskich dworców kolejowych.
* **📄 Podgląd dla Maszynisty:** Przeglądaj uproszczone rozkłady służbowe (tzw. "węże"), które zawierają kluczowe informacje dla prowadzącego pociąg, w tym maksymalne prędkości na odcinkach i obowiązujące ostrzeżenia.
* **🟧 Symulator Wyświetlacza LED:** Uruchom symulację wyświetlacza znanego z pociągów, która pokazuje trasę przejazdu, następną stację, aktualny czas i datę. Aplikacja jest PWA (Progressive Web App), co pozwala na jej "zainstalowanie" na pulpicie lub ekranie głównym telefonu.
* **🛠️ Pełne Zarządzanie Danymi:** Wygodnie dodawaj i edytuj kluczowe elementy systemu:
    * Zarządzanie stacjami i przystankami,
    * Wizualny kreator tras pociągów,
    * Edytor odcinków między stacjami (czasy przejazdu, Vmax),
    * Panel do zarządzania stałymi ostrzeżeniami (tzw. Rozkazy O).

## Galeria

![Formularz generatora rozkładu](https://github.com/kuba200333/Generator-rozkladu-jazdy-pociagow/blob/main/image/generator_rozkladu.png?raw=true)
*Formularz do generowania rozkładu dla pociągu*

![Przeglądarka rozkładów stacyjnych](https://github.com/kuba200333/Generator-rozkladu-jazdy-pociagow/blob/main/image/przegladarka_rozkladow.png?raw=true)
*Strona, gdzie można podejrzeć rozkłady stacyjne*

![Wyświetlacz LED](https://github.com/kuba200333/Generator-rozkladu-jazdy-pociagow/blob/main/image/wyswietlacz_led.png?raw=true)
*Wyświetlacz LED imitujący ten znany z pociągów*

## Instalacja i Konfiguracja

Aby uruchomić projekt lokalnie, potrzebujesz środowiska serwerowego obsługującego PHP i bazę danych MariaDB/MySQL.

### Wymagania
* Serwer WWW (np. Apache)
* PHP (projekt testowany na wersji 8.2.12)
* Baza danych MariaDB (projekt testowany na wersji 10.4.32) lub MySQL
* **Rekomendacja:** Zainstaluj pakiet [XAMPP](https://www.apachefriends.org/pl/index.html), który zawiera wszystkie powyższe elementy.

### Kroki Instalacji
1.  **Pobierz Projekt:**
    * Sklonuj repozytorium: `git clone https://github.com/kuba200333/Generator-rozkladu-jazdy-pociagow.git`
    * Lub pobierz pliki jako archiwum ZIP.

2.  **Umieść Pliki na Serwerze:**
    * Skopiuj wszystkie pliki projektu do głównego folderu serwera WWW (np. `C:\xampp\htdocs\generator-rozkladow`).

3.  **Skonfiguruj Bazę Danych:**
    * Otwórz narzędzie do zarządzania bazą danych (np. phpMyAdmin, domyślnie dostępne pod adresem `http://localhost/phpmyadmin`).
    * Stwórz nową bazę danych o nazwie `rozklad`.
    * Wybierz nowo utworzoną bazę, przejdź do zakładki "Import", a następnie wybierz i zaimportuj plik `baza.sql` z folderu projektu. Spowoduje to utworzenie wszystkich tabel i wgranie początkowych danych.

4.  **Sprawdź Połączenie:**
    * Otwórz plik `db_config.php` w edytorze kodu.
    * Upewnij się, że dane dostępu (`$username`, `$password`) zgadzają się z konfiguracją Twojego serwera. Domyślne ustawienia dla XAMPP to `root` i puste hasło.

5.  **Uruchom Aplikację:**
    * Otwórz przeglądarkę internetową i wejdź pod adres `http://localhost/nazwa-folderu-projektu` (np. `http://localhost/generator-rozkladow`). Powinieneś zobaczyć stronę główną aplikacji.

## Użyte Technologie
* **Backend:** PHP
* **Baza Danych:** MariaDB / MySQL
* **Frontend:** HTML, CSS, JavaScript