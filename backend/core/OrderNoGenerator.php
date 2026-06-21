<?php

class OrderNoGenerator
{
    public static function generate($prefix = "WH")
    {
        $datetime = date("YmdHis");
        $random = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_LEFT);
        return $prefix . $datetime . $random;
    }
}
