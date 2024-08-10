<?php
require_once '../vendor/autoload.php';
session_start();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$session = new SpotifyWebAPI\Session(
    $_ENV['SPOTIFY_API_CLIENT_ID'],
    $_ENV['SPOTIFY_API_CLIENT_SECRET'],
    $_ENV['SPOTIFY_API_REDIRECT_URL'],
);

if (isset($_POST['playlistName'], $_POST['trackAmount'])) {
    $_SESSION['playlistName'] = trim(strip_tags($_POST['playlistName']));
    $_SESSION['trackAmount'] = (int)$_POST['trackAmount'];

    $options = [
        'scope' => [
            'user-read-email',
            'user-follow-read',
            'playlist-modify-public',
            'playlist-modify-private'
        ],
    ];

    header('Location: ' . $session->getAuthorizeUrl($options));
    die();
}

if (isset($_GET['code'], $_SESSION['playlistName'], $_SESSION['trackAmount'])) {
    ?>
    <html lang="de">
    <head>
        <title>Generate Playlist</title>
    </head>
    <body>
        <?php
        try {
            $trackAmount = (int)$_SESSION['trackAmount'] ?: 100;
            $playlistName = $_SESSION['playlistName'];

            $db = new PDO($_ENV['DATABASE_DSN'], $_ENV['DATABASE_USERNAME'], $_ENV['DATABASE_PASSWORD']);
            $api = new SpotifyWebAPI\SpotifyWebAPI();
            $session->requestAccessToken($_GET['code']);
            $accessToken = $session->getAccessToken();

            $api->setAccessToken($accessToken);

            $userData = $api->me();

            echo 'getting artists <br/>';
            ob_flush();
            $artistIds = [];
            $options = ['limit' => 50];
            $stmt = $db->prepare('INSERT IGNORE INTO artists (id, name, last_refresh) VALUES (?, ?, CURRENT_TIME())');
            do {
                try {
                    $artists = $api->getUserFollowedArtists($options);

                    foreach ($artists->artists->items as $artist) {
                        $artistIds[] = $artist->id;

                        $stmt->execute([$artist->id, $artist->name]);
                    }
                    $options['after'] = end($artistIds);
                } catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
                    echo $e->getCode() . ' ' . $e->getMessage(). '<br/>';
                }
            } while (count($artistIds) % 50 === 0);

            unset($artists);

            $stmt = $db->prepare('
                    SELECT artists.id AS id 
                    FROM artists 
                        LEFT JOIN albums ON artists.id = albums.artist 
                    WHERE albums.id is null 
                      AND artists.id IN (' . implode(',', array_fill(0, count($artistIds), '?')) . ')'
            );
            $stmt->execute($artistIds);
            $artistIdsWithoutAlbums = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($artistIdsWithoutAlbums)) {
                echo 'getting albums<br/>';
                ob_flush();

                $stmt = $db->prepare('INSERT IGNORE INTO albums (id, name, artist) VALUES (?, ?, ?)');
                foreach ($artistIdsWithoutAlbums as $artistId) {
                    try {
                        $albums = $api->getArtistAlbums($artistId, ['limit' => 50, 'include_groups' => 'album']);

                        foreach ($albums->items as $album) {
                            $stmt->execute([$album->id, $album->name, $artistId]);
                        }
                    } catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
                        echo $e->getCode() . ' ' . $e->getMessage() . '<br/>';
                    }
                }

                unset($albums);
            }

            $stmt = $db->prepare('SELECT albums.id AS id FROM albums LEFT JOIN tracks ON albums.id = tracks.album WHERE tracks.id is null AND albums.artist IN (' . implode(',', array_fill(0, count($artistIds), '?')) . ')');
            $stmt->execute($artistIds);
            $albumIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($albumIds)) {
                echo 'getting tracks<br/>';
                ob_flush();
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
                        echo $e->getCode() . ' ' . $e->getMessage(). '<br/>';
                    }
                }
            }

            $playlist = $api->createPlaylist($userData->id, ['name' => $playlistName]);
            $playlistId = $playlist->id;

            echo 'Empty playlist created</br>';
            echo 'Fill Playlist with tracks<br/>';
            ob_flush();
            $stmt = $db->prepare(
                'SELECT tracks.id 
                           FROM tracks 
                               LEFT JOIN albums ON tracks.album = albums.id 
                           WHERE albums.podcast = 0 AND albums.artist IN (' . implode(',', array_fill(0, count($artistIds), '?')) . ') 
                    ORDER BY RAND() LIMIT 1000 ');

            $insertedTracks = [];
            for ($x = 0; $x < $trackAmount / 100; $x++) {
                $stmt->execute($artistIds);
                $trackIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                shuffle($trackIds);

                if (count($trackIds) < 100) {
                    $tracksToAdd = $trackIds;
                    echo 'not enough tracks to add, only ' .count($trackIds). ' found<br>';
                } else {
                    $tracksToAdd = [];
                    do {
                        $index = random_int(0, count($trackIds) - 1);

                        if (isset($trackIds[$index]) === false || isset($insertedTracks[$index])) {
                            continue;
                        }

                        $tracksToAdd[] = $trackIds[$index];
                        $insertedTracks[$trackIds[$index]] = $trackIds[$index];
                        unset($trackIds[$index]);
                    } while (count($tracksToAdd) < 100);
                }

                $api->addPlaylistTracks($playlistId, $tracksToAdd);

                if (count($tracksToAdd) < 100) {
                    break;
                }
            }

            echo 'Playlist created <a target="_blank" href="https://open.spotify.com/playlist/' . $playlistId . '">' . $playlistId . '</a></br>';

        } catch (\SpotifyWebAPI\SpotifyWebAPIException $e) {
            echo 'Error: '. $e->getMessage();

            if ($e->isRateLimited()) {
                $headers = $api->getRequest()->getLastResponse()['headers'];
                echo "\n" . 'Retry after: ' . gmdate("H:i:s", $headers['retry-after']);
                echo "\n";
            }
        }
        ?>
    </body>
    </html>
    <?php
} else {
    header('Location: index.html');
}