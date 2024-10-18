<?php

namespace GPhotos;

require __DIR__ . '/vendor/autoload.php';

use Google\Auth\OAuth2;
use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Photos\Library\V1\PhotosLibraryClient;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Exception;
use DateTime;

set_time_limit(0);

class GPhotosService
{
    private static function sessionManager()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function checkAuthenticationStatus(): string
    {
        self::sessionManager();

        if (isset($_SESSION['credentials'])) {
            try {
                $photosLibraryClient = new PhotosLibraryClient([
                    'credentials' => $_SESSION['credentials']
                ]);

                if ($photosLibraryClient) {
                    return 'authorized';
                }
            } catch (Exception $ex) {
                return (self::validateCredentials() ? 'valid_credentials' : 'invalid_credentials');
            }
        }

        return (self::validateCredentials() ? 'valid_credentials' : 'invalid_credentials');
    }

    private static function validateCredentials(): bool
    {
        self::sessionManager();

        $clientSecretJson = [];

        if (file_exists('credentials.json')) {
            $clientSecretJson = json_decode(file_get_contents('credentials.json'), true);
        }

        return !empty($clientSecretJson);
    }

    public static function connectAccount(): string
    {
        self::sessionManager();

        $clientSecretJson = json_decode(file_get_contents('credentials.json'), true);

        $_SESSION['clientId'] = $clientSecretJson['installed']['client_id'];
        $_SESSION['clientSecret'] = $clientSecretJson['installed']['client_secret'];

        $_SESSION['OAuth2'] = new OAuth2([
            'scope'                 => 'https://www.googleapis.com/auth/photoslibrary.readonly',
            'clientId'              => $_SESSION['clientId'],
            'clientSecret'          => $_SESSION['clientSecret'],
            'authorizationUri'      => 'https://accounts.google.com/o/oauth2/auth',
            'redirectUri'           => 'http://localhost:8080',
            'tokenCredentialUri'    => 'https://oauth2.googleapis.com/token'
        ]);

        return $_SESSION['OAuth2']->buildFullAuthorizationUri([
            'access_type' => 'offline'
        ]);
    }

    public static function saveCode(string $code): string
    {
        self::sessionManager();

        $_SESSION['OAuth2']->setCode($code);

        $authToken = $_SESSION['OAuth2']->fetchAuthToken();

        $refreshToken = $authToken['access_token'];

        $_SESSION['credentials'] = new UserRefreshCredentials(
            $_SESSION['OAuth2']->getScope(),
            [
                'client_id'     => $_SESSION['clientId'],
                'client_secret' => $_SESSION['clientSecret'],
                'refresh_token' => $refreshToken
            ]
        );

        $_SESSION['access_token'] = $authToken['access_token'];

        return $_SESSION['OAuth2']->getRedirectUri();
    }

    public static function fetchMedia()
    {
        self::sessionManager();

        $startTime = microtime(true);

        try {
            $photosLibraryClient = new PhotosLibraryClient([
                'credentials' => $_SESSION['credentials']
            ]);

            $pagedResponse = $photosLibraryClient->listMediaItems([
                'pageSize' => 100
            ]);
        } catch (Exception $ex) {
            session_destroy();

            self::asyncOutput($ex->getMessage());
            self::asyncOutput('EOF');

            return;
        }

        $mediaLinks = [];
        $localStoragePath = './media';

        $pages = $pagedResponse->iteratePages();

        foreach ($pages as $page) {
            foreach ($page as $element) {
                if (strpos($element->getMimeType(), 'video/') === 0) {
                    $baseUrl = $element->getBaseUrl() . '=dv';
                } else {
                    $baseUrl = $element->getBaseUrl() . '=d';
                }

                $date = new DateTime();
                $date->setTimestamp($element->getMediaMetadata()->getCreationTime()->getSeconds());
                $formattedDate = $date->format('d/m/Y');

                $dateParts = explode('/', $formattedDate);
                list($day, $month, $year) = $dateParts;
                $dirPath = $localStoragePath . '/' . $year . '/' . $month . '/' . $day;

                array_push($mediaLinks, [
                    'dirPath'       => $dirPath,
                    'filename'      => $element->getFilename(),
                    'filePath'      => "$dirPath/{$element->getFilename()}",
                    'baseUrl'       => $baseUrl,
                    'date'          => $formattedDate
                ]);

                self::asyncOutput("Listando arquivo: {$element->getFilename()}");
            }
        }

        if (!file_exists($localStoragePath) && !mkdir($localStoragePath, 0777, true)) {
            self::asyncOutput('Falha ao criar a pasta principal');
            self::asyncOutput('EOF');

            return;
        }

        foreach ($mediaLinks as $link) {
            if (!is_dir($link['dirPath'])) {
                if (!mkdir($link['dirPath'], 0777, true)) {
                    self::asyncOutput("Falha ao criar a pasta: {$link['dirPath']}");
                    self::asyncOutput('EOF');

                    return;
                }

                self::asyncOutput("Criando pasta: {$link['dirPath']}");
            }
        }

        $client = new Client();
        $batchSize = 10;
        $total = count($mediaLinks);
        $currentDir = '';

        foreach (array_chunk($mediaLinks, $batchSize) as $batchIndex => $batch) {
            $promises = [];

            foreach ($batch as $index => $link) {
                if ($currentDir != $link['dirPath']) {
                    self::asyncOutput("Inserindo na pasta: <b>{$link['dirPath']}</b>");
                }

                self::asyncOutput('Baixando arquivo ' . (($batchIndex * $batchSize) + $index + 1) . " de {$total}: {$link['filename']}</b>");

                $promises[] = $client->getAsync($link['baseUrl'], [
                    'headers' => [
                        'Authorization' => "Bearer {$_SESSION['access_token']}",
                        'Accept' => '*/*'
                    ]
                ])->then(function ($response) use ($link) {
                    file_put_contents($link['filePath'], $response->getBody());
                });

                $currentDir = $link['dirPath'];
            }

            Promise\Utils::settle($promises)->wait();
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        self::asyncOutput('<b>Tempo de execução:</b> ' . number_format($executionTime, 2) . ' segundos');
    }

    private static function asyncOutput(string $message): void
    {
        echo "data: $message\n\n";
        ob_flush();
        flush();
    }
}
