<?php
namespace App\Services;
final class M25StoreJws {
    private function decode(string $value): string {
        $decoded=base64_decode(strtr($value,'-_','+').str_repeat('=',(4-strlen($value)%4)%4),true);
        if($decoded===false)throw new \RuntimeException('Invalid JWS.');
        return $decoded;
    }
    private function cert(string $value) {
        if(!preg_match('/^[A-Za-z0-9+\/=]+$/D',$value))throw new \RuntimeException('Invalid certificate.');
        $c=openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split($value,64,"\n")."-----END CERTIFICATE-----\n");
        if(!$c)throw new \RuntimeException('Invalid certificate.');
        return $c;
    }
    public function verify(string $jws): array {
        $p=explode('.',$jws);
        if(count($p)!==3||strlen($jws)>65536)throw new \RuntimeException('Invalid JWS.');
        $h=json_decode($this->decode($p[0]),true,16);
        if(($h['alg']??null)!=='ES256'||!is_array($h['x5c']??null)||count($h['x5c'])!==3)throw new \RuntimeException('Invalid certificate chain.');
        $leaf=$this->cert($h['x5c'][0]);$intermediate=$this->cert($h['x5c'][1]);
        $root=openssl_x509_read(file_get_contents((string)config('m25_apple.store_root_path')));
        if(!$root)throw new \RuntimeException('Missing Apple root.');
        $lp=openssl_x509_parse($leaf);$ip=openssl_x509_parse($intermediate);$rp=openssl_x509_parse($root);
        if(!isset($lp['extensions']['1.2.840.113635.100.6.11.1'])||
            !isset($ip['extensions']['1.2.840.113635.100.6.2.1'])||
            !str_contains($ip['extensions']['basicConstraints']??'','CA:TRUE')||
            $lp['issuer']!==$ip['subject']||$ip['issuer']!==$rp['subject']||
            openssl_x509_verify($leaf,openssl_pkey_get_public($intermediate))!==1||
            openssl_x509_verify($intermediate,openssl_pkey_get_public($root))!==1)throw new \RuntimeException('Invalid Apple certificate chain.');
        $key=openssl_pkey_get_details(openssl_pkey_get_public($leaf));
        if(($key['type']??null)!==OPENSSL_KEYTYPE_EC||($key['bits']??null)!==256)throw new \RuntimeException('Invalid Apple signing key.');
        $claims=M25AppleJwt::claims($jws);
        $date=$claims['signedDate']??null;
        if(!is_int($date)||$date/1000>time()+60)throw new \RuntimeException('Invalid signing time.');
        foreach([$lp,$ip,$rp] as $cert)if($date/1000<$cert['validFrom_time_t']||$date/1000>$cert['validTo_time_t'])throw new \RuntimeException('Invalid signing certificate time.');
        $raw=$this->decode($p[2]);
        if(strlen($raw)!==64)throw new \RuntimeException('Invalid signature length.');
        $der='';
        foreach([substr($raw,0,32),substr($raw,32,32)] as $n){
            $n=ltrim($n,"\0");if($n===''||(ord($n[0])&128))$n="\0".$n;
            $der.=chr(2).chr(strlen($n)).$n;
        }
        if(openssl_verify($p[0].'.'.$p[1],chr(48).chr(strlen($der)).$der,openssl_pkey_get_public($leaf),OPENSSL_ALGO_SHA256)!==1)throw new \RuntimeException('Invalid signature.');
        return $claims;
    }
}
