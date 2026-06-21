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

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $property)) {
                $this->$property = $value;
            }
        }
        $this->calculateAvailable();
    }

    public function calculateAvailable(): void
    {
        $this->availableAmount = bcsub($this->balance, $this->frozenAmount, 2);
        $this->status = WalletStateMachine::calculateStatus($this->balance, $this->frozenAmount);
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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
