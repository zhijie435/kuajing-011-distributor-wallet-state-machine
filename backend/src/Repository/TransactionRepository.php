<?php

namespace Dealer\Wallet\Repository;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Model\Transaction;
use PDO;

class TransactionRepository
{
    /** @var PDO|MockDatabase */
    private $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO dealer_wallet_transaction 
                (wallet_id, dealer_id, type, amount, balance_before, balance_after, 
                 frozen_before, frozen_after, related_no, operator, remark) 
                VALUES (:wallet_id, :dealer_id, :type, :amount, :balance_before, :balance_after, 
                        :frozen_before, :frozen_after, :related_no, :operator, :remark)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $data['wallet_id'], PDO::PARAM_INT);
        $stmt->bindValue(':dealer_id', $data['dealer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':type', $data['type'], PDO::PARAM_INT);
        $stmt->bindValue(':amount', $data['amount']);
        $stmt->bindValue(':balance_before', $data['balance_before']);
        $stmt->bindValue(':balance_after', $data['balance_after']);
        $stmt->bindValue(':frozen_before', $data['frozen_before']);
        $stmt->bindValue(':frozen_after', $data['frozen_after']);
        $stmt->bindValue(':related_no', $data['related_no'] ?? '');
        $stmt->bindValue(':operator', $data['operator'] ?? '');
        $stmt->bindValue(':remark', $data['remark'] ?? '');
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function findByWalletId(int $walletId, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;
        $sql = "SELECT * FROM dealer_wallet_transaction 
                WHERE wallet_id = :wallet_id 
                ORDER BY id DESC LIMIT :offset, :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = (new Transaction($data))->toArray();
        }
        return $items;
    }

    public function countByWalletId(int $walletId): int
    {
        $sql = "SELECT COUNT(*) FROM dealer_wallet_transaction WHERE wallet_id = :wallet_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function findAllByWalletIdOrdered(int $walletId): array
    {
        $sql = "SELECT * FROM dealer_wallet_transaction 
                WHERE wallet_id = :wallet_id 
                ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = new Transaction($data);
        }
        return $items;
    }

    public function findByDateRange(?string $startDate = null, ?string $endDate = null, ?int $dealerId = null): array
    {
        $sql = "SELECT * FROM dealer_wallet_transaction WHERE 1=1";
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
            $items[] = (new Transaction($data))->toArray();
        }
        return $items;
    }

    public function findByRelatedNo(string $relatedNo): array
    {
        $sql = "SELECT * FROM dealer_wallet_transaction WHERE related_no = :related_no ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':related_no', $relatedNo);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = new Transaction($data);
        }
        return $items;
    }

    public function findAllByDealerIdOrdered(int $dealerId): array
    {
        $sql = "SELECT * FROM dealer_wallet_transaction 
                WHERE dealer_id = :dealer_id 
                ORDER BY id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dealer_id', $dealerId, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = new Transaction($data);
        }
        return $items;
    }
}
