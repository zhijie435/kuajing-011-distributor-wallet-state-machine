<?php

namespace Dealer\Wallet\Repository;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Model\FreezeRecord;
use PDO;

class FreezeRecordRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO dealer_wallet_freeze_record 
                (wallet_id, dealer_id, freeze_no, amount, remaining_amount, status, 
                 reason, expired_at, operator) 
                VALUES (:wallet_id, :dealer_id, :freeze_no, :amount, :remaining_amount, :status, 
                        :reason, :expired_at, :operator)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $data['wallet_id'], PDO::PARAM_INT);
        $stmt->bindValue(':dealer_id', $data['dealer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':freeze_no', $data['freeze_no']);
        $stmt->bindValue(':amount', $data['amount']);
        $stmt->bindValue(':remaining_amount', $data['amount']);
        $stmt->bindValue(':status', FreezeStatus::FROZEN, PDO::PARAM_INT);
        $stmt->bindValue(':reason', $data['reason'] ?? '');
        $stmt->bindValue(':expired_at', $data['expired_at'] ?? null);
        $stmt->bindValue(':operator', $data['operator'] ?? '');
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function findByFreezeNo(string $freezeNo): ?FreezeRecord
    {
        $sql = "SELECT * FROM dealer_wallet_freeze_record WHERE freeze_no = :freeze_no FOR UPDATE";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':freeze_no', $freezeNo);
        $stmt->execute();
        $data = $stmt->fetch();
        return $data ? new FreezeRecord($data) : null;
    }

    public function findByWalletId(int $walletId, int $status = null, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;
        $sql = "SELECT * FROM dealer_wallet_freeze_record WHERE wallet_id = :wallet_id";
        if ($status !== null) {
            $sql .= " AND status = :status";
        }
        $sql .= " ORDER BY id DESC LIMIT :offset, :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        if ($status !== null) {
            $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = (new FreezeRecord($data))->toArray();
        }
        return $items;
    }

    public function updateRemaining(FreezeRecord $record, float $remainingAmount, int $status): bool
    {
        $sql = "UPDATE dealer_wallet_freeze_record 
                SET remaining_amount = :remaining_amount, status = :status 
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':remaining_amount', $remainingAmount);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':id', $record->id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function generateFreezeNo(): string
    {
        return 'FZ' . date('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function findAllByWalletId(int $walletId): array
    {
        $sql = "SELECT * FROM dealer_wallet_freeze_record WHERE wallet_id = :wallet_id ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = new FreezeRecord($data);
        }
        return $items;
    }

    public function findAllByDealerId(int $dealerId): array
    {
        $sql = "SELECT * FROM dealer_wallet_freeze_record WHERE dealer_id = :dealer_id ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dealer_id', $dealerId, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = new FreezeRecord($data);
        }
        return $items;
    }

    public function findByDateRange(?string $startDate = null, ?string $endDate = null, ?int $dealerId = null): array
    {
        $sql = "SELECT * FROM dealer_wallet_freeze_record WHERE 1=1";
        $params = [];
        if ($startDate) {
            $sql .= " AND created_at >= :start_date";
            $params[':start_date'] = $startDate;
        }
        if ($endDate) {
            $sql .= " AND created_at <= :end_date";
            $params[':end_date'] = $endDate;
        }
        if ($dealerId !== null) {
            $sql .= " AND dealer_id = :dealer_id";
            $params[':dealer_id'] = $dealerId;
        }
        $sql .= " ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':dealer_id') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = (new FreezeRecord($data))->toArray();
        }
        return $items;
    }

    public function sumFrozenByDealerId(int $dealerId): float
    {
        $sql = "SELECT COALESCE(SUM(remaining_amount), 0) FROM dealer_wallet_freeze_record 
                WHERE dealer_id = :dealer_id AND status = :status";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dealer_id', $dealerId, PDO::PARAM_INT);
        $stmt->bindValue(':status', FreezeStatus::FROZEN, PDO::PARAM_INT);
        $stmt->execute();
        return (float)$stmt->fetchColumn();
    }
}
