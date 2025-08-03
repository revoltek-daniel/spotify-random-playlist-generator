<?php
require_once 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/');
$dotenv->load();

$session = new SpotifyWebAPI\Session(
    $_ENV['SPOTIFY_API_CLIENT_ID'],
    $_ENV['SPOTIFY_API_CLIENT_SECRET'],
    $_ENV['SPOTIFY_API_REDIRECT_URL'],
);

$token = $session->requestCredentialsToken();

$api = new SpotifyWebAPI\SpotifyWebAPI();
$api->setAccessToken($session->getAccessToken());

$db = new PDO($_ENV['DATABASE_DSN'], $_ENV['DATABASE_USERNAME'], $_ENV['DATABASE_PASSWORD']);

$offset = 0;
$count = 0;
$artistCount = 0;
$updateQuery = 'UPDATE artists SET genre = ?, last_refresh = CURRENT_TIME() WHERE id = ?';
do {
    $stmt = $db->query('SELECT id from artists WHERE genre = "" ORDER BY last_refresh DESC LIMIT 50 OFFSET ' . $offset);
    $artistsToRefresh = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($artistsToRefresh) === 0) {
        break;
    }

    $artistCount += count($artistsToRefresh);
    $artists = $api->getArtists($artistsToRefresh);
    foreach ($artists->artists as $artist) {
        if (count($artist->genres)) {
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([implode(',', $artist->genres), $artist->id]);
            $count++;
        }
    }

    $offset += 50;
} while (count($artistsToRefresh) !== 0);

echo 'updated ' . $count . ' artists, ' . $artistCount . ' artists in total';