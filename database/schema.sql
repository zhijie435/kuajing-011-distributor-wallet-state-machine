CREATE TABLE IF NOT EXISTS dealer_wallet (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dealer_id BIGINT UNSIGNED NOT NULL COMMENT '经销商ID',
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '账户余额',
    frozen_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '冻结金额',
    available_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '可用余额 = balance - frozen_amount',
    status TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1-正常 2-部分冻结 3-全额冻结',
    version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '乐观锁版本号',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dealer_id (dealer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='经销商钱包主表';

CREATE TABLE IF NOT EXISTS dealer_wallet_transaction (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id BIGINT UNSIGNED NOT NULL COMMENT '钱包ID',
    dealer_id BIGINT UNSIGNED NOT NULL COMMENT '经销商ID',
    type TINYINT NOT NULL COMMENT '交易类型：1-充值 2-提现 3-消费 4-退款 5-冻结 6-解冻',
    amount DECIMAL(12,2) NOT NULL COMMENT '交易金额',
    balance_before DECIMAL(12,2) NOT NULL COMMENT '变更前余额',
    balance_after DECIMAL(12,2) NOT NULL COMMENT '变更后余额',
    frozen_before DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '变更前冻结金额',
    frozen_after DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '变更后冻结金额',
    related_no VARCHAR(64) NOT NULL DEFAULT '' COMMENT '关联单号',
    operator VARCHAR(64) NOT NULL DEFAULT '' COMMENT '操作人',
    remark VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wallet_id (wallet_id),
    INDEX idx_dealer_id (dealer_id),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='钱包交易流水表';

CREATE TABLE IF NOT EXISTS dealer_wallet_freeze_record (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id BIGINT UNSIGNED NOT NULL COMMENT '钱包ID',
    dealer_id BIGINT UNSIGNED NOT NULL COMMENT '经销商ID',
    freeze_no VARCHAR(64) NOT NULL COMMENT '冻结单号',
    amount DECIMAL(12,2) NOT NULL COMMENT '冻结金额',
    remaining_amount DECIMAL(12,2) NOT NULL COMMENT '剩余冻结金额',
    status TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1-冻结中 2-已解冻 3-已扣除',
    reason VARCHAR(255) NOT NULL DEFAULT '' COMMENT '冻结原因',
    expired_at TIMESTAMP NULL COMMENT '过期时间',
    operator VARCHAR(64) NOT NULL DEFAULT '' COMMENT '操作人',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_freeze_no (freeze_no),
    INDEX idx_wallet_id (wallet_id),
    INDEX idx_dealer_id (dealer_id),
    INDEX idx_status (status),
    INDEX idx_expired_at (expired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='钱包冻结记录表';

INSERT INTO dealer_wallet (dealer_id, balance, frozen_amount, available_amount, status) VALUES
(1001, 10000.00, 0.00, 10000.00, 1),
(1002, 5000.00, 2000.00, 3000.00, 2),
(1003, 8000.00, 8000.00, 0.00, 3);

INSERT INTO dealer_wallet_freeze_record (wallet_id, dealer_id, freeze_no, amount, remaining_amount, status, reason, operator) VALUES
(2, 1002, 'FZ20240101001', 2000.00, 2000.00, 1, '订单冻结', 'system'),
(3, 1003, 'FZ20240101002', 8000.00, 8000.00, 1, '违规冻结', 'admin');
