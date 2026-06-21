<?php

namespace Dealer\Wallet\Repository;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Model\Wallet;
use PDO;

class WalletRepository
{
    /** @var PDO|MockDatabase */
    private $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function findByDealerId(int $dealerId): ?Wallet
    {
        $sql = "SELECT * FROM dealer_wallet WHERE dealer_id = :dealer_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dealer_id', $dealerId, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch();
        return $data ? new Wallet($data, true) : null;
    }

    public function findByDealerIdForUpdate(int $dealerId): ?Wallet
    {
        $sql = "SELECT * FROM dealer_wallet WHERE dealer_id = :dealer_id FOR UPDATE";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dealer_id', $dealerId, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch();
        return $data ? new Wallet($data, true) : null;
    }

    public function findById(int $id): ?Wallet
    {
        $sql = "SELECT * FROM dealer_wallet WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch();
        return $data ? new Wallet($data, true) : null;
    }

    public function findAll(int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;
        $sql = "SELECT * FROM dealer_wallet ORDER BY id DESC LIMIT :offset, :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        while ($data = $stmt->fetch()) {
            $items[] = (new Wallet($data, true))->toArray();
        }
        return $items;
    }

    public function count(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM dealer_wallet");
        return (int)$stmt->fetchColumn();
    }

    public function create(int $dealerId): Wallet
    {
        $sql = "INSERT INTO dealer_wallet (dealer_id, balance, frozen_amount, available_amount, status, version) 
                VALUES (:dealer_id, 0.00, 0.00, 0.00, 1, 0)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dealer_id', $dealerId, PDO::PARAM_INT);
        $stmt->execute();
        $id = (int)$this->pdo->lastInsertId();
        return $this->findById($id);
    }

    public function update(Wallet $wallet): bool
    {
        $sql = "UPDATE dealer_wallet 
                SET balance = :balance, 
                    frozen_amount = :frozen_amount, 
                    available_amount = :available_amount, 
                    status = :status, 
                    version = version + 1 
                WHERE id = :id AND version = :version";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':balance', number_format($wallet->balance, 2, '.', ''));
        $stmt->bindValue(':frozen_amount', number_format($wallet->frozenAmount, 2, '.', ''));
        $stmt->bindValue(':available_amount', number_format($wallet->availableAmount, 2, '.', ''));
        $stmt->bindValue(':status', $wallet->status, PDO::PARAM_INT);
        $stmt->bindValue(':id', $wallet->id, PDO::PARAM_INT);
        $stmt->bindValue(':version', $wallet->version, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
