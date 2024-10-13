<?php

namespace GPhotos;

require __DIR__ . '/vendor/autoload.php';

use GPhotos\GPhotosService;

$action = $_GET['action'] ?? '';

if ($action) {
    switch ($action) {
        case 'checkAuthenticationStatus':
            $response = GPhotosService::checkAuthenticationStatus();

            output(['status' => $response]);
            break;

        case 'connectAccount':
            $response = GPhotosService::connectAccount();

            output(['authorization' => $response]);
            break;

        case 'saveCode':
            $response = GPhotosService::saveCode($_GET['code']);

            output(['redirect' => $response]);
            break;

        case 'fetchMedia':
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');

            ob_end_flush();
            ob_start();

            GPhotosService::fetchMedia();

            echo "data: EOF\n\n";

            ob_flush();
            flush();
            break;
    }
}

function output(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}
