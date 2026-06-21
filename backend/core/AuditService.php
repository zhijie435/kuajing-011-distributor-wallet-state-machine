<?php

class AuditService
{
    public function logRouting($orderNo, $warehouseCode, $routingData, $operator = null)
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "routing",
            "business_no" => $orderNo,
            "operator" => $operator,
            "action" => "warehouse_routing",
            "before_data" => json_encode(["warehouse_code" => null]),
            "after_data" => json_encode(["warehouse_code" => $warehouseCode, "routing_data" => $routingData]),
            "remark" => "仓库路由分配",
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    public function logCallback($callbackType, $orderNo, $warehouseCode, $requestData, $responseData, $success)
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "callback",
            "business_no" => $orderNo,
            "operator" => $warehouseCode,
            "action" => "callback_" . strtolower($callbackType),
            "before_data" => is_string($requestData) ? $requestData : json_encode($requestData),
            "after_data" => is_string($responseData) ? $responseData : json_encode($responseData),
            "remark" => $success ? "回调处理成功" : "回调处理失败",
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    public function logOperation($businessNo, $action, $beforeData = null, $afterData = null, $operator = null, $remark = "")
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "operation",
            "business_no" => $businessNo,
            "operator" => $operator,
            "action" => $action,
            "before_data" => is_string($beforeData) ? $beforeData : json_encode($beforeData),
            "after_data" => is_string($afterData) ? $afterData : json_encode($afterData),
            "remark" => $remark,
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    public function logStatusChange($orderNo, $beforeStatus, $afterStatus, $action, $operator = null)
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "status_change",
            "business_no" => $orderNo,
            "operator" => $operator,
            "action" => $action,
            "before_data" => json_encode(["status" => $beforeStatus]),
            "after_data" => json_encode(["status" => $afterStatus]),
            "remark" => "状态变更",
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    private function getClientIp()
    {
        if (!empty($_SERVER["HTTP_CLIENT_IP"])) {
            return $_SERVER["HTTP_CLIENT_IP"];
        }
        if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            return $_SERVER["HTTP_X_FORWARDED_FOR"];
        }
        return $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";
    }
}
