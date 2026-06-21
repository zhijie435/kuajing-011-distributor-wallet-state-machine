<?php

namespace Dealer\Wallet\Model;

use Dealer\Wallet\Enum\TransactionType;

class Transaction
{
    public int $id;
    public int $walletId;
    public int $dealerId;
    public int $type;
    public float $amount;
    public float $balanceBefore;
    public float $balanceAfter;
    public float $frozenBefore;
    public float $frozenAfter;
    public string $relatedNo;
    public string $operator;
    public string $remark;
    public string $createdAt;

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
            'type' => $this->type,
            'type_name' => TransactionType::getName($this->type),
            'direction' => TransactionType::getDirection($this->type),
            'amount' => number_format($this->amount, 2, '.', ''),
            'balance_before' => number_format($this->balanceBefore, 2, '.', ''),
            'balance_after' => number_format($this->balanceAfter, 2, '.', ''),
            'frozen_before' => number_format($this->frozenBefore, 2, '.', ''),
            'frozen_after' => number_format($this->frozenAfter, 2, '.', ''),
            'related_no' => $this->relatedNo,
            'operator' => $this->operator,
            'remark' => $this->remark,
            'created_at' => $this->createdAt,
        ];
    }
}
