<?php

declare(strict_types=1);

$records = [];
for ($index = 0; $index < 10_000; $index++) {
    $records[] = [
        'id' => sprintf('message-%05d', $index),
        'chat_id' => 'chat-1',
        'body' => str_repeat('Nitro ', 12),
        'type' => 1,
        'created_at' => 1_700_000_000 + $index,
    ];
}

$encoded = json_encode($records, JSON_THROW_ON_ERROR);
$started = hrtime(true);
for ($iteration = 0; $iteration < 100; $iteration++) {
    $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    array_slice($decoded, -20);
}
$jsonMs = (hrtime(true) - $started) / 1_000_000;

printf("JSON full hydration (10k records × 100): %.3f ms\n", $jsonMs);
printf("This baseline is not a Nitro result. Native device benchmarks are required.\n");
