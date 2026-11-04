<?php

/**
 * Read-only M8 compatibility audit for M7 approval-frozen private PDFs.
 *
 * Prints aggregate counts only (no customer data, paths, hashes, tokens).
 * Works against the existing M7 production runtime without FPDI installed.
 * This is a STRUCTURAL SCREEN, not a parser/visual signing acceptance.
 *
 * From sign_backend root: php scripts/m8_approved_pdf_audit.php
 */
declare(strict_types=1);
$root=getcwd();
if(!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')){
    fwrite(STDERR,"M8_AUDIT_BLOCKED: Run from installed Sign2Digital backend.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!\Illuminate\Support\Facades\Schema::hasColumn('service_quotes','approved_pdf_sha256')){
    echo "M8_AUDIT_BLOCKED: M7 approval integrity schema is absent.\n";
    exit(2);
}
$stats=[
    'checked'=>0,'missing_frozen_pdf'=>0,'corrupt_or_mismatch'=>0,
    'compressed_xref_candidate'=>0,'encrypted_candidate'=>0,
    'classic_xref_candidate'=>0,
];
$disk=\Illuminate\Support\Facades\Storage::disk('local');
$records=\App\Models\ServiceQuote::query()
    ->whereIn('status',['approved','sent'])
    ->orderByDesc('id')->limit(50)
    ->get(['id','approved_pdf_path','approved_pdf_sha256']);
foreach($records as $quote){
    $stats['checked']++;
    $path=(string)($quote->approved_pdf_path??'');
    $sha=(string)($quote->approved_pdf_sha256??'');
    if($path===''||$sha===''){
        $stats['missing_frozen_pdf']++;
        continue;
    }
    try{
        if(!$disk->exists($path)){
            $stats['corrupt_or_mismatch']++;continue;
        }
        $bytes=$disk->get($path);
        if(!is_string($bytes) || strlen($bytes)>5*1024*1024 ||
            !str_starts_with($bytes,'%PDF-') ||
            !str_contains(substr($bytes,-4096),'%%EOF') ||
            !hash_equals($sha,hash('sha256',$bytes))){
            $stats['corrupt_or_mismatch']++;continue;
        }
        if(preg_match('/\/Encrypt\b/',$bytes)){
            $stats['encrypted_candidate']++;continue;
        }
        if(preg_match('/\/Type\s*\/XRef\b|\/ObjStm\b/',$bytes) ||
           !preg_match('/(?:^|\r?\n)xref\s*\r?\n/', $bytes)){
            $stats['compressed_xref_candidate']++;continue;
        }
        $stats['classic_xref_candidate']++;
    }catch(\Throwable $ignored){
        $stats['corrupt_or_mismatch']++;
    }
}
foreach($stats as $name=>$count){
    echo 'M8_'.strtoupper($name).' '.(int)$count.PHP_EOL;
}
echo "INFO: This is an aggregate structural screen, not proof of FPDI import.\n";
echo "INFO: No private document bytes or metadata were printed or changed.\n";
echo "INFO: Full import, render and checksum verification remains mandatory before deployment.\n";
