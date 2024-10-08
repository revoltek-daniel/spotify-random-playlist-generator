<?php
require_once 'vendor/autoload.php';
session_start();

// @todo make configurable
$lastTimestamp = time() - 3600 * 24 * 7;

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

$stmt = $db->prepare('
                    SELECT artists.id AS id 
                    FROM artists 
                    WHERE podcast = 0 AND (last_refresh is null OR last_refresh < FROM_UNIXTIME(?))
                    ORDER BY RAND()
                    LIMIT 150
                    '
);

$stmt->execute([$lastTimestamp]);
$artistsToRefresh = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($artistsToRefresh) === 0) {
    die('no artists to refresh');
}

$stmt = $db->prepare('INSERT IGNORE INTO albums (id, name, artist) VALUES (?, ?, ?)');
$artistUpdateStmt = $db->prepare('UPDATE artists SET last_refresh = CURRENT_TIME() WHERE id = ?');
foreach ($artistsToRefresh as $key => $artistId) {
    try {
        $albums = $api->getArtistAlbums($artistId, ['limit' => 50, 'include_groups' => 'album']);

        foreach ($albums->items as $album) {
            $stmt->execute([$album->id, $album->name, $artistId]);
        }

        $artistUpdateStmt->execute([$artistId]);
    } catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
        echo 'error getting albums ' . $artistId . "\r\n";
        echo $e->getMessage() . "\r\n";
        unset($artistsToRefresh[$key]);
    }
}

$artistsToRefresh = array_values($artistsToRefresh);

unset($albums);

$stmt = $db->prepare('SELECT albums.id AS id FROM albums LEFT JOIN tracks ON albums.id = tracks.album WHERE tracks.id is null AND albums.artist IN (' . implode(',', array_fill(0, count($artistsToRefresh), '?')) . ')');
$stmt->execute($artistsToRefresh);
$albumIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($albumIds)) {
    echo 'getting tracks' . "\r\n";
    $stmt = $db->prepare('INSERT IGNORE INTO tracks (id, name, album) VALUES (?, ?, ?)');
    foreach ($albumIds as $key => $albumId) {
        try {
            $albumTracks = $api->getAlbumTracks($albumId, ['limit' => 50]);
            foreach ($albumTracks->items as $track) {
                if (isset($track->available_markets) && count($track->available_markets) === 0) {
                    continue;
                }

                $stmt->execute([$track->id, $track->name, $albumId]);
            }
        } catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
            echo 'error on getting tracks for album ' . $albumId . "\r\n";
            echo $e->getMessage() . "\r\n";
        }
    }
}