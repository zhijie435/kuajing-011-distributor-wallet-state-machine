<?php

return [
    "db" => [
        "host" => "127.0.0.1",
        "port" => 3306,
        "database" => "overseas_warehouse",
        "username" => "root",
        "password" => "",
        "charset" => "utf8mb4",
    ],
    "callback" => [
        "token" => "wh_callback_token_2024",
        "ip_whitelist" => [
            "127.0.0.1",
            "10.0.0.0/8",
            "192.168.0.0/16",
        ],
    ],
    "order" => [
        "no_prefix" => "WH",
        "max_quantity_per_item" => 999,
    ],
    "warehouse" => [
        "default_priority" => 0,
    ],
];
