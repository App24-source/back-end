<?php

return [
    'server_key' => env('FCM_SERVER_KEY'),
    'sender_id' => env('FCM_SENDER_ID'),
    'server_url' => 'https://fcm.googleapis.com/v1/projects/'.env('FCM_SENDER_ID').'/messages:send',
];