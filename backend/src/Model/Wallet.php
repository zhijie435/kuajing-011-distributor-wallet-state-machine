<?php

namespace Dealer\Wallet\Model;

use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\StateMachine\WalletStateMachine;

class Wallet
{
    public int $id;
    public int $dealerId;
    public float $balance;
    public float $frozenAmount;
    public float $availableAmount;
    public int $status;
    public int $version;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(array $data = [], bool $fromDatabase = true)
    {
        foreach ($data as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $property)) {
                $this->$property = $this->castProperty($property, $value);
            }
        }
        if (!$fromDatabase) {
            $this->calculateAvailable();
        } else {
            $this->sanitizeFromDatabase();
        }
    }

    private function castProperty(string $property, $value)
    {
        $intProps = ['id', 'dealerId', 'status', 'version'];
        $floatProps = ['balance', 'frozenAmount', 'availableAmount'];
        if (in_array($property, $intProps, true)) {
            return (int)$value;
        }
        if (in_array($property, $floatProps, true)) {
            return (float)$value;
        }
        return (string)$value;
    }

    private function sanitizeFromDatabase(): void
    {
        $expectedAvailable = (float)bcsub((string)($this->balance ?? 0), (string)($this->frozenAmount ?? 0), 2);
        $expectedStatus = WalletStateMachine::calculateStatus(
            (float)($this->balance ?? 0),
            (float)($this->frozenAmount ?? 0),
            false
        );

        $dbAvailable = $this->availableAmount ?? 0.0;
        $dbStatus = $this->status ?? WalletStatus::NORMAL;

        $availDiff = abs($expectedAvailable - (float)$dbAvailable) > 0.001;
        $statusDiff = $expectedStatus !== $dbStatus;

        if ($availDiff || $statusDiff) {
            $this->availableAmount = $expectedAvailable;
            $this->status = $expectedStatus;
        }
    }

    public function calculateAvailable(): void
    {
        $this->availableAmount = (float)bcsub((string)$this->balance, (string)$this->frozenAmount, 2);
        $this->status = WalletStateMachine::calculateStatus($this->balance, $this->frozenAmount, false);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'dealer_id' => $this->dealerId,
            'balance' => number_format($this->balance, 2, '.', ''),
            'frozen_amount' => number_format($this->frozenAmount, 2, '.', ''),
            'available_amount' => number_format($this->availableAmount, 2, '.', ''),
            'status' => $this->status,
            'status_name' => WalletStatus::getName($this->status),
            'status_color' => WalletStatus::getColor($this->status),
            'version' => $this->version,
            'created_at' => $this->createdAt ?? '',
            'updated_at' => $this->updatedAt ?? '',
        ];
    }
}
