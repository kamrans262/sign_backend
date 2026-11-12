<?php

namespace App\Services;

/**
 * PDF-only drawing primitive for the approved quotation's rounded 10pt cards.
 * FPDF has a rectangular border but no rounded rectangle method.
 */
final class Sign2DigitalInvoicePdfCanvas extends \FPDF
{
    private function curve(float $x1, float $y1, float $x2, float $y2,
        float $x3, float $y3): void
    {
        $k=$this->k;
        $h=$this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1*$k,($h-$y1)*$k,$x2*$k,($h-$y2)*$k,$x3*$k,($h-$y3)*$k));
    }

    public function quotationCard(float $x, float $y, float $w,
        float $h, float $radius = 3.53): void
    {
        $r=min($radius,$w/2,$h/2);
        $k=$this->k;
        $ph=$this->h;
        $c=$r*0.55228474983;
        $this->_out(sprintf('%.2F %.2F m',
            ($x+$r)*$k,($ph-$y)*$k));
        $this->_out(sprintf('%.2F %.2F l',
            ($x+$w-$r)*$k,($ph-$y)*$k));
        $this->curve($x+$w-$r+$c,$y,$x+$w,$y+$r-$c,$x+$w,$y+$r);
        $this->_out(sprintf('%.2F %.2F l',
            ($x+$w)*$k,($ph-($y+$h-$r))*$k));
        $this->curve($x+$w,$y+$h-$r+$c,$x+$w-$r+$c,$y+$h,$x+$w-$r,$y+$h);
        $this->_out(sprintf('%.2F %.2F l',
            ($x+$r)*$k,($ph-($y+$h))*$k));
        $this->curve($x+$r-$c,$y+$h,$x,$y+$h-$r+$c,$x,$y+$h-$r);
        $this->_out(sprintf('%.2F %.2F l',
            $x*$k,($ph-($y+$r))*$k));
        $this->curve($x,$y+$r-$c,$x+$r-$c,$y,$x+$r,$y);
        $this->_out('h S');
    }
}
