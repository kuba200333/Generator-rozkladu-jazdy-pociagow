 <?php

// Ukrywamy błędy PHP, żeby nie psuły struktury JSON, jeśli coś pójdzie nie tak

error_reporting(0);

header('Content-Type: application/json');



// ==========================================

// WYBÓR LEKTORA (Zmień wartość poniżej)

// Dostępne głosy:

// 'Maja'  - Żeński (Amazon Polly) - Najlepsza jakość

// 'Ewa'   - Żeński (Amazon Polly)

// 'Jacek' - Męski  (Amazon Polly)

// 'Jan'   - Męski  (Amazon Polly)

// 'google'- Żeński (Google Translate - Awaryjny, bez limitów ttsmp3)

// ==========================================

$WYBRANY_GLOS = 'Ewa';





$data = json_decode(file_get_contents('php://input'), true);

$text = $data['text'] ?? '';



if (empty($text)) {

    echo json_encode(['success' => false, 'error' => 'Brak tekstu.']);

    exit;

}



$text = trim(preg_replace('/\s+/', ' ', $text));



// --- SYSTEM CACHE ---

$cache_dir = 'audio_cache';

if (!is_dir($cache_dir)) {

    mkdir($cache_dir, 0777, true);

}



// Zmieniamy nazwę pliku, żeby uwzględniała wybrany głos,

// aby cache nie pomieszał nagrań różnych lektorów dla tego samego tekstu

$file_name = md5($text . $WYBRANY_GLOS) . "_x2.mp3";

$file_path = $cache_dir . '/' . $file_name;



if (file_exists($file_path)) {

    $audio_data = file_get_contents($file_path);

    echo json_encode([

        'success' => true,

        'audio_url' => 'data:audio/mpeg;base64,' . base64_encode($audio_data),

        'source' => 'cache'

    ]);

    exit;

}



// Funkcja pomocnicza do bezpiecznego pobierania plików przez cURL

function pobierz_curl($url) {

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $data = curl_exec($ch);

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return ($code == 200) ? $data : false;

}



$single_audio_data = '';

$encoded_text = urlencode($text);



// --- PRÓBA POBRANIA Z TTSMP3 (jeśli nie wybrano na sztywno Google) ---

if ($WYBRANY_GLOS !== 'google') {

    $url = "https://ttsmp3.com/makemp3_new.php";

    $post_data = "msg=" . $encoded_text . "&lang=" . $WYBRANY_GLOS . "&source=ttsmp3";



    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);

    curl_setopt($ch, CURLOPT_POST, 1);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // Dodajemy nagłówki, żeby udawać prawdziwą przeglądarkę

    curl_setopt($ch, CURLOPT_HTTPHEADER, [

        'Content-Type: application/x-www-form-urlencoded',

        'Referer: https://ttsmp3.com/'

    ]);



    $response = curl_exec($ch);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);



    if ($http_code == 200) {

        $res = json_decode($response, true);

        if (isset($res['URL'])) {

            $single_audio_data = pobierz_curl($res['URL']);

        }

    }

}



// --- KOŁO RATUNKOWE: GOOGLE TTS ---

// Uruchamia się, jeśli ttsmp3 odrzuciło zapytanie, wyczerpałeś limit, lub gdy na twardo wybrałeś 'google'

if (empty($single_audio_data) || strlen($single_audio_data) < 500) {

   

    // Google wymaga cięcia tekstu na mniejsze kawałki

    $chunks = preg_split('/(?<=[.,?!])\s+/', $text);

    $finalChunks = [];

    $currentChunk = '';

    $maxLength = 150;



    foreach ($chunks as $chunk) {

        if (mb_strlen($currentChunk . ' ' . $chunk) <= $maxLength) {

            $currentChunk .= ($currentChunk === '' ? '' : ' ') . $chunk;

        } else {

            if ($currentChunk !== '') $finalChunks[] = $currentChunk;

            if (mb_strlen($chunk) > $maxLength) {

                 $words = explode(' ', $chunk);

                 $temp = '';

                 foreach($words as $w) {

                     if(mb_strlen($temp . ' ' . $w) <= $maxLength) {

                         $temp .= ($temp === '' ? '' : ' ') . $w;

                     } else {

                         $finalChunks[] = $temp;

                         $temp = $w;

                     }

                 }

                 $currentChunk = $temp;

            } else {

                 $currentChunk = $chunk;

            }

        }

    }

    if ($currentChunk !== '') $finalChunks[] = $currentChunk;



    foreach ($finalChunks as $chunk) {

        if (empty(trim($chunk))) continue;

        $enc = urlencode(trim($chunk));

        $g_url = "https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&q={$enc}&tl=pl";

        $audio_part = pobierz_curl($g_url);

       

        if ($audio_part) {

            $single_audio_data .= $audio_part;

        }

        usleep(100000); // Mała przerwa, żeby Google nie zablokowało za spam

    }

}



// --- KOŃCOWE KLEJENIE I WYSYŁKA ---

if ($single_audio_data && strlen($single_audio_data) > 500) {

    // Podwójna zapowiedź (powtarzanie komunikatu)

    $double_audio_data = $single_audio_data . $single_audio_data;

   

    // Zapis do cache

    file_put_contents($file_path, $double_audio_data);



    echo json_encode([

        'success' => true,

        'audio_url' => 'data:audio/mpeg;base64,' . base64_encode($double_audio_data),

        'source' => 'api_generated'

    ]);

} else {

    echo json_encode(['success' => false, 'error' => 'Nie udało się pobrać dźwięku z żadnego źródła.']);

}

?>