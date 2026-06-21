<?php

namespace Dealer\Wallet\Exception;

class WalletException extends \RuntimeException
{
    private array $rollbackInfo = [];

    private array $retryInfo = [];

    public function setRollbackInfo(array $info): self
    {
        $this->rollbackInfo = $info;
        return $this;
    }

    public function getRollbackInfo(): array
    {
        return $this->rollbackInfo;
    }

    public function setRetryInfo(array $info): self
    {
        $this->retryInfo = $info;
        return $this;
    }

    public function getRetryInfo(): array
    {
        return $this->retryInfo;
    }

    public function hasRollbackInfo(): bool
    {
        return !empty($this->rollbackInfo);
    }

    public function hasRetryInfo(): bool
    {
        return !empty($this->retryInfo);
    }

    public function getFullContext(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'rollback' => $this->rollbackInfo,
            'retry' => $this->retryInfo,
        ];
    }
}
