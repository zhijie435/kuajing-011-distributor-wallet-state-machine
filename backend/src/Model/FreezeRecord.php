<?php

namespace Dealer\Wallet\Model;

use Dealer\Wallet\Enum\FreezeStatus;

class FreezeRecord
{
    public int $id;
    public int $walletId;
    public int $dealerId;
    public string $freezeNo;
    public float $amount;
    public float $remainingAmount;
    public int $status;
    public string $reason;
    public ?string $expiredAt;
    public string $operator;
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
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->walletId,
            'dealer_id' => $this->dealerId,
            'freeze_no' => $this->freezeNo,
            'amount' => number_format($this->amount, 2, '.', ''),
            'remaining_amount' => number_format($this->remainingAmount, 2, '.', ''),
            'status' => $this->status,
            'status_name' => FreezeStatus::getName($this->status),
            'reason' => $this->reason,
            'expired_at' => $this->expiredAt,
            'operator' => $this->operator,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
