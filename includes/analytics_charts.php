<?php
function anFormat(int|float|null $value,string $unit='count'): string {
    if($value===null)return 'Unavailable';return match($unit){'money'=>'PHP '.number_format($value/100,2),'percent'=>number_format($value,2).'%', 'hours'=>number_format($value,2).' hours',default=>number_format($value,($value==floor($value)?0:2))};
}
function anAxis(int|float $value,string $unit): string {
    $v=$unit==='money'?$value/100:$value;$scale=abs($v);
    foreach([1000000000=>'B',1000000=>'M',1000=>'K']as$divisor=>$suffix)if($scale>=$divisor)return ($unit==='money'?'PHP ':'').rtrim(rtrim(number_format($v/$divisor,1,'.',''),'0'),'.').$suffix;
    return anFormat($value,$unit);
}
/** Native SVG extends the existing dependency-free charts; all labels/links are escaped. */
function anChartSvg(array $c,bool $print=false): string {
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$ink=$print?'#526176':'var(--text-secondary)';$grid=$print?'#dbe2eb':'var(--border-color)';$colors=['#d97706','#2563eb','#059669','#9333ea','#e11d48','#64748b'];$rows=$c['rows'];$n=count($rows);$max=max(array_merge([0],array_column($rows,'value'),array_column($c['secondary']??[],'value')));
    $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 320" role="img" aria-label="'.$e($c['title']).'" style="color:'.$ink.';font-family:DejaVu Sans,Arial,sans-serif"><title>'.$e($c['title']).'</title>';
    if(!$n||$max<=0)return $svg.'<text x="360" y="160" text-anchor="middle" fill="'.$ink.'" font-size="16">No matching chart values for this period.</text></svg>';
    $link=static function($shape,$row,$value)use($c,$e){$title=$e($row['label'].' | '.anFormat($value,$c['unit']));$shape=str_replace('><title>', ' aria-label="'.$title.'"><title>',$shape);$url=$row['url']??$c['url'];return $url?'<a href="'.$e($url).'">'.$shape.'</a>':$shape;};
    if($c['type']==='donut'&&$n<=12&&min(array_column($rows,'value'))>=0){$sum=array_sum(array_column($rows,'value'));$angle=-M_PI/2;foreach($rows as$i=>$r){$v=$r['value'];if($v<=0)continue;$end=$angle+$v/$sum*2*M_PI;$x1=180+94*cos($angle);$y1=150+94*sin($angle);$x2=180+94*cos($end-.000001);$y2=150+94*sin($end-.000001);$large=($end-$angle)>M_PI?1:0;$path='<path d="M '.$x1.' '.$y1.' A 94 94 0 '.$large.' 1 '.$x2.' '.$y2.'" fill="none" stroke="'.$colors[$i%count($colors)].'" stroke-width="42"><title>'.$e($r['label'].' | '.anFormat($v,$c['unit'])).'</title></path>';$svg.=$link($path,$r,$v);$angle=$end;}$svg.='<text x="180" y="155" text-anchor="middle" fill="'.$ink.'" font-size="22">'.$e(anFormat($sum,$c['unit'])).'</text>';foreach($rows as$i=>$r){$y=32+$i*23;$svg.='<rect x="330" y="'.($y-11).'" width="10" height="10" fill="'.$colors[$i%count($colors)].'"/><text x="350" y="'.$y.'" fill="'.$ink.'" font-size="14">'.$e(mb_strimwidth($r['label'],0,26,'...')).' | '.$e(anFormat($r['value'],$c['unit'])).'</text>';}return $svg.'</svg>';}
    $left=112;$bottom=264;$height=220;$width=590;$max=ceil($max*1.05);$min=min(array_merge([0],array_column($rows,'value')));$range=$max-$min;if($range<=0)$range=1;$y=static fn($v)=>$bottom-($v-$min)/$range*$height;
    for($i=0;$i<=4;$i++){$v=$min+$range*$i/4;$yy=$y($v);$svg.='<line x1="'.$left.'" y1="'.$yy.'" x2="702" y2="'.$yy.'" stroke="'.$grid.'"/><text x="106" y="'.($yy+4).'" text-anchor="end" fill="'.$ink.'" font-size="16">'.$e(anAxis($v,$c['unit'])).'</text>';}
    $series=[['rows'=>$rows,'color'=>$colors[0]]];if(isset($c['secondary']))$series[]=['rows'=>$c['secondary'],'color'=>$colors[1]];
    foreach($series as$seriesIndex=>$set){$points=[];foreach($set['rows']as$i=>$r){$x=$left+($i+.5)*$width/$n;$yy=$y($r['value']);$points[]=$x.','.$yy;$title=$e($r['label'].' | '.anFormat($r['value'],$c['unit']));if($c['type']==='line')$shape='<circle cx="'.$x.'" cy="'.$yy.'" r="3" fill="'.$set['color'].'"><title>'.$title.'</title></circle>';else{$w=max(1,$width/$n*.65/count($series));$xx=$x-$width/$n*.325+$seriesIndex*$w;$shape='<rect x="'.$xx.'" y="'.min($yy,$y(0)).'" width="'.$w.'" height="'.max(.1,abs($y(0)-$yy)).'" rx="2" fill="'.$set['color'].'"><title>'.$title.'</title></rect>';}$svg.=$link($shape,$r,$r['value']);}if($c['type']==='line')$svg.='<polyline points="'.implode(' ',$points).'" fill="none" stroke="'.$set['color'].'" stroke-width="2" pointer-events="none"/>';}
    $step=max(1,(int)ceil($n/6));foreach($rows as$i=>$r)if($i%$step===0){$x=$left+($i+.5)*$width/$n;$svg.='<text x="'.$x.'" y="286" text-anchor="middle" fill="'.$ink.'" font-size="16">'.$e(mb_strimwidth($r['label'],0,15,'...')).'</text>';}
    if(isset($c['secondary']))$svg.='<text x="82" y="312" fill="#d97706" font-size="14">'.$e($c['primary_name']).'</text><text x="370" y="312" fill="#2563eb" font-size="14">'.$e($c['secondary_name']).'</text>';
    return $svg.'</svg>';
}
