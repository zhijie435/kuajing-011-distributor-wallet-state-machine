<?php

namespace Dealer\Wallet\Model;

use Dealer\Wallet\Enum\FreezeStatus;

class FreezeRecord
{
    public int $id = 0;
    public int $walletId = 0;
    public int $dealerId = 0;
    public string $freezeNo = '';
    public float $amount = 0.0;
    public float $remainingAmount = 0.0;
    public int $status = 0;
    public string $reason = '';
    public ?string $expiredAt = null;
    public string $operator = '';
    public string $createdAt = '';
    public string $updatedAt = '';

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $property)) {
                $this->$property = $this->castProperty($property, $value);
            }
        }
    }

    private function castProperty(string $property, $value)
    {
        $intProps = ['id', 'walletId', 'dealerId', 'status'];
        $floatProps = ['amount', 'remainingAmount'];
        if (in_array($property, $intProps, true)) {
            return (int)$value;
        }
        if (in_array($property, $floatProps, true)) {
            return (float)$value;
        }
        if ($property === 'expiredAt') {
            return $value === null || $value === '' ? null : (string)$value;
        }
        return (string)$value;
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
