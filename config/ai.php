<?php
return [
    'model'    => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    'endpoint' => rtrim(env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
    'api_key'  => env('GEMINI_API_KEY'),
    'temperature' => 0.2,
];
