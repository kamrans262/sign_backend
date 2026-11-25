<?php
namespace App\Services;

final class M25AppleJwt {
    public static function b64(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
    public static function claims(string $jwt): array {
        $parts=explode('.',$jwt);
        if(count($parts)!==3 || strlen($jwt)>65536) throw new \RuntimeException('Invalid Apple response.');
        $raw=base64_decode(strtr($parts[1],'-_','+').str_repeat('=',(4-strlen($parts[1])%4)%4),true);
        $claims=is_string($raw)?json_decode($raw,true,32):null;
        if(!is_array($claims)) throw new \RuntimeException('Invalid Apple claims.');
        return $claims;
    }
    public static function sign(string $kid,string $path,array $claims): string {
        if($kid==='' || !is_file($path) || !is_readable($path)) throw new \RuntimeException('Apple credentials not configured.');
        $key=openssl_pkey_get_private(file_get_contents($path));
        if(!$key) throw new \RuntimeException('Apple key invalid.');
        $details=openssl_pkey_get_details($key);
        if(($details['type']??null)!==OPENSSL_KEYTYPE_EC || ($details['bits']??0)!==256) throw new \RuntimeException('Expected P-256 key.');
        $input=self::b64(json_encode(['alg'=>'ES256','kid'=>$kid,'typ'=>'JWT'],JSON_THROW_ON_ERROR)).'.'.self::b64(json_encode($claims,JSON_THROW_ON_ERROR));
        if(!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256)) throw new \RuntimeException('Signing failed.');
        // OpenSSL produces DER; JOSE requires two fixed-width unsigned integers.
        $i=0;
        $read=function(int $tag) use (&$i,$der): string {
            if($i+2>strlen($der) || ord($der[$i++])!==$tag) throw new \RuntimeException('Invalid signature.');
            $len=ord($der[$i++]);
            if($len>=128 || $i+$len>strlen($der)) throw new \RuntimeException('Invalid signature length.');
            $value=substr($der,$i,$len); $i+=$len; return $value;
        };
        $seq=$read(0x30); if($i!==strlen($der)) throw new \RuntimeException('Invalid signature.');
        $der=$seq; // Parse sequence separately, no long-form length for P-256.
        $pos=0; $raw='';
        for($j=0;$j<2;$j++){
            if($pos+2>strlen($der)||ord($der[$pos++])!==2) throw new \RuntimeException('Invalid integer.');
            $len=ord($der[$pos++]);
            if($len<1||$len>33||$pos+$len>strlen($der)) throw new \RuntimeException('Invalid integer.');
            $v=ltrim(substr($der,$pos,$len),"\0");$pos+=$len;
            if(strlen($v)>32) throw new \RuntimeException('Invalid integer.');
            $raw.=str_pad($v,32,"\0",STR_PAD_LEFT);
        }
        if($pos!==strlen($der)) throw new \RuntimeException('Invalid signature.');
        return $input.'.'.self::b64($raw);
    }
}
