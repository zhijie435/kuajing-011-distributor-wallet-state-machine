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
    "wallet" => [
        "freeze" => [
            "max_single_amount" => (float)($_ENV["WALLET_FREEZE_MAX_SINGLE_AMOUNT"] ?? 100000.00),
            "max_daily_amount" => (float)($_ENV["WALLET_FREEZE_MAX_DAILY_AMOUNT"] ?? 500000.00),
            "max_count_per_dealer" => (int)($_ENV["WALLET_FREEZE_MAX_COUNT_PER_DEALER"] ?? 50),
            "default_expire_hours" => (int)($_ENV["WALLET_FREEZE_DEFAULT_EXPIRE_HOURS"] ?? 72),
            "auto_unfreeze_enabled" => (bool)($_ENV["WALLET_FREEZE_AUTO_UNFREEZE_ENABLED"] ?? true),
            "auto_unfreeze_threshold_hours" => (int)($_ENV["WALLET_FREEZE_AUTO_UNFREEZE_THRESHOLD_HOURS"] ?? 168),
            "allow_partial_unfreeze" => (bool)($_ENV["WALLET_FREEZE_ALLOW_PARTIAL_UNFREEZE"] ?? true),
            "unfreeze_requires_audit" => (bool)($_ENV["WALLET_FREEZE_UNFREEZE_REQUIRES_AUDIT"] ?? false),
            "deduct_requires_audit" => (bool)($_ENV["WALLET_FREEZE_DEDUCT_REQUIRES_AUDIT"] ?? false),
            "no_prefix" => $_ENV["WALLET_FREEZE_NO_PREFIX"] ?? "FRZ",
        ],
        "balance" => [
            "recharge" => [
                "min_single_amount" => (float)($_ENV["WALLET_RECHARGE_MIN_SINGLE_AMOUNT"] ?? 0.01),
                "max_single_amount" => (float)($_ENV["WALLET_RECHARGE_MAX_SINGLE_AMOUNT"] ?? 500000.00),
                "max_daily_amount" => (float)($_ENV["WALLET_RECHARGE_MAX_DAILY_AMOUNT"] ?? 2000000.00),
                "requires_audit" => (bool)($_ENV["WALLET_RECHARGE_REQUIRES_AUDIT"] ?? false),
                "audit_threshold" => (float)($_ENV["WALLET_RECHARGE_AUDIT_THRESHOLD"] ?? 100000.00),
            ],
            "withdraw" => [
                "min_single_amount" => (float)($_ENV["WALLET_WITHDRAW_MIN_SINGLE_AMOUNT"] ?? 1.00),
                "max_single_amount" => (float)($_ENV["WALLET_WITHDRAW_MAX_SINGLE_AMOUNT"] ?? 200000.00),
                "max_daily_amount" => (float)($_ENV["WALLET_WITHDRAW_MAX_DAILY_AMOUNT"] ?? 500000.00),
                "daily_count_limit" => (int)($_ENV["WALLET_WITHDRAW_DAILY_COUNT_LIMIT"] ?? 10),
                "requires_audit" => (bool)($_ENV["WALLET_WITHDRAW_REQUIRES_AUDIT"] ?? true),
                "audit_threshold" => (float)($_ENV["WALLET_WITHDRAW_AUDIT_THRESHOLD"] ?? 50000.00),
            ],
            "consume" => [
                "min_single_amount" => (float)($_ENV["WALLET_CONSUME_MIN_SINGLE_AMOUNT"] ?? 0.01),
                "max_single_amount" => (float)($_ENV["WALLET_CONSUME_MAX_SINGLE_AMOUNT"] ?? 100000.00),
                "max_daily_amount" => (float)($_ENV["WALLET_CONSUME_MAX_DAILY_AMOUNT"] ?? 1000000.00),
                "allow_negative_balance" => (bool)($_ENV["WALLET_CONSUME_ALLOW_NEGATIVE_BALANCE"] ?? false),
            ],
            "refund" => [
                "max_single_amount" => (float)($_ENV["WALLET_REFUND_MAX_SINGLE_AMOUNT"] ?? 100000.00),
                "requires_audit" => (bool)($_ENV["WALLET_REFUND_REQUIRES_AUDIT"] ?? true),
                "audit_threshold" => (float)($_ENV["WALLET_REFUND_AUDIT_THRESHOLD"] ?? 10000.00),
                "refund_within_days" => (int)($_ENV["WALLET_REFUND_WITHIN_DAYS"] ?? 90),
            ],
        ],
        "reconciliation" => [
            "enabled" => (bool)($_ENV["WALLET_RECONCILIATION_ENABLED"] ?? true),
            "auto_reconcile_hour" => (int)($_ENV["WALLET_RECONCILIATION_AUTO_HOUR"] ?? 3),
            "alert_on_error" => (bool)($_ENV["WALLET_RECONCILIATION_ALERT_ON_ERROR"] ?? true),
            "alert_email" => $_ENV["WALLET_RECONCILIATION_ALERT_EMAIL"] ?? "admin@example.com",
            "export_encoding" => $_ENV["WALLET_RECONCILIATION_EXPORT_ENCODING"] ?? "UTF-8",
            "max_export_rows" => (int)($_ENV["WALLET_RECONCILIATION_MAX_EXPORT_ROWS"] ?? 100000),
        ],
        "state_machine" => [
            "strict_validation" => (bool)($_ENV["WALLET_STATE_MACHINE_STRICT_VALIDATION"] ?? true),
            "allow_force_transition" => (bool)($_ENV["WALLET_STATE_MACHINE_ALLOW_FORCE_TRANSITION"] ?? false),
            "transition_log_enabled" => (bool)($_ENV["WALLET_STATE_MACHINE_TRANSITION_LOG_ENABLED"] ?? true),
        ],
        "security" => [
            "operation_password_required" => (bool)($_ENV["WALLET_OPERATION_PASSWORD_REQUIRED"] ?? true),
            "password_threshold" => (float)($_ENV["WALLET_OPERATION_PASSWORD_THRESHOLD"] ?? 10000.00),
            "two_factor_required" => (bool)($_ENV["WALLET_TWO_FACTOR_REQUIRED"] ?? false),
            "two_factor_threshold" => (float)($_ENV["WALLET_TWO_FACTOR_THRESHOLD"] ?? 50000.00),
            "ip_whitelist_enabled" => (bool)($_ENV["WALLET_IP_WHITELIST_ENABLED"] ?? false),
        ],
    ],
];
